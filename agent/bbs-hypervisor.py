#!/usr/bin/env python3
"""
BBS hypervisor module.

Installed only on hypervisor agents (install.sh --hypervisor), next to
bbs-agent.py, which loads it when its config says `role = hypervisor`. A
client agent never has this file, so it carries none of the hypervisor
capabilities (snapshotting, reading and writing VM disks, creating VMs).

Each supported hypervisor has a connector class here and a matching one on
the server (src/Services/Hypervisors). The server tells the agent which
hypervisors it serves and with which connector; the connector reports the
inventory in the common model the server stores:

    {"type": <slug>, "info": {...}, "vms": [vm, ...]}
    vm:   ref, name, host, status, cpus, memory_bytes, uptime_seconds,
          guest_tools, template, tags, disks[], extra{}
    disk: key, path, datastore, datastore_type, kind, size_bytes,
          default_excluded, supported, unsupported_reason

VM backup and restore are connector methods too, so adding ESXi or vCenter
is a new class registered in CONNECTORS.

Run as a script, `bbs-hypervisor.py segfs|imagefs <spec> <mountpoint>`
serves the segment filesystem of changed block tracking (segfs_serve) or
the volume images of file-level browsing (imagefs_serve, VmBrowser).

This file is signed like bbs-agent.py (bin/bbs-sign-agent): an agent
refuses an update of it whose signature does not verify.
"""
import contextlib
import json
import logging
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import time

MODULE_VERSION = "1.4.0"

IS_WINDOWS = sys.platform == "win32"
IS_MACOS = sys.platform == "darwin"

# Same logger as the core agent, so messages land in its log file.
logger = logging.getLogger("bbs-agent")

DEPLOY_AGENT_ON_HOST = "agent_on_host"
DEPLOY_PROXY_AGENT = "proxy_agent"


class HypervisorConnector(object):
    slug = None
    deployment = DEPLOY_AGENT_ON_HOST

    def __init__(self, settings=None):
        # Connection settings from the server (address, credentials) for
        # connectors that reach their hypervisor over an API. Unused by a
        # connector that runs on the host.
        self.settings = settings or {}

    @classmethod
    def detect(cls):
        """For an on-host connector: True when this machine is that hypervisor."""
        return False

    def inventory(self):
        raise NotImplementedError


class ProxmoxConnector(HypervisorConnector):
    """Proxmox VE, single node; the agent runs on the node.

    VM configs are read straight from /etc/pve (the cluster filesystem is the
    source of truth, and far faster than one `qm config` per VM); only the
    run state comes from pvesh."""

    slug = "proxmox"
    deployment = DEPLOY_AGENT_ON_HOST
    QEMU_CONF_DIR = "/etc/pve/qemu-server"
    STORAGE_CFG = "/etc/pve/storage.cfg"
    DISK_KEY = re.compile(r"^(?:(?:scsi|virtio|sata|ide)\d+|efidisk0|tpmstate0|unused\d+)$")
    # Storage types whose volumes can be snapshotted and backed up. LVM-thin
    # first; ZFS and others come later.
    SUPPORTED_STORAGE = ("lvmthin",)

    @classmethod
    def detect(cls):
        return (not IS_WINDOWS and not IS_MACOS
                and os.path.exists("/usr/bin/pveversion") and os.path.isdir(cls.QEMU_CONF_DIR))

    @staticmethod
    def node_name():
        # /etc/pve/local links to nodes/<this node>
        try:
            name = os.path.basename(os.path.realpath("/etc/pve/local"))
            if name and name != "local":
                return name
        except OSError:
            pass
        return socket.gethostname().split(".")[0]

    @staticmethod
    def version():
        try:
            out = subprocess.run(["pveversion"], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                 timeout=15).stdout.decode("utf-8", errors="replace").strip()
            # pve-manager/9.2.10/abcdef01 (running kernel: 6.14.8-2-pve)
            m = re.match(r"pve-manager/([^/\s]+)", out)
            return m.group(1) if m else out[:40]
        except Exception:
            return None

    @staticmethod
    def parse_size(value):
        """Proxmox sizes: 32G, 512M, 4M, 1T, or plain bytes."""
        if not value:
            return None
        m = re.match(r"^(\d+(?:\.\d+)?)([KMGT]?)$", value.strip(), re.I)
        if not m:
            return None
        mult = {"": 1, "K": 1024, "M": 1024 ** 2, "G": 1024 ** 3, "T": 1024 ** 4}[m.group(2).upper()]
        return int(float(m.group(1)) * mult)

    def storage_config(self):
        """storage id -> {"type": ..., <option>: <value>} from storage.cfg:

            lvmthin: local-lvm
                    thinpool data
                    vgname pve
        """
        storages = {}
        current = None
        try:
            with open(self.STORAGE_CFG) as f:
                for line in f:
                    if not line.strip() or line.startswith("#"):
                        continue
                    if not line[:1].isspace() and ":" in line:
                        stype, sid = line.split(":", 1)
                        current = storages[sid.strip()] = {"type": stype.strip()}
                    elif current is not None:
                        parts = line.strip().split(None, 1)
                        current[parts[0]] = parts[1] if len(parts) > 1 else ""
        except OSError:
            pass
        return storages

    def storage_types(self):
        """Map storage id -> type ("local-lvm" -> "lvmthin")."""
        return {sid: s.get("type") for sid, s in self.storage_config().items()}

    def backup_session(self, vm, log):
        """A VM backup in progress: see ProxmoxVmBackup."""
        return ProxmoxVmBackup(self, vm, log)

    # Storage types a VM's disks can be restored to
    RESTORE_STORAGE = ("lvmthin",)

    def restore_vm(self, source, ref, options, log, progress):
        """Restore a VM archive as a new VM: see ProxmoxVmRestore."""
        return ProxmoxVmRestore(self, source, ref, options, log, progress).run()

    @staticmethod
    def parse_conf(path):
        """The current config of a VM: the lines before its first [snapshot]
        section. Comment lines hold the description and are skipped."""
        conf = {}
        with open(path, encoding="utf-8", errors="replace") as f:
            for line in f:
                line = line.rstrip("\n")
                if line.startswith("["):
                    break
                if not line or line.startswith("#") or ":" not in line:
                    continue
                key, value = line.split(":", 1)
                conf[key.strip()] = value.strip()
        return conf

    def disk_entry(self, key, value, storage_types):
        parts = value.split(",")
        volume = parts[0]
        opts = {}
        for p in parts[1:]:
            if "=" in p:
                k, v = p.split("=", 1)
                opts[k] = v

        if key.startswith("efidisk"):
            kind = "efi"
        elif key.startswith("tpmstate"):
            kind = "tpm"
        elif key.startswith("unused"):
            kind = "unused"
        elif opts.get("media") == "cdrom":
            kind = "cloudinit" if "cloudinit" in volume else "cdrom"
        elif volume.startswith("/dev/"):
            kind = "passthrough"
        else:
            kind = "disk"

        storage = None
        if ":" in volume and not volume.startswith("/"):
            storage = volume.split(":", 1)[0]
        storage_type = storage_types.get(storage) if storage else None

        reason = None
        if kind in ("cdrom", "cloudinit", "unused"):
            reason = "not part of a VM backup"
        elif kind == "passthrough":
            reason = "physical disk passthrough"
        elif storage_type not in self.SUPPORTED_STORAGE:
            reason = "storage type {} not supported yet".format(storage_type or "unknown")

        return {
            "key": key,
            "path": volume,
            "datastore": storage,
            "datastore_type": storage_type,
            "kind": kind,
            "size_bytes": self.parse_size(opts.get("size")),
            # backup=0 is Proxmox's own "skip this disk in vzdump" flag
            "default_excluded": opts.get("backup", "1") == "0",
            "supported": reason is None,
            "unsupported_reason": reason,
        }

    @staticmethod
    def run_state(node):
        """vmid -> run state from the API (status, uptime, ...)."""
        try:
            res = subprocess.run(
                ["pvesh", "get", "/nodes/{}/qemu".format(node), "--output-format", "json"],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
            if res.returncode != 0:
                logger.warning("pvesh failed: {}".format(res.stderr.decode("utf-8", errors="replace").strip()[:300]))
                return {}
            return {int(vm["vmid"]): vm for vm in json.loads(res.stdout.decode("utf-8")) if "vmid" in vm}
        except Exception as e:
            logger.warning("Could not read VM run state: {}".format(e))
            return {}

    @staticmethod
    def _int(value):
        try:
            return int(value)
        except (TypeError, ValueError):
            return None

    def inventory(self):
        node = self.node_name()
        storage_types = self.storage_types()
        state = self.run_state(node)
        vms = []
        for entry in sorted(os.listdir(self.QEMU_CONF_DIR)):
            m = re.match(r"^(\d+)\.conf$", entry)
            if not m:
                continue
            vmid = int(m.group(1))
            try:
                conf = self.parse_conf(os.path.join(self.QEMU_CONF_DIR, entry))
            except OSError as e:
                logger.warning("Could not read config of VM {}: {}".format(vmid, e))
                continue
            disks = [self.disk_entry(k, v, storage_types) for k, v in conf.items() if self.DISK_KEY.match(k)]
            disks.sort(key=lambda d: d["key"])
            st = state.get(vmid, {})
            agent_opt = conf.get("agent", "0").split(",")[0]
            memory_mb = self._int(conf.get("memory"))
            vms.append({
                "ref": str(vmid),
                "name": conf.get("name") or st.get("name") or "VM {}".format(vmid),
                "host": node,
                "status": st.get("status", "unknown"),
                "cpus": st.get("cpus") or self._int(conf.get("cores")),
                "memory_bytes": st.get("maxmem") or (memory_mb * 1024 * 1024 if memory_mb else None),
                "uptime_seconds": st.get("uptime"),
                "guest_tools": agent_opt in ("1", "enabled=1"),
                "template": conf.get("template") == "1",
                "tags": conf.get("tags"),
                "disks": disks,
                "extra": {"ostype": conf.get("ostype"), "bios": conf.get("bios", "seabios")},
            })
        return {
            "type": self.slug,
            "info": {
                "node": node,
                "version": self.version(),
                "datastores": [{"id": k, "type": v} for k, v in sorted(storage_types.items())],
            },
            "vms": vms,
        }


def _run(argv, timeout=120, check=True):
    """Run a command; raise with its error output when it fails."""
    res = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    if check and res.returncode != 0:
        err = (res.stderr or res.stdout).decode("utf-8", errors="replace").strip()
        raise RuntimeError("{} failed: {}".format(" ".join(argv[:3]), err[:500] or "exit {}".format(res.returncode)))
    return res


class ThinPool(object):
    """LVM-thin helpers for changed block tracking."""

    LOCK_FILE = "/var/lib/bbs-agent/vm-cbt/thin-delta.lock"

    @staticmethod
    def dm_name(vg, lv):
        return vg.replace("-", "--") + "-" + lv.replace("-", "--")

    @staticmethod
    def lv_fields(vg, lv, fields):
        res = _run(["lvs", "--noheadings", "--separator", "|", "-o", ",".join(fields), "{}/{}".format(vg, lv)],
                   timeout=30, check=False)
        if res.returncode != 0:
            return None
        values = res.stdout.decode("utf-8", errors="replace").strip().split("|")
        return dict(zip(fields, [v.strip() for v in values]))

    @classmethod
    def thin_id(cls, vg, lv):
        f = cls.lv_fields(vg, lv, ["thin_id"])
        try:
            return int(f["thin_id"]) if f else None
        except (KeyError, ValueError):
            return None

    @classmethod
    def pool_of(cls, vg, lv):
        f = cls.lv_fields(vg, lv, ["pool_lv"])
        return (f or {}).get("pool_lv") or None

    @classmethod
    def data_percent(cls, vg, pool):
        f = cls.lv_fields(vg, pool, ["data_percent", "metadata_percent"])
        try:
            return max(float(f["data_percent"] or 0), float(f["metadata_percent"] or 0))
        except (TypeError, KeyError, ValueError):
            return None

    @classmethod
    def changed_ranges(cls, vg, pool, id1, id2):
        """Byte ranges (start, length) whose block mapping differs between
        thin devices id1 and id2 of the pool. A block both share has the
        same content in both; anything else is reported, so the result can
        over-report (a rewrite with identical data) but never under-report.

        thin_delta reads the pool metadata, which the kernel keeps changing
        while VMs run: it works on a metadata snapshot, taken and released
        here under a lock (a pool holds one metadata snapshot at a time)."""
        import fcntl
        tpool = cls.dm_name(vg, pool) + "-tpool"
        tmeta = "/dev/mapper/" + cls.dm_name(vg, pool + "_tmeta")
        os.makedirs(os.path.dirname(cls.LOCK_FILE), exist_ok=True)
        with open(cls.LOCK_FILE, "w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            reserve = ["dmsetup", "message", tpool, "0", "reserve_metadata_snap"]
            release = ["dmsetup", "message", tpool, "0", "release_metadata_snap"]
            if _run(reserve, timeout=60, check=False).returncode != 0:
                # A metadata snapshot left by an interrupted run
                _run(release, timeout=60, check=False)
                _run(reserve, timeout=60)
            try:
                out = _run(["thin_delta", "-m", "--snap1", str(id1), "--snap2", str(id2), tmeta],
                           timeout=3600).stdout.decode("utf-8", errors="replace")
            finally:
                _run(release, timeout=60, check=False)
        m = re.search(r'data_block_size="(\d+)"', out)
        if not m or "<diff" not in out or "</diff>" not in out:
            raise RuntimeError("unexpected thin_delta output: {}".format(out[:200]))
        block = int(m.group(1)) * 512
        ranges = []
        for kind, begin, length in re.findall(r'<(different|left_only|right_only) begin="(\d+)" length="(\d+)"', out):
            ranges.append((int(begin) * block, int(length) * block))
        return ranges


class ProxmoxVmBackup(object):
    """Back up one VM of this node: the core agent calls prepare(command)
    before running borg and cleanup(succeeded) after, whatever happened.

    prepare():
      1. reads the VM config and picks the disks to back up (supported, not
         excluded by the plan);
      2. freezes the guest filesystems when the QEMU guest agent answers;
      3. takes a thin snapshot of each disk (snap_<lv>_bbsnew), then thaws at
         once (the guest is frozen for the time of the lvcreate calls only);
      4. builds <WORK_ROOT>/vm-<vmid>/vm-<vmid>/ holding the disks, the VM
         config (qemu-server.conf) and a manifest (bbs-vm.json) for a restore;
      5. returns the borg command with that directory as path, run from
         WORK_ROOT/vm-<vmid>, so the archive holds vm-<vmid>/...

    Changed block tracking (manifest format 2, the default when the node has
    thin_delta and FUSE): each disk is shown to borg as a directory of
    fixed-size segment files (disks/<key>/000000.seg ...) served from the
    snapshot by a small read-only FUSE filesystem (see segfs_serve). Every
    segment carries a "last changed" timestamp as its ctime/mtime. After a
    successful backup the snapshot is kept as the reference (snap_<lv>_bbsbase);
    the next run asks thin_delta which blocks differ between the reference
    and the new snapshot, and only the segments holding such blocks get a new
    timestamp. borg's files cache then skips every other segment without
    reading it, while the archive still holds the whole disk.

    The timestamps are kept in STATE_DIR/vm-<vmid>.json together with the
    thin id of the reference snapshot. They only move forward, and only when
    the content may have changed, so a files cache filled by any earlier run
    (to this repository or another) stays correct. Any doubt (no reference,
    disk resized or moved, thin_delta error) reads the disk in full, as does
    a run more than FULL_READ_DAYS after the last full read.

    Without thin_delta or FUSE, format 1: each disk is a symlink to its
    snapshot read with --read-special, in full every time.
    """

    WORK_ROOT = "/var/lib/bbs-agent/vm-backup"
    STATE_DIR = "/var/lib/bbs-agent/vm-cbt"
    LEGACY_SNAP_SUFFIX = "-bbs-snap"
    NEW_SNAP = "snap_{}_bbsnew"
    BASE_SNAP = "snap_{}_bbsbase"
    SEGMENT_SIZE = 64 * 1024 * 1024
    CHUNKER_PARAMS = "fixed,4194304"
    FULL_READ_DAYS = 7
    # Above this pool usage the reference snapshots are not kept
    POOL_LIMIT_PERCENT = 85.0

    def __init__(self, connector, vm, log):
        ref = str(vm.get("ref", ""))
        if not re.match(r"^[0-9]+$", ref):
            raise ValueError("invalid VMID {!r}".format(ref))
        self.c = connector
        self.vmid = ref
        self.exclude = set(d for d in (vm.get("exclude_disks") or []) if isinstance(d, str))
        self.log = log
        self.root = os.path.join(self.WORK_ROOT, "vm-" + ref)
        self.vmdir = os.path.join(self.root, "vm-" + ref)
        self.mountpoint = os.path.join(self.vmdir, "disks")
        self.state_path = os.path.join(self.STATE_DIR, "vm-{}.json".format(ref))
        self.snapshots = []  # (vg, lv) created by this run
        self.disks = []
        self.frozen = False
        self.cbt = False
        self.new_state = None
        self.fuse_proc = None
        self.lock_file = None

    # -- helpers -------------------------------------------------------------

    def _status(self):
        out = _run(["qm", "status", self.vmid], timeout=30).stdout.decode("utf-8", errors="replace")
        m = re.search(r"status:\s*(\S+)", out)
        return m.group(1) if m else "unknown"

    def _guest_agent_answers(self, conf):
        if conf.get("agent", "0").split(",")[0] not in ("1", "enabled=1"):
            return False
        try:
            return _run(["qm", "guest", "cmd", self.vmid, "ping"], timeout=15, check=False).returncode == 0
        except Exception:
            return False

    def _thaw(self):
        last = None
        for attempt in range(3):
            try:
                _run(["qm", "guest", "cmd", self.vmid, "fsfreeze-thaw"], timeout=60)
                self.frozen = False
                self.log("Guest filesystems thawed")
                return
            except Exception as e:
                last = e
        self.log("WARNING: could not thaw the guest filesystems of VM {}: {}. "
                 "Run 'qm guest cmd {} fsfreeze-thaw' on the node.".format(self.vmid, last, self.vmid))

    @staticmethod
    def _lv_exists(vg, lv):
        return _run(["lvs", "--noheadings", "{}/{}".format(vg, lv)], timeout=30, check=False).returncode == 0

    def _remove_snapshot(self, vg, lv):
        if not self._lv_exists(vg, lv):
            return True
        # Right after borg the device can still be held for a moment
        for attempt in range(5):
            if _run(["lvremove", "-y", "{}/{}".format(vg, lv)], timeout=60, check=False).returncode == 0:
                return True
            time.sleep(2)
        self.log("WARNING: could not remove snapshot {}/{}; remove it with 'lvremove {}/{}'".format(vg, lv, vg, lv))
        return False

    @staticmethod
    def _device_size(device):
        try:
            return int(_run(["blockdev", "--getsize64", device], timeout=30).stdout.decode().strip())
        except Exception:
            return None

    def _lock(self):
        import fcntl
        os.makedirs(self.STATE_DIR, mode=0o700, exist_ok=True)
        self.lock_file = open(os.path.join(self.STATE_DIR, "vm-{}.lock".format(self.vmid)), "w")
        try:
            fcntl.flock(self.lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.lock_file.close()
            self.lock_file = None
            raise RuntimeError("another backup of VM {} is running".format(self.vmid))

    def _unlock(self):
        if self.lock_file is not None:
            self.lock_file.close()  # releases the flock
            self.lock_file = None

    def _load_state(self):
        try:
            with open(self.state_path) as f:
                state = json.load(f)
            if isinstance(state, dict) and state.get("format") == 1 and isinstance(state.get("disks"), dict):
                return state
        except (OSError, ValueError):
            pass
        return {"format": 1, "disks": {}, "last_ts": 0}

    def _save_state(self, state):
        tmp = self.state_path + ".tmp"
        with open(tmp, "w") as f:
            json.dump(state, f)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, self.state_path)

    @staticmethod
    def cbt_unavailable():
        """Why changed block tracking cannot be used on this node, or None."""
        import platform
        if platform.machine() not in ("x86_64", "amd64"):
            return "only implemented for x86_64"
        for tool in ("thin_delta", "fusermount", "dmsetup"):
            if not shutil.which(tool):
                return "{} is not installed".format(tool)
        if not os.path.exists("/dev/fuse"):
            return "/dev/fuse is missing"
        try:
            import ctypes
            ctypes.CDLL("libfuse.so.2")
        except OSError:
            return "libfuse2 is not installed"
        return None

    # -- changed block tracking ----------------------------------------------

    def _plan_segments(self, now_ns):
        """Give every segment of every disk its "last changed" timestamp."""
        state = self._load_state()
        ts = max(now_ns, int(state.get("last_ts") or 0) + 1)
        full_after = self.FULL_READ_DAYS * 86400 * 10 ** 9
        seg = self.SEGMENT_SIZE
        new_disks = {}
        for d in self.disks:
            size = d["device_size"]
            count = (size + seg - 1) // seg
            prev = state["disks"].get(d["key"])
            pool = ThinPool.pool_of(d["vg"], d["lv"])
            new_id = ThinPool.thin_id(d["vg"], d["new"])
            if pool is None or new_id is None:
                raise RuntimeError("cannot read the thin pool of {}/{}".format(d["vg"], d["lv"]))
            reason = None
            if not prev:
                reason = "first backup with change tracking"
            elif prev.get("vg") != d["vg"] or prev.get("lv") != d["lv"] or prev.get("pool") != pool:
                reason = "the disk moved"
            elif prev.get("size") != size or prev.get("segment_size") != seg or len(prev.get("seg_ts") or []) != count:
                reason = "the disk size changed"
            elif not prev.get("base_thin_id") or ThinPool.thin_id(d["vg"], d["base"]) != prev["base_thin_id"]:
                reason = "no reference snapshot"
            elif ts - int(prev.get("last_full") or 0) > full_after:
                reason = "periodic full read (every {} days)".format(self.FULL_READ_DAYS)

            if reason is None:
                try:
                    ranges = ThinPool.changed_ranges(d["vg"], pool, prev["base_thin_id"], new_id)
                except Exception as e:
                    reason = "thin_delta failed: {}".format(e)
            if reason is not None:
                seg_ts = [ts] * count
                last_full = ts
                changed = count
                self.log("Disk {}: full read, {} ({} segments of {} MiB)".format(
                    d["key"], reason, count, seg // 1048576))
            else:
                seg_ts = list(prev["seg_ts"])
                marked = set()
                for start, length in ranges:
                    first = start // seg
                    last = min(count - 1, (start + length - 1) // seg)
                    marked.update(range(first, last + 1))
                for i in marked:
                    seg_ts[i] = ts
                changed = len(marked)
                last_full = int(prev["last_full"])
                self.log("Disk {}: {} of {} segments changed since the last backup ({:.0f} MiB to read)".format(
                    d["key"], changed, count, changed * seg / 1048576.0))
            d["seg_ts"] = seg_ts
            new_disks[d["key"]] = {
                "vg": d["vg"], "lv": d["lv"], "pool": pool, "size": size, "segment_size": seg,
                "seg_ts": seg_ts, "last_full": last_full, "base_thin_id": new_id,
            }
        self.new_state = {"format": 1, "disks": new_disks, "last_ts": ts, "old_disks": state["disks"]}
        return ts

    def _mount(self, ts):
        spec_path = os.path.join(self.root, "segfs.json")
        with open(spec_path, "w") as f:
            json.dump({
                "segment_size": self.SEGMENT_SIZE, "ts": ts,
                "disks": [{"key": d["key"], "device": d["device"], "size": d["device_size"], "seg_ts": d["seg_ts"]}
                          for d in self.disks],
            }, f)
        os.makedirs(self.mountpoint, mode=0o700)
        errlog = open(os.path.join(self.root, "segfs.log"), "wb")
        self.fuse_proc = subprocess.Popen(
            [sys.executable, os.path.abspath(__file__), "segfs", spec_path, self.mountpoint],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=errlog, start_new_session=True)
        errlog.close()
        deadline = time.time() + 20
        while not os.path.ismount(self.mountpoint):
            if self.fuse_proc.poll() is not None or time.time() > deadline:
                try:
                    with open(os.path.join(self.root, "segfs.log"), "rb") as f:
                        err = f.read()[-500:].decode("utf-8", errors="replace").strip()
                except OSError:
                    err = ""
                raise RuntimeError("the segment filesystem did not mount: {}".format(err or "timeout"))
            time.sleep(0.1)
        for d in self.disks:
            first = os.path.join(self.mountpoint, d["key"], "000000.seg")
            if os.stat(first).st_size != min(self.SEGMENT_SIZE, d["device_size"]):
                raise RuntimeError("the segment filesystem shows a wrong size for {}".format(first))

    def _unmount(self):
        if os.path.ismount(self.mountpoint):
            if _run(["fusermount", "-u", self.mountpoint], timeout=60, check=False).returncode != 0:
                _run(["fusermount", "-u", "-z", self.mountpoint], timeout=60, check=False)
        if self.fuse_proc is not None:
            _stop_group(self.fuse_proc)
            self.fuse_proc = None

    # -- prepare / cleanup ---------------------------------------------------

    def prepare(self, command):
        if not hasattr(os, "geteuid") or os.geteuid() != 0:
            raise RuntimeError("VM backups need the agent to run as root")
        conf_path = os.path.join(self.c.QEMU_CONF_DIR, self.vmid + ".conf")
        if not os.path.exists(conf_path):
            raise RuntimeError("VM {} does not exist on this node".format(self.vmid))
        conf = self.c.parse_conf(conf_path)
        storages = self.c.storage_config()
        types = {sid: s.get("type") for sid, s in storages.items()}

        disks = []
        for key in sorted(conf):
            if not self.c.DISK_KEY.match(key):
                continue
            d = self.c.disk_entry(key, conf[key], types)
            if d["kind"] in ("cdrom", "cloudinit", "unused"):
                continue
            if key in self.exclude:
                self.log("Disk {} excluded by the plan".format(key))
                continue
            if not d["supported"]:
                self.log("WARNING: disk {} ({}) not backed up: {}".format(key, d["path"], d["unsupported_reason"]))
                continue
            vg = storages.get(d["datastore"], {}).get("vgname")
            volname = d["path"].split(":", 1)[1] if ":" in d["path"] else ""
            if not vg or not re.match(r"^[A-Za-z0-9+_.-]+$", volname):
                self.log("WARNING: disk {} ({}) not backed up: cannot locate its logical volume".format(key, d["path"]))
                continue
            d.update(vg=vg, lv=volname, new=self.NEW_SNAP.format(volname), base=self.BASE_SNAP.format(volname))
            disks.append(d)
        if not disks:
            raise RuntimeError("VM {} has no disk that can be backed up".format(self.vmid))
        self.disks = disks

        self._lock()
        # Leftovers of an interrupted run would make lvcreate fail
        self._unmount()
        for d in disks:
            self._remove_snapshot(d["vg"], d["lv"] + self.LEGACY_SNAP_SUFFIX)
            self._remove_snapshot(d["vg"], d["new"])
        shutil.rmtree(self.root, ignore_errors=True)

        no_cbt = self.cbt_unavailable()
        if no_cbt:
            self.log("Change tracking unavailable ({}): the disks are read in full".format(no_cbt))

        status = self._status()
        freeze = status == "running" and self._guest_agent_answers(conf)
        if status == "running" and not freeze:
            self.log("VM {} runs without a responding QEMU guest agent: the backup is crash-consistent "
                     "(enable the guest agent in the VM options and install qemu-guest-agent in the guest "
                     "for a consistent one)".format(self.vmid))
        try:
            if freeze:
                self.frozen = True  # thaw in any case, even if the freeze call itself fails half-way
                try:
                    _run(["qm", "guest", "cmd", self.vmid, "fsfreeze-freeze"], timeout=60)
                    self.log("Guest filesystems frozen")
                except Exception as e:
                    self.log("WARNING: freezing the guest filesystems failed ({}); the backup is crash-consistent".format(e))
            for d in disks:
                _run(["lvcreate", "-s", "-n", d["new"], "{}/{}".format(d["vg"], d["lv"])], timeout=60)
                self.snapshots.append((d["vg"], d["new"]))
        finally:
            if self.frozen:
                self._thaw()
        # Segment timestamps: the snapshot moment. Every file written below
        # is newer, which matters: borg does not memorize the files carrying
        # the newest ctime of a run.
        snap_ns = time.time_ns()
        self.log("Snapshot taken of {} disk(s): {}".format(len(disks), ", ".join(d["key"] for d in disks)))

        # Thin snapshots are created with activation skipped
        for d in disks:
            _run(["lvchange", "-ay", "-K", "{}/{}".format(d["vg"], d["new"])], timeout=60)
            d["device"] = "/dev/{}/{}".format(d["vg"], d["new"])
        _run(["udevadm", "settle"], timeout=60, check=False)
        for d in disks:
            if not os.path.exists(d["device"]):
                raise RuntimeError("snapshot device {} did not appear".format(d["device"]))
            d["device_size"] = self._device_size(d["device"])
            if not d["device_size"]:
                raise RuntimeError("cannot read the size of {}".format(d["device"]))

        os.makedirs(self.vmdir, mode=0o700)
        if not no_cbt:
            try:
                ts = self._plan_segments(snap_ns)
                self._mount(ts)
                self.cbt = True
            except Exception as e:
                self.log("WARNING: change tracking failed ({}); the disks are read in full".format(e))
                self._unmount()
                shutil.rmtree(self.mountpoint, ignore_errors=True)
                self.new_state = None

        manifest_disks = []
        for d in disks:
            entry = {
                "key": d["key"], "kind": d["kind"],
                "volume": d["path"], "storage": d["datastore"], "storage_type": d["datastore_type"],
                "size_bytes": d["device_size"],
            }
            if self.cbt:
                entry.update(layout="segments", dir="disks/" + d["key"], segment_size=self.SEGMENT_SIZE,
                             segments=len(d["seg_ts"]))
            else:
                entry.update(layout="file", file=d["key"] + ".raw")
                os.symlink(d["device"], os.path.join(self.vmdir, entry["file"]))
            manifest_disks.append(entry)
        shutil.copyfile(conf_path, os.path.join(self.vmdir, "qemu-server.conf"))
        with open(os.path.join(self.vmdir, "bbs-vm.json"), "w") as f:
            json.dump({
                "format": 2 if self.cbt else 1,
                "type": "proxmox",
                "vmid": int(self.vmid),
                "name": conf.get("name", ""),
                "node": self.c.node_name(),
                "pve_version": self.c.version(),
                "status": status,
                "guest_frozen": freeze,
                "change_tracking": self.cbt,
                "disks": manifest_disks,
                "excluded_disks": sorted(self.exclude),
                "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }, f, indent=2)

        try:
            dd = command.index("--")
        except ValueError:
            raise RuntimeError("unexpected borg command (no '--')")
        options = command[2:dd]
        if not any(o.startswith("--chunker-params") for o in options):
            # Fixed 4 MiB chunks: aligned on disk blocks and far cheaper to
            # compute than the content-defined default
            options = ["--chunker-params=" + self.CHUNKER_PARAMS] + options
        if not self.cbt:
            options = ["--read-special"] + options
        return command[:2] + options + ["--", "vm-" + self.vmid], self.root

    def borg_env(self):
        # A files cache of its own per VM: smaller to load, and the segments
        # are seen at every run of this VM, so they never age out of it
        return {"BORG_FILES_CACHE_SUFFIX": "vm-" + self.vmid}

    def _commit(self):
        """After a successful backup: record the segment timestamps and keep
        this run's snapshots as the references of the next run."""
        state = self.new_state
        old = state.pop("old_disks", {})
        keep = True
        for vg, pool in set((v["vg"], v["pool"]) for v in state["disks"].values()):
            used = ThinPool.data_percent(vg, pool)
            if used is not None and used > self.POOL_LIMIT_PERCENT:
                keep = False
                self.log("WARNING: thin pool {}/{} is {:.0f}% full: the reference snapshots are not kept, "
                         "the next backup reads the disks in full".format(vg, pool, used))
        if not keep:
            for v in state["disks"].values():
                v["base_thin_id"] = None
        # The state first: a crash before the renames below leaves a
        # reference whose thin id does not match, hence a full read
        self._save_state(state)
        for key, v in old.items():
            if key not in state["disks"]:
                self._remove_snapshot(v["vg"], self.BASE_SNAP.format(v["lv"]))
        for d in self.disks:
            self._remove_snapshot(d["vg"], d["base"])
            if keep:
                _run(["lvchange", "-an", "{}/{}".format(d["vg"], d["new"])], timeout=60, check=False)
                _run(["lvrename", d["vg"], d["new"], d["base"]], timeout=60)
                self.snapshots.remove((d["vg"], d["new"]))

    def cleanup(self, succeeded=False):
        try:
            self._unmount()
            if succeeded and self.cbt and self.new_state is not None:
                try:
                    self._commit()
                except Exception as e:
                    self.log("WARNING: could not keep the change tracking state ({}); "
                             "the next backup reads the disks in full".format(e))
                    try:
                        os.remove(self.state_path)
                    except OSError:
                        pass
            for vg, lv in reversed(self.snapshots):
                self._remove_snapshot(vg, lv)
            self.snapshots = []
            shutil.rmtree(self.root, ignore_errors=True)
        finally:
            self._unlock()


def readonly_fuse(mountpoint, nodes, read, fsname):
    """Serve a read-only FUSE filesystem until it is unmounted.

    nodes: path ("/", "/a", "/a/b") -> {"dir": bool, "ino": int, "size": int,
    "ts": ns, "children": [names]} ; read(path, size, offset) -> bytes.
    Talks to libfuse 2 through ctypes, so it needs no Python package; the
    stat layout below is the x86_64 one (callers check the arch)."""
    import ctypes
    import errno
    import stat as stat_mod

    class Timespec(ctypes.Structure):
        _fields_ = [("tv_sec", ctypes.c_long), ("tv_nsec", ctypes.c_long)]

    class Stat(ctypes.Structure):
        _fields_ = [
            ("st_dev", ctypes.c_ulong), ("st_ino", ctypes.c_ulong), ("st_nlink", ctypes.c_ulong),
            ("st_mode", ctypes.c_uint), ("st_uid", ctypes.c_uint), ("st_gid", ctypes.c_uint),
            ("pad0", ctypes.c_int), ("st_rdev", ctypes.c_ulong), ("st_size", ctypes.c_long),
            ("st_blksize", ctypes.c_long), ("st_blocks", ctypes.c_long),
            ("st_atim", Timespec), ("st_mtim", Timespec), ("st_ctim", Timespec),
            ("reserved", ctypes.c_long * 3),
        ]

    c_off = ctypes.c_int64
    GETATTR = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_char_p, ctypes.POINTER(Stat))
    OPEN = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_char_p, ctypes.c_void_p)
    READ = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_char_p, ctypes.c_void_p, ctypes.c_size_t, c_off, ctypes.c_void_p)
    FILLER = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_void_p, ctypes.c_char_p, ctypes.c_void_p, c_off)
    READDIR = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_char_p, ctypes.c_void_p, FILLER, c_off, ctypes.c_void_p)

    # struct fuse_operations (libfuse 2.9), up to readdir; the rest stays
    # NULL, which libfuse accepts when told the shorter size
    names = ["getattr", "readlink", "getdir", "mknod", "mkdir", "unlink", "rmdir", "symlink", "rename",
             "link", "chmod", "chown", "truncate", "utime", "open", "read", "write", "statfs", "flush",
             "release", "fsync", "setxattr", "getxattr", "listxattr", "removexattr", "opendir", "readdir"]
    types = {"getattr": GETATTR, "open": OPEN, "read": READ, "readdir": READDIR}

    class Operations(ctypes.Structure):
        _fields_ = [(n, types.get(n, ctypes.c_void_p)) for n in names]

    def guarded(fn):
        def wrapper(*args):
            try:
                return fn(*args)
            except OSError as e:
                return -(e.errno or errno.EIO)
            except Exception:
                return -errno.EIO
        return wrapper

    def set_time(ts, field):
        field.tv_sec = ts // 10 ** 9
        field.tv_nsec = ts % 10 ** 9

    def node_of(path):
        return nodes.get(path.decode("utf-8", errors="replace"))

    @guarded
    def op_getattr(path, st_p):
        node = node_of(path)
        if node is None:
            return -errno.ENOENT
        ctypes.memset(st_p, 0, ctypes.sizeof(Stat))
        st = st_p.contents
        if node["dir"]:
            st.st_mode, st.st_nlink = stat_mod.S_IFDIR | 0o500, 2
        else:
            st.st_mode, st.st_nlink = stat_mod.S_IFREG | 0o400, 1
            st.st_size = node["size"]
            st.st_blocks = (node["size"] + 511) // 512
        st.st_ino = node["ino"]
        st.st_blksize = 65536
        set_time(node["ts"], st.st_atim)
        set_time(node["ts"], st.st_mtim)
        set_time(node["ts"], st.st_ctim)
        return 0

    @guarded
    def op_open(path, fi):
        node = node_of(path)
        if node is None:
            return -errno.ENOENT
        if node["dir"]:
            return -errno.EISDIR
        if ctypes.cast(fi, ctypes.POINTER(ctypes.c_int))[0] & os.O_ACCMODE != os.O_RDONLY:
            return -errno.EROFS
        return 0

    @guarded
    def op_read(path, buf, size, offset, fi):
        node = node_of(path)
        if node is None or node["dir"]:
            return -errno.ENOENT
        if offset >= node["size"]:
            return 0
        want = min(size, node["size"] - offset)
        data = read(path.decode("utf-8", errors="replace"), want, offset)
        if len(data) != want:
            return -errno.EIO  # the source is shorter than it said
        ctypes.memmove(buf, data, want)
        return want

    @guarded
    def op_readdir(path, buf, filler, offset, fi):
        node = node_of(path)
        if node is None:
            return -errno.ENOENT
        if not node["dir"]:
            return -errno.ENOTDIR
        for name in [".", ".."] + node["children"]:
            filler(buf, name.encode("utf-8"), None, 0)
        return 0

    ops = Operations()
    ops.getattr = GETATTR(op_getattr)
    ops.open = OPEN(op_open)
    ops.read = READ(op_read)
    ops.readdir = READDIR(op_readdir)

    lib = ctypes.CDLL("libfuse.so.2")
    lib.fuse_main_real.argtypes = [ctypes.c_int, ctypes.POINTER(ctypes.c_char_p), ctypes.POINTER(Operations),
                                   ctypes.c_size_t, ctypes.c_void_p]
    lib.fuse_main_real.restype = ctypes.c_int
    args = [b"bbs-" + fsname.encode(), mountpoint.encode(), b"-f", b"-s", b"-o",
            b"ro,use_ino,fsname=bbs-" + fsname.encode() + b",subtype=bbs-" + fsname.encode()
            + b",kernel_cache,entry_timeout=86400,attr_timeout=86400"]
    argv = (ctypes.c_char_p * len(args))(*args)
    return lib.fuse_main_real(len(args), argv, ctypes.byref(ops), ctypes.sizeof(ops), None)


def _pread_full(fd, size, offset):
    parts = []
    while size > 0:
        data = os.pread(fd, size, offset)
        if not data:
            break
        parts.append(data)
        size -= len(data)
        offset += len(data)
    return b"".join(parts)


def segfs_serve(spec_path, mountpoint):
    """Block devices shown as directories of fixed-size segment files with
    the timestamps given in the spec (changed block tracking, see
    ProxmoxVmBackup):  /<disk key>/000000.seg, 000001.seg, ..."""
    with open(spec_path) as f:
        spec = json.load(f)
    seg = int(spec["segment_size"])
    ts = int(spec["ts"])
    nodes = {"/": {"dir": True, "ino": 1, "size": 0, "ts": ts, "children": []}}
    fds = {}
    for i, d in enumerate(spec["disks"]):
        key, size = d["key"], int(d["size"])
        fds[key] = os.open(d["device"], os.O_RDONLY)
        names = []
        for idx, t in enumerate(d["seg_ts"]):
            name = "{:06d}.seg".format(idx)
            names.append(name)
            nodes["/{}/{}".format(key, name)] = {
                "dir": False, "ino": ((i + 1) << 32) | idx, "size": min(seg, size - idx * seg),
                "ts": int(t), "key": key, "start": idx * seg,
            }
        nodes["/" + key] = {"dir": True, "ino": 2 + i, "size": 0, "ts": ts, "children": names}
        nodes["/"]["children"].append(key)

    def read(path, size, offset):
        node = nodes[path]
        return _pread_full(fds[node["key"]], size, node["start"] + offset)

    return readonly_fuse(mountpoint, nodes, read, "segfs")


def imagefs_serve(spec_path, mountpoint):
    """Volumes of a VM backup shown as image files, /<volume id>.img, for
    file-level browsing. A volume is a list of extents of the VM's disks
    (a partition is one, a logical volume one or more); a disk is a list of
    files of the mounted archive (one image, or its segments)."""
    with open(spec_path) as f:
        spec = json.load(f)
    disks = {}
    for key, parts in spec["disks"].items():
        starts, pos = [], 0
        for path, size in parts:
            starts.append((pos, int(size), path))
            pos += int(size)
        disks[key] = starts
    nodes = {"/": {"dir": True, "ino": 1, "size": 0, "ts": int(spec["ts"]), "children": []}}
    for i, (vid, vol) in enumerate(sorted(spec["volumes"].items())):
        name = vid + ".img"
        nodes["/" + name] = {"dir": False, "ino": 2 + i, "size": int(vol["size"]), "ts": int(spec["ts"]),
                             "extents": [[int(a), int(b), k, int(c)] for a, b, k, c in vol["extents"]]}
        nodes["/"]["children"].append(name)

    fd_cache = {}  # path -> fd, a few at a time (a disk can have thousands of segments)

    def fd_of(path):
        fd = fd_cache.pop(path, None)
        if fd is None:
            fd = os.open(path, os.O_RDONLY)
            if len(fd_cache) >= 64:
                old = next(iter(fd_cache))
                os.close(fd_cache.pop(old))
        fd_cache[path] = fd  # most recently used last
        return fd

    def read_disk(key, size, offset):
        out = []
        for start, length, path in disks[key]:
            if size <= 0:
                break
            if offset >= start + length or offset + size <= start:
                continue
            n = min(size, start + length - offset)
            data = _pread_full(fd_of(path), n, offset - start)
            if len(data) != n:
                raise IOError("short read from {}".format(path))
            out.append(data)
            offset += n
            size -= n
        if size > 0:
            raise IOError("read past the end of disk {}".format(key))
        return b"".join(out)

    def read(path, size, offset):
        out = []
        for vstart, length, key, dstart in nodes[path]["extents"]:
            if size <= 0:
                break
            if offset >= vstart + length or offset + size <= vstart:
                continue
            n = min(size, vstart + length - offset)
            out.append(read_disk(key, n, dstart + offset - vstart))
            offset += n
            size -= n
        return b"".join(out)

    return readonly_fuse(mountpoint, nodes, read, "imagefs")


# -- partition tables, LVM, filesystem probing (file-level browsing) ---------

SECTOR = 512

MBR_TYPES = {
    0x01: "FAT12", 0x04: "FAT16", 0x05: "Extended", 0x06: "FAT16", 0x07: "NTFS / exFAT", 0x0B: "FAT32",
    0x0C: "FAT32", 0x0E: "FAT16", 0x0F: "Extended", 0x27: "Windows recovery", 0x82: "Linux swap",
    0x83: "Linux", 0x85: "Linux extended", 0x8E: "Linux LVM", 0xEE: "GPT protective", 0xEF: "EFI system",
    0xFD: "Linux RAID",
}
MBR_EXTENDED = (0x05, 0x0F, 0x85)

GPT_TYPES = {
    "C12A7328-F81F-11D2-BA4B-00A0C93EC93B": "EFI system",
    "21686148-6449-6E6F-744E-656564454649": "BIOS boot",
    "0FC63DAF-8483-4772-8E79-3D69D8477DE4": "Linux filesystem",
    "E6D6D379-F507-44C2-A23C-238F2A3DF928": "Linux LVM",
    "0657FD6D-A4AB-43C4-84E5-0933C84B4F4F": "Linux swap",
    "A19D880F-05FC-4D3B-A006-743F0F84911E": "Linux RAID",
    "4F68BCE3-E8CD-4DB1-96E7-FBCAF984B709": "Linux root (x86-64)",
    "933AC7E1-2EB4-4F13-B844-0E14E2AEF915": "Linux home",
    "BC13C2FF-59E6-4262-A352-B275FD6F7172": "Linux /boot",
    "EBD0A0A2-B9E5-4433-87C0-68B6B72699C7": "Microsoft basic data",
    "E3C9E316-0B5C-4DB8-817D-F92DF00215AE": "Microsoft reserved",
    "DE94BBA4-06D1-4D40-A16A-BFD50179D6AC": "Windows recovery",
}


def _guid(raw):
    import uuid
    return str(uuid.UUID(bytes_le=raw)).upper()


def parse_partition_table(read, disk_size):
    """(scheme, partitions) of a disk; scheme is "gpt", "dos" or None.
    partition: number, start, size, type, extended, logical, name."""
    import struct
    mbr = read(0, SECTOR)
    if len(mbr) < SECTOR or mbr[510:512] != b"\x55\xaa":
        return None, []
    entries = []
    for i in range(4):
        e = mbr[446 + 16 * i: 462 + 16 * i]
        ptype = e[4]
        lba, count = struct.unpack("<II", e[8:16])
        if ptype and count:
            entries.append((i + 1, ptype, lba, count))
    if not entries:
        # A filesystem boot sector (FAT, NTFS) also ends with 55 AA
        return None, []
    if any(t == 0xEE for _, t, _, _ in entries):
        return "gpt", _parse_gpt(read, disk_size)

    parts = []
    for number, ptype, lba, count in entries:
        ext = ptype in MBR_EXTENDED
        parts.append({"number": number, "start": lba * SECTOR, "size": count * SECTOR,
                      "type": MBR_TYPES.get(ptype, "type 0x{:02x}".format(ptype)),
                      "extended": ext, "logical": False, "name": ""})
        if ext:
            # Chain of extended boot records: each holds one logical partition
            # (relative to itself) and the link to the next (relative to the
            # extended partition)
            ext_start = lba
            ebr = lba
            nr = 5
            seen = set()
            while ebr and ebr not in seen and nr < 128:
                seen.add(ebr)
                sec = read(ebr * SECTOR, SECTOR)
                if len(sec) < SECTOR or sec[510:512] != b"\x55\xaa":
                    break
                t1 = sec[446 + 4]
                l1, c1 = struct.unpack("<II", sec[446 + 8:446 + 16])
                if t1 and c1:
                    parts.append({"number": nr, "start": (ebr + l1) * SECTOR, "size": c1 * SECTOR,
                                  "type": MBR_TYPES.get(t1, "type 0x{:02x}".format(t1)),
                                  "extended": False, "logical": True, "name": ""})
                    nr += 1
                l2, c2 = struct.unpack("<II", sec[462 + 8:462 + 16])
                ebr = ext_start + l2 if (sec[462 + 4] and c2) else 0
    return "dos", parts


def _parse_gpt(read, disk_size):
    import struct
    hdr = read(SECTOR, SECTOR)
    if hdr[0:8] != b"EFI PART":
        # Primary header lost: the backup one is in the last sector
        hdr = read(disk_size - SECTOR, SECTOR)
        if hdr[0:8] != b"EFI PART":
            return []
    entries_lba, = struct.unpack("<Q", hdr[72:80])
    count, size = struct.unpack("<II", hdr[80:88])
    count = min(count, 256)
    if size < 128 or size > 4096:
        return []
    table = read(entries_lba * SECTOR, count * size)
    parts = []
    for i in range(count):
        e = table[i * size:(i + 1) * size]
        if len(e) < 128 or e[0:16] == b"\x00" * 16:
            continue
        first, last = struct.unpack("<QQ", e[32:48])
        type_guid = _guid(e[0:16])
        name = e[56:128].decode("utf-16-le", errors="replace").split("\x00")[0]
        parts.append({"number": i + 1, "start": first * SECTOR, "size": (last - first + 1) * SECTOR,
                      "type": GPT_TYPES.get(type_guid, type_guid), "extended": False, "logical": False,
                      "name": name})
    return parts


def probe_content(read, size):
    """{"fs": ..., "label": ...} of a volume, from its magic numbers; fs is
    None when unknown. Browsable kinds are in BROWSABLE_FS."""
    def at(off, n):
        if off + n > size:
            return b""
        return read(off, n)

    def text(raw):
        return raw.split(b"\x00")[0].decode("utf-8", errors="replace").strip()

    head = at(0, 4096)
    for i in range(4):
        sec = head[i * SECTOR:(i + 1) * SECTOR]
        if sec[0:8] == b"LABELONE" and sec[24:32] == b"LVM2 001":
            return {"fs": "LVM2_member", "label": ""}
    if head[0:6] == b"LUKS\xba\xbe":
        return {"fs": "crypto_LUKS", "label": ""}
    if head[0:4] == b"XFSB":
        return {"fs": "xfs", "label": text(head[108:120])}
    if head[3:11] == b"NTFS    ":
        return {"fs": "ntfs", "label": ""}
    if head[3:11] == b"EXFAT   ":
        return {"fs": "exfat", "label": ""}
    if head[510:512] == b"\x55\xaa" and (head[82:87] == b"FAT32" or head[54:59] in (b"FAT12", b"FAT16")):
        label = text(head[71:82]) if head[82:87] == b"FAT32" else text(head[43:54])
        return {"fs": "vfat", "label": "" if label == "NO NAME" else label}
    sb = at(1024, 1024)
    if sb[56:58] == b"\x53\xef":
        import struct
        compat, incompat, ro_compat = struct.unpack("<III", sb[92:104])
        fs = "ext4" if (incompat & 0x2C0 or ro_compat & 0x8) else ("ext3" if compat & 0x4 else "ext2")
        return {"fs": fs, "label": text(sb[120:136])}
    btr = at(65536, 4096)
    if btr[64:72] == b"_BHRfS_M":
        return {"fs": "btrfs", "label": text(btr[0x12b:0x12b + 256])}
    for page in (4096, 8192, 16384, 65536):
        if at(page - 10, 10) in (b"SWAPSPACE2", b"SWAP-SPACE"):
            return {"fs": "swap", "label": ""}
    return {"fs": None, "label": ""}


# Filesystems the browser mounts, and the read-only options that keep the
# kernel from writing anything (no journal replay) or trusting the content
BROWSABLE_FS = {
    "ext2": ("ext2", "ro,noload"), "ext3": ("ext3", "ro,noload"), "ext4": ("ext4", "ro,noload"),
    "xfs": ("xfs", "ro,norecovery,nouuid"),
    "btrfs": ("btrfs", "ro,rescue=nologreplay"),
    "ntfs": ("ntfs3", "ro"),
    "vfat": ("vfat", "ro"),
    "exfat": ("exfat", "ro"),
}


def _lvm_text_parse(text):
    """LVM2 text metadata (the VG description) as nested dicts."""
    tokens = re.findall(r'"(?:\\.|[^"\\])*"|[A-Za-z0-9_.+-]+|[{}\[\]=,]|#[^\n]*', text)
    tokens = [t for t in tokens if not t.startswith("#")]
    pos = [0]

    def value():
        t = tokens[pos[0]]
        pos[0] += 1
        if t == "[":
            items = []
            while tokens[pos[0]] != "]":
                if tokens[pos[0]] == ",":
                    pos[0] += 1
                    continue
                items.append(value())
            pos[0] += 1
            return items
        if t.startswith('"'):
            return t[1:-1].replace('\\"', '"').replace("\\\\", "\\")
        try:
            return int(t)
        except ValueError:
            return t

    def block(end):
        out = {}
        while pos[0] < len(tokens) and tokens[pos[0]] != end:
            name = tokens[pos[0]]
            nxt = tokens[pos[0] + 1] if pos[0] + 1 < len(tokens) else None
            if nxt == "{":
                pos[0] += 2
                out[name] = block("}")
                pos[0] += 1
            elif nxt == "=":
                pos[0] += 2
                out[name] = value()
            else:
                pos[0] += 1
        return out

    return block(None)


def read_lvm_pv(read, size):
    """(pv_uuid, vg_name, vg_metadata) of an LVM2 physical volume, or None."""
    import struct
    head = read(0, 4 * SECTOR)
    for i in range(4):
        sec = head[i * SECTOR:(i + 1) * SECTOR]
        if sec[0:8] == b"LABELONE" and sec[24:32] == b"LVM2 001":
            break
    else:
        return None
    offset_xl, = struct.unpack("<I", sec[20:24])
    pvh = read(i * SECTOR + offset_xl, SECTOR)
    pv_uuid = pvh[0:32].decode("ascii", errors="replace")
    pos = 40
    areas = [[], []]  # data areas, then metadata areas, each list ends with a zero entry
    for which in (0, 1):
        while pos + 16 <= len(pvh):
            off, sz = struct.unpack("<QQ", pvh[pos:pos + 16])
            pos += 16
            if off == 0 and sz == 0:
                break
            areas[which].append((off, sz))
    for mda_off, mda_size in areas[1]:
        mh = read(mda_off, SECTOR)
        if mh[4:20] != b" LVM2 x[5A%r0N*>":
            continue
        rl_off, rl_size = struct.unpack("<QQ", mh[40:56])
        if not rl_size:
            continue
        if rl_off + rl_size <= mda_size:
            raw = read(mda_off + rl_off, rl_size)
        else:  # the text wraps around the circular buffer
            first = mda_size - rl_off
            raw = read(mda_off + rl_off, first) + read(mda_off + SECTOR, rl_size - first)
        meta = _lvm_text_parse(raw.decode("utf-8", errors="replace").rstrip("\x00"))
        for name, vg in meta.items():
            if isinstance(vg, dict) and "physical_volumes" in vg:
                return pv_uuid, name, vg
    return None


class VmBrowseSession(object):
    """One VM archive opened for file-level browsing on this node:

      WORK/borg      borg mount of the archive (borg mount -f, our child)
      WORK/img       imagefs: one image file per volume (partition, LV...)
      WORK/vol/<id>  each browsable filesystem, mounted read-only from a
                     read-only loop device on its image

    Partition tables and LVM metadata are read here, in Python: the guest's
    LVM physical volumes are never exposed as block devices, so the host's
    LVM never sees (or auto-activates) the guest's volume groups."""

    def __init__(self, root, archive_key, ref):
        self.root = root
        self.key = archive_key
        self.ref = str(ref)
        self.work = os.path.join(root, "a" + re.sub(r"[^0-9A-Za-z_-]", "_", str(archive_key)))
        self.borg_mnt = os.path.join(self.work, "borg")
        self.img_mnt = os.path.join(self.work, "img")
        self.vol_root = os.path.join(self.work, "vol")
        self.borg_proc = None
        self.img_proc = None
        self.loops = []
        self.mounts = {}  # volume id -> mount path
        self.structure = None
        self.last_used = time.time()
        self.busy = 0

    # -- open -----------------------------------------------------------------

    def open(self, borg_mount_cmd, env, log=None):
        os.makedirs(self.borg_mnt, mode=0o700)
        errlog = open(os.path.join(self.work, "borg-mount.log"), "wb")
        self.borg_proc = subprocess.Popen(borg_mount_cmd + [self.borg_mnt], env=env, stdin=subprocess.DEVNULL,
                                          stdout=subprocess.DEVNULL, stderr=errlog, start_new_session=True)
        errlog.close()
        self._wait_mount(self.borg_mnt, self.borg_proc, "borg-mount.log", 900)
        base = os.path.join(self.borg_mnt, "vm-" + self.ref)
        with open(os.path.join(base, "bbs-vm.json")) as f:
            manifest = json.load(f)

        # Disks: the image file(s) of each one in the borg mount
        disk_parts = {}
        disks = []
        for d in manifest.get("disks") or []:
            key = d.get("key", "")
            if not re.match(r"^[A-Za-z0-9_-]+$", key):
                continue
            size = int(d.get("size_bytes") or 0)
            if d.get("layout") == "segments":
                seg = int(d["segment_size"])
                folder = os.path.join(base, d["dir"])
                parts = [[os.path.join(folder, "{:06d}.seg".format(i)), min(seg, size - i * seg)]
                         for i in range(int(d["segments"]))]
            else:
                parts = [[os.path.join(base, d["file"]), size]]
            disk_parts[key] = parts
            disks.append({"key": key, "size": size, "kind": d.get("kind", "disk")})

        readers = {k: self._disk_reader(p) for k, p in disk_parts.items()}
        volumes = {}  # id -> {"size", "extents", "title", "fs", "label", ...}
        pvs = {}  # pv uuid -> (vg name, vg meta, disk key, byte offset of the PV)
        out_disks = []
        for d in disks:
            key, size = d["key"], d["size"]
            read = readers[key]
            scheme, parts = parse_partition_table(read, size)
            entry = {"key": key, "size": size, "kind": d["kind"], "table": scheme, "partitions": []}
            if not parts:
                parts = [{"number": 0, "start": 0, "size": size, "type": "whole disk", "extended": False,
                          "logical": False, "name": ""}]
            for p in parts:
                pid = "{}-p{}".format(key, p["number"]) if p["number"] else key
                p = dict(p, id=pid)
                if not p["extended"] and p["size"] > 0:
                    sub = (lambda off, n, s=p["start"], r=read: r(s + off, n))
                    content = probe_content(sub, p["size"])
                    p.update(content)
                    if content["fs"] == "LVM2_member":
                        info = read_lvm_pv(sub, p["size"])
                        if info:
                            pvs[info[0]] = (info[1], info[2], key, p["start"])
                            p["vg"] = info[1]
                    volumes[pid] = {"size": p["size"], "extents": [[0, p["size"], key, p["start"]]],
                                    "fs": content["fs"], "label": content["label"],
                                    "title": "{} partition {}".format(key, p["number"]) if p["number"] else key}
                entry["partitions"].append(p)
            out_disks.append(entry)

        vgs = self._volume_groups(pvs, readers, volumes)
        self.structure = {"vm": {k: manifest.get(k) for k in ("vmid", "name", "created_at", "guest_frozen", "node")},
                          "disks": out_disks, "volume_groups": vgs, "volumes": {}}

        # Serve every volume as an image file, then mount the browsable ones
        spec_path = os.path.join(self.work, "imagefs.json")
        with open(spec_path, "w") as f:
            json.dump({"ts": time.time_ns(), "disks": disk_parts,
                       "volumes": {vid: {"size": v["size"], "extents": v["extents"]} for vid, v in volumes.items()}}, f)
        os.makedirs(self.img_mnt, mode=0o700)
        errlog = open(os.path.join(self.work, "imagefs.log"), "wb")
        self.img_proc = subprocess.Popen(
            [sys.executable, os.path.abspath(__file__), "imagefs", spec_path, self.img_mnt],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=errlog, start_new_session=True)
        errlog.close()
        self._wait_mount(self.img_mnt, self.img_proc, "imagefs.log", 30)

        for vid, v in sorted(volumes.items()):
            info = {"title": v["title"], "size": v["size"], "fs": v["fs"], "label": v["label"],
                    "browsable": False, "error": None}
            if v["fs"] in BROWSABLE_FS:
                try:
                    self._mount_volume(vid, v["fs"])
                    info["browsable"] = True
                except Exception as e:
                    info["error"] = str(e)[:300]
            elif v["fs"] == "LVM2_member":
                info["error"] = "LVM physical volume: browse its logical volumes"
            elif v["fs"] == "swap":
                info["error"] = "swap space, no files"
            elif v["fs"] == "crypto_LUKS":
                info["error"] = "encrypted (LUKS), not supported"
            elif v.get("unsupported"):
                info["error"] = v["unsupported"]
            else:
                info["error"] = "no filesystem recognised"
            self.structure["volumes"][vid] = info
        return self.structure

    def _wait_mount(self, path, proc, logname, timeout):
        deadline = time.time() + timeout
        while not os.path.ismount(path):
            if proc.poll() is not None or time.time() > deadline:
                try:
                    with open(os.path.join(self.work, logname), "rb") as f:
                        err = f.read()[-600:].decode("utf-8", errors="replace").strip()
                except OSError:
                    err = ""
                raise RuntimeError("mount of {} failed: {}".format(os.path.basename(path), err or "timeout"))
            time.sleep(0.2)

    @staticmethod
    def _disk_reader(parts):
        """read(offset, size) over the files of one disk in the borg mount."""
        starts, pos = [], 0
        for path, size in parts:
            starts.append((pos, size, path))
            pos += size
        handles = {}

        def read(offset, size):
            out = []
            for start, length, path in starts:
                if size <= 0:
                    break
                if offset >= start + length or offset + size <= start:
                    continue
                n = min(size, start + length - offset)
                if path not in handles:
                    if len(handles) > 32:
                        os.close(handles.pop(next(iter(handles))))
                    handles[path] = os.open(path, os.O_RDONLY)
                out.append(_pread_full(handles[path], n, offset - start))
                offset += n
                size -= n
            return b"".join(out)
        return read

    def _volume_groups(self, pvs, readers, volumes):
        """Logical volumes of the volume groups whose PVs are all here, as
        volumes (extents on the disks); linear ones only."""
        groups = {}
        for pv_uuid, (vg_name, meta, key, pv_start) in pvs.items():
            groups.setdefault(vg_name, {"meta": meta, "pvs": {}})["pvs"][pv_uuid] = (key, pv_start)
        out = []
        for vg_name, g in sorted(groups.items()):
            meta = g["meta"]
            extent = int(meta.get("extent_size", 0)) * SECTOR
            pv_names = {}
            missing = []
            for pv_name, pv in (meta.get("physical_volumes") or {}).items():
                uid = str(pv.get("id", "")).replace("-", "")
                if uid in g["pvs"]:
                    key, start = g["pvs"][uid]
                    pv_names[pv_name] = (key, start + int(pv.get("pe_start", 0)) * SECTOR)
                else:
                    missing.append(pv_name)
            lvs = []
            for lv_name, lv in sorted((meta.get("logical_volumes") or {}).items()):
                if not isinstance(lv, dict) or "VISIBLE" not in (lv.get("status") or []):
                    continue
                vid = "lvm-{}-{}".format(re.sub(r"[^A-Za-z0-9_.+-]", "_", vg_name), lv_name)
                extents, size, reason = [], 0, None
                for s in range(1, int(lv.get("segment_count", 0)) + 1):
                    seg = lv.get("segment{}".format(s)) or {}
                    count = int(seg.get("extent_count", 0)) * extent
                    stripes = seg.get("stripes") or []
                    if seg.get("type") != "striped" or int(seg.get("stripe_count", 0)) != 1 or len(stripes) != 2:
                        reason = "{} logical volume, not supported".format(seg.get("type", "?"))
                        break
                    if stripes[0] not in pv_names:
                        reason = "a physical volume of {} is missing from the backup".format(vg_name)
                        break
                    key, pe0 = pv_names[stripes[0]]
                    extents.append([int(seg.get("start_extent", 0)) * extent, count, key, pe0 + int(stripes[1]) * extent])
                    size += count
                entry = {"name": lv_name, "id": vid, "size": size}
                if reason is None and extents:
                    def sub(off, n, e=extents):
                        # Probing reads only the first few KiB: first extent
                        for vstart, length, k, dstart in e:
                            if vstart <= off < vstart + length:
                                return readers[k](dstart + off - vstart, min(n, vstart + length - off))
                        return b""
                    content = probe_content(sub, size)
                    entry.update(content)
                    volumes[vid] = {"size": size, "extents": extents, "fs": content["fs"], "label": content["label"],
                                    "title": "{}/{}".format(vg_name, lv_name)}
                else:
                    entry.update(fs=None, label="", unsupported=reason or "empty")
                lvs.append(entry)
            out.append({"name": vg_name, "missing_pvs": missing, "lvs": lvs})
        return out

    def _mount_volume(self, vid, fs):
        fstype, opts = BROWSABLE_FS[fs]
        image = os.path.join(self.img_mnt, vid + ".img")
        loop = _run(["losetup", "--find", "--show", "--read-only", image], timeout=60).stdout.decode().strip()
        self.loops.append(loop)
        _loop_record(loop, vid + ".img", True)
        target = os.path.join(self.vol_root, vid)
        os.makedirs(target, mode=0o700, exist_ok=True)
        _run(["mount", "-t", fstype, "-o", opts + ",nodev,nosuid,noexec", loop, target], timeout=300)
        self.mounts[vid] = target

    # -- browse -------------------------------------------------------------

    def resolve(self, vid, path):
        """Absolute path of `path` inside volume `vid`; refuses to leave it
        (.., or a symlink of the guest pointing elsewhere)."""
        root = self.mounts.get(vid)
        if root is None:
            raise RuntimeError("volume {} is not browsable".format(vid))
        rel = "/".join(p for p in str(path or "").split("/") if p not in ("", "."))
        if any(p == ".." for p in rel.split("/")):
            raise RuntimeError("invalid path")
        full = os.path.join(root, rel) if rel else root
        real = os.path.realpath(full)
        if real != root and not real.startswith(root + "/"):
            raise RuntimeError("{} leads outside the volume".format(path))
        return full, rel

    def list(self, vid, path, limit=5000):
        import stat as stat_mod
        full, rel = self.resolve(vid, path)
        if not os.path.isdir(full) or os.path.islink(full):
            raise RuntimeError("{} is not a directory".format(path or "/"))
        entries = []
        truncated = False
        with os.scandir(full) as it:
            for e in it:
                if len(entries) >= limit:
                    truncated = True
                    break
                try:
                    st = e.stat(follow_symlinks=False)
                except OSError:
                    continue
                if stat_mod.S_ISDIR(st.st_mode):
                    kind = "d"
                elif stat_mod.S_ISREG(st.st_mode):
                    kind = "f"
                elif stat_mod.S_ISLNK(st.st_mode):
                    kind = "l"
                else:
                    kind = "o"
                item = {"name": e.name, "type": kind, "size": st.st_size if kind == "f" else None,
                        "mtime": int(st.st_mtime), "mode": stat_mod.filemode(st.st_mode)}
                if kind == "l":
                    try:
                        item["target"] = os.readlink(e.path)
                    except OSError:
                        pass
                entries.append(item)
        entries.sort(key=lambda x: (x["type"] != "d", x["name"].lower()))
        return {"volume": vid, "path": rel, "entries": entries, "truncated": truncated}

    def download(self, vid, paths, sink):
        """Write a tar.gz of the selected paths of a volume to sink (a file
        object). Symlinks are stored as links, never followed."""
        import tarfile
        members = []
        for p in paths[:1000]:
            full, rel = self.resolve(vid, p)
            if not rel:
                rel = "."
            members.append((full, rel))
        if not members:
            raise RuntimeError("nothing selected")
        count = [0]

        def track(info):
            count[0] += 1
            return info
        with tarfile.open(fileobj=sink, mode="w|gz", format=tarfile.PAX_FORMAT) as tar:
            for full, rel in members:
                tar.add(full, arcname=rel, recursive=True, filter=track)
        return count[0]

    # -- close ----------------------------------------------------------------

    def close(self):
        for vid, target in list(self.mounts.items()):
            if _run(["umount", target], timeout=120, check=False).returncode != 0:
                _run(["umount", "-l", target], timeout=60, check=False)
        self.mounts = {}
        for loop in self.loops:
            _run(["losetup", "-d", loop], timeout=60, check=False)
            _loop_record(loop, None, False)
        self.loops = []
        for mnt, proc in ((self.img_mnt, self.img_proc), (self.borg_mnt, self.borg_proc)):
            _unmount_fuse(mnt)
            _stop_group(proc)
        self.img_proc = self.borg_proc = None
        shutil.rmtree(self.work, ignore_errors=True)


LOOP_RECORD = "/var/lib/bbs-agent/vm-browse.loops"


def _loop_record(loop, image_name, add):
    """Keep the loop devices of browse sessions in a file, so that an agent
    restarted while one was open can detach them: by then their image files
    (imagefs, killed with the agent) no longer have a usable path."""
    try:
        with open(LOOP_RECORD) as f:
            entries = [l.rstrip("\n").split("\t") for l in f if "\t" in l]
    except OSError:
        entries = []
    entries = [e for e in entries if e[0] != loop]
    if add:
        entries.append([loop, image_name])
    with open(LOOP_RECORD + ".tmp", "w") as f:
        f.writelines("{}\t{}\n".format(a, b) for a, b in entries)
    os.replace(LOOP_RECORD + ".tmp", LOOP_RECORD)


def _detach_recorded_loops():
    try:
        with open(LOOP_RECORD) as f:
            entries = [l.rstrip("\n").split("\t") for l in f if "\t" in l]
    except OSError:
        return
    for loop, image_name in entries:
        res = _run(["losetup", "-n", "-O", "BACK-FILE", loop], timeout=30, check=False)
        back = res.stdout.decode("utf-8", errors="replace").strip()
        # Still the device we attached (same image name), not one reused since
        if res.returncode == 0 and os.path.basename(back.split(" (")[0]) == image_name:
            _run(["losetup", "-d", loop], timeout=60, check=False)
    try:
        os.remove(LOOP_RECORD)
    except OSError:
        pass


def _unmount_fuse(mnt):
    """Unmount a FUSE mount, lazily if it stays busy."""
    for attempt in range(5):
        if not os.path.ismount(mnt):
            return
        if _run(["fusermount", "-u", mnt], timeout=60, check=False).returncode == 0:
            return
        time.sleep(1)
    _run(["fusermount", "-u", "-z", mnt], timeout=60, check=False)


def _stop_group(proc, grace=10):
    """Stop a process started with start_new_session=True and everything in
    its group. borg is a PyInstaller binary: the process we start is a
    launcher and the real borg is its child, so killing the pid alone leaves
    a borg mount running, and holding a lock on the repository."""
    if proc is None:
        return
    try:
        proc.wait(timeout=grace)
    except subprocess.TimeoutExpired:
        pass

    def group_alive():
        try:
            os.killpg(proc.pid, 0)
            return True
        except (ProcessLookupError, PermissionError):
            return False
    for sig, wait in ((signal.SIGTERM, 15), (signal.SIGKILL, 5)):
        if not group_alive():
            break
        try:
            os.killpg(proc.pid, sig)
        except ProcessLookupError:
            break
        deadline = time.time() + wait
        while group_alive() and time.time() < deadline:
            if proc.poll() is None:
                try:
                    proc.wait(timeout=0.5)
                except subprocess.TimeoutExpired:
                    pass
            else:
                time.sleep(0.5)
    if proc.poll() is None:
        proc.kill()
        proc.wait()


def _kill_stale_processes(marker):
    """Processes whose command line mentions `marker` (an agent work path):
    borg mount and imagefs left by an agent that stopped with a session open."""
    for pid in os.listdir("/proc"):
        if not pid.isdigit() or int(pid) == os.getpid():
            continue
        try:
            with open("/proc/{}/cmdline".format(pid), "rb") as f:
                cmd = f.read().replace(b"\x00", b" ").decode("utf-8", errors="replace")
        except OSError:
            continue
        if marker in cmd and ("borg" in cmd or "imagefs" in cmd):
            try:
                os.kill(int(pid), signal.SIGTERM)
            except OSError:
                pass


class VmBrowser(object):
    """The browse sessions of this agent, one per archive, closed after
    IDLE_SECONDS without use and before any backup or restore (borg mount
    keeps a lock on the repository that would hold a backup back)."""

    ROOT = "/var/lib/bbs-agent/vm-browse"
    IDLE_SECONDS = 600

    def __init__(self):
        import threading
        self.sessions = {}
        self.lock = threading.RLock()
        self.reaper = None

    @staticmethod
    def unavailable():
        import platform
        if IS_WINDOWS or IS_MACOS:
            return "only on Linux"
        if not hasattr(os, "geteuid") or os.geteuid() != 0:
            return "the agent must run as root"
        if platform.machine() not in ("x86_64", "amd64"):
            return "only implemented for x86_64"
        for tool in ("fusermount", "losetup", "mount"):
            if not shutil.which(tool):
                return "{} is not installed".format(tool)
        if not os.path.exists("/dev/fuse"):
            return "/dev/fuse is missing"
        try:
            import ctypes
            ctypes.CDLL("libfuse.so.2")
        except OSError:
            return "libfuse2 is not installed"
        return None

    @contextlib.contextmanager
    def use(self, archive_key, ref, borg_mount_cmd, env, log=None):
        """The session of an archive (opened if needed), kept from the idle
        reaper while in use."""
        with self.lock:
            s = self.session(archive_key, ref, borg_mount_cmd, env, log)
            s.busy += 1
        try:
            yield s
        finally:
            with self.lock:
                s.busy -= 1
                s.last_used = time.time()

    def cleanup_stale(self):
        """Leftovers of an agent that stopped with sessions open."""
        if not os.path.isdir(self.ROOT):
            _detach_recorded_loops()
            return
        try:
            with open("/proc/mounts") as f:
                mounts = [line.split()[1].replace("\\040", " ") for line in f]
        except OSError:
            mounts = []
        for m in sorted((m for m in mounts if m.startswith(self.ROOT + "/")), key=len, reverse=True):
            if "/vol/" in m:
                _run(["umount", "-l", m], timeout=60, check=False)
        _detach_recorded_loops()
        try:
            out = _run(["losetup", "-l", "-n", "-O", "NAME,BACK-FILE"], timeout=30, check=False).stdout.decode()
            for line in out.splitlines():
                parts = line.split(None, 1)
                if len(parts) == 2 and parts[1].strip().startswith(self.ROOT + "/"):
                    _run(["losetup", "-d", parts[0]], timeout=30, check=False)
        except Exception:
            pass
        for m in sorted((m for m in mounts if m.startswith(self.ROOT + "/")), key=len, reverse=True):
            if "/vol/" not in m:
                _run(["fusermount", "-u", "-z", m], timeout=60, check=False)
        _kill_stale_processes(self.ROOT + "/")
        for name in os.listdir(self.ROOT):
            shutil.rmtree(os.path.join(self.ROOT, name), ignore_errors=True)

    def session(self, archive_key, ref, borg_mount_cmd, env, log=None):
        """The open session of an archive, opened now if needed."""
        with self.lock:
            s = self.sessions.get(archive_key)
            if s is not None:
                s.last_used = time.time()
                return s
            reason = self.unavailable()
            if reason:
                raise RuntimeError("file-level browsing is unavailable on this node: {}".format(reason))
            # One archive mounted at a time keeps the node's resources and the
            # repository locks in check
            self.close_all()
            os.makedirs(self.ROOT, mode=0o700, exist_ok=True)
            s = VmBrowseSession(self.ROOT, archive_key, ref)
            try:
                s.open(borg_mount_cmd, env, log)
            except BaseException:
                s.close()
                raise
            self.sessions[archive_key] = s
            self._start_reaper()
            return s

    def close_all(self):
        with self.lock:
            for key, s in list(self.sessions.items()):
                s.close()
                del self.sessions[key]

    def _start_reaper(self):
        import threading
        if self.reaper is not None and self.reaper.is_alive():
            return

        def loop():
            while True:
                time.sleep(30)
                with self.lock:
                    for key, s in list(self.sessions.items()):
                        if s.busy == 0 and time.time() - s.last_used > self.IDLE_SECONDS:
                            logger.info("Closing idle VM browse session {}".format(key))
                            s.close()
                            del self.sessions[key]
                    if not self.sessions:
                        self.reaper = None
                        return
        self.reaper = threading.Thread(target=loop, name="vm-browse-reaper", daemon=True)
        self.reaper.start()


BROWSER = VmBrowser()


class ProxmoxVmRestore(object):
    """Restore one VM archive (made by ProxmoxVmBackup) as a NEW VM.

    `source` reads files out of the archive (the core agent's
    BorgArchiveSource): read(path) returns a small file, copy_to(path,
    device, progress) streams a disk image onto a block device, list(path)
    lists the files under a path and stream(path) gives their contents
    one after the other, in archive order.

    A disk is either one image file (manifest format 1) or a directory of
    segment files (format 2, changed block tracking), written back at the
    offset their number gives.

      1. read the manifest (bbs-vm.json) and the VM config from the archive;
      2. claim the target VMID by creating its config with "lock: create";
      3. for each disk in the manifest: allocate a volume on the target
         storage (pvesm alloc) and stream the image onto it;
      4. write the config: new volumes, new MAC addresses, network cards
         unplugged unless asked otherwise, new SMBIOS UUID and VM generation
         ID, name, a note saying where it came from;
      5. start it if asked.
    Any failure frees the volumes created so far and removes the config.
    """

    def __init__(self, connector, source, ref, options, log, progress):
        ref = str(ref)
        if not re.match(r"^[0-9]+$", ref):
            raise ValueError("invalid source VMID {!r}".format(ref))
        self.c = connector
        self.source = source
        self.ref = ref
        self.options = options or {}
        self.log = log
        self.progress = progress

    @staticmethod
    def _vmid_taken(vmid):
        import glob
        for pattern in ("/etc/pve/nodes/*/qemu-server/{}.conf", "/etc/pve/nodes/*/lxc/{}.conf"):
            if glob.glob(pattern.format(vmid)):
                return True
        return False

    def _target_vmid(self):
        requested = self.options.get("vmid")
        if requested:
            vmid = int(requested)
            if vmid < 100:
                raise RuntimeError("VMID {} is reserved by Proxmox".format(vmid))
            if self._vmid_taken(vmid):
                raise RuntimeError("VMID {} already exists".format(vmid))
            return vmid
        out = _run(["pvesh", "get", "/cluster/nextid", "--output-format", "json"], timeout=60).stdout
        return int(json.loads(out.decode("utf-8")))

    @staticmethod
    def _mac():
        # Proxmox's own prefix, so the address looks like one it generated
        import random
        return "BC:24:11:{:02X}:{:02X}:{:02X}".format(random.randint(0, 255), random.randint(0, 255), random.randint(0, 255))

    def _rewrite_conf(self, conf_text, new_volumes, vmid):
        import uuid
        out = []
        named = False
        for line in conf_text.splitlines():
            if line.startswith("["):
                break  # snapshots of the original VM are not restored
            if line.startswith("#"):
                out.append(line)  # description
                continue
            if ":" not in line:
                continue
            key, value = [x.strip() for x in line.split(":", 1)]
            if key in ("parent", "lock", "template"):
                continue
            if self.c.DISK_KEY.match(key):
                if key in new_volumes:
                    rest = value.split(",", 1)[1] if "," in value else ""
                    value = new_volumes[key] + ("," + rest if rest else "")
                elif "media=cdrom" in value:
                    value = "none,media=cdrom"  # the ISO may not exist here
                else:
                    if not key.startswith("unused"):
                        self.log("Disk {} was not in the backup; it is left out of the restored VM".format(key))
                    continue
            elif re.match(r"^net[0-9]+$", key):
                value = re.sub(r"((?:^|,)[A-Za-z0-9]+=)[0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5}",
                               lambda m: m.group(1) + self._mac(), value)
                if self.options.get("network_down", True) and "link_down=1" not in value:
                    value += ",link_down=1"
            elif key == "name":
                value = self.options.get("name") or (value + "-restored")[:63]
                named = True
            elif key == "smbios1":
                value = re.sub(r"uuid=[0-9A-Fa-f-]+", "uuid=" + str(uuid.uuid4()), value)
            elif key == "vmgenid":
                value = str(uuid.uuid4())
            out.append("{}: {}".format(key, value))
        if not named and self.options.get("name"):
            out.append("name: {}".format(self.options["name"]))
        note = "#Restored by BBS from VM {} (backup of {}) on {}".format(
            self.ref, self.manifest.get("created_at", "?"), time.strftime("%Y-%m-%d %H:%M"))
        return "\n".join([note] + out) + "\n"

    CHUNK = 4 * 1024 * 1024

    def _copy_segments(self, d, device, report):
        """Write the segment files of a disk back onto a block device. All
        segments come from one borg extract; their order and sizes come
        from the archive listing, so each lands at its own offset whatever
        the order. All-zero chunks are skipped: a new thin volume stays thin."""
        prefix = "vm-{}/{}".format(self.ref, d["dir"])
        seg = int(d["segment_size"])
        size = int(d["size_bytes"])
        count = (size + seg - 1) // seg
        entries = []
        for item in self.source.list(prefix):
            if item.get("type") != "-":
                continue
            m = re.match(r"^" + re.escape(prefix) + r"/(\d{6})\.seg$", item.get("path", ""))
            if not m:
                raise RuntimeError("unexpected file in the backup: {}".format(item.get("path")))
            idx = int(m.group(1))
            if idx >= count or int(item.get("size", -1)) != min(seg, size - idx * seg):
                raise RuntimeError("segment {} of disk {} has a wrong size".format(idx, d["key"]))
            entries.append(idx)
        if sorted(entries) != list(range(count)):
            raise RuntimeError("disk {}: {} segments in the backup, {} expected".format(d["key"], len(entries), count))

        zero = bytes(self.CHUNK)
        total = 0
        last = 0
        with self.source.stream(prefix) as src, open(device, "r+b") as out:
            for idx in entries:
                out.seek(idx * seg)
                left = min(seg, size - idx * seg)
                while left > 0:
                    want = min(self.CHUNK, left)
                    parts = []
                    while want > 0:
                        piece = src.read(want)
                        if not piece:
                            raise RuntimeError("the backup stream of disk {} ended early".format(d["key"]))
                        parts.append(piece)
                        want -= len(piece)
                    buf = b"".join(parts)
                    if buf == zero[:len(buf)]:
                        out.seek(len(buf), os.SEEK_CUR)
                    else:
                        out.write(buf)
                    left -= len(buf)
                    total += len(buf)
                    if time.time() - last >= 3:
                        report(total)
                        last = time.time()
            if src.read(1):
                raise RuntimeError("the backup stream of disk {} is longer than expected".format(d["key"]))
            out.flush()
            os.fsync(out.fileno())
        report(total)
        return total

    def run(self):
        self.manifest = json.loads(self.source.read("vm-{}/bbs-vm.json".format(self.ref)).decode("utf-8"))
        conf_text = self.source.read("vm-{}/qemu-server.conf".format(self.ref)).decode("utf-8", errors="replace")
        disks = self.manifest.get("disks") or []
        if not disks:
            raise RuntimeError("the backup holds no disk")

        storage = self.options.get("storage") or ""
        st = self.c.storage_config().get(storage)
        if not st or st.get("type") not in self.c.RESTORE_STORAGE:
            raise RuntimeError("storage '{}' does not exist here or cannot hold restored disks (supported: {})".format(
                storage, ", ".join(self.c.RESTORE_STORAGE)))

        vmid = self._target_vmid()
        conf_path = os.path.join(self.c.QEMU_CONF_DIR, "{}.conf".format(vmid))
        # Claim the VMID first; O_EXCL fails if someone took it meanwhile
        fd = os.open(conf_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
        os.write(fd, b"lock: create\n")
        os.close(fd)
        self.log("Restoring VM {} as VMID {} on {}".format(self.ref, vmid, storage))

        created = []
        try:
            total = sum(int(d.get("size_bytes") or 0) for d in disks)
            done = 0
            new_volumes = {}
            for i, d in enumerate(disks):
                size = int(d.get("size_bytes") or 0)
                segmented = d.get("layout") == "segments"
                if size <= 0 or not re.match(r"^[A-Za-z0-9._/-]+$", d.get("dir" if segmented else "file") or "") \
                        or ".." in (d.get("dir") or ""):
                    raise RuntimeError("bad disk entry in the manifest: {}".format(d))
                name = "vm-{}-disk-{}".format(vmid, i)
                _run(["pvesm", "alloc", storage, str(vmid), name, str((size + 1023) // 1024)], timeout=300)
                volid = "{}:{}".format(storage, name)
                created.append(volid)
                device = _run(["pvesm", "path", volid], timeout=60).stdout.decode("utf-8").strip()
                if st.get("vgname"):
                    _run(["lvchange", "-ay", "{}/{}".format(st["vgname"], name)], timeout=60, check=False)
                if not os.path.exists(device):
                    raise RuntimeError("volume {} has no device at {}".format(volid, device))
                self.log("Restoring disk {} ({:.1f} GiB) to {}".format(d["key"], size / 1024.0 ** 3, volid))
                report = lambda n, base=done, key=d["key"]: self.progress(base + n, total, "Restoring disk {}".format(key))
                if segmented:
                    written = self._copy_segments(d, device, report)
                else:
                    written = self.source.copy_to("vm-{}/{}".format(self.ref, d["file"]), device, report)
                if written != size:
                    raise RuntimeError("disk {}: {} bytes restored, {} expected".format(d["key"], written, size))
                done += size
                new_volumes[d["key"]] = volid

            with open(conf_path, "w") as f:
                f.write(self._rewrite_conf(conf_text, new_volumes, vmid))
        except BaseException:
            for volid in reversed(created):
                _run(["pvesm", "free", volid], timeout=300, check=False)
            try:
                os.remove(conf_path)
            except OSError:
                pass
            raise

        message = "VM {} restored as VMID {} on {} ({} disk(s){})".format(
            self.ref, vmid, storage, len(new_volumes),
            ", network unplugged" if self.options.get("network_down", True) else "")
        if self.options.get("start"):
            _run(["qm", "start", str(vmid)], timeout=300)
            message += ", started"
        self.log(message)
        return message


# slug -> connector class. Add new connectors here.
CONNECTORS = {c.slug: c for c in (ProxmoxConnector,)}


if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "segfs":
        sys.exit(segfs_serve(sys.argv[2], sys.argv[3]))
    if len(sys.argv) == 4 and sys.argv[1] == "imagefs":
        sys.exit(imagefs_serve(sys.argv[2], sys.argv[3]))
    sys.stderr.write("usage: bbs-hypervisor.py segfs|imagefs <spec.json> <mountpoint>\n")
    sys.exit(2)

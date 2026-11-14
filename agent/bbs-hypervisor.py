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

Run as a script, `bbs-hypervisor.py segfs <spec> <mountpoint>` serves the
segment filesystem used by changed block tracking (see segfs_serve).

This file is signed like bbs-agent.py (bin/bbs-sign-agent): an agent
refuses an update of it whose signature does not verify.
"""
import json
import logging
import os
import re
import shutil
import socket
import subprocess
import sys
import time

MODULE_VERSION = "1.3.0"

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
            try:
                self.fuse_proc.wait(timeout=15)
            except subprocess.TimeoutExpired:
                self.fuse_proc.kill()
                self.fuse_proc.wait()
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


def segfs_serve(spec_path, mountpoint):
    """Read-only FUSE filesystem showing block devices as directories of
    fixed-size segment files, with the timestamps given in the spec:

        /<disk key>/000000.seg, 000001.seg, ...

    Run in its own process by ProxmoxVmBackup; returns when unmounted.
    Talks to libfuse 2 through ctypes, so it needs no Python package; the
    stat layout below is the x86_64 one (cbt_unavailable() checks the arch)."""
    import ctypes
    import errno
    import stat as stat_mod

    with open(spec_path) as f:
        spec = json.load(f)
    seg = int(spec["segment_size"])
    dir_ts = int(spec["ts"])
    disks = {}
    for i, d in enumerate(spec["disks"]):
        disks[d["key"]] = {
            "index": i, "fd": os.open(d["device"], os.O_RDONLY),
            "size": int(d["size"]), "ts": [int(t) for t in d["seg_ts"]],
        }
    seg_name = re.compile(r"^(\d{6})\.seg$")

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

    def lookup(path):
        parts = [p for p in path.decode("utf-8", errors="replace").split("/") if p]
        if not parts:
            return ("root", None, None)
        d = disks.get(parts[0])
        if d is None or len(parts) > 2:
            return None
        if len(parts) == 1:
            return ("dir", d, None)
        m = seg_name.match(parts[1])
        if not m or int(m.group(1)) >= len(d["ts"]):
            return None
        return ("seg", d, int(m.group(1)))

    def seg_len(d, idx):
        return min(seg, d["size"] - idx * seg)

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

    @guarded
    def op_getattr(path, st_p):
        node = lookup(path)
        if node is None:
            return -errno.ENOENT
        ctypes.memset(st_p, 0, ctypes.sizeof(Stat))
        st = st_p.contents
        kind, d, idx = node
        ts = dir_ts
        if kind == "root":
            st.st_mode, st.st_nlink, st.st_ino = stat_mod.S_IFDIR | 0o500, 2 + len(disks), 1
        elif kind == "dir":
            st.st_mode, st.st_nlink, st.st_ino = stat_mod.S_IFDIR | 0o500, 2, 2 + d["index"]
        else:
            size = seg_len(d, idx)
            st.st_mode, st.st_nlink = stat_mod.S_IFREG | 0o400, 1
            st.st_ino = ((d["index"] + 1) << 32) | idx
            st.st_size = size
            st.st_blocks = (size + 511) // 512
            ts = d["ts"][idx]
        st.st_blksize = 65536
        set_time(ts, st.st_atim)
        set_time(ts, st.st_mtim)
        set_time(ts, st.st_ctim)
        return 0

    @guarded
    def op_open(path, fi):
        node = lookup(path)
        if node is None:
            return -errno.ENOENT
        if node[0] != "seg":
            return -errno.EISDIR
        flags = ctypes.cast(fi, ctypes.POINTER(ctypes.c_int))[0]
        if flags & os.O_ACCMODE != os.O_RDONLY:
            return -errno.EROFS
        return 0

    @guarded
    def op_read(path, buf, size, offset, fi):
        node = lookup(path)
        if node is None or node[0] != "seg":
            return -errno.ENOENT
        kind, d, idx = node
        length = seg_len(d, idx)
        if offset >= length:
            return 0
        want = min(size, length - offset)
        pos = idx * seg + offset
        got = 0
        while got < want:
            data = os.pread(d["fd"], want - got, pos + got)
            if not data:
                return -errno.EIO  # the device is shorter than it said
            ctypes.memmove(buf + got, data, len(data))
            got += len(data)
        return got

    @guarded
    def op_readdir(path, buf, filler, offset, fi):
        node = lookup(path)
        if node is None:
            return -errno.ENOENT
        if node[0] == "seg":
            return -errno.ENOTDIR
        for name in (".", ".."):
            filler(buf, name.encode(), None, 0)
        if node[0] == "root":
            for key in sorted(disks):
                filler(buf, key.encode("utf-8"), None, 0)
        else:
            for i in range(len(node[1]["ts"])):
                filler(buf, "{:06d}.seg".format(i).encode(), None, 0)
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
    args = [b"bbs-segfs", mountpoint.encode(), b"-f", b"-s", b"-o",
            b"ro,use_ino,fsname=bbs-segfs,subtype=bbs-segfs,kernel_cache,entry_timeout=86400,attr_timeout=86400"]
    argv = (ctypes.c_char_p * len(args))(*args)
    return lib.fuse_main_real(len(args), argv, ctypes.byref(ops), ctypes.sizeof(ops), None)


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
    sys.stderr.write("usage: bbs-hypervisor.py segfs <spec.json> <mountpoint>\n")
    sys.exit(2)

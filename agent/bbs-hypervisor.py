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

VM backup and restore will be connector methods too, so adding ESXi or
vCenter is a new class registered in CONNECTORS.

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

MODULE_VERSION = "1.1.0"

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


class ProxmoxVmBackup(object):
    """Back up one VM of this node: the core agent calls prepare(command)
    before running borg and cleanup() after, whatever happened.

    prepare():
      1. reads the VM config and picks the disks to back up (supported, not
         excluded by the plan);
      2. freezes the guest filesystems when the QEMU guest agent answers;
      3. takes a thin snapshot of each disk, then thaws at once (the guest is
         frozen for the time of the lvcreate calls only);
      4. builds <WORK_ROOT>/vm-<vmid>/vm-<vmid>/ holding a symlink per
         snapshot (<disk key>.raw), the VM config (qemu-server.conf) and a
         manifest (bbs-vm.json) describing the disks for a restore;
      5. returns the borg command with --read-special (borg reads the block
         devices behind the symlinks as files) and that directory as path,
         run from WORK_ROOT/vm-<vmid>, so the archive holds vm-<vmid>/...
    """

    WORK_ROOT = "/var/lib/bbs-agent/vm-backup"
    SNAP_SUFFIX = "-bbs-snap"

    def __init__(self, connector, vm, log):
        ref = str(vm.get("ref", ""))
        if not re.match(r"^[0-9]+$", ref):
            raise ValueError("invalid VMID {!r}".format(ref))
        self.c = connector
        self.vmid = ref
        self.exclude = set(d for d in (vm.get("exclude_disks") or []) if isinstance(d, str))
        self.log = log
        self.root = os.path.join(self.WORK_ROOT, "vm-" + ref)
        self.snapshots = []  # (vg, lv) created by this run
        self.frozen = False

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
            d.update(vg=vg, lv=volname, snap=volname + self.SNAP_SUFFIX)
            disks.append(d)
        if not disks:
            raise RuntimeError("VM {} has no disk that can be backed up".format(self.vmid))

        # Leftovers of an interrupted run would make lvcreate fail
        for d in disks:
            self._remove_snapshot(d["vg"], d["snap"])
        shutil.rmtree(self.root, ignore_errors=True)

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
                _run(["lvcreate", "-s", "-n", d["snap"], "{}/{}".format(d["vg"], d["lv"])], timeout=60)
                self.snapshots.append((d["vg"], d["snap"]))
        finally:
            if self.frozen:
                self._thaw()
        self.log("Snapshot taken of {} disk(s): {}".format(len(disks), ", ".join(d["key"] for d in disks)))

        # Thin snapshots are created with activation skipped
        for d in disks:
            _run(["lvchange", "-ay", "-K", "{}/{}".format(d["vg"], d["snap"])], timeout=60)
            d["device"] = "/dev/{}/{}".format(d["vg"], d["snap"])
        _run(["udevadm", "settle"], timeout=60, check=False)

        vmdir = os.path.join(self.root, "vm-" + self.vmid)
        os.makedirs(vmdir, mode=0o700)
        manifest_disks = []
        for d in disks:
            if not os.path.exists(d["device"]):
                raise RuntimeError("snapshot device {} did not appear".format(d["device"]))
            fname = d["key"] + ".raw"
            os.symlink(d["device"], os.path.join(vmdir, fname))
            manifest_disks.append({
                "key": d["key"], "file": fname, "kind": d["kind"],
                "volume": d["path"], "storage": d["datastore"], "storage_type": d["datastore_type"],
                "size_bytes": self._device_size(d["device"]) or d["size_bytes"],
            })
        shutil.copyfile(conf_path, os.path.join(vmdir, "qemu-server.conf"))
        with open(os.path.join(vmdir, "bbs-vm.json"), "w") as f:
            json.dump({
                "format": 1,
                "type": "proxmox",
                "vmid": int(self.vmid),
                "name": conf.get("name", ""),
                "node": self.c.node_name(),
                "pve_version": self.c.version(),
                "status": status,
                "guest_frozen": freeze,
                "disks": manifest_disks,
                "excluded_disks": sorted(self.exclude),
                "created_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            }, f, indent=2)

        try:
            dd = command.index("--")
        except ValueError:
            raise RuntimeError("unexpected borg command (no '--')")
        new_command = command[:2] + ["--read-special"] + command[2:dd] + ["--", "vm-" + self.vmid]
        return new_command, self.root

    def cleanup(self):
        for vg, lv in reversed(self.snapshots):
            self._remove_snapshot(vg, lv)
        self.snapshots = []
        shutil.rmtree(self.root, ignore_errors=True)


# slug -> connector class. Add new connectors here.
CONNECTORS = {c.slug: c for c in (ProxmoxConnector,)}

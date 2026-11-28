#!/usr/bin/env python3
"""
BBS hypervisor module.

Installed only on hypervisor agents (install.sh --hypervisor), next to
bbs-agent.py, which loads it when its config says `role = hypervisor`. A
client agent never has this file, so it carries none of the hypervisor
capabilities (snapshotting, reading and writing VM disks, creating VMs).
See docs/hypervisors.md#d1-a-dedicated-hypervisor-agent-not-a-plugin

Where this file sits in the flow:

    BBS server  --(task poll, HTTPS)-->  bbs-agent.py (core, role = hypervisor)
                                            |  loads this module, calls:
                                            |   - connector.inventory()       inventory thread
                                            |   - connector.backup_session()  'backup' task with hypervisor_vm
                                            |   - connector.restore_vm()      'restore_vm' task
                                            |   - BROWSER.session()           'vm_browse' task
                                            v
                                     Proxmox VE node: qm, pvesm, pvesh, lvm,
                                     thin_delta, /etc/pve, QEMU (helper VM)

Everything here runs as ROOT on the hypervisor. The commands that change
state on the node (thin snapshots, new volumes, new VM configs, loop
devices, FUSE mounts, the helper VM) are marked "SAFETY" where they happen.
Only standard library modules are imported (libfuse is reached through
ctypes), like the core agent.

Sections of this file, in order:
  1. Connectors (HypervisorConnector, ProxmoxConnector), inventory
  2. VM backup: ThinPool, ProxmoxVmBackup (snapshots, changed block tracking)
  3. Read-only FUSE filesystems: segfs (CBT segments), imagefs (browsing)
  4. Disk structure parsing: partition tables, LVM2 metadata
  5. File-level browsing: helper VM (HelperImage, BrowseVM, vmhelper_main),
     VmBrowseSession, VmBrowser
  6. Full VM restore: ProxmoxVmRestore
  7. CONNECTORS registry and the script entry points

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

# The module's own version, independent of AGENT_VERSION: bumped (semver)
# whenever this file changes. Logged by the core agent when it loads the
# module. See docs/hypervisors.md#versioning
MODULE_VERSION = "1.5.2"

IS_WINDOWS = sys.platform == "win32"
IS_MACOS = sys.platform == "darwin"

# Same logger as the core agent, so messages land in its log file.
logger = logging.getLogger("bbs-agent")

# How a connector reaches its hypervisor. Same values as the constants of
# src/Services/Hypervisors/HypervisorConnector.php.
DEPLOY_AGENT_ON_HOST = "agent_on_host"   # the agent runs on the hypervisor itself (Proxmox node)
DEPLOY_PROXY_AGENT = "proxy_agent"       # the agent runs elsewhere and talks to an API (ESXi, vCenter; not implemented)


# ===========================================================================
# 1. Connectors
# ===========================================================================

class HypervisorConnector(object):
    """Base class of an agent-side connector: one per kind of hypervisor.

    The server-side counterpart is src/Services/Hypervisors/HypervisorConnector.php;
    both share the slug (e.g. "proxmox"). A connector must at least report
    its inventory in the common model (see the module docstring); backup,
    restore and browsing are optional methods the core agent looks for.
    See docs/hypervisors.md#d2-modular-connectors-on-both-sides
    """

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
        """Return {"type": slug, "info": {...}, "vms": [...]} in the common model."""
        raise NotImplementedError


class ProxmoxConnector(HypervisorConnector):
    """Proxmox VE, single node; the agent runs on the node.

    VM configs are read straight from /etc/pve (the cluster filesystem is the
    source of truth, and far faster than one `qm config` per VM); only the
    run state comes from pvesh.

    The node's own command-line tools are used (qm, pvesm, pvesh, lvm), not
    the Proxmox REST API: the agent is root on the node already, so no API
    token has to be created, stored or rotated, and LVM snapshots are not
    exposed by the API anyway.
    See docs/hypervisors.md#d9-local-cli-on-the-node-rather-than-the-proxmox-api

    Server-side counterpart: src/Services/Hypervisors/ProxmoxConnector.php
    (its restoreDatastoreTypes() must match RESTORE_STORAGE below)."""

    slug = "proxmox"
    deployment = DEPLOY_AGENT_ON_HOST
    QEMU_CONF_DIR = "/etc/pve/qemu-server"   # /etc/pve/nodes/<this node>/qemu-server
    STORAGE_CFG = "/etc/pve/storage.cfg"
    # Config keys that hold a disk (or a disk-like device: EFI vars, TPM
    # state, a detached "unusedN" volume)
    DISK_KEY = re.compile(r"^(?:(?:scsi|virtio|sata|ide)\d+|efidisk0|tpmstate0|unused\d+)$")
    # Storage types whose volumes can be snapshotted and backed up. LVM-thin
    # first; ZFS and others come later.
    SUPPORTED_STORAGE = ("lvmthin",)

    @classmethod
    def detect(cls):
        """True on a Proxmox VE node (pveversion and the VM config dir exist)."""
        return (not IS_WINDOWS and not IS_MACOS
                and os.path.exists("/usr/bin/pveversion") and os.path.isdir(cls.QEMU_CONF_DIR))

    @staticmethod
    def node_name():
        """This node's name in the Proxmox cluster filesystem (falls back to
        the short hostname)."""
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
        """The pve-manager version ("8.2.2"), or None if pveversion fails."""
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
        """A VM backup in progress: see ProxmoxVmBackup.

        Called by the core agent for a 'backup' task carrying hypervisor_vm
        ({"ref", "exclude_disks", ...}); log(str) writes to the job log."""
        return ProxmoxVmBackup(self, vm, log)

    # Storage types a VM's disks can be restored to. Must match
    # ProxmoxConnector::restoreDatastoreTypes() on the server, which only
    # offers these storages in the Restore form.
    RESTORE_STORAGE = ("lvmthin",)

    def restore_vm(self, source, ref, options, log, progress):
        """Restore a VM archive as a NEW VM: see ProxmoxVmRestore.

        source: the core agent's BorgArchiveSource for the archive;
        ref: the source VMID; options: restore_options from the server
        (vmid, storage, name, network_down, start); log(str) and
        progress(done, total, message) report to the job.
        Returns a one-line summary; raises on failure (after cleaning up)."""
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
        """One disk line of a VM config in the common disk model.

        key: the config key ("scsi0", "efidisk0"...); value: its value
        ("local-lvm:vm-100-disk-0,iothread=1,size=16G"); storage_types: the
        map from storage_types(). The disk is classified (disk, efi, tpm,
        cdrom, cloudinit, passthrough, unused) and marked supported only
        when it is a real disk on a storage type in SUPPORTED_STORAGE; the
        reason is kept otherwise, so the UI can tell the user why a disk is
        not backed up."""
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
        """The node's QEMU VMs and storages in the common model.

        Read-only: parses /etc/pve/qemu-server/*.conf and storage.cfg, and
        runs `pvesh get /nodes/<node>/qemu` once for the run state. Called
        from the core agent's inventory thread every inventory interval
        (1-60 min, set on the server) or when Refresh is pressed."""
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
            run_info = state.get(vmid, {})
            agent_opt = conf.get("agent", "0").split(",")[0]
            memory_mb = self._int(conf.get("memory"))
            vms.append({
                "ref": str(vmid),
                "name": conf.get("name") or run_info.get("name") or "VM {}".format(vmid),
                "host": node,
                "status": run_info.get("status", "unknown"),
                "cpus": run_info.get("cpus") or self._int(conf.get("cores")),
                "memory_bytes": run_info.get("maxmem") or (memory_mb * 1024 * 1024 if memory_mb else None),
                "uptime_seconds": run_info.get("uptime"),
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


# ===========================================================================
# 2. VM backup (Proxmox VE, LVM-thin)
# ===========================================================================

def _run(argv, timeout=120, check=True):
    """Run a command (list form, never through a shell); raise RuntimeError
    with the first 500 characters of its error output when it fails and
    check is True. Returns the CompletedProcess (stdout/stderr as bytes)."""
    res = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout)
    if check and res.returncode != 0:
        err = (res.stderr or res.stdout).decode("utf-8", errors="replace").strip()
        raise RuntimeError("{} failed: {}".format(" ".join(argv[:3]), err[:500] or "exit {}".format(res.returncode)))
    return res


class ThinPool(object):
    """LVM-thin helpers for changed block tracking.

    LVM-thin in two lines: a thin pool is one big LV whose space is handed
    out block by block to the thin volumes (VM disks) and their snapshots;
    a snapshot shares every block with its origin until one side rewrites
    it. The pool's metadata records which block belongs to which volume, so
    comparing two snapshots' mappings (thin_delta) tells which blocks
    changed between them without reading any data.
    See docs/hypervisors.md#d5-changed-block-tracking-with-thin_delta-and-a-segment-filesystem
    """

    # One flock for every thin_delta run on this node: a pool holds a single
    # metadata snapshot at a time (see changed_ranges)
    LOCK_FILE = "/var/lib/bbs-agent/vm-cbt/thin-delta.lock"

    @staticmethod
    def dm_name(vg, lv):
        """Device-mapper name of vg/lv ("pve", "data" -> "pve-data"); LVM
        doubles the dashes inside each name."""
        return vg.replace("-", "--") + "-" + lv.replace("-", "--")

    @staticmethod
    def lv_fields(vg, lv, fields):
        """{field: value} from `lvs -o <fields> vg/lv`, or None if the LV
        does not exist. Read-only."""
        res = _run(["lvs", "--noheadings", "--separator", "|", "-o", ",".join(fields), "{}/{}".format(vg, lv)],
                   timeout=30, check=False)
        if res.returncode != 0:
            return None
        values = res.stdout.decode("utf-8", errors="replace").strip().split("|")
        return dict(zip(fields, [v.strip() for v in values]))

    @classmethod
    def thin_id(cls, vg, lv):
        """The thin device id of vg/lv inside its pool (what thin_delta
        takes), or None. A renamed LV keeps its id; a new one gets a new id,
        which is how a replaced reference snapshot is detected."""
        f = cls.lv_fields(vg, lv, ["thin_id"])
        try:
            return int(f["thin_id"]) if f else None
        except (KeyError, ValueError):
            return None

    @classmethod
    def pool_of(cls, vg, lv):
        """Name of the thin pool holding vg/lv, or None."""
        f = cls.lv_fields(vg, lv, ["pool_lv"])
        return (f or {}).get("pool_lv") or None

    @classmethod
    def data_percent(cls, vg, pool):
        """How full the pool is, in percent: the higher of data and metadata
        usage (either one at 100% stops every VM on the pool), or None."""
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
        here under a lock (a pool holds one metadata snapshot at a time).

        SAFETY: `dmsetup message ... reserve_metadata_snap` only freezes a
        read-only copy of the pool's metadata; no data or mapping changes.
        It is always released in `finally`. If a previous run died with
        one reserved, it is released and taken again.

        Raises RuntimeError on any unexpected thin_delta output: the caller
        then reads the disk in full rather than trust a partial answer."""
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

    SAFETY: this class creates and removes LVM thin snapshots as root. It
    only ever removes LVs whose names it builds itself (NEW_SNAP, BASE_SNAP,
    LEGACY_SNAP_SUFFIX around the VM's own disk LV); it never writes to a
    VM disk. cleanup() removes every snapshot this run created, whatever
    happened, and prepare() first removes leftovers of an interrupted run.
    The guest is frozen only between the fsfreeze-freeze and the thaw in
    the `finally` of prepare().

    Node impact: each disk gets one snapshot during the run and, with CBT,
    keeps one reference snapshot between runs; both use thin-pool space in
    proportion to what the VM writes meanwhile.
    See docs/hypervisors.md#d4-lvm-thin-snapshots-guest-frozen-only-for-the-snapshot
    """

    WORK_ROOT = "/var/lib/bbs-agent/vm-backup"   # vm-<vmid>/ trees borg reads from
    STATE_DIR = "/var/lib/bbs-agent/vm-cbt"      # per-VM CBT state (JSON) and locks
    # Snapshot name of the first versions (before CBT); still removed if found
    LEGACY_SNAP_SUFFIX = "-bbs-snap"
    # snap_<lv>_<name> is the pattern Proxmox uses for its own LVM-thin
    # snapshots: Proxmox does not list them as VM disks and frees them when
    # the disk is deleted, so a VM removed later leaves no orphan behind.
    NEW_SNAP = "snap_{}_bbsnew"     # this run's snapshot
    BASE_SNAP = "snap_{}_bbsbase"   # reference kept for the next run (CBT)
    # Segment file size: the unit of "changed or not". Smaller segments read
    # less after a small change but make more files for borg to track.
    SEGMENT_SIZE = 64 * 1024 * 1024
    # Fixed 4 MiB borg chunks: aligned on disk blocks (an unchanged block
    # always gives the same chunk) and much cheaper than the default
    # content-defined chunker on raw disk images
    CHUNKER_PARAMS = "fixed,4194304"
    # Safety net: a full read at least this often, whatever CBT says
    FULL_READ_DAYS = 7
    # Above this pool usage the reference snapshots are not kept, so CBT
    # never pushes a nearly full pool over the edge (a full thin pool stops
    # every VM on it)
    POOL_LIMIT_PERCENT = 85.0

    def __init__(self, connector, vm, log):
        """connector: the ProxmoxConnector; vm: the task's hypervisor_vm
        block ({"ref": "100", "exclude_disks": ["scsi1"], ...}); log(str):
        writes to the job log. Raises ValueError on a non-numeric VMID (it
        ends up in LV names and paths)."""
        ref = str(vm.get("ref", ""))
        if not re.match(r"^[0-9]+$", ref):
            raise ValueError("invalid VMID {!r}".format(ref))
        self.connector = connector
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
        """"running", "stopped"... from `qm status`."""
        out = _run(["qm", "status", self.vmid], timeout=30).stdout.decode("utf-8", errors="replace")
        m = re.search(r"status:\s*(\S+)", out)
        return m.group(1) if m else "unknown"

    def _guest_agent_answers(self, conf):
        """True when the VM has the QEMU guest agent enabled in its options
        AND the agent inside the guest answers a ping. Only then can the
        guest filesystems be frozen for a consistent snapshot."""
        if conf.get("agent", "0").split(",")[0] not in ("1", "enabled=1"):
            return False
        try:
            return _run(["qm", "guest", "cmd", self.vmid, "ping"], timeout=15, check=False).returncode == 0
        except Exception:
            return False

    def _thaw(self):
        """Unfreeze the guest filesystems, retried 3 times. A guest left
        frozen stops writing to disk, so on failure the job log says how to
        thaw it by hand."""
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
        """True if the logical volume vg/lv exists (read-only lvs call)."""
        return _run(["lvs", "--noheadings", "{}/{}".format(vg, lv)], timeout=30, check=False).returncode == 0

    def _remove_snapshot(self, vg, lv):
        """Remove a snapshot this class created. Returns False (and logs the
        manual command) if it is still busy after 5 tries.

        SAFETY: irreversible `lvremove`. Callers only pass names built from
        NEW_SNAP, BASE_SNAP or LEGACY_SNAP_SUFFIX, never a VM disk."""
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
        """Size of a block device in bytes (blockdev), or None. The snapshot
        device's size, not the config's "size=", is what gets backed up."""
        try:
            return int(_run(["blockdev", "--getsize64", device], timeout=30).stdout.decode().strip())
        except Exception:
            return None

    def _lock(self):
        """One backup of a VM at a time (two runs would fight over the same
        snapshot names and CBT state). Raises RuntimeError if one runs."""
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
        """The VM's CBT state (STATE_DIR/vm-<vmid>.json); an empty state if
        missing or unreadable, which simply means a full read."""
        try:
            with open(self.state_path) as f:
                state = json.load(f)
            if isinstance(state, dict) and state.get("format") == 1 and isinstance(state.get("disks"), dict):
                return state
        except (OSError, ValueError):
            pass
        return {"format": 1, "disks": {}, "last_ts": 0}

    def _save_state(self, state):
        """Write the CBT state atomically (temp file, fsync, rename), so a
        crash leaves either the old state or the new one, never half."""
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
        """Give every segment of every disk its "last changed" timestamp.

        Per disk: if anything is in doubt (first run, disk moved or resized,
        reference snapshot missing or replaced, FULL_READ_DAYS elapsed,
        thin_delta error), every segment gets the new timestamp, i.e. a full
        read. Otherwise only the segments overlapping a range returned by
        thin_delta get it; the others keep their previous timestamp, so
        borg's files cache recognises them and skips them.

        The new timestamp is strictly greater than any timestamp given
        before (last_ts + 1 at least), even if the node's clock went back.
        The state to save after a successful backup is kept in
        self.new_state (see _commit). Returns the new timestamp (ns)."""
        state = self._load_state()
        ts = max(now_ns, int(state.get("last_ts") or 0) + 1)
        full_after = self.FULL_READ_DAYS * 86400 * 10 ** 9
        seg = self.SEGMENT_SIZE
        new_disks = {}
        for disk in self.disks:
            size = disk["device_size"]
            count = (size + seg - 1) // seg
            prev = state["disks"].get(disk["key"])
            pool = ThinPool.pool_of(disk["vg"], disk["lv"])
            new_id = ThinPool.thin_id(disk["vg"], disk["new"])
            if pool is None or new_id is None:
                raise RuntimeError("cannot read the thin pool of {}/{}".format(disk["vg"], disk["lv"]))
            reason = None
            if not prev:
                reason = "first backup with change tracking"
            elif prev.get("vg") != disk["vg"] or prev.get("lv") != disk["lv"] or prev.get("pool") != pool:
                reason = "the disk moved"
            elif prev.get("size") != size or prev.get("segment_size") != seg or len(prev.get("seg_ts") or []) != count:
                reason = "the disk size changed"
            elif not prev.get("base_thin_id") or ThinPool.thin_id(disk["vg"], disk["base"]) != prev["base_thin_id"]:
                reason = "no reference snapshot"
            elif ts - int(prev.get("last_full") or 0) > full_after:
                reason = "periodic full read (every {} days)".format(self.FULL_READ_DAYS)

            if reason is None:
                try:
                    ranges = ThinPool.changed_ranges(disk["vg"], pool, prev["base_thin_id"], new_id)
                except Exception as e:
                    reason = "thin_delta failed: {}".format(e)
            if reason is not None:
                seg_ts = [ts] * count
                last_full = ts
                changed = count
                self.log("Disk {}: full read, {} ({} segments of {} MiB)".format(
                    disk["key"], reason, count, seg // 1048576))
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
                    disk["key"], changed, count, changed * seg / 1048576.0))
            disk["seg_ts"] = seg_ts
            new_disks[disk["key"]] = {
                "vg": disk["vg"], "lv": disk["lv"], "pool": pool, "size": size, "segment_size": seg,
                "seg_ts": seg_ts, "last_full": last_full, "base_thin_id": new_id,
            }
        self.new_state = {"format": 1, "disks": new_disks, "last_ts": ts, "old_disks": state["disks"]}
        return ts

    def _mount(self, ts):
        """Start the segment filesystem (this file run as `segfs`, in its own
        process group) on self.mountpoint and wait up to 20 s for it.

        The spec file tells it each disk's snapshot device, size and segment
        timestamps. Read-only: it only reads the snapshot devices. Checks
        the size of the first segment of each disk before returning; raises
        RuntimeError with the tail of segfs.log otherwise."""
        spec_path = os.path.join(self.root, "segfs.json")
        with open(spec_path, "w") as f:
            json.dump({
                "segment_size": self.SEGMENT_SIZE, "ts": ts,
                "disks": [{"key": disk["key"], "device": disk["device"], "size": disk["device_size"], "seg_ts": disk["seg_ts"]}
                          for disk in self.disks],
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
        for disk in self.disks:
            first = os.path.join(self.mountpoint, disk["key"], "000000.seg")
            if os.stat(first).st_size != min(self.SEGMENT_SIZE, disk["device_size"]):
                raise RuntimeError("the segment filesystem shows a wrong size for {}".format(first))

    def _unmount(self):
        """Unmount the segment filesystem (lazily if busy) and stop its
        process. Safe to call when nothing is mounted."""
        if os.path.ismount(self.mountpoint):
            if _run(["fusermount", "-u", self.mountpoint], timeout=60, check=False).returncode != 0:
                _run(["fusermount", "-u", "-z", self.mountpoint], timeout=60, check=False)
        if self.fuse_proc is not None:
            _stop_group(self.fuse_proc)
            self.fuse_proc = None

    # -- prepare / cleanup ---------------------------------------------------

    def prepare(self, command):
        """Snapshot the VM and lay out what borg will archive.

        command: the borg create command the server built
        (["borg", "create", <options>, "repo::archive", "--"]).
        Returns (command, cwd): the command with this VM's options and path
        (vm-<vmid>) and the directory to run it from. Raises RuntimeError
        when the VM cannot be backed up; the core agent then still calls
        cleanup(), which removes whatever was created.

        Side effects on the node (root): may freeze/thaw the guest
        filesystems, creates one thin snapshot per disk and activates it,
        may start the segment filesystem, writes under WORK_ROOT."""
        if not hasattr(os, "geteuid") or os.geteuid() != 0:
            raise RuntimeError("VM backups need the agent to run as root")
        conf_path = os.path.join(self.connector.QEMU_CONF_DIR, self.vmid + ".conf")
        if not os.path.exists(conf_path):
            raise RuntimeError("VM {} does not exist on this node".format(self.vmid))
        conf = self.connector.parse_conf(conf_path)
        storages = self.connector.storage_config()
        types = {sid: s.get("type") for sid, s in storages.items()}

        disks = []
        for key in sorted(conf):
            if not self.connector.DISK_KEY.match(key):
                continue
            disk = self.connector.disk_entry(key, conf[key], types)
            if disk["kind"] in ("cdrom", "cloudinit", "unused"):
                continue
            if key in self.exclude:
                self.log("Disk {} excluded by the plan".format(key))
                continue
            if not disk["supported"]:
                self.log("WARNING: disk {} ({}) not backed up: {}".format(key, disk["path"], disk["unsupported_reason"]))
                continue
            vg = storages.get(disk["datastore"], {}).get("vgname")
            volname = disk["path"].split(":", 1)[1] if ":" in disk["path"] else ""
            if not vg or not re.match(r"^[A-Za-z0-9+_.-]+$", volname):
                self.log("WARNING: disk {} ({}) not backed up: cannot locate its logical volume".format(key, disk["path"]))
                continue
            disk.update(vg=vg, lv=volname, new=self.NEW_SNAP.format(volname), base=self.BASE_SNAP.format(volname))
            disks.append(disk)
        if not disks:
            raise RuntimeError("VM {} has no disk that can be backed up".format(self.vmid))
        self.disks = disks

        self._lock()
        # Leftovers of an interrupted run would make lvcreate fail
        self._unmount()
        for disk in disks:
            self._remove_snapshot(disk["vg"], disk["lv"] + self.LEGACY_SNAP_SUFFIX)
            self._remove_snapshot(disk["vg"], disk["new"])
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
        # The guest is frozen for the lvcreate calls only: writes inside the
        # VM block meanwhile, so nothing slow may happen between freeze and
        # thaw. SAFETY: the `finally` below always thaws.
        try:
            if freeze:
                self.frozen = True  # thaw in any case, even if the freeze call itself fails half-way
                try:
                    _run(["qm", "guest", "cmd", self.vmid, "fsfreeze-freeze"], timeout=60)
                    self.log("Guest filesystems frozen")
                except Exception as e:
                    self.log("WARNING: freezing the guest filesystems failed ({}); the backup is crash-consistent".format(e))
            for disk in disks:
                _run(["lvcreate", "-s", "-n", disk["new"], "{}/{}".format(disk["vg"], disk["lv"])], timeout=60)
                self.snapshots.append((disk["vg"], disk["new"]))
        finally:
            if self.frozen:
                self._thaw()
        # Segment timestamps: the snapshot moment. Every file written below
        # is newer, which matters: borg does not memorize the files carrying
        # the newest ctime of a run.
        snap_ns = time.time_ns()
        self.log("Snapshot taken of {} disk(s): {}".format(len(disks), ", ".join(disk["key"] for disk in disks)))

        # Thin snapshots are created with activation skipped
        for disk in disks:
            _run(["lvchange", "-ay", "-K", "{}/{}".format(disk["vg"], disk["new"])], timeout=60)
            disk["device"] = "/dev/{}/{}".format(disk["vg"], disk["new"])
        _run(["udevadm", "settle"], timeout=60, check=False)
        for disk in disks:
            if not os.path.exists(disk["device"]):
                raise RuntimeError("snapshot device {} did not appear".format(disk["device"]))
            disk["device_size"] = self._device_size(disk["device"])
            if not disk["device_size"]:
                raise RuntimeError("cannot read the size of {}".format(disk["device"]))

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
        for disk in disks:
            entry = {
                "key": disk["key"], "kind": disk["kind"],
                "volume": disk["path"], "storage": disk["datastore"], "storage_type": disk["datastore_type"],
                "size_bytes": disk["device_size"],
            }
            if self.cbt:
                entry.update(layout="segments", dir="disks/" + disk["key"], segment_size=self.SEGMENT_SIZE,
                             segments=len(disk["seg_ts"]))
            else:
                entry.update(layout="file", file=disk["key"] + ".raw")
                os.symlink(disk["device"], os.path.join(self.vmdir, entry["file"]))
            manifest_disks.append(entry)
        shutil.copyfile(conf_path, os.path.join(self.vmdir, "qemu-server.conf"))
        with open(os.path.join(self.vmdir, "bbs-vm.json"), "w") as f:
            json.dump({
                "format": 2 if self.cbt else 1,
                "type": "proxmox",
                "vmid": int(self.vmid),
                "name": conf.get("name", ""),
                "node": self.connector.node_name(),
                "pve_version": self.connector.version(),
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
        """Extra environment for borg create (merged by the core agent)."""
        # A files cache of its own per VM: smaller to load, and the segments
        # are seen at every run of this VM, so they never age out of it
        return {"BORG_FILES_CACHE_SUFFIX": "vm-" + self.vmid}

    def _commit(self):
        """After a successful backup: record the segment timestamps and keep
        this run's snapshots as the references of the next run.

        SAFETY: removes the previous reference snapshots (BASE_SNAP) and
        renames this run's snapshots to BASE_SNAP. When the pool is above
        POOL_LIMIT_PERCENT no reference is kept at all (next run = full
        read). Called from cleanup() only when borg succeeded."""
        state = self.new_state
        old = state.pop("old_disks", {})
        keep = True
        for vg, pool in set((disk_state["vg"], disk_state["pool"]) for disk_state in state["disks"].values()):
            used = ThinPool.data_percent(vg, pool)
            if used is not None and used > self.POOL_LIMIT_PERCENT:
                keep = False
                self.log("WARNING: thin pool {}/{} is {:.0f}% full: the reference snapshots are not kept, "
                         "the next backup reads the disks in full".format(vg, pool, used))
        if not keep:
            for disk_state in state["disks"].values():
                disk_state["base_thin_id"] = None
        # The state first: a crash before the renames below leaves a
        # reference whose thin id does not match, hence a full read
        self._save_state(state)
        for key, disk_state in old.items():
            if key not in state["disks"]:
                self._remove_snapshot(disk_state["vg"], self.BASE_SNAP.format(disk_state["lv"]))
        for disk in self.disks:
            self._remove_snapshot(disk["vg"], disk["base"])
            if keep:
                _run(["lvchange", "-an", "{}/{}".format(disk["vg"], disk["new"])], timeout=60, check=False)
                _run(["lvrename", disk["vg"], disk["new"], disk["base"]], timeout=60)
                self.snapshots.remove((disk["vg"], disk["new"]))

    def cleanup(self, succeeded=False):
        """Always called by the core agent after borg, also after a failure,
        a cancel or an exception in prepare().

        Unmounts the segment filesystem; if borg succeeded with CBT, keeps
        the new snapshots as references (_commit); then removes every
        snapshot of this run still listed in self.snapshots, deletes the
        work tree and releases the VM lock. If keeping the references
        fails, the CBT state is deleted so the next run reads in full."""
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


# ===========================================================================
# 3. Read-only FUSE filesystems (segfs for CBT, imagefs for browsing)
#
# FUSE (Filesystem in Userspace) lets a normal process answer the kernel's
# file requests for a mount point. Both filesystems below run as a child
# process (this file started as `bbs-hypervisor.py segfs|imagefs ...`), in
# their own process group so they can be stopped as a whole.
# ===========================================================================

def readonly_fuse(mountpoint, nodes, read, fsname):
    """Serve a read-only FUSE filesystem until it is unmounted.

    nodes: path ("/", "/a", "/a/b") -> {"dir": bool, "ino": int, "size": int,
    "ts": ns, "children": [names]} ; read(path, size, offset) -> bytes.
    Talks to libfuse 2 through ctypes, so it needs no Python package; the
    stat layout below is the x86_64 one (callers check the arch).

    Only getattr, open, read and readdir are implemented; every other
    operation is left NULL, so the kernel answers "not supported", and an
    open for writing is refused with EROFS: nothing can be written through
    it. Mounted with `ro`; runs in the foreground, single-threaded (-f -s).

    Rejected: the fusepy / pyfuse3 packages (the agent uses the standard
    library only, like bbs-agent.py)."""
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

    # A Python exception must never cross back into libfuse's C code: every
    # callback returns -errno instead
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
    # kernel_cache and the 24 h timeouts: the content never changes while
    # mounted, so the kernel may cache pages and attributes freely
    return lib.fuse_main_real(len(args), argv, ctypes.byref(ops), ctypes.sizeof(ops), None)


def _pread_full(fd, size, offset):
    """os.pread until `size` bytes are read or the end is reached (pread
    may return less than asked). Returns the bytes read."""
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
    ProxmoxVmBackup):  /<disk key>/000000.seg, 000001.seg, ...

    spec_path: JSON written by ProxmoxVmBackup._mount() (segment_size, ts,
    disks[{key, device, size, seg_ts}]). The snapshot devices are opened
    read-only. Each segment's mtime/ctime is its "last changed" timestamp,
    and its inode number is stable across runs ((disk index + 1) << 32 |
    segment index): borg's files cache matches a file on path, inode, size
    and ctime, so an unchanged segment is skipped without being read.
    Blocks until unmounted; returns libfuse's exit code."""
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
    files of the mounted archive (one image, or its segments).

    spec_path: JSON written by VmBrowseSession.open():
      disks:   {disk key: [[file path in the borg mount, size], ...]}
      volumes: {volume id: {"size", "extents": [[volume offset, length,
               disk key, disk offset], ...]}}
    Everything is read from the read-only borg mount; the images are then
    attached read-only to the helper VM. Serving volumes as plain files
    (instead of loop devices or device-mapper on the host) keeps the
    guest's partitions and LVM volumes invisible to the host kernel.
    Blocks until unmounted; returns libfuse's exit code."""
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
        """Bytes [offset, offset+size) of a disk, across its files."""
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
        """Bytes of a volume image: mapped extent by extent onto its disks."""
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


# ===========================================================================
# 4. Disk structure parsing (file-level browsing)
#
# Partition tables (MBR, GPT) and LVM2 metadata of the VM's disks are parsed
# here, in Python, on the node: these are small, fixed structures, and
# parsing them in a memory-safe language avoids handing the guest's disks
# to the host's kernel or LVM tools. Filesystems themselves are only ever
# mounted inside the helper VM (section 5).
# See docs/hypervisors.md#d7-file-level-browsing-through-an-isolation-vm
# ===========================================================================

SECTOR = 512

# Partition type codes -> labels shown on the browse page (display only;
# whether a volume can be browsed is decided by probe_content)
MBR_TYPES = {
    0x01: "FAT12", 0x04: "FAT16", 0x05: "Extended", 0x06: "FAT16", 0x07: "NTFS / exFAT", 0x0B: "FAT32",
    0x0C: "FAT32", 0x0E: "FAT16", 0x0F: "Extended", 0x27: "Windows recovery", 0x82: "Linux swap",
    0x83: "Linux", 0x85: "Linux extended", 0x8E: "Linux LVM", 0xEE: "GPT protective", 0xEF: "EFI system",
    0xFD: "Linux RAID",
}
MBR_EXTENDED = (0x05, 0x0F, 0x85)  # containers of logical partitions

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
    """A GPT GUID (16 bytes, mixed-endian on disk) as the usual upper-case text."""
    import uuid
    return str(uuid.UUID(bytes_le=raw)).upper()


def parse_partition_table(read, disk_size):
    """(scheme, partitions) of a disk; scheme is "gpt", "dos" or None.
    partition: number, start, size, type, extended, logical, name.

    read(offset, size) -> bytes reads the disk image (from the borg mount).
    The data is untrusted (written by the guest): loops are bounded (128
    logical partitions, 256 GPT entries, EBR chain loop detection) and
    offsets are only used to read the image, never trusted further."""
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
    """GPT partitions (number, start, size, type, name) of a disk whose MBR
    is a protective one; [] if neither GPT header is valid."""
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
    None when unknown. Browsable kinds are in BROWSABLE_FS.

    Only identifies the content (like `blkid`, without running it on the
    host): nothing is mounted here. LVM2_member volumes are then opened as
    LVM physical volumes (read_lvm_pv); LUKS and swap are shown but not
    browsable."""
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


def _lvm_text_parse(text):
    """LVM2 text metadata (the VG description) as nested dicts.

    The format is LVM's own config syntax: `name { ... }` blocks,
    `key = value` with strings, integers and [lists]. Comments are dropped.
    Example: {"vg0": {"physical_volumes": {...}, "logical_volumes": {...}}}"""
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
    """(pv_uuid, vg_name, vg_metadata) of an LVM2 physical volume, or None.

    Reads the PV label (one of the first 4 sectors), its header and the
    metadata area, where LVM keeps the VG description as text in a circular
    buffer. This replaces `pvscan`/`vgchange` on the host: the guest's
    volume group is reconstructed from its metadata, never activated."""
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


# ===========================================================================
# 5. File-level browsing: the isolation (helper) VM and browse sessions
#
# A VM backup holds whatever its guest wrote, including a filesystem crafted
# to exploit a bug of a kernel filesystem driver. So the browser never mounts
# a guest filesystem on the hypervisor: a small KVM virtual machine (the
# node's own kernel, an initramfs built here holding Python and this module,
# no network) gets the volumes as read-only virtio disks, mounts them with
# its own kernel and answers list/tar requests over a virtio-serial channel.
# A malicious filesystem can at worst take over that VM, which sees nothing
# but the volumes it was given and is killed when the session closes.
#
# Pieces, host side -> guest side:
#   VmBrowser          the agent's sessions (one per restore point), idle
#                      timeout, close before backup/restore, crash cleanup
#   VmBrowseSession    borg mount + imagefs + one BrowseVM for an archive
#   HelperImage        builds the helper's initramfs (once per kernel/module)
#   BrowseVM           starts QEMU, talks to the helper over virtio-serial
#   vmhelper_main()    runs INSIDE the helper as /init: mounts, lists, tars
#
# Messages on the channel are frames: 1 byte kind ("J" = JSON, "D" = data),
# 4 bytes big-endian length, payload (at most FRAME_MAX bytes).
# See docs/hypervisors.md#d7-file-level-browsing-through-an-isolation-vm
# ===========================================================================

FRAME_JSON = b"J"
FRAME_DATA = b"D"
FRAME_MAX = 16 * 1024 * 1024  # a larger frame is a protocol error, never allocated

# Filesystem -> (kernel type, mount options), mounted with MS_RDONLY |
# MS_NOSUID | MS_NODEV | MS_NOEXEC; no journal replay, nothing written.
# Replaying a journal would write to the volume; the options below make
# each driver read the filesystem as it is on disk instead. The image is
# also attached read-only, so even a driver bug cannot write to it.
VM_FS = {
    "ext2": ("ext2", ""),  # no journal, so no "noload"
    "ext3": ("ext3", "noload"), "ext4": ("ext4", "noload"),
    "xfs": ("xfs", "norecovery,nouuid"),
    "btrfs": ("btrfs", "rescue=nologreplay"),
    "ntfs": ("ntfs3", "iocharset=utf8"),
    "vfat": ("vfat", "iocharset=utf8,shortname=mixed"),
    "exfat": ("exfat", "iocharset=utf8"),
}
BROWSABLE_FS = VM_FS
# Kernel modules copied into the helper's initramfs (with their
# dependencies) so its kernel can mount these filesystems
HELPER_KERNEL_MODULES = ("xfs", "btrfs", "ntfs3", "exfat", "vfat", "ext4", "nls_utf8", "nls_cp437", "nls_iso8859_1")
HELPER_MAX_DRIVES = 24  # one PCI slot each


def _frame_send(write, kind, payload):
    """Send one frame (kind, 4-byte big-endian length, payload)."""
    import struct
    write(kind + struct.pack(">I", len(payload)) + payload)


def _frame_recv(read_exact):
    """(kind, payload) of the next frame."""
    import struct
    head = read_exact(5)
    kind, size = head[0:1], struct.unpack(">I", head[1:5])[0]
    if kind not in (FRAME_JSON, FRAME_DATA) or size > FRAME_MAX:
        raise RuntimeError("protocol error on the helper channel")
    return kind, read_exact(size)


def _safe_resolve(root, path):
    """(absolute path, relative path) of `path` under `root`, refusing to
    leave it (.., or a symlink pointing elsewhere).

    Runs inside the helper VM, where root is a guest filesystem mount: a
    guest symlink to /etc/passwd resolves to the helper's own /etc, outside
    root, and is refused. `path` comes from the browser (via the server and
    the agent), so it is untrusted too. Raises RuntimeError."""
    rel = "/".join(p for p in str(path or "").split("/") if p not in ("", "."))
    if any(p == ".." for p in rel.split("/")):
        raise RuntimeError("invalid path")
    full = os.path.join(root, rel) if rel else root
    real = os.path.realpath(full)
    if real != root and not real.startswith(root + "/"):
        raise RuntimeError("{} leads outside the volume".format(path))
    return full, rel


def _list_dir(root, path, limit=5000):
    """One directory of a mounted volume, for the browse page (inside the
    helper VM): {"path", "entries": [{name, type d/f/l/o, size, mtime, mode,
    target for links}], "truncated"}. At most `limit` entries; directories
    first. Symlinks are listed, not followed."""
    import stat as stat_mod
    full, rel = _safe_resolve(root, path)
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
    return {"path": rel, "entries": entries, "truncated": truncated}


def _tar_paths(root, paths, sink):
    """tar.gz of the selected paths of a volume into sink (a file object);
    symlinks are stored as links, never followed. Runs inside the helper
    VM; the stream goes back to the host in data frames. At most 1000
    selected paths. Returns the number of archive members."""
    import tarfile
    members = []
    for p in list(paths)[:1000]:
        full, rel = _safe_resolve(root, p)
        members.append((full, rel or "."))
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


class HelperImage(object):
    """The helper VM's boot files: the running kernel, and an initramfs
    built from this node's own Python, this module and the filesystem
    modules of that kernel. Built once per kernel and module version.

    Why the node's own kernel and Python: nothing has to be downloaded or
    shipped with BBS, the kernel modules match the kernel by construction,
    and the image is rebuilt automatically after a kernel or agent update.
    Rejected: a prebuilt appliance image (another artifact to build, sign
    and keep in step with every kernel), libguestfs (not standard library,
    large dependency). Size: about 14 MiB, under DIR."""

    DIR = "/var/lib/bbs-agent/vm-browse-helper"

    @classmethod
    def kernel(cls):
        """Path of the running kernel's image, booted by the helper VM."""
        return "/boot/vmlinuz-" + os.uname().release

    @classmethod
    def ensure(cls):
        """(kernel path, initramfs path), building the initramfs if needed.

        The file name carries a hash of this module's source, the kernel
        release and the Python version, so any change to one of them makes
        a new image; older images are deleted. Built to a temp file and
        renamed, so a crash never leaves a half image in use."""
        import hashlib
        release = os.uname().release
        with open(os.path.abspath(__file__), "rb") as f:
            me = f.read()
        key = hashlib.sha256(me + release.encode() + sys.version.encode()).hexdigest()[:16]
        path = os.path.join(cls.DIR, "initrd-{}-{}.img".format(release, key))
        if os.path.exists(path):
            return cls.kernel(), path
        os.makedirs(cls.DIR, mode=0o700, exist_ok=True)
        for old in os.listdir(cls.DIR):
            if old.startswith("initrd-"):
                try:
                    os.remove(os.path.join(cls.DIR, old))
                except OSError:
                    pass
        tmp = path + ".tmp"
        cls._build(tmp, me)
        os.replace(tmp, path)
        return cls.kernel(), path

    @staticmethod
    def _ldd(path):
        """Shared libraries a binary needs (absolute paths), from ldd."""
        out = _run(["ldd", path], timeout=30, check=False).stdout.decode("utf-8", errors="replace")
        libs = []
        for line in out.splitlines():
            m = re.search(r"(/\S+)\s+\(0x", line)
            if m:
                libs.append(m.group(1))
        return libs

    @classmethod
    def _build(cls, target, module_source):
        """Write a gzip'd cpio "newc" initramfs to target, holding:
        the Python interpreter, its standard library (tests, GUI and
        site-packages left out) and their shared libraries; the kernel
        modules of HELPER_KERNEL_MODULES with their dependencies; this
        module as /bbs/helper.py; and /init, which runs vmhelper_main().
        The cpio format is written by hand (a few lines) rather than with
        the cpio tool, which is not always installed."""
        import gzip
        files = {}  # path in the image -> source path on the host
        python = os.path.realpath(sys.executable)
        files["/usr/bin/python3"] = python
        stdlib = os.path.dirname(os.__file__)
        skip = {"test", "tests", "idlelib", "tkinter", "turtledemo", "ensurepip", "lib2to3", "pydoc_data",
                "__pycache__", "site-packages", "dist-packages", "venv"}
        dynload = []
        for dirpath, dirnames, filenames in os.walk(stdlib):
            dirnames[:] = [d for d in dirnames if d not in skip and not d.startswith("config-")]
            for name in filenames:
                src = os.path.join(dirpath, name)
                files[src] = src
                if name.endswith(".so"):
                    dynload.append(src)
        for binary in [python] + dynload:
            for lib in cls._ldd(binary):
                files[lib] = os.path.realpath(lib)

        modules = []
        for name in HELPER_KERNEL_MODULES:
            out = _run(["modprobe", "--show-depends", name], timeout=30, check=False).stdout.decode()
            for line in out.splitlines():
                parts = line.split()
                if len(parts) >= 2 and parts[0] == "insmod" and parts[1] not in modules:
                    modules.append(parts[1])
                    files[parts[1]] = parts[1]

        init = (b"#!/usr/bin/python3 -SB\n"
                b"import sys\n"
                b"sys.argv = ['bbs-helper', 'vmhelper']\n"
                b"exec(compile(open('/bbs/helper.py').read(), '/bbs/helper.py', 'exec'),"
                b" {'__name__': '__main__', '__file__': '/bbs/helper.py'})\n")
        blobs = {"/init": (init, 0o100755), "/bbs/helper.py": (module_source, 0o100644),
                 "/bbs/modules.list": ("\n".join(modules).encode() + b"\n", 0o100644)}

        entries = []  # (name, mode, data, rdev)
        dirs = set()

        def add_dirs(p):
            parent = os.path.dirname(p)
            while parent not in ("/", "") and parent not in dirs:
                dirs.add(parent)
                parent = os.path.dirname(parent)
        for p in list(files) + list(blobs) + ["/proc/x", "/sys/x", "/dev/x", "/mnt/x", "/tmp/x"]:
            add_dirs(p)
        for d in sorted(dirs, key=lambda x: (x.count("/"), x)):
            entries.append((d, 0o040755, b"", (0, 0)))
        entries.append(("/dev/console", 0o020600, b"", (5, 1)))
        entries.append(("/dev/null", 0o020666, b"", (1, 3)))
        for p, src in sorted(files.items()):
            with open(src, "rb") as f:
                data = f.read()
            mode = 0o100755 if os.access(src, os.X_OK) else 0o100644
            entries.append((p, mode, data, (0, 0)))
        for p, (data, mode) in blobs.items():
            entries.append((p, mode, data, (0, 0)))
        # /lib64 for the dynamic loader on a merged-usr system
        if "/lib64/ld-linux-x86-64.so.2" not in files:
            for p in list(files):
                if p.endswith("/ld-linux-x86-64.so.2"):
                    with open(files[p], "rb") as f:
                        entries.append(("/lib64/ld-linux-x86-64.so.2", 0o100755, f.read(), (0, 0)))
                    break

        with gzip.open(target, "wb", compresslevel=6) as out:
            ino = 1
            for name, mode, data, (rmaj, rmin) in entries + [("TRAILER!!!", 0, b"", (0, 0))]:
                n = name.lstrip("/").encode() + b"\0"
                head = "070701" + "".join("{:08X}".format(v) for v in (
                    ino, mode, 0, 0, 2 if mode & 0o040000 else 1, 0, len(data), 0, 0, rmaj, rmin, len(n), 0))
                out.write(head.encode() + n + b"\0" * ((4 - (110 + len(n)) % 4) % 4))
                out.write(data + b"\0" * ((4 - len(data) % 4) % 4))
                ino += 1


class BrowseVM(object):
    """The helper VM of one browse session (see the comment above).

    Node impact while a session is open: one QEMU process (512 MiB RAM,
    2 vCPUs, KVM when /dev/kvm is usable, software emulation otherwise),
    no network device, no display, a UNIX socket under the session's work
    directory. It is not a Proxmox VM: it has no VMID and does not appear
    in the Proxmox UI."""

    MEMORY_MB = 512

    def __init__(self, work, drives):
        """work: the session's work directory (socket, logs); drives:
        [(volume id, image path)] from imagefs, attached in that order."""
        self.work = work
        self.drives = drives  # [(volume id, image path)]
        self.proc = None
        self.sock = None
        self.seq = 0

    @staticmethod
    def unavailable():
        """Why the helper VM cannot run on this node, or None."""
        if not shutil.which("qemu-system-x86_64"):
            return "qemu-system-x86_64 is not installed"
        if not os.path.exists(HelperImage.kernel()):
            return "the running kernel's image {} is missing".format(HelperImage.kernel())
        for tool in ("ldd", "modprobe"):
            if not shutil.which(tool):
                return "{} is not installed".format(tool)
        return None

    def start(self, timeout=120):
        """Boot the helper VM and wait until it answers "hello" (about 1.5 s
        with KVM). Returns the hello answer (the disk serials it sees).
        Raises RuntimeError with the tail of its logs on failure.

        Isolation, option by option:
          -nodefaults / -no-user-config  no default devices (no NIC, no
                                         disks, no USB) and no host config
          (no -netdev)                   no network at all
          -sandbox on,...                seccomp filter: QEMU may not spawn
                                         processes, raise privileges or
                                         change resource limits
          readonly=on                    every volume image is read-only
          -no-reboot, panic=-1           a crash stops the VM, it never loops
        Drives get serial "bbs<i>" so the helper can match them to volumes."""
        kernel, initrd = HelperImage.ensure()
        sock_path = os.path.join(self.work, "helper.sock")
        kvm = os.access("/dev/kvm", os.R_OK | os.W_OK)
        args = [shutil.which("qemu-system-x86_64"), "-name", "bbs-browse-helper",
                "-machine", "q35,accel=" + ("kvm" if kvm else "tcg"), "-cpu", "host" if kvm else "max",
                "-m", str(self.MEMORY_MB), "-smp", "2",
                "-nodefaults", "-no-user-config", "-nographic", "-no-reboot",
                "-kernel", kernel, "-initrd", initrd,
                "-append", "console=ttyS0 quiet panic=-1 rdinit=/init",
                "-serial", "file:" + os.path.join(self.work, "helper-console.log"),
                "-device", "virtio-serial-pci",
                "-chardev", "socket,id=ch0,path={},server=on,wait=off".format(sock_path),
                "-device", "virtserialport,chardev=ch0,name=bbs",
                "-sandbox", "on,obsolete=deny,elevateprivileges=deny,spawn=deny,resourcecontrol=deny"]
        for i, (vid, image) in enumerate(self.drives):
            # A comma would inject extra options into QEMU's -drive argument
            if "," in image:
                raise RuntimeError("unexpected character in {}".format(image))
            args += ["-drive", "if=none,id=d{0},file={1},format=raw,readonly=on,file.locking=off".format(i, image),
                     "-device", "virtio-blk-pci,drive=d{0},serial=bbs{0}".format(i)]
        errlog = open(os.path.join(self.work, "helper-qemu.log"), "wb")
        self.proc = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=errlog, stderr=errlog,
                                     start_new_session=True)
        errlog.close()

        deadline = time.time() + timeout
        while True:
            if self.proc.poll() is not None:
                raise RuntimeError("the helper VM did not start: {}".format(self._log_tail()))
            try:
                s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                s.connect(sock_path)
                self.sock = s
                break
            except OSError:
                s.close()
                if time.time() > deadline:
                    raise RuntimeError("the helper VM did not start in time")
                time.sleep(0.1)
        # The guest opens its end of the channel once booted: say hello until
        # it answers (requests carry an id, so a late answer is ignored)
        while True:
            try:
                return self.request({"op": "hello"}, timeout=3)
            except socket.timeout:
                if self.proc.poll() is not None or time.time() > deadline:
                    raise RuntimeError("the helper VM did not answer: {}".format(self._log_tail()))

    def _log_tail(self):
        """The last lines of QEMU's and the helper console's logs, for errors."""
        out = []
        for name in ("helper-qemu.log", "helper-console.log"):
            try:
                with open(os.path.join(self.work, name), "rb") as f:
                    out.append(f.read()[-400:].decode("utf-8", errors="replace").strip())
            except OSError:
                pass
        return " / ".join(o for o in out if o) or "no output"

    def _read_exact(self, n):
        """Exactly n bytes from the helper channel; raises if it closes."""
        chunks = []
        while n > 0:
            data = self.sock.recv(min(n, 1048576))
            if not data:
                raise RuntimeError("the helper VM closed the channel: {}".format(self._log_tail()))
            chunks.append(data)
            n -= len(data)
        return b"".join(chunks)

    def request(self, obj, timeout=300, sink=None):
        """Send a request; returns the JSON answer. Data frames that come
        before it (a tar stream) go to sink.write.

        obj: {"op": "hello" | "mount" | "list" | "tar", ...} (see
        vmhelper_main). Raises RuntimeError with the helper's error message
        when it answers ok = false, and socket.timeout after `timeout` s."""
        self.seq += 1
        obj = dict(obj, id=self.seq)
        self.sock.settimeout(timeout)
        _frame_send(self.sock.sendall, FRAME_JSON, json.dumps(obj).encode())
        while True:
            kind, payload = _frame_recv(self._read_exact)
            if kind == FRAME_DATA:
                if sink is None:
                    raise RuntimeError("unexpected data from the helper VM")
                sink.write(payload)
                continue
            answer = json.loads(payload.decode("utf-8"))
            if answer.get("id") != self.seq:
                continue  # answer to an earlier hello
            if not answer.get("ok"):
                raise RuntimeError(answer.get("error") or "the helper VM refused the request")
            return answer

    def stop(self):
        """Kill the helper VM (SIGTERM to its process group, then SIGKILL
        after 5 s). Nothing in it needs a clean shutdown: its disks are
        read-only and it holds no state."""
        if self.sock is not None:
            try:
                self.sock.close()
            except OSError:
                pass
            self.sock = None
        if self.proc is not None:
            if self.proc.poll() is None:
                try:
                    os.killpg(self.proc.pid, signal.SIGTERM)
                except ProcessLookupError:
                    pass
            _stop_group(self.proc, grace=5)
            self.proc = None


def vmhelper_main():
    """PID 1 of the helper VM: mount the volumes it was given, read-only,
    and answer list/tar requests from the host on the virtio-serial port.

    This function never runs on the hypervisor: /init in the initramfs
    built by HelperImage calls it inside the helper VM. Requests:
      hello                          -> {"disks": [serials]}
      mount {volumes: [{vid, serial, fs}]}
                                     -> {"results": {vid: None | error}}
      list  {vid, path}              -> {"listing": see _list_dir}
      tar   {vid, paths}             -> data frames, then {"entries": n}
    Every answer carries the request id and "ok"; an error answers
    ok = false with a message. Runs until the VM is killed."""
    import ctypes
    libc = ctypes.CDLL(None, use_errno=True)

    def mount(src, dst, fstype, flags, data):
        """mount(2) through libc (no mount binary in the initramfs)."""
        os.makedirs(dst, exist_ok=True)
        if libc.mount(src.encode(), dst.encode(), fstype.encode(), ctypes.c_ulong(flags),
                      data.encode() if data else None) != 0:
            err = ctypes.get_errno()
            raise OSError(err, os.strerror(err))

    for src, dst, fstype in (("proc", "/proc", "proc"), ("sysfs", "/sys", "sysfs"), ("devtmpfs", "/dev", "devtmpfs")):
        try:
            mount(src, dst, fstype, 0, "")
        except OSError:
            pass
    try:
        with open("/bbs/modules.list") as f:
            for path in [l.strip() for l in f if l.strip()]:
                fd = os.open(path, os.O_RDONLY)
                libc.syscall(313, fd, b"", 0)  # finit_module; EEXIST when built in is fine
                os.close(fd)
    except OSError as e:
        print("bbs-helper: module loading: {}".format(e), flush=True)

    port = None
    for _ in range(100):
        try:
            for d in os.listdir("/sys/class/virtio-ports"):
                with open("/sys/class/virtio-ports/{}/name".format(d)) as f:
                    if f.read().strip() == "bbs":
                        port = "/dev/" + d
        except OSError:
            pass
        if port and os.path.exists(port):
            break
        time.sleep(0.1)
    if not port:
        print("bbs-helper: no channel to the host", flush=True)
        return 1
    disks = {}
    for b in os.listdir("/sys/block"):
        try:
            with open("/sys/block/{}/serial".format(b)) as f:
                disks[f.read().strip()] = "/dev/" + b
        except OSError:
            pass
    fd = os.open(port, os.O_RDWR)

    def read_exact(n):
        chunks = []
        while n > 0:
            data = os.read(fd, min(n, 65536))
            if not data:
                time.sleep(0.05)  # host not connected yet
                continue
            chunks.append(data)
            n -= len(data)
        return b"".join(chunks)

    def write(data):
        view = memoryview(data)
        while view:
            n = os.write(fd, view)
            view = view[n:]

    def reply(req, **values):
        _frame_send(write, FRAME_JSON, json.dumps(dict(values, id=req.get("id"), ok=True)).encode())

    class Sink(object):
        def __init__(self):
            self.buf = []
            self.size = 0

        def write(self, data):
            self.buf.append(bytes(data))
            self.size += len(data)
            if self.size >= 1048576:
                self.flush()
            return len(data)

        def flush(self):
            if self.size:
                _frame_send(write, FRAME_DATA, b"".join(self.buf))
                self.buf, self.size = [], 0

    print("bbs-helper: ready, {} disk(s)".format(len(disks)), flush=True)
    mounts = {}
    while True:
        kind, payload = _frame_recv(read_exact)
        if kind != FRAME_JSON:
            continue
        req = json.loads(payload.decode("utf-8"))
        try:
            op = req.get("op")
            if op == "hello":
                reply(req, disks=sorted(disks))
            elif op == "mount":
                results = {}
                for v in req.get("volumes") or []:
                    vid = v["vid"]
                    try:
                        dev = disks.get(v["serial"])
                        if not dev:
                            raise RuntimeError("disk not attached")
                        fstype, opts = VM_FS[v["fs"]]
                        target = "/mnt/" + re.sub(r"[^A-Za-z0-9_.+-]", "_", vid)
                        # MS_RDONLY | MS_NOSUID | MS_NODEV | MS_NOEXEC
                        mount(dev, target, fstype, 1 | 2 | 4 | 8, opts)
                        mounts[vid] = target
                        results[vid] = None
                    except Exception as e:
                        results[vid] = str(e)[:300]
                reply(req, results=results)
            elif op == "list":
                root = mounts.get(req.get("vid"))
                if root is None:
                    raise RuntimeError("volume not browsable")
                reply(req, listing=_list_dir(root, req.get("path") or ""))
            elif op == "tar":
                root = mounts.get(req.get("vid"))
                if root is None:
                    raise RuntimeError("volume not browsable")
                sink = Sink()
                count = _tar_paths(root, req.get("paths") or [], sink)
                sink.flush()
                reply(req, entries=count)
            else:
                raise RuntimeError("unknown request")
        except Exception as e:
            _frame_send(write, FRAME_JSON, json.dumps({"id": req.get("id"), "ok": False, "error": str(e)[:500]}).encode())


class VmBrowseSession(object):
    """One VM archive opened for file-level browsing on this node:

      WORK/borg      borg mount of the archive (borg mount -f, our child)
      WORK/img       imagefs: one image file per volume (partition, LV...)
      helper VM      gets the images of the browsable volumes as read-only
                     disks and mounts them (BrowseVM): no guest filesystem
                     is ever mounted on the hypervisor itself

    Partition tables and LVM metadata are read here, in Python (memory-safe
    parsing of a few structures): the guest's LVM physical volumes are never
    exposed as block devices, so the host's LVM never sees (or
    auto-activates) the guest's volume groups."""

    def __init__(self, root, archive_key, ref):
        """root: VmBrowser.ROOT; archive_key: the server's archive id;
        ref: the VMID the archive holds (its files are under vm-<ref>/)."""
        self.root = root
        self.key = archive_key
        self.ref = str(ref)
        self.work = os.path.join(root, "a" + re.sub(r"[^0-9A-Za-z_-]", "_", str(archive_key)))
        self.borg_mnt = os.path.join(self.work, "borg")
        self.img_mnt = os.path.join(self.work, "img")
        self.borg_proc = None
        self.img_proc = None
        self.vm = None
        self.browsable = set()
        self.structure = None
        self.opened_at = time.time()
        self.last_used = time.time()
        self.busy = 0

    # -- open -----------------------------------------------------------------

    def open(self, borg_mount_cmd, env, log=None):
        """Open the archive and return its structure for the browse page:
        {"vm": {...}, "disks": [...partitions...], "volume_groups": [...],
        "volumes": {id: {title, size, fs, label, browsable, error}}}.

        Steps: borg mount (read-only FUSE view of the archive, up to 15 min
        to wait for the repository lock), read the manifest, parse each
        disk's partition table and LVM metadata, serve every volume as an
        image (imagefs), boot the helper VM with the browsable ones and ask
        it to mount them. A volume that cannot be opened is still listed,
        with the reason.

        Node impact: a borg process holding the repository lock until
        close(), an imagefs process, a helper VM (512 MiB). Nothing is
        written to the archive or to any VM. Raises on failure; the caller
        (VmBrowser.session) then calls close()."""
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
        for disk in manifest.get("disks") or []:
            key = disk.get("key", "")
            if not re.match(r"^[A-Za-z0-9_-]+$", key):
                continue
            size = int(disk.get("size_bytes") or 0)
            if disk.get("layout") == "segments":
                seg = int(disk["segment_size"])
                folder = os.path.join(base, disk["dir"])
                parts = [[os.path.join(folder, "{:06d}.seg".format(i)), min(seg, size - i * seg)]
                         for i in range(int(disk["segments"]))]
            else:
                parts = [[os.path.join(base, disk["file"]), size]]
            disk_parts[key] = parts
            disks.append({"key": key, "size": size, "kind": disk.get("kind", "disk")})

        readers = {k: self._disk_reader(partition) for k, partition in disk_parts.items()}
        volumes = {}  # id -> {"size", "extents", "title", "fs", "label", ...}
        pvs = {}  # pv uuid -> (vg name, vg meta, disk key, byte offset of the PV)
        out_disks = []
        for disk in disks:
            key, size = disk["key"], disk["size"]
            read = readers[key]
            scheme, parts = parse_partition_table(read, size)
            entry = {"key": key, "size": size, "kind": disk["kind"], "table": scheme, "partitions": []}
            if not parts:
                parts = [{"number": 0, "start": 0, "size": size, "type": "whole disk", "extended": False,
                          "logical": False, "name": ""}]
            for partition in parts:
                pid = "{}-p{}".format(key, partition["number"]) if partition["number"] else key
                partition = dict(partition, id=pid)
                if not partition["extended"] and partition["size"] > 0:
                    sub = (lambda off, n, s=partition["start"], r=read: r(s + off, n))
                    content = probe_content(sub, partition["size"])
                    partition.update(content)
                    if content["fs"] == "LVM2_member":
                        info = read_lvm_pv(sub, partition["size"])
                        if info:
                            pvs[info[0]] = (info[1], info[2], key, partition["start"])
                            partition["vg"] = info[1]
                    volumes[pid] = {"size": partition["size"], "extents": [[0, partition["size"], key, partition["start"]]],
                                    "fs": content["fs"], "label": content["label"],
                                    "title": "{} partition {}".format(key, partition["number"]) if partition["number"] else key}
                entry["partitions"].append(partition)
            out_disks.append(entry)

        vgs = self._volume_groups(pvs, readers, volumes)
        self.structure = {"vm": {k: manifest.get(k) for k in ("vmid", "name", "created_at", "guest_frozen", "node")},
                          "disks": out_disks, "volume_groups": vgs, "volumes": {}}

        # Serve every volume as an image file; the helper VM mounts the
        # browsable ones
        spec_path = os.path.join(self.work, "imagefs.json")
        with open(spec_path, "w") as f:
            json.dump({"ts": time.time_ns(), "disks": disk_parts,
                       "volumes": {vid: {"size": volume["size"], "extents": volume["extents"]} for vid, volume in volumes.items()}}, f)
        os.makedirs(self.img_mnt, mode=0o700)
        errlog = open(os.path.join(self.work, "imagefs.log"), "wb")
        self.img_proc = subprocess.Popen(
            [sys.executable, os.path.abspath(__file__), "imagefs", spec_path, self.img_mnt],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=errlog, start_new_session=True)
        errlog.close()
        self._wait_mount(self.img_mnt, self.img_proc, "imagefs.log", 30)

        candidates = [vid for vid, volume in sorted(volumes.items()) if volume["fs"] in VM_FS]
        drives = candidates[:HELPER_MAX_DRIVES]
        results = {}
        if drives:
            self.vm = BrowseVM(self.work, [(vid, os.path.join(self.img_mnt, vid + ".img")) for vid in drives])
            self.vm.start()
            answer = self.vm.request({"op": "mount", "volumes": [
                {"vid": vid, "serial": "bbs{}".format(i), "fs": volumes[vid]["fs"]} for i, vid in enumerate(drives)]})
            results = answer.get("results") or {}

        for vid, volume in sorted(volumes.items()):
            info = {"title": volume["title"], "size": volume["size"], "fs": volume["fs"], "label": volume["label"],
                    "browsable": False, "error": None}
            if volume["fs"] in VM_FS:
                if vid not in drives:
                    info["error"] = "more than {} filesystems: not opened".format(HELPER_MAX_DRIVES)
                elif results.get(vid) is None and vid in results:
                    info["browsable"] = True
                    self.browsable.add(vid)
                else:
                    info["error"] = "could not be mounted: {}".format(results.get(vid) or "no answer")
            elif volume["fs"] == "LVM2_member":
                info["error"] = "LVM physical volume: browse its logical volumes"
            elif volume["fs"] == "swap":
                info["error"] = "swap space, no files"
            elif volume["fs"] == "crypto_LUKS":
                info["error"] = "encrypted (LUKS), not supported"
            elif volume.get("unsupported"):
                info["error"] = volume["unsupported"]
            else:
                info["error"] = "no filesystem recognised"
            self.structure["volumes"][vid] = info
        return self.structure

    def _wait_mount(self, path, proc, logname, timeout):
        """Wait until `path` is a mount point; raise with the tail of the
        process's log if it exits or `timeout` seconds pass."""
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
        volumes (extents on the disks); linear ones only.

        Each LV segment is mapped to (offset in the LV, length, disk key,
        offset on that disk) from the VG metadata, so imagefs can serve the
        LV as one image without activating anything. Striped (RAID 0),
        mirrored, thin or cached LVs, and LVs with a PV missing from the
        backup (disk excluded from the plan) are listed as unsupported."""
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

    # -- browse (through the helper VM) ----------------------------------------

    def _check(self, vid):
        """Refuse a volume id the helper VM did not mount (ids come from the
        browser, through the server)."""
        if vid not in self.browsable or self.vm is None:
            raise RuntimeError("volume {} is not browsable".format(vid))

    def list(self, vid, path, limit=5000):
        """One directory of a volume, listed by the helper VM (see _list_dir).
        `limit` is not passed on: the helper applies its own default (5000)."""
        self._check(vid)
        listing = self.vm.request({"op": "list", "vid": vid, "path": str(path or "")}, timeout=300)["listing"]
        listing["volume"] = vid
        return listing

    def download(self, vid, paths, sink):
        """Write a tar.gz of the selected paths of a volume to sink (the core
        agent's uploader, which sends it to the server in 4 MiB chunks).
        Built by the helper VM; at most 1000 paths, 15 minutes.
        Returns the number of archive members."""
        self._check(vid)
        paths = [str(p) for p in list(paths)[:1000]]
        if not paths:
            raise RuntimeError("nothing selected")
        return self.vm.request({"op": "tar", "vid": vid, "paths": paths}, timeout=900, sink=sink)["entries"]

    # -- close ----------------------------------------------------------------

    def close(self):
        """Stop the helper VM, unmount imagefs then borg (in that order:
        imagefs reads from the borg mount), stop their processes and delete
        the work directory. Releases the repository lock. Safe to call on a
        half-opened session."""
        if self.vm is not None:
            self.vm.stop()
            self.vm = None
        for mnt, proc in ((self.img_mnt, self.img_proc), (self.borg_mnt, self.borg_proc)):
            _unmount_fuse(mnt)
            _stop_group(proc)
        self.img_proc = self.borg_proc = None
        shutil.rmtree(self.work, ignore_errors=True)


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
    borg mount and imagefs left by an agent that stopped with a session open.
    SAFETY: SIGTERM only to processes that name our own work directory AND
    are borg, imagefs or qemu, so no unrelated process (or Proxmox VM,
    whose command line never holds that path) is touched."""
    for pid in os.listdir("/proc"):
        if not pid.isdigit() or int(pid) == os.getpid():
            continue
        try:
            with open("/proc/{}/cmdline".format(pid), "rb") as f:
                cmd = f.read().replace(b"\x00", b" ").decode("utf-8", errors="replace")
        except OSError:
            continue
        if marker in cmd and ("borg" in cmd or "imagefs" in cmd or "qemu" in cmd):
            try:
                os.kill(int(pid), signal.SIGTERM)
            except OSError:
                pass


class VmBrowser(object):
    """The browse sessions of this agent, one per archive, closed after
    IDLE_SECONDS without use and before any backup or restore (borg mount
    keeps a lock on the repository that would hold a backup back).

    A single instance, BROWSER, lives for the agent's lifetime; the core
    agent calls session()/use() for vm_browse tasks, close_all() before a
    backup or restore and cleanup_stale() at start-up. Every open/close is
    reported through `notify` so the server can show the lock in the UI.
    In practice one session at a time is open (opening another closes it)."""

    ROOT = "/var/lib/bbs-agent/vm-browse"
    # Must match HypervisorBackupService::BROWSE_IDLE_SECONDS on the server
    # (the UI's "closes in" countdown)
    IDLE_SECONDS = 600

    def __init__(self):
        import threading
        self.sessions = {}
        self.lock = threading.RLock()
        self.reaper = None
        # Called with {"archive_id", "state": open|closed, "reason"} or
        # {"state": "closed_all"}; the core agent reports it to the server,
        # which shows how long a repository has been locked by a session
        self.notify = None

    @staticmethod
    def unavailable():
        """Why file-level browsing cannot run on this node, or None."""
        import platform
        if IS_WINDOWS or IS_MACOS:
            return "only on Linux"
        if not hasattr(os, "geteuid") or os.geteuid() != 0:
            return "the agent must run as root"
        if platform.machine() not in ("x86_64", "amd64"):
            return "only implemented for x86_64"
        if not shutil.which("fusermount"):
            return "fusermount is not installed"
        if not os.path.exists("/dev/fuse"):
            return "/dev/fuse is missing"
        try:
            import ctypes
            ctypes.CDLL("libfuse.so.2")
        except OSError:
            return "libfuse2 is not installed"
        return BrowseVM.unavailable()

    def _notify(self, event):
        """Report a session change; a failure is only logged (the session
        itself does not depend on the server knowing)."""
        if self.notify is not None:
            try:
                self.notify(event)
            except Exception as e:
                logger.warning("Could not report a VM browse session change: {}".format(e))

    def close(self, archive_key, reason):
        """Close the session of an archive if open; True if one was closed."""
        with self.lock:
            session = self.sessions.pop(archive_key, None)
            if session is not None:
                session.close()
                self._notify({"archive_id": archive_key, "state": "closed", "reason": reason})
            return session is not None

    @contextlib.contextmanager
    def use(self, archive_key, ref, borg_mount_cmd, env, log=None):
        """The session of an archive (opened if needed), kept from the idle
        reaper while in use."""
        with self.lock:
            session = self.session(archive_key, ref, borg_mount_cmd, env, log)
            session.busy += 1
        try:
            yield session
        finally:
            with self.lock:
                session.busy -= 1
                session.last_used = time.time()

    def cleanup_stale(self):
        """Leftovers of an agent that stopped with sessions open.

        Called once at agent start. In order: lazily unmount our FUSE mounts
        (imagefs before borg: deepest path first), stop leftover
        borg/imagefs/qemu processes, delete ROOT's contents, and tell the
        server every session is closed. Only paths under ROOT are touched."""
        if not os.path.isdir(self.ROOT):
            self._notify({"state": "closed_all", "reason": "agent restarted"})
            return
        try:
            with open("/proc/mounts") as f:
                mounts = [line.split()[1].replace("\\040", " ") for line in f]
        except OSError:
            mounts = []
        for m in sorted((m for m in mounts if m.startswith(self.ROOT + "/")), key=len, reverse=True):
            _run(["fusermount", "-u", "-z", m], timeout=60, check=False)
        _kill_stale_processes(self.ROOT + "/")
        for name in os.listdir(self.ROOT):
            shutil.rmtree(os.path.join(self.ROOT, name), ignore_errors=True)
        self._notify({"state": "closed_all", "reason": "agent restarted"})

    def session(self, archive_key, ref, borg_mount_cmd, env, log=None):
        """The open session of an archive, opened now if needed.

        archive_key: the server's archive id; ref: the VMID; borg_mount_cmd
        and env: built by the core agent from the task (borg mount with the
        repository passphrase in env). Opening a new archive closes the
        previous session first. Raises RuntimeError if browsing is not
        possible on this node or the archive cannot be opened (the
        half-open session is closed before raising)."""
        with self.lock:
            session = self.sessions.get(archive_key)
            if session is not None:
                session.last_used = time.time()
                return session
            reason = self.unavailable()
            if reason:
                raise RuntimeError("file-level browsing is unavailable on this node: {}".format(reason))
            # One archive mounted at a time keeps the node's resources and the
            # repository locks in check
            self.close_all("another restore point was opened")
            os.makedirs(self.ROOT, mode=0o700, exist_ok=True)
            session = VmBrowseSession(self.ROOT, archive_key, ref)
            try:
                session.open(borg_mount_cmd, env, log)
            except BaseException:
                session.close()
                raise
            self.sessions[archive_key] = session
            self._notify({"archive_id": archive_key, "state": "open"})
            self._start_reaper()
            return session

    def close_all(self, reason="closed"):
        """Close every session (before a backup or restore, so borg mount
        releases its repository lock first)."""
        with self.lock:
            for key in list(self.sessions):
                self.close(key, reason)

    def _start_reaper(self):
        """Start the background thread that closes idle sessions (checked
        every 30 s). It ends by itself when no session is left."""
        import threading
        if self.reaper is not None and self.reaper.is_alive():
            return

        def loop():
            while True:
                time.sleep(30)
                with self.lock:
                    for key, session in list(self.sessions.items()):
                        if session.busy == 0 and time.time() - session.last_used > self.IDLE_SECONDS:
                            logger.info("Closing idle VM browse session {}".format(key))
                            self.close(key, "idle for {} minutes".format(self.IDLE_SECONDS // 60))
                    if not self.sessions:
                        self.reaper = None
                        return
        self.reaper = threading.Thread(target=loop, name="vm-browse-reaper", daemon=True)
        self.reaper.start()


# The agent's single browser, used by bbs-agent.py (execute_vm_browse,
# close_vm_browse_sessions, cleanup at start-up)
BROWSER = VmBrowser()


# ===========================================================================
# 6. Full VM restore (as a new VM)
# ===========================================================================

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

    SAFETY: never writes to an existing VM. The target VMID must be free
    (checked across all nodes' configs, then claimed atomically with
    O_EXCL); disks are written only to volumes this run allocated. The
    restored VM gets new MAC addresses and has its network unplugged by
    default, so it cannot clash with the original on the network.
    `pvesm free` on failure is irreversible but only ever targets volumes
    created by this run.
    See docs/hypervisors.md#d6-restore-as-a-new-vm-only

    Node impact: the target storage needs room for the whole VM (thin
    volumes stay thin: all-zero chunks are not written).
    """

    def __init__(self, connector, source, ref, options, log, progress):
        """See ProxmoxConnector.restore_vm for the arguments. Raises
        ValueError on a non-numeric source VMID."""
        ref = str(ref)
        if not re.match(r"^[0-9]+$", ref):
            raise ValueError("invalid source VMID {!r}".format(ref))
        self.connector = connector
        self.source = source
        self.ref = ref
        self.options = options or {}
        self.log = log
        self.progress = progress

    @staticmethod
    def _vmid_taken(vmid):
        """True if any node of the cluster has a VM or container with this
        id (VMIDs are cluster-wide in Proxmox)."""
        import glob
        for pattern in ("/etc/pve/nodes/*/qemu-server/{}.conf", "/etc/pve/nodes/*/lxc/{}.conf"):
            if glob.glob(pattern.format(vmid)):
                return True
        return False

    def _target_vmid(self):
        """The VMID to restore to: the one requested (refused if < 100 or
        taken), else Proxmox's next free id (pvesh /cluster/nextid)."""
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
        """The config text of the new VM, from the backed-up one.

        Kept as is: CPU, memory, devices, options, the description.
        Changed so the copy can run next to the original: disks point to
        the new volumes (disks not in the backup are dropped, CD-ROMs
        emptied), every NIC gets a new MAC and link_down=1 unless the user
        asked for the network, new SMBIOS UUID and VM generation ID (guests
        such as Windows and AD domain controllers use them to detect a
        clone), name from the user or "<name>-restored". Dropped: the
        original's snapshots, lock, parent and template flag. A first
        comment line records where the VM came from."""
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
            if self.connector.DISK_KEY.match(key):
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

    CHUNK = 4 * 1024 * 1024  # same as the backup's fixed borg chunk size

    def _copy_segments(self, disk, device, report):
        """Write the segment files of a disk back onto a block device. All
        segments come from one borg extract; their order and sizes come
        from the archive listing, so each lands at its own offset whatever
        the order. All-zero chunks are skipped: a new thin volume stays thin.

        Skipping zeros is only correct because the target is a volume
        allocated by this run on LVM-thin, whose unwritten blocks read as
        zeros (another reason RESTORE_STORAGE is lvmthin only). The segment
        list is checked against the manifest (count and sizes) before the
        first byte is written. Returns the bytes restored."""
        prefix = "vm-{}/{}".format(self.ref, disk["dir"])
        seg = int(disk["segment_size"])
        size = int(disk["size_bytes"])
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
                raise RuntimeError("segment {} of disk {} has a wrong size".format(idx, disk["key"]))
            entries.append(idx)
        if sorted(entries) != list(range(count)):
            raise RuntimeError("disk {}: {} segments in the backup, {} expected".format(disk["key"], len(entries), count))

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
                            raise RuntimeError("the backup stream of disk {} ended early".format(disk["key"]))
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
                raise RuntimeError("the backup stream of disk {} is longer than expected".format(disk["key"]))
            out.flush()
            os.fsync(out.fileno())
        report(total)
        return total

    def run(self):
        """Do the restore (steps in the class docstring). Returns a one-line
        summary for the job log; raises on failure after freeing what it
        created. Side effects on the node (root): a new VM config under
        /etc/pve, new volumes on the target storage (pvesm alloc), disk
        data written to them, and `qm start` if asked."""
        self.manifest = json.loads(self.source.read("vm-{}/bbs-vm.json".format(self.ref)).decode("utf-8"))
        conf_text = self.source.read("vm-{}/qemu-server.conf".format(self.ref)).decode("utf-8", errors="replace")
        disks = self.manifest.get("disks") or []
        if not disks:
            raise RuntimeError("the backup holds no disk")

        storage = self.options.get("storage") or ""
        storage_entry = self.connector.storage_config().get(storage)
        if not storage_entry or storage_entry.get("type") not in self.connector.RESTORE_STORAGE:
            raise RuntimeError("storage '{}' does not exist here or cannot hold restored disks (supported: {})".format(
                storage, ", ".join(self.connector.RESTORE_STORAGE)))

        vmid = self._target_vmid()
        conf_path = os.path.join(self.connector.QEMU_CONF_DIR, "{}.conf".format(vmid))
        # Claim the VMID first; O_EXCL fails if someone took it meanwhile.
        # "lock: create" is Proxmox's own marker for a VM being created: the
        # UI shows it locked, and qm refuses to start or edit it meanwhile.
        fd = os.open(conf_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
        os.write(fd, b"lock: create\n")
        os.close(fd)
        self.log("Restoring VM {} as VMID {} on {}".format(self.ref, vmid, storage))

        created = []
        try:
            total = sum(int(disk.get("size_bytes") or 0) for disk in disks)
            done = 0
            new_volumes = {}
            for i, disk in enumerate(disks):
                size = int(disk.get("size_bytes") or 0)
                segmented = disk.get("layout") == "segments"
                if size <= 0 or not re.match(r"^[A-Za-z0-9._/-]+$", disk.get("dir" if segmented else "file") or "") \
                        or ".." in (disk.get("dir") or ""):
                    raise RuntimeError("bad disk entry in the manifest: {}".format(disk))
                name = "vm-{}-disk-{}".format(vmid, i)
                _run(["pvesm", "alloc", storage, str(vmid), name, str((size + 1023) // 1024)], timeout=300)
                volid = "{}:{}".format(storage, name)
                created.append(volid)
                device = _run(["pvesm", "path", volid], timeout=60).stdout.decode("utf-8").strip()
                if storage_entry.get("vgname"):
                    _run(["lvchange", "-ay", "{}/{}".format(storage_entry["vgname"], name)], timeout=60, check=False)
                if not os.path.exists(device):
                    raise RuntimeError("volume {} has no device at {}".format(volid, device))
                self.log("Restoring disk {} ({:.1f} GiB) to {}".format(disk["key"], size / 1024.0 ** 3, volid))
                report = lambda n, base=done, key=disk["key"]: self.progress(base + n, total, "Restoring disk {}".format(key))
                if segmented:
                    written = self._copy_segments(disk, device, report)
                else:
                    written = self.source.copy_to("vm-{}/{}".format(self.ref, disk["file"]), device, report)
                if written != size:
                    raise RuntimeError("disk {}: {} bytes restored, {} expected".format(disk["key"], written, size))
                done += size
                new_volumes[disk["key"]] = volid

            with open(conf_path, "w") as f:
                f.write(self._rewrite_conf(conf_text, new_volumes, vmid))
        except BaseException:
            # SAFETY: irreversible, but `created` only holds volumes this run
            # allocated. BaseException: also on a cancel from the server.
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


# ===========================================================================
# 7. Registry and script entry points
# ===========================================================================

# slug -> connector class. Add new connectors here, and the server-side
# class to src/Services/Hypervisors/HypervisorRegistry.php. install.sh also
# checks that the downloaded module defines CONNECTORS.
CONNECTORS = {c.slug: c for c in (ProxmoxConnector,)}


# Run as a script, this file is one of three helper processes, each started
# by the code above with this same file, so no other file has to be
# installed or signed:
#   segfs   <spec> <mnt>  CBT segment filesystem      (ProxmoxVmBackup._mount)
#   imagefs <spec> <mnt>  volume images for browsing  (VmBrowseSession.open)
#   vmhelper              /init inside the helper VM   (HelperImage._build)
if __name__ == "__main__":
    if len(sys.argv) == 4 and sys.argv[1] == "segfs":
        sys.exit(segfs_serve(sys.argv[2], sys.argv[3]))
    if len(sys.argv) == 4 and sys.argv[1] == "imagefs":
        sys.exit(imagefs_serve(sys.argv[2], sys.argv[3]))
    if len(sys.argv) == 2 and sys.argv[1] == "vmhelper":
        sys.exit(vmhelper_main())
    sys.stderr.write("usage: bbs-hypervisor.py segfs|imagefs <spec.json> <mountpoint>\n")
    sys.exit(2)

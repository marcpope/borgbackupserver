# Hypervisors: Proxmox VE VM backup, restore and file-level browsing

Developer documentation for pull request #576 (`feature/proxmox`). It is
written for the BBS maintainer reviewing the change: what is added, why, and
what it touches in existing code. User documentation (wiki) is separate.

If this document and the code disagree, the code is right and this document
has a bug: please say so in the review.

## Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Design decisions](#design-decisions)
- [Changes to existing BBS code](#changes-to-existing-bbs-code)
- [Database](#database)
- [Security model](#security-model)
- [Installation & requirements](#installation--requirements)
- [Testing](#testing)
- [Versioning](#versioning)
- [Limitations & open questions](#limitations--open-questions)

## Overview

### What it does

BBS backs up files from client machines. This feature adds **virtual machine
backups on a Proxmox VE node**, at the disk level, with the same repositories,
queue, schedules, retention and UI conventions as the rest of BBS:

| Capability | Summary |
|---|---|
| Inventory | The node's QEMU VMs, their disks and the node's storages, refreshed every 1-60 minutes (default 5) or on demand. |
| Full VM backup | One borg archive per VM per run, taken from LVM-thin snapshots, guest filesystems frozen when the QEMU guest agent answers. |
| Changed block tracking (CBT) | An incremental run only reads the parts of the disk that changed since the last run; the archive still holds the whole disk. |
| Full VM restore | Restores a VM archive as a **new** VM (new VMID, new MACs, network unplugged by default). The original VM is never touched. |
| File-level browsing | Opens a VM restore point, lists partitions, LVM volumes and filesystems, and lets the user download files or folders as a `.tar.gz`. |

### Who it is for

Administrators who run Proxmox VE and already use BBS for their servers, and
want VM-level backups in the same tool instead of a separate one.

### What it does not do

- Containers (LXC): not backed up.
- Storage other than **LVM-thin**: disks on ZFS, directory/qcow2, Ceph, NFS
  etc. are listed in the inventory and marked unsupported, with the reason.
- Proxmox clusters: one agent per node, each node is its own hypervisor in
  BBS. No cluster-wide view, no migration awareness.
- Other hypervisors (VMware ESXi, vCenter): the connector model is ready for
  them and they are registered as *planned*, but nothing is implemented and
  they are hidden from Add Hypervisor.
- Backup of the Proxmox host itself (`/etc/pve`, host OS): use a normal
  client agent for that, as today.
- In-place restore over an existing VM: deliberately not offered.

## Architecture

### Components

| Side | File | Role |
|---|---|---|
| Server | `src/Controllers/HypervisorController.php` | Hypervisors section: list, Add Hypervisor, detail page tabs (VMs, Backups, Restore, Settings, Install agent), browse page. |
| Server | `src/Services/Hypervisors/HypervisorConnector.php` | Base class of a server-side connector and the common VM/disk model. |
| Server | `src/Services/Hypervisors/ProxmoxConnector.php` | Proxmox VE connector (labels, capabilities, restore storage types, info normalisation). |
| Server | `src/Services/Hypervisors/PlannedConnector.php` | Placeholder for a hypervisor not implemented yet (ESXi, vCenter). |
| Server | `src/Services/Hypervisors/HypervisorRegistry.php` | The list of connectors, by slug. |
| Server | `src/Services/Hypervisors/HypervisorBackupService.php` | VM backup plans: which VMs a plan covers, one job per VM, archive prefix, per-VM prune scope, browse sessions and downloads. |
| Server | `src/Services/AgentProvisioningService.php` | Agent creation (API key, storage dir, SSH user, owner permissions), moved out of `ClientController` so Add Client and Add Hypervisor share it; `discard()` removes a new agent again if Add Hypervisor cannot save the hypervisor. |
| Server | `src/Views/hypervisors/*.php` | `index`, `add`, `detail`, `backups`, `restore`, `browse`. |
| Agent | `agent/bbs-agent.py` | Core agent. With `role = hypervisor` it loads the hypervisor module, runs the inventory thread and dispatches VM tasks to it. |
| Agent | `agent/bbs-hypervisor.py` | Hypervisor module, installed **only** on hypervisor agents. Connectors, VM backup, CBT, restore, browsing, helper VM. |
| Agent | `agent/install.sh` | `--hypervisor` flag: installs the module and writes `role = hypervisor`. |

### Flows

The network model of BBS is unchanged: **the agent initiates every
connection**; nothing listens on the Proxmox node for BBS.

```
                       Proxmox VE node (root)
   +-----------------------------------------------------------------+
   |  bbs-agent.py (role = hypervisor) + bbs-hypervisor.py           |
   |                                                                 |
   |  inventory thread ----(1) HTTPS GET  /api/agent/hypervisor/assignments  every 30 s
   |                   ----(2) HTTPS POST /api/agent/hypervisor/inventory    every N min
   |  task loop        ----(3) HTTPS GET  /api/agent/tasks  (backup, restore_vm, vm_browse)
   |                   ----(4) HTTPS POST /api/agent/status, /progress
   |  borg (create,    ----(5) SSH  borg serve  -------------------------+
   |   extract, mount)                                                 |
   |  browse downloads ----(6) HTTPS POST /api/agent/hypervisor/browse-upload (4 MiB chunks)
   |  browse sessions  ----(7) HTTPS POST /api/agent/hypervisor/browse-session
   |                                                                 | |
   |  lvcreate/lvremove, qm, pvesm, thin_delta  (local commands)     | |
   |  helper VM (QEMU, no network) for file-level browsing           | |
   +-----------------------------------------------------------------+ |
                 |  (1)(2)(3)(4)(6)(7)  HTTPS, agent -> server          |
                 v                                                      v
   +--------------------------------------+       +-------------------------------+
   | BBS server (Apache/PHP, MySQL)       |       | Borg repository (BBS server   |
   | queue, schedules, prune, UI          |       | SSH, or remote SSH storage)   |
   +--------------------------------------+       +-------------------------------+
```

| # | From → to | Protocol / port | Purpose |
|---|---|---|---|
| 1 | Node → server | HTTPS, the server's web port | Which hypervisors this agent serves, connector, inventory interval, refresh flag. Separate thread so a long backup does not freeze the inventory. |
| 2 | Node → server | HTTPS | VM inventory in the common model; replaces the stored list. |
| 3, 4 | Node → server | HTTPS | Ordinary task poll and status reports, as for any agent. |
| 5 | Node → repository | SSH (BBS borg SSH port, or the remote storage's) | `borg create` (backup), `borg extract` (restore), `borg mount` (browsing). Same forced-command, append-only access as a client agent. |
| 6 | Node → server | HTTPS | File-level download: the agent uploads the `.tar.gz` in 4 MiB chunks; the browser then downloads it from the server. |
| 7 | Node → server | HTTPS | Browse session opened/closed, so the UI can show how long the repository has been locked. |

The server never connects to the node. The helper VM used for browsing has
no network device at all.

### A VM backup, step by step

1. The scheduler (or Run now) calls `HypervisorBackupService::queueJobs()`,
   which queues **one ordinary `backup` job per VM** (`backup_jobs.vm_ref`).
2. `QueueManager` builds the task like any backup, with archive prefix
   `plan<id>-vm<ref>` and an extra `hypervisor_vm` block (VMID, excluded
   disks).
3. The agent hands the borg command to `ProxmoxVmBackup.prepare()`, which
   freezes the guest (if its guest agent answers), snapshots each disk,
   thaws, and builds a directory `vm-<vmid>/` holding the disks, the VM
   config and a manifest (`bbs-vm.json`).
4. The core agent runs `borg create` on that directory, exactly as for a
   file backup (progress, archive record, catalog, notifications).
5. `ProxmoxVmBackup.cleanup()` always runs: it unmounts, keeps the new
   snapshots as CBT references on success, and removes every other
   snapshot it created.
6. Auto-prune and manual prune are scoped to `plan<id>-vm<ref>-*`, so
   retention counts each VM's own archives.

## Design decisions

Each decision: context, decision, alternatives rejected, consequences.

### D1. A dedicated hypervisor agent, not a plugin

- **Context.** Backing up VMs needs root on the node and dangerous
  capabilities: snapshotting and removing logical volumes, reading and
  writing VM disks, creating VMs. BBS plugins run on ordinary client agents.
- **Decision.** A hypervisor agent is the same `bbs-agent.py` with
  `role = hypervisor` in its config **plus** a separate module,
  `bbs-hypervisor.py`, installed only by `install.sh --hypervisor`.
  `agents.agent_type` (`client` | `hypervisor`) is set when the agent is
  created from Add Hypervisor; every hypervisor API endpoint refuses a
  client agent (HTTP 403).
- **Rejected.** A plugin on a client agent: every client would carry code
  able to destroy volumes, and plugin configs are per plan, not per host.
  A separate agent program: two agents to install, update and sign.
- **Consequences.** A client agent never has the module, so it cannot run a
  VM task even if one were queued for it. Hypervisor agents are listed under
  Hypervisors and hidden from Clients. The agent self-update downloads and
  verifies the module together with the core and refuses the update unless
  both are valid.

### D2. Modular connectors on both sides

- **Context.** Proxmox first, but ESXi/vCenter are likely next, with a
  different deployment (a proxy agent talking to an API).
- **Decision.** Everything hypervisor-specific is in a connector:
  `HypervisorConnector` subclasses on the server (labels, capabilities,
  normalisation of the inventory into a common model) and classes in
  `CONNECTORS` in `bbs-hypervisor.py` on the agent. Pages, API and tables
  only see the common VM/disk model.
- **Rejected.** Proxmox-specific tables and pages: a second hypervisor would
  have meant a second set.
- **Consequences.** `hypervisors.agent_role` (`host` | `proxy`) and
  `hypervisors.config_encrypted` exist for API-based connectors but are not
  used yet (see open questions).

### D3. One backup job and one archive per VM, inside the normal pipeline

- **Context.** Backups in BBS carry a lot of shared behaviour: progress,
  archive records, catalog import, notifications, offline retry, auto-prune.
- **Decision.** A VM backup plan is an ordinary `backup_plans` row with
  `hypervisor_id` set. Each run queues one `backup` job per VM; each job
  produces one archive named `plan<id>-vm<ref>-<date>`.
- **Rejected.** One archive holding all VMs: retention, restore and failure
  handling would be all-or-nothing. A new job type for VM backups: every
  place that handles `backup` would need a twin.
- **Consequences.** Prune must be scoped per VM (`--glob-archives
  plan<id>-vm<ref>-*`), otherwise retention would count all VMs' archives
  together; this is done in `scheduler.php` and in the auto-prune of
  `AgentApiController`. VM plans are hidden from the client page.

### D4. LVM-thin snapshots, guest frozen only for the snapshot

- **Context.** A consistent image needs a point-in-time copy of every disk.
- **Decision.** `lvcreate -s` of each disk (thin snapshot, instant, no
  pre-allocated space). If the VM runs and its QEMU guest agent answers,
  `qm guest cmd <vmid> fsfreeze-freeze` before the snapshots and thaw right
  after, in a `finally` block; the guest is frozen for the duration of the
  `lvcreate` calls only. Without a guest agent the backup is
  crash-consistent and the job log says so.
- **Rejected.** `vzdump`: it produces its own archive format (one big
  stream per VM, recompressed every time), which defeats borg
  deduplication. QEMU dirty bitmaps (as Proxmox Backup Server uses): only
  valid while the VM process runs, lost on stop/migrate, and tied to the
  PBS protocol.
- **Consequences.** Only LVM-thin is supported for now. Leftover snapshots
  of an interrupted run are removed at the start of the next one.

### D5. Changed block tracking with `thin_delta` and a segment filesystem

- **Context.** Reading a 100 GB disk in full every night, only for borg to
  find that 99% of the chunks already exist, costs I/O and time.
- **Decision.** After a successful backup, the snapshot is kept as a
  reference (`snap_<lv>_bbsbase`). Next run, `thin_delta` (from
  thin-provisioning-tools) compares the reference and the new snapshot in
  the pool metadata and returns the changed block ranges. Each disk is shown
  to borg as a directory of 64 MiB segment files served by a small read-only
  FUSE filesystem (libfuse 2 through `ctypes`, standard library only). Only
  segments holding changed blocks get a new timestamp, so borg's files cache
  skips the others without reading them. Fixed 4 MiB chunker, one files
  cache per VM (`BORG_FILES_CACHE_SUFFIX`), one lock per VM.
- **Safety rules.** Timestamps only move forward and only for segments that
  may have changed, so a files cache filled by any earlier run stays
  correct. The disk is read in full on any doubt: no reference, reference
  thin id mismatch, disk resized or moved, `thin_delta` error, and every
  7 days (`FULL_READ_DAYS`). If the thin pool is above 85% after a backup
  (`POOL_LIMIT_PERCENT`), the references are not kept.
- **Rejected.** Hashing the whole disk to find changes (reads everything
  anyway). Keeping state in the guest (needs software in every VM).
- **Consequences.** Each backed-up disk keeps one extra thin snapshot
  between runs; it uses pool space in proportion to what changed since the
  last backup. Without `thin_delta` or FUSE the agent falls back to reading
  the snapshot device in full (`--read-special`, manifest format 1).
  Measured on a 16 GB disk: incremental with little change 24 s → 7 s.

### D6. Restore as a new VM only

- **Context.** Overwriting a running production VM by mistake is
  unrecoverable.
- **Decision.** `restore_vm` always creates a new VM: target VMID claimed by
  creating its config with `O_EXCL` and `lock: create`, disks allocated with
  `pvesm alloc` on an LVM-thin storage, streamed from `borg extract
  --stdout` (all-zero chunks skipped so thin volumes stay thin). The config
  gets new MAC addresses, network cards unplugged by default
  (`link_down=1`), new SMBIOS UUID and VM generation ID, CD-ROM emptied, and
  a note recording the source. Any failure frees the new volumes and
  removes the config.
- **Rejected.** In-place restore: destructive, and the user can delete the
  old VM themselves after checking the new one.
- **Consequences.** The restore needs free space for a full copy of the VM
  on the target storage.

### D7. File-level browsing through an isolation VM

- **Context.** Mounting a guest filesystem from a backup on the hypervisor
  means parsing untrusted, possibly crafted, on-disk structures with the
  host kernel, as root, on the machine that runs every VM.
- **Decision.** The archive is `borg mount`ed on the node; partition tables
  (MBR, GPT) and LVM2 metadata are parsed **in Python** on the node; each
  volume is exposed as an image file by a read-only FUSE filesystem
  (`imagefs`), so the guest's LVM volumes never appear as block devices
  and the host's LVM never activates them. The images are attached
  read-only to a **throwaway helper VM** (QEMU, the node's own kernel, an
  initramfs the agent builds from the node's Python and this module, no
  network, `-sandbox on`). The helper mounts the filesystems read-only with
  its own kernel (no journal replay, `nosuid,nodev,noexec`) and answers
  list/tar requests over a virtio-serial channel. Path checks (`..`, guest
  symlinks) run inside the helper.
- **Rejected.** Mounting on the host (first version of step 4): a crafted
  filesystem could attack the hypervisor kernel. libguestfs: an extra
  dependency, and not in the standard library.
- **Consequences.** A crafted filesystem can at worst take over the helper
  VM, which sees only its read-only volumes and is killed with the session.
  The helper boots in about 1.5 s; its initramfs (~14 MiB) is built once per
  kernel and module version. `borg mount` holds the repository lock while a
  session is open, so sessions close after 10 minutes idle and before any
  backup or restore on that agent; the UI shows how long the lock has been
  held and lets the user close the session. Tested filesystems: ext2/3/4,
  xfs, btrfs, ntfs (ntfs3), vfat, exfat, on MBR (including logical
  partitions), GPT and LVM.

### D8. Inventory in its own thread

- **Context.** The inventory was first reported from the task loop and froze
  during long tasks.
- **Decision.** A separate thread polls `/api/agent/hypervisor/assignments`
  every 30 s and reports the inventory when due or when Refresh was
  pressed. VM configs are read from `/etc/pve/qemu-server` directly (fast,
  source of truth); run state comes from `pvesh`.
- **Consequences.** One extra lightweight HTTPS request every 30 s per
  hypervisor agent.

### D9. Local CLI on the node rather than the Proxmox API

- **Context.** The agent runs as root on the node.
- **Decision.** It uses the node's own tools (`qm`, `pvesm`, `pvesh`,
  `lvcreate`, `thin_delta`) and files under `/etc/pve`. No Proxmox API
  token is created or stored.
- **Rejected.** The REST API with a token: a secret to store and rotate,
  and LVM snapshots are not exposed by the API anyway.
- **Consequences.** No new secret in BBS for Proxmox. The agent must run as
  root (it already does on Linux).

## Changes to existing BBS code

Every addition to an existing file carries a short comment saying why it is
there, so it can be spotted in the diff.

| File | Change | Why |
|---|---|---|
| `agent/bbs-agent.py` | Hypervisor section (module loading, inventory thread, `BorgArchiveSource`, `execute_restore_vm`, `execute_vm_browse`, browse upload); hooks in the backup path; module included in self-update; `_version_tuple()` ignores a `-suffix`. | Runs VM tasks through the module; see [Versioning](#versioning) for the version change. |
| `agent/install.sh` | `--hypervisor` flag, module download, `role` in `config.ini`; a client reinstall removes the module. | D1. |
| `bin/bbs-sign-agent`, `.githooks/pre-commit`, `.github/workflows/verify-agent-signature.yml` | `bbs-hypervisor.py` added to the signed files. | The module is code that runs as root: signed like the agent. |
| `schema.sql`, `migrations/140-145` | New tables and columns. | See [Database](#database). |
| `scheduler.php` | Offline retry keeps `vm_ref`; prune scoped per VM. | D3. |
| `src/Core/App.php` | Hypervisor routes (UI and agent API). | |
| `src/Core/JobType.php` | Labels for `restore_vm`, `vm_browse`. | |
| `src/Controllers/Api/AgentApiController.php` | Four hypervisor endpoints; archive records keep `vm_ref`; auto-prune per VM; `vm_browse` result handling; module downloadable. | |
| `src/Controllers/ClientController.php` | Clients list hides hypervisor agents and VM plans; agent creation moved to `AgentProvisioningService`. | D1, D3. |
| `src/Controllers/BackupPlanController.php` | Run now and delete of a VM plan go through the hypervisor page. | D3. |
| `src/Services/QueueManager.php` | New task types; VM backup context; `restore_vm`/`vm_browse` payloads; a VM task whose restore point was pruned fails instead of being re-queued. | |
| `src/Services/SchedulerService.php` | A scheduled VM plan queues one job per VM. | D3. |
| `src/Controllers/Api/AdminApiController.php`, `DashboardController.php`, `QueueController.php`, `src/Views/dashboard/index.php` | `restore_vm` counted with the other restore types. | |
| `src/Views/layouts/app.php` | Hypervisors entry in the sidebar and mobile menu. | |
| `README.md` | One line in the feature list, linking to this document. | Replace the link by the wiki page once it exists. |

## Database

Migrations 140 to 145; `schema.sql` is updated to match.

| Migration | Change | Role |
|---|---|---|
| 140 `proxmox_hypervisors` | `agents.agent_type`; tables `hypervisors`, `hypervisor_vms` | Agent kind; one row per hypervisor (connector slug, agent, info, inventory time); one row per VM in the common model (disks as JSON). |
| 141 `hypervisor_inventory_interval` | `hypervisors.inventory_interval_minutes` | Inventory interval, 1-60 minutes, default 5. |
| 142 `hypervisor_vm_backup` | `backup_plans.hypervisor_id`, `backup_plans.vm_selection`; table `backup_plan_vms`; `backup_jobs.vm_ref`; `archives.vm_ref` | VM plans: `selected` VMs, or `all` minus excluded ones (new VMs included automatically); excluded disks per VM; which VM a job/archive is for. |
| 143 `restore_vm` | `restore_vm` task type; `backup_jobs.restore_options` (JSON) | Full VM restore target: VMID, storage, name, network, start. |
| 144 `vm_browse` | `vm_browse` task type | File-level browsing requests (open, list, download) and answers in `task_result`. |
| 145 `vm_browse_sessions` | table `vm_browse_sessions` | Open/closed browse sessions, with server times, to show the repository lock. |

Migrations 143 and 144 restate the full `task_type` enum; it was checked
against upstream after the rebase onto 2.102.1 (no upstream type is lost).
If upstream adds a task type before this is merged, both migrations and
`schema.sql` must include it.

Foreign keys cascade on delete: deleting a hypervisor deletes its VMs and
its VM plans; deleting the agent deletes its hypervisors.

## Security model

### Privileges

| Where | Needs | Why |
|---|---|---|
| Proxmox node | The agent runs as **root** (systemd service, as installed today). | `lvcreate`/`lvremove`, `dmsetup`, `qm`, `pvesm`, FUSE mounts, QEMU. |
| BBS server | No new privilege. No `sudo`, no change to `bbs-ssh-helper`. | Everything privileged happens on the node. |
| Proxmox API | None: no token, no user, no role. | D9. |

### Secrets

No new secret is stored. Repository passphrases and remote SSH keys travel
in the task payload exactly as for file backups and restores.
`hypervisors.config_encrypted` is reserved for future API-based connectors
and must be encrypted with `Encryption::encrypt()` like other secrets when
used.

### Isolation between client and hypervisor agents

- `agents.agent_type` is set by the server at creation; an agent cannot
  change it.
- Every `/api/agent/hypervisor/*` endpoint returns 403 to a client agent.
- The module is not installed on client agents, and a client reinstall
  removes it. Even if the file were present, `bbs-agent.py` only loads it
  when its config says `role = hypervisor`.
- Trust in the module is the same as in the core agent: at install time it
  is downloaded from the server over HTTPS (no signature check, as for
  `bbs-agent.py`); on self-update it must carry a valid signature, and the
  core and the module are only replaced together.
- The inventory endpoint only accepts hypervisors assigned to the calling
  agent, with the expected connector type.

### Backups stay append-only

The agent still uses borg over the same restricted SSH access. It creates
archives, extracts and mounts them; it never deletes. Prune runs on the
server, scoped per VM.

### Operations that change state on the node

| Operation | Where | Guard |
|---|---|---|
| Create/remove thin snapshots | `ProxmoxVmBackup` | Only names this code creates: `snap_<lv>_bbsnew`, `snap_<lv>_bbsbase`, legacy `<lv>-bbs-snap`. Always removed in `cleanup()`, also after failure; leftovers removed at the next run. |
| Freeze the guest filesystems | `ProxmoxVmBackup.prepare()` | Thaw in `finally`, right after the snapshots. |
| Allocate volumes, write disks, create a VM config | `ProxmoxVmRestore` | New VMID only (`O_EXCL`), new volumes only; on failure the volumes are freed and the config removed. Never writes to an existing VM's volumes. |
| Start a VM | `ProxmoxVmRestore` | Only the restored VM, only if the user ticked "start", network unplugged by default. |
| FUSE mounts (borg mount, imagefs), helper VM | `VmBrowseSession`, `VmBrowser` | All read-only; closed at session close, idle timeout, before any backup/restore, and at agent start (`cleanup_stale`, which only touches paths under `/var/lib/bbs-agent/vm-browse/`). |
| Kill leftover processes | `VmBrowser.cleanup_stale` | At agent start only: SIGTERM to borg/imagefs/qemu processes whose command line names the browse work dir. |

### Server side

- All UI routes use `requireAuth()`, `verifyCsrf()` on POST, and the
  existing permission checks (restore permission for restore and browsing).
  A hypervisor is only visible to users who can access its agent.
- Everything the UI posts is checked against the database, not trusted:
  a plan's repository must belong to the hypervisor's agent and its VMs and
  disks are taken from the stored inventory; a restore point must be a VM
  archive of that agent; a restore target storage must be one of the node's
  storages of a type the connector can restore to.
- The agent's inventory report is untrusted input: the connector keeps
  known fields only, casts them and cuts them to the column sizes.
- Browse downloads are stored under `/var/bbs/tmp/vm-browse/` (fallback:
  the system temp dir), refused when less than 1 GB is free, and removed
  after 6 hours. The file path is built from the job id only; an upload
  chunk must belong to the calling agent's running download task and start
  at the size already received.
- The controller and services only read and write the database: work on
  the node is queued as jobs, nothing runs a command on the server.

## Installation & requirements

### Proxmox node

| Requirement | Shipped with Proxmox VE 8? | Used for |
|---|---|---|
| Proxmox VE 8.x, x86_64 | — | Tested on 8.2. |
| VM disks on an **LVM-thin** storage | default `local-lvm` | Backup and restore. |
| `thin_delta`, `dmsetup` (thin-provisioning-tools, dmsetup) | yes | CBT. |
| `fusermount`, `/dev/fuse`, `libfuse.so.2` (fuse, libfuse2) | yes | CBT, browsing. |
| `qemu-system-x86_64`, `/dev/kvm` | yes | Browsing helper VM (falls back to software emulation without KVM, much slower). |
| `ldd`, `modprobe` | yes | Building the helper VM's initramfs (browsing). |
| Python 3 | yes (3.11 on PVE 8) | The agent, standard library only. |
| borg | installed by the agent as on any client | |
| QEMU guest agent in the VM (optional) | no | Consistent (frozen) backups instead of crash-consistent. |

There is no `sudo` on a Proxmox node; the install command shown in BBS runs
as root without it.

### Installing a hypervisor agent

1. In BBS: **Hypervisors → Add Hypervisor → Proxmox VE**, give it a name.
2. Copy the install command from the **Install agent** tab and run it on the
   node as root. It is the normal installer with `--hypervisor`.
3. The node comes online within a minute; its VMs appear on the
   **Virtual machines** tab.

### Disk usage

- **Thin pool on the node.** During a backup, one snapshot per disk; between
  backups, one reference snapshot per disk (CBT). Their size grows with
  what the VM writes. References are dropped when the pool is above 85%.
- **Node local disk.** `/var/lib/bbs-agent/` holds CBT state (small JSON per
  VM), the helper initramfs (~14 MiB), and temporary browse work dirs.
- **Repository.** First backup of a VM ≈ the used space of its disks after
  compression; incremental backups store changed 4 MiB chunks only.
- **BBS server.** Browse downloads under `/var/bbs/tmp/vm-browse/`, kept
  6 hours.

## Testing

### Environment used

- Proxmox VE 8.2.2 (kernel 6.8.4-2-pve), single node, nested in VMware
  Workstation, `local-lvm` thin pool.
- Test VM 100: Debian, 3 disks (16 GB, 2 GB, 4 GB) with MBR (extended +
  logical swap), GPT, ext4 and an LVM PV with xfs (two segments) and ext4
  LVs; QEMU guest agent and MariaDB installed.
- BBS server in Docker (`bbs-dev` image built from this branch).

There are no automated tests for this feature (BBS has no test suite); the
checks below are manual. For this review they were also run as a script
over HTTP against the test server (every hypervisor page and action, the
four agent endpoints, a client agent refused with 403, a scheduled run).

Unrelated to this branch, but seen while testing: a Docker image rebuilt in
November 2026 gets, through `apt-get upgrade`, a Debian 13 `sudo`
(1.9.16p2-3+deb13u5) that crashes with a segmentation fault when started
with an empty environment (`env -i sudo -n true`). `scheduler.php` starts
`bbs-ssh-helper` with an empty environment, so every server-side prune,
compact and check of a local repository fails with "Exit code 11"
(`ArchiveDownloadService` uses the same pattern). The same commands work
when `PATH` is set. If auto-prune fails on a fresh image, this is why.

### Manual test procedure

1. **Install.** Add a Proxmox hypervisor, run the install command on the
   node. Expect: agent online, VMs listed with their disks, unsupported
   disks marked with a reason.
2. **First backup.** Create a VM plan for one VM, Run now. Expect: job
   completes; log shows "Guest filesystems frozen" (with guest agent),
   "first backup with change tracking"; `lvs` shows `snap_*_bbsbase` after
   the job and no `snap_*_bbsnew`.
3. **Incremental backup.** Write some data in the VM, run again. Expect: log
   shows "N of M segments changed", job much faster than the first.
4. **Failure cleanup.** Start a backup and cancel it, or stop the agent
   mid-backup. Expect: next run removes leftovers; no `bbsnew` snapshot left.
5. **Retention.** With two VMs in a plan and `keep_last=2`, run three times.
   Expect: each VM keeps its own 2 archives.
6. **Restore.** Restore an archive to a new VMID on `local-lvm`, network
   unplugged. Expect: new VM, new MACs, `link_down=1`, original VM untouched;
   boot it and compare file checksums with the source.
7. **Browsing.** Restore tab → Browse files. Expect: partitions, LVs and
   filesystems listed; download a folder and compare checksums; a guest
   symlink to `/etc/passwd` is refused; `vgs` on the node does not show the
   guest's volume group; the lock bar shows the session; Close session
   ends it.
8. **Client isolation.** With a client agent's API key, call
   `/api/agent/hypervisor/assignments`. Expect: 403.

## Versioning

While this feature is unmerged, its versions carry an `-exp.N` suffix so a
development build is never mistaken for a release:

| Number | Where | Rule |
|---|---|---|
| Server | `VERSION` | `X.Y.Z-exp.N`: X.Y.Z is the next feature release after upstream `main`; N goes up with each delivery that changes application code. |
| Agent | `AGENT_VERSION` in `agent/bbs-agent.py` | Same as `VERSION` when the agent changed in that delivery; otherwise left as is. |
| Hypervisor module | `MODULE_VERSION` in `agent/bbs-hypervisor.py` | Its own semver, bumped when the module changes. |

Current: server `2.103.0-exp.7`, agent `2.103.0-exp.3`, module `1.5.2`.

Agents self-update when the server's bundled `AGENT_VERSION` is newer than
theirs, and a hypervisor agent then fetches the module along with the core.
A delivery that changes only `bbs-hypervisor.py` therefore does not reach
agents by itself: bump `AGENT_VERSION` too when a module change must be
rolled out, or reinstall the node with `install.sh --hypervisor`.

### Effect on version comparison

- **Server (PHP `version_compare`).** `2.103.0-exp.1` sorts *before*
  `2.103.0`, so the official release is offered as an update. No change was
  needed.
- **Agent (`_version_tuple()`).** It used to keep only the digits of each
  dot-separated part, so `2.103.0-exp.2` became `(2, 103, 0, 2)`, *newer*
  than `(2, 103, 0)`, and an experimental agent refused the official
  release as a downgrade. `_version_tuple()` now drops everything after the
  first `-`, so `2.103.0-exp.N` compares equal to `2.103.0`: the official
  version is accepted, and older versions are still refused. Side effect:
  `exp.2` → `exp.1` is not detected as a downgrade (only matters in testing).

### Back to an official version

1. The maintainer chooses the official number and removes the suffix from
   `VERSION` and `AGENT_VERSION` (both the same number, since the agent
   changed).
2. Re-sign the agent files: `python3 bin/bbs-sign-agent sign`.
3. Agents running an `-exp` build with the new `_version_tuple()` accept the
   official signed update normally. An agent installed **before** that
   change (`-exp` builds before 2.103.0-exp.1) must be reinstalled with
   `install.sh --hypervisor`.

## Limitations & open questions

### Known limitations

- LVM-thin only; single node; no LXC; x86_64 only for CBT and browsing.
- The agent files in this branch are **not signed** (the signing key is the
  maintainer's). Agents with `require_signed_updates` on (the default)
  refuse to update to this branch from the server; test nodes are installed
  directly.
- `borg mount` (browsing) holds the repository lock; a backup on the same
  repository waits until the session closes (idle 10 min, or closed from
  the UI, or closed automatically before a backup on the same agent).
- A restore needs free space for the whole VM on the target storage.
- A CBT reference snapshot (`snap_<lv>_bbsbase`) stays on the thin pool when
  its disk stops being backed up while the VM still exists (disk unticked,
  plan deleted): the agent only manages the references of the disks it is
  backing up. Remove it with `lvremove <vg>/snap_<lv>_bbsbase`. Deleting the
  VM frees it, as Proxmox removes `snap_<disk>_*` volumes with the disk.
- LUKS volumes, swap, and guest logical volumes other than plain linear
  ones (thin, RAID, multi-stripe) are listed when browsing but cannot be
  opened.

### Questions for the maintainer

1. **Reserved columns.** `hypervisors.agent_role` (`proxy`) and
   `hypervisors.config_encrypted` are unused until an API-based connector
   (ESXi/vCenter) exists. Keep them now, or drop them and add them with that
   connector?
2. **Planned connectors.** ESXi and vCenter are registered as
   `PlannedConnector` and hidden from Add Hypervisor. Keep, or remove until
   there is code?
3. **One hypervisor per agent.** The restore/browse payload finds the
   hypervisor by `agent_id`. Fine for a node agent (one node = one
   hypervisor); a proxy agent serving several would need the hypervisor id
   on the job. Address now or with the first proxy connector?
4. **CBT reference snapshots** stay on the node between backups and use
   thin-pool space. Is the 85% pool threshold the right default, and should
   it be a setting?
5. **Release number.** The `-exp` suffix is removed at merge; the official
   version number is yours to choose.
6. **Browsing and storage slots.** `vm_browse` is in
   `QueueManager::STORAGE_FREE_TASKS`, so a browse request never waits for a
   storage slot behind backups: each click is a short task and only what is
   opened is read. Its `open`, however, runs `borg mount`, which holds the
   repository lock until the session closes. Is it right to keep it out of
   the storage concurrency limit, or should `open` count as a repository
   task?

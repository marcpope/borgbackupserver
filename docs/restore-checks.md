# Restore checks

A restore check proves that a backup can be read back. On a schedule (or with
**Run now**) BBS takes the newest eligible archive, extracts the selected paths
or the whole archive into private staging on the BBS server, validates what came
out against the archive's own listing, deletes it, and keeps a report.

It is **not** a restore onto the client, a database or service start, or a
disaster-recovery drill. A pass means the files can be extracted and read; the
measured duration is not a recovery time objective. Reports say so.

## Using it

Client → **Restore** tab → **Restore checks** → **New restore check**.

| Setting | Meaning |
|---|---|
| Repository / archives tested | Newest archive from one backup plan (follows that plan's real archive-to-job link, so plans sharing a repository are kept apart), or newest archive in the whole repository. |
| Coverage | **Selected paths** (literal archive paths; a directory includes everything below it) or **Full archive**. An empty path list is refused; it never becomes a full extraction. |
| Expected SHA-256 | Optional, per file. Only for content you know independently and that does not change (a marker file you created). Without it the report shows a *fingerprint*, not proof of correctness. |
| Schedule | Daily, weekly or monthly at one or more times, in an IANA timezone. A time skipped by a daylight-saving change does not run that day; a time that occurs twice runs once (the first). |
| Maximum archive age | The newest eligible archive must be younger than this, or the run fails `archive_too_old`. Set it from the backup cadence. |
| Overdue after | No strict pass for this long (or since the plan was enabled) raises *Restore Check Overdue*. Failed attempts do not reset it. |
| Limits | Maximum queue wait, run time, extraction size, item count and free space to keep on the staging filesystem. Full-archive mode has no default size: enter one. |

New checks are created **disabled**. Review, then **Enable schedule** or **Run now**
(Run now never enables the schedule). Scheduled runs act with the restore
permission of the user who enabled the plan; if that user loses access, the plan
disables itself and says why until someone with access enables it again.

### Results

| Result | Meaning |
|---|---|
| Passed (strict pass) | Every selected item came back with the right type, size and link target, every regular file was read to the end, expected hashes matched, borg exited 0, and the staging was deleted. |
| Warnings | Extracted and validated, but something could not be fully verified: borg exit code 1, device nodes (cannot be created without root), names that are not valid UTF-8, extra items, or an archive from a backup that had warnings (only if the plan allows those). Never counted as a strict pass. |
| Failed | A specific reason code, e.g. `probe_missing`, `hash_mismatch`, `items_missing`, `archive_missing`, `archive_too_old`, `no_eligible_archive`, `unsafe_archive_layout`, `insufficient_space`, `limit_exceeded`, `timeout`, `repository_unavailable`, `unauthorized`, `queue_timeout`, `worker_lost`, `cleanup_failed`. |
| Cancelled / Skipped | Cancelled from the queue; or an occurrence that could not run (previous run still active, owner lost access). |

Each finished run has a JSON report (`…/report.json`, versioned schema, served
byte-for-byte as stored, with its SHA-256 in `X-Report-SHA256`) and a printable
page (browser **Print / Save as PDF**). The digest shows a report is unchanged
only when compared with a copy kept elsewhere; export reports to a separately
protected store if they are audit evidence.

Notification events (Settings → Notifications, Apprise services, push):
*Restore Check Failed*, *Warnings*, *Overdue*, *Cleanup Failed* and, opt-in per
plan, *Passed*.

### Permissions

| Action | Needs |
|---|---|
| See results, open reports, Run now, cancel a run | Client access + **Perform Restores** |
| Create, edit, enable, disable, delete a check | Client access + **Perform Restores** + **Manage Plans** |
| Evidence of deleted clients (`/restore-checks`) | Admin |

## Operating it

**Staging root.** `/var/bbs/restore-checks`, owned by root, created by the
installer, the updater and the Docker entrypoint. Each run gets
`/var/bbs/restore-checks/<random token>/data`, owned by the repository's own
Unix user; nothing else is ever written or deleted. To bound disk use hard, mount
a separate filesystem (or a quota) at that path: the free-space reserve is
checked every few seconds and can overshoot by one interval.

**Plaintext.** Restored files are customer data in plaintext for the duration of
a run, and are deleted afterwards — on failure and cancellation too. BBS's own
self-backup does not include `/var/bbs/restore-checks`. Exclude it from any
host-level backup or snapshot of `/var/bbs` as well.

**Privileges.** Extraction runs through `bbs-ssh-helper restore-check`, as the
repository-owning `bbs-*` user (never root), with the passphrase in the
environment rather than on a command line. That user owns the repository, so
an archive that wrote through a symlink could reach the repository itself; any
archive storing an item beneath one of its own symlinks is therefore refused
before anything is extracted (`unsafe_archive_layout`). Remote SSH repositories are read as
`www-data` over SSH, like every other server-side remote job. Clients whose
repositories have no dedicated Unix user are refused.

**Queue.** Restore checks are server-side jobs: the client does not need to be
online. One runs at a time server-wide (`restore_check_max_concurrent`), and a
repository busy with a backup, prune, compact, check or another restore check is
not touched until it is free. Borg lock waits are bounded; locks are never
broken. Maintenance mode stops new runs.

**Crashes.** If the server restarts mid-run, the next scheduler pass marks the
run failed (`worker_lost`), stops anything still running in its staging and
deletes it. Directories left in the staging root by runs BBS no longer knows
about are swept every ten minutes. A cleanup that keeps failing is retried three
times and then left for an operator, with a *Cleanup Failed* notification.

**Retention.** Runs are kept for `restore_check_evidence_days` (default 365),
independently of the 30-day job and log cleanup. Deleting a plan keeps its
history; deleting a client keeps its runs visible to admins only.

## Supported and not supported

* Borg 1.x from 1.2 on, on the BBS server; other versions fail with
  `unsupported_borg_version`. Tested with 1.4.0 (the Docker image). Borg 2 is
  not supported anywhere in BBS.
* Local and Remote SSH repositories. Read-only offsite copies, S3 mirrors and
  imported archives without a BBS backup record are not tested (imported
  archives are skipped and the report says how many).
* Not verified: ownership, permissions, ACLs, extended attributes and flags
  (extraction is unprivileged); device nodes and sockets (reported as warnings).
* Not in this version: managed canary files, restore checks on the client,
  database or application recovery drills, after-backup triggers, sampling of
  older archives.

## Upgrading

Migration `137_restore_checks.sql` adds two tables, the `restore_check` job
type and six settings. It changes no existing rows and is safe to re-run.
Existing installations behave exactly as before until someone creates a check.

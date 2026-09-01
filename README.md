# Borg Backup Server

<img width="100%" alt="borg backup server gui" src="https://github.com/user-attachments/assets/c623708f-65b6-4f88-a786-a570c51d60a4" />



A self-hosted web application for centrally managing [BorgBackup](https://borgbackup.readthedocs.io/) across multiple endpoints (Linux, Mac and Windows). A lightweight agent polls the server for tasks over HTTPS, backs up over SSH to the server, and reports progress back. No inbound connections to endpoints from the server — this works behind firewalls and NAT from where the server is providing easy provisioning. Includes a setup wizard for simple installation or a Docker image to start up in 30 seconds.

**View Demo **
The developer has made a system for provisioning Demos at no cost here: [Borg Backup Server](https://www.borgbackupserver.com/)

## Features

- **Agent-based architecture** — endpoints check-in with the server for tasks, the server doesn't need ssh access to the agent
- **SSH with append-only security** — agents can only backup or restore, can't delete or prune
- **FULL Encryption** - Software keeps everything encrypted at rest for enhanced security
- **Setup wizard** — browser-based installer configures database, admin account, and storage quicky
- **Real-time progress** — live progress bars during backups with detailed logging
- **File-level restore** — catalog data is saved in ClickHouse DB for fast search and file-tree without having to lock the borg repo
- **Download archives** — extract and download files as .tar.gz directly from the browser
- **Database plugins** — MySQL and PostgreSQL pre-dumps with automatic restore back into the database as a copy or replacement
- **Flexible scheduling** — hourly to monthly intervals, multiple plans per client, manual trigger
- **Backup templates** — pre-configured and customizeable directory sets for common server roles
- **Retention policies** — per-plan prune settings (hourly/daily/weekly/monthly/yearly)
- **S3 offsite sync** — mirror repositories to S3-compatible storage (AWS, Wasabi, Backblaze B2) for enahnced compliance
- **Remote Storage Repos** — wizards to backup to BorgBase, Hetzen and rsync.net (or any SSH provider that provides borg)
- **Repo Management** - Perform hard unlocks, repair, re-catalog, and other repo specific features
- **Nightly Backup Reports** - get an email every day with backup stats
- **Multi-user** — custom role-based access with various roles
- **Two-factor authentication** — TOTP-based 2FA with recovery codes, hooks into your 2FA of choice
- **Queue management** — concurrent job limits, cancel/retry, progress tracking
- **Encrypted passphrases** — repository passwords encrypted at rest (AES-256-GCM)
- **Apprise alerts** — custom push notifications to over 100 different notification services (Slack, Pushover, etc)
- **Extensive Dashboard** — backup charts, server stats, active jobs, see everything at a glance
- **Server self-backup** — daily automated backup of BBS itself with optional S3 sync offsite with restore scripts
- **Automatic Self-Upgrade** - one-click upgrade of the software plus all the agents. Also manage borg versions of client machines
  
---

## Quick Start

Both installation paths are documented step by step on the Wiki — start there rather than piecing it together:

- **[Bare Metal / VM installation](https://github.com/marcpope/borgbackupserver/wiki/Installation)** — a fresh Ubuntu server (22.04, 24.04, and 26.04 LTS are supported) and one installer command; the guide covers prerequisites, SSL, the setup wizard, and what the installer changes.
- **[Docker installation](https://github.com/marcpope/borgbackupserver/wiki/Docker-Installation)** — pre-built images on [Docker Hub](https://hub.docker.com/r/marcpope/borgbackupserver) for every release; the guide covers compose configuration, storage, reverse proxy setup, and updates.

**Unraid:** a Community Applications template is included at [`unraid/borgbackupserver.xml`](unraid/borgbackupserver.xml) — add it via *Docker → Add Container → Template* (or your CA templates repo). It maps the web/SSH ports and the `/var/bbs` data path, and defaults `APP_URL`/`SSH_PORT` to the host.

---

## 📱 BBS Manager is here

**[BBS Manager](https://www.borgbackupserver.com/bbs-manager/) is now available on the Apple App Store** — manage your Borg Backup Server from your phone, with native push notifications for failed backups, server status, and more. It's an optional companion app; see the [announcement](https://github.com/marcpope/borgbackupserver/discussions/460) for details and how to connect it under **Settings → Push Service**. Update to the latest release for the best experience with the app.

---

## Documentation

All documentation lives on the **[GitHub Wiki](https://github.com/marcpope/borgbackupserver/wiki)**:

- [System Requirements](https://github.com/marcpope/borgbackupserver/wiki/System-Requirements)
- [Installation](https://github.com/marcpope/borgbackupserver/wiki/Installation)
- [Getting Started](https://github.com/marcpope/borgbackupserver/wiki/Getting-Started)
- [Agent Setup](https://github.com/marcpope/borgbackupserver/wiki/Agent-Setup)
- [Backup Plans](https://github.com/marcpope/borgbackupserver/wiki/Backup-Plans)
- [Restoring Files](https://github.com/marcpope/borgbackupserver/wiki/Restoring-Files)
- [Plugins](https://github.com/marcpope/borgbackupserver/wiki/Plugins)
- [S3 Offsite Sync](https://github.com/marcpope/borgbackupserver/wiki/S3-Offsite-Sync)
- [Settings](https://github.com/marcpope/borgbackupserver/wiki/Settings)
- [CLI Reference](https://github.com/marcpope/borgbackupserver/wiki/CLI-Reference)
- [Troubleshooting](https://github.com/marcpope/borgbackupserver/wiki/Troubleshooting)
- [Contributing](docs/CONTRIBUTING.md)

---

## Architecture

<img width="100%" alt="Borg Backup Server Web GUI Architecture" src="https://github.com/user-attachments/assets/5163abe0-c2aa-44f0-b4c9-5feb6f2436fb" />


- **HTTPS** for control plane (task polling, progress, status)
- **SSH** for data plane (borg backup/restore via `borg serve`)
- **Append-only** — agents cannot delete existing archives; pruning runs server-side

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ (no framework) |
| Database | MySQL 8.0 |
| Frontend | Bootstrap 5, Chart.js |
| Agent | Python 3 (stdlib only) |
| Backup engine | BorgBackup |
| Offsite sync | rclone |

---

## License

[MIT License](LICENSE)

---

## Support

This project was the love of years of refined work and production use within a small web hosting company. Consider making a small [monthly or one-time donation](https://github.com/sponsors/marcpope) to help keep the project going.

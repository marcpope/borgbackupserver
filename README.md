# Borg Backup Server

<img width="100%" alt="Borg Backup Server Dashboard" src="https://github.com/user-attachments/assets/6e3cee15-6a2d-428b-be25-d150a38c787a" />


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

Start with a fresh **Ubuntu 22.04+** server, then run:

```bash
curl -sO https://raw.githubusercontent.com/marcpope/borgbackupserver/main/bin/bbs-install
sudo bash bbs-install --hostname backups.example.com
```

The installer handles everything — packages, Apache, MySQL, SSL, and cron. When it finishes, open the URL and the setup wizard walks you through the rest.

See the **[full documentation on the Wiki](https://github.com/marcpope/borgbackupserver/wiki)** for installation details, agent setup, configuration, and usage guides.

---

## Docker

Pre-built images are published to [Docker Hub](https://hub.docker.com/r/marcpope/borgbackupserver) on every release:

```bash
curl -sO https://raw.githubusercontent.com/marcpope/borgbackupserver/main/docker-compose.yml
docker compose up -d
```

Get admin credentials from the container logs:

```bash
docker compose logs bbs
```

Open `http://localhost:8080` and log in. See the **[Docker Installation guide](https://github.com/marcpope/borgbackupserver/wiki/Docker-Installation)** for full configuration, storage, reverse proxy, and update documentation.

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

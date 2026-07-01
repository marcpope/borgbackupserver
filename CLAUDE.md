# BBS — Borg Backup Server

## Tech Stack
- **Backend:** PHP 8.1+ (no framework, custom MVC in `src/`)
- **Database:** MySQL 8.0 (NOT SQLite) — all queries use MySQL syntax
- **Frontend:** Bootstrap 5, vanilla JS, server-rendered PHP views
- **Agent:** Python 3 (`agent/bbs-agent.py`) — runs on client machines, polls server for tasks
- **OS:** Ubuntu Server 22.04+ only — no other distros supported

## Project Structure
- `src/Controllers/` — route handlers (extend `src/Core/Controller.php`)
- `src/Services/` — business logic (PluginManager, QueueManager, etc.)
- `src/Views/` — PHP templates (Bootstrap)
- `src/Core/App.php` — routes, `Controller.php` — base controller, `Database.php` — MySQL PDO wrapper
- `agent/bbs-agent.py` — client agent
- `schema.sql` — consolidated DB schema (source of truth for fresh installs)
- `migrations/` — incremental SQL migrations
- `bin/bbs-install` — installer, `bin/bbs-update` — updater
- `scheduler.php` — cron-driven task runner

## Version Numbers
- **Server version:** `VERSION` file in project root.
- **Agent version:** `AGENT_VERSION` constant in `agent/bbs-agent.py`.
- **Always synchronized.** As of `2.53.0` the agent and server share the same version number. When cutting a release, bump both to the same value, regardless of whether the agent code changed.

## Server Installation Path
Software is installed at `/var/www/bbs/` on all servers. Config lives at `/var/www/bbs/config/.env`.

## Test Servers & Infrastructure

### Falcon (Primary Test Server)
- **Host:** `ssh bbs@falcon.borgbackupserver.com` — bare VM install, primary test/dev server
- **Sudo:** passwordless sudo available for troubleshooting
- **MySQL access:** `sudo mysql` (no password needed via sudo)
- **Deploy dev code:** `sudo /var/www/bbs/bin/bbs-update /var/www/bbs main`
- **Uses Apache mod_php** (not PHP-FPM), so `fastcgi_finish_request()` is unavailable

### Docker Multi-Tenant Server
- **Host:** `ssh bbs@us.borgbackupserver.com` — Docker host for tenant provisioning (NOT a BBS install, do not deploy code here)
- **Demo Tenant:** `https://demo.us.borgbackupserver.com`
- **Infrastructure code:** `~/Projects/bbs-docker-hosting/` (separate from this repo, not public)

### Test Client
- **Scooter:** `ssh root@scooter.falconinternet.net` — test client for agent installs and backup testing

## ⚠️ CRITICAL: No Direct sudo in Application Code

**NEVER use `sudo` directly in PHP code.** Production servers do NOT have passwordless sudo configured for www-data.

The ONLY way to execute privileged operations is through `bin/bbs-ssh-helper`:
- All privileged commands MUST go through the SSH helper
- The SSH helper is the ONLY binary with sudoers access for www-data
- Direct `sudo` calls will FAIL silently or error on production systems

**Allowed pattern:**
```php
$helper = __DIR__ . '/../bin/bbs-ssh-helper';
$cmd = "sudo $helper <command> <args>";
```

**FORBIDDEN patterns:**
```php
// NEVER DO THIS - will fail on production!
shell_exec("sudo /some/command");
shell_exec("sudo -u someuser /bin/something");
```

If you need a new privileged operation, ADD IT TO `bin/bbs-ssh-helper` first.

## Git & GitHub Workflow
- **Repo:** `marcpope/borgbackupserver` on GitHub
- **Commit and push:** Commit to `main`, push to origin. Always use descriptive commit messages. **NEVER** add `Co-Authored-By` trailers or any other attribution to Claude in commit messages.
- **Releases:** Tagged as `vX.Y.Z` (e.g., `v1.2.0`). To create a release:
  1. Bump `VERSION` file (only bump `AGENT_VERSION` in `agent/bbs-agent.py` if agent code changed)
  2. Commit the version bump
  3. Tag: `git tag vX.Y.Z`
  4. Push: `git push origin main --tags`
  5. **Create a GitHub Release** (required for the update checker to see it):
     `gh release create vX.Y.Z --title "vX.Y.Z" --notes "Release notes here"`
     The server's "Check for Updates" uses the GitHub Releases API — tags alone are NOT sufficient.
  6. Production servers pull the latest tag via `bbs-update` (without the `main` argument)

## Patterns & Conventions
- **Auth:** `$this->requireAuth()`, `$this->verifyCsrf()`, agent ownership check
- **Flash + redirect:** `$this->flash('success', 'msg'); $this->redirect('/path');`
- **JSON response:** `$this->json(['status' => 'ok', ...])`
- **DB queries:** `$this->db->fetchOne()`, `$this->db->fetchAll()`, `$this->db->insert()`, `$this->db->update()`, `$this->db->delete()`
- **Job queue:** `backup_jobs` table — statuses: queued → sent → running → completed/failed
- **Agent communication:** Agent polls `GET /api/agent/tasks`, reports via `POST /api/agent/status`

## Plugin System
- `plugins` table — master plugin list (slug-based dispatch)
- `agent_plugins` — per-agent enable/disable
- `plugin_configs` — named reusable configurations (e.g., "Production DB")
- `backup_plan_plugins` — links plans to plugin configs via `plugin_config_id`
- Plugin UI lives on the **Plugins tab** of client detail page
- Schema-driven forms via `PluginManager::getPluginSchema()`

## ClickHouse (catalog engine, Docker)
- ClickHouse stores the file catalog (tables in the `bbs` database; port 8123). It runs inside the Docker image.
- **⚠️ ClickHouse is version-PINNED in the `Dockerfile`** via `ARG CLICKHOUSE_VERSION` (+ `apt-mark hold`). **Never** revert to an unpinned `stable` install and never remove the hold.
  - Why: unpinned, every image rebuild silently pulled the latest `stable`. The v2.60.9 rebuild pulled ClickHouse `26.6.1.1193`, which raised its CPU-instruction baseline → `Illegal instruction (core dumped)` on hosts that ran 26.5 fine → ClickHouse never bound port 8123 → catalog import / file-level restore failed and **every backup job errored** (`ClickHouse TSV upload failed: Failed to connect to localhost port 8123`). Root cause was version drift, not app code. Fixed in v2.60.10 by pinning to `26.5.2.39` (#327).
  - To change the version: edit the `CLICKHOUSE_VERSION` ARG only, and **test the new version on an older/baseline CPU** before shipping.
  - Inspect what an image actually shipped: `docker run --rm --entrypoint dpkg-query marcpope/borgbackupserver:<tag> -W clickhouse-server`.

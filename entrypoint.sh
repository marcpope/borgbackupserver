#!/bin/bash
set -e

echo "=== BBS Container Starting ==="
echo "Version: $(cat /var/www/bbs/VERSION 2>/dev/null || echo 'unknown')"

# --- Helper: ensure directory exists with correct owner and permissions ---
ensure_dir() {
    local dir="$1" owner="$2" mode="${3:-755}"
    mkdir -p "$dir"
    chown "$owner" "$dir"
    chmod "$mode" "$dir"
}

# ============================================================
#  UID/GID migration — declarative, idempotent, logged.
# ============================================================
# Persistent volumes carry file ownership across container rebuilds.
# If the user changes PUID/PGID/MYSQL_PUID/CH_PUID between runs, the
# pre-existing files on the volume still reference the old UIDs, so
# we remap the in-container users AND chown the data.
#
# Desired state comes from env vars. The last applied state is stored
# in /var/bbs/config/.ownership (written atomically after success).
# If env != state, we migrate; otherwise this section is a no-op.
#
# Every step is logged with a timestamp so users can see exactly what
# happened and why a container start took longer than usual.
#
# Supported env vars:
#   PUID / PGID              — app (www-data). Defaults: 33 / 33
#   MYSQL_PUID / MYSQL_PGID  — MariaDB.        Defaults: 100 / 100
#   CH_PUID / CH_PGID        — ClickHouse.     Defaults: 999 / 999

log_mig() { echo "[$(date '+%H:%M:%S')] $*"; }

mkdir -p /var/bbs/config
OWNERSHIP_FILE=/var/bbs/config/.ownership

# Defaults come from the actual /etc/passwd inside this image, not hardcoded
# values. apt-get assigns system UIDs dynamically, so clickhouse can be 995 on
# one build and 999 on another — hardcoding would cause false "migrations" that
# look for files owned by a UID that never existed on this volume.
DEFAULT_APP_UID=$(id -u www-data 2>/dev/null || echo 33)
DEFAULT_APP_GID=$(id -g www-data 2>/dev/null || echo 33)
DEFAULT_MYSQL_UID=$(id -u mysql 2>/dev/null || echo 100)
DEFAULT_MYSQL_GID=$(id -g mysql 2>/dev/null || echo 100)
DEFAULT_CH_UID=$(id -u clickhouse 2>/dev/null || echo 999)
DEFAULT_CH_GID=$(id -g clickhouse 2>/dev/null || echo 999)

# --- Load previously-applied state (if any) ---
APP_UID=""; APP_GID=""
MYSQL_UID=""; MYSQL_GID=""
CH_UID=""; CH_GID=""
if [ -f "$OWNERSHIP_FILE" ]; then
    # shellcheck disable=SC1090
    . "$OWNERSHIP_FILE"
fi
PREV_APP_UID="${APP_UID:-$DEFAULT_APP_UID}"
PREV_APP_GID="${APP_GID:-$DEFAULT_APP_GID}"
PREV_MYSQL_UID="${MYSQL_UID:-$DEFAULT_MYSQL_UID}"
PREV_MYSQL_GID="${MYSQL_GID:-$DEFAULT_MYSQL_GID}"
PREV_CH_UID="${CH_UID:-$DEFAULT_CH_UID}"
PREV_CH_GID="${CH_GID:-$DEFAULT_CH_GID}"

# --- Desired state from env (falls back to previous, which falls back to defaults) ---
DESIRED_APP_UID="${PUID:-$PREV_APP_UID}"
DESIRED_APP_GID="${PGID:-$PREV_APP_GID}"
DESIRED_MYSQL_UID="${MYSQL_PUID:-$PREV_MYSQL_UID}"
DESIRED_MYSQL_GID="${MYSQL_PGID:-$PREV_MYSQL_GID}"
DESIRED_CH_UID="${CH_PUID:-$PREV_CH_UID}"
DESIRED_CH_GID="${CH_PGID:-$PREV_CH_GID}"

# --- Preflight guards ---
abort_config() {
    echo ""
    echo "!!! FATAL: invalid UID/GID configuration !!!"
    echo "  $1"
    echo "  Fix the offending env var (e.g. in docker-compose.yml / .env) and restart."
    exit 1
}

for pair in "PUID:$DESIRED_APP_UID" "PGID:$DESIRED_APP_GID" \
            "MYSQL_PUID:$DESIRED_MYSQL_UID" "MYSQL_PGID:$DESIRED_MYSQL_GID" \
            "CH_PUID:$DESIRED_CH_UID" "CH_PGID:$DESIRED_CH_GID"; do
    name="${pair%%:*}"; val="${pair##*:}"
    if [ "$val" = "0" ]; then
        abort_config "$name = 0 (root) is not allowed. Services must not run as root."
    fi
done

if [ "$DESIRED_APP_UID" = "$DESIRED_MYSQL_UID" ]; then
    abort_config "PUID ($DESIRED_APP_UID) collides with MYSQL_PUID. Pick distinct UIDs for each service."
fi
if [ "$DESIRED_APP_UID" = "$DESIRED_CH_UID" ]; then
    abort_config "PUID ($DESIRED_APP_UID) collides with CH_PUID. Pick distinct UIDs for each service."
fi
if [ "$DESIRED_MYSQL_UID" = "$DESIRED_CH_UID" ]; then
    abort_config "MYSQL_PUID ($DESIRED_MYSQL_UID) collides with CH_PUID. Pick distinct UIDs for each service."
fi

# Check collision with existing SSH client UIDs (recorded in /var/bbs/home/*/.uid)
for uidfile in /var/bbs/home/*/.uid; do
    [ -f "$uidfile" ] || continue
    ssh_uid=$(cat "$uidfile" 2>/dev/null | tr -d '[:space:]')
    [ -z "$ssh_uid" ] && continue
    for pair in "PUID:$DESIRED_APP_UID" "MYSQL_PUID:$DESIRED_MYSQL_UID" "CH_PUID:$DESIRED_CH_UID"; do
        name="${pair%%:*}"; val="${pair##*:}"
        if [ "$ssh_uid" = "$val" ]; then
            client_dir=$(dirname "$uidfile")
            abort_config "$name ($val) collides with an existing SSH client (UID stored in $uidfile, home $client_dir). Pick a different value."
        fi
    done
done

# --- Migration helper ---
# Args: service_name display_name old_uid old_gid new_uid new_gid path1 path2 ...
# Remaps the in-container user/group to the new IDs, then chowns files on the
# given volume paths that still reference the old IDs. Only runs if IDs changed.
migrate_service_uid() {
    local service="$1" label="$2"
    local old_uid="$3" old_gid="$4"
    local new_uid="$5" new_gid="$6"
    shift 6
    local paths=("$@")

    if [ "$old_uid" = "$new_uid" ] && [ "$old_gid" = "$new_gid" ]; then
        return 0
    fi

    log_mig ""
    log_mig "--- $label migration ---"
    log_mig "  from: UID=$old_uid GID=$old_gid"
    log_mig "  to:   UID=$new_uid GID=$new_gid"

    # Remap in-container group (if GID changed)
    if [ "$old_gid" != "$new_gid" ]; then
        local clash
        clash=$(getent group "$new_gid" 2>/dev/null | cut -d: -f1)
        if [ -n "$clash" ] && [ "$clash" != "$service" ]; then
            local tmp=$((new_gid + 10000))
            log_mig "  note: GID $new_gid is taken by group '$clash' — moving it to GID $tmp to free the slot"
            groupmod -g "$tmp" "$clash" || abort_config "Failed to move group '$clash' out of GID $new_gid"
        fi
        log_mig "  remapping group '$service' from GID $old_gid to $new_gid"
        groupmod -g "$new_gid" "$service" || abort_config "Failed to set group '$service' to GID $new_gid"
    fi

    # Remap in-container user (if UID changed)
    if [ "$old_uid" != "$new_uid" ]; then
        local clash
        clash=$(getent passwd "$new_uid" 2>/dev/null | cut -d: -f1)
        if [ -n "$clash" ] && [ "$clash" != "$service" ]; then
            local tmp=$((new_uid + 10000))
            log_mig "  note: UID $new_uid is taken by user '$clash' — moving it to UID $tmp to free the slot"
            usermod -u "$tmp" "$clash" || abort_config "Failed to move user '$clash' out of UID $new_uid"
        fi
        log_mig "  remapping user '$service' from UID $old_uid to $new_uid"
        usermod -u "$new_uid" "$service" || abort_config "Failed to set user '$service' to UID $new_uid"
    fi

    # Chown files that still reference the old IDs
    for path in "${paths[@]}"; do
        if [ ! -e "$path" ]; then continue; fi
        local uid_count gid_count total
        uid_count=$(find "$path" -uid "$old_uid" 2>/dev/null | wc -l | tr -d ' ')
        gid_count=$(find "$path" -gid "$old_gid" 2>/dev/null | wc -l | tr -d ' ')
        total=$((uid_count > gid_count ? uid_count : gid_count))
        if [ "$total" = "0" ]; then
            log_mig "  [$path] nothing to chown (already correct)"
            continue
        fi
        log_mig "  [$path] chowning $total entries (UID matches: $uid_count, GID matches: $gid_count)"
        log_mig "          this can take several minutes on large repositories — do not cancel"
        local start end elapsed
        start=$(date +%s)
        find "$path" -uid "$old_uid" -exec chown -h "$new_uid" {} + 2>/dev/null || true
        find "$path" -gid "$old_gid" -exec chgrp -h "$new_gid" {} + 2>/dev/null || true
        end=$(date +%s); elapsed=$((end - start))
        log_mig "  [$path] completed in ${elapsed}s"
    done
}

# --- Run migrations (if any) ---
MIGRATION_NEEDED=0
if [ "$PREV_APP_UID" != "$DESIRED_APP_UID" ] || [ "$PREV_APP_GID" != "$DESIRED_APP_GID" ] \
    || [ "$PREV_MYSQL_UID" != "$DESIRED_MYSQL_UID" ] || [ "$PREV_MYSQL_GID" != "$DESIRED_MYSQL_GID" ] \
    || [ "$PREV_CH_UID" != "$DESIRED_CH_UID" ] || [ "$PREV_CH_GID" != "$DESIRED_CH_GID" ]; then
    MIGRATION_NEEDED=1
fi

if [ "$MIGRATION_NEEDED" = "1" ]; then
    log_mig ""
    log_mig "=== UID/GID migration starting ==="
    log_mig "Volume was configured as: app=${PREV_APP_UID}:${PREV_APP_GID}  mysql=${PREV_MYSQL_UID}:${PREV_MYSQL_GID}  clickhouse=${PREV_CH_UID}:${PREV_CH_GID}"
    log_mig "Reconfiguring to:         app=${DESIRED_APP_UID}:${DESIRED_APP_GID}  mysql=${DESIRED_MYSQL_UID}:${DESIRED_MYSQL_GID}  clickhouse=${DESIRED_CH_UID}:${DESIRED_CH_GID}"

    migrate_service_uid "www-data" "app (www-data)" \
        "$PREV_APP_UID" "$PREV_APP_GID" "$DESIRED_APP_UID" "$DESIRED_APP_GID" \
        /var/bbs/home /var/bbs/cache /var/bbs/backups /var/bbs/tmp /var/bbs/config

    migrate_service_uid "mysql" "MariaDB" \
        "$PREV_MYSQL_UID" "$PREV_MYSQL_GID" "$DESIRED_MYSQL_UID" "$DESIRED_MYSQL_GID" \
        /var/bbs/mysql

    migrate_service_uid "clickhouse" "ClickHouse" \
        "$PREV_CH_UID" "$PREV_CH_GID" "$DESIRED_CH_UID" "$DESIRED_CH_GID" \
        /var/bbs/clickhouse

    # Write new state atomically so a crash mid-migration doesn't leave us
    # thinking the new state is applied when it isn't.
    cat > "${OWNERSHIP_FILE}.tmp" <<EOF
# BBS UID/GID state — managed by entrypoint.sh. Do not edit manually.
# Written after a successful migration on $(date '+%Y-%m-%d %H:%M:%S UTC').
APP_UID=$DESIRED_APP_UID
APP_GID=$DESIRED_APP_GID
MYSQL_UID=$DESIRED_MYSQL_UID
MYSQL_GID=$DESIRED_MYSQL_GID
CH_UID=$DESIRED_CH_UID
CH_GID=$DESIRED_CH_GID
EOF
    mv "${OWNERSHIP_FILE}.tmp" "$OWNERSHIP_FILE"
    chown "$DESIRED_APP_UID:$DESIRED_APP_GID" "$OWNERSHIP_FILE"
    chmod 644 "$OWNERSHIP_FILE"
    log_mig "=== UID/GID migration complete ==="
    log_mig ""
elif [ ! -f "$OWNERSHIP_FILE" ]; then
    # First run on this volume — record baseline so future changes are detected.
    cat > "$OWNERSHIP_FILE" <<EOF
# BBS UID/GID state — managed by entrypoint.sh. Do not edit manually.
# Written on $(date '+%Y-%m-%d %H:%M:%S UTC') (baseline).
APP_UID=$DESIRED_APP_UID
APP_GID=$DESIRED_APP_GID
MYSQL_UID=$DESIRED_MYSQL_UID
MYSQL_GID=$DESIRED_MYSQL_GID
CH_UID=$DESIRED_CH_UID
CH_GID=$DESIRED_CH_GID
EOF
    chown "$DESIRED_APP_UID:$DESIRED_APP_GID" "$OWNERSHIP_FILE"
    chmod 644 "$OWNERSHIP_FILE"
fi

# Expose PUID/PGID as numbers for any downstream references.
PUID="$DESIRED_APP_UID"
PGID="$DESIRED_APP_GID"

# /var/www/bbs lives in the container filesystem (not the volume), so its
# files come from the image baked with UID 33 on every container recreation.
# Re-chown on each start when PUID/PGID differ from the image defaults.
if [ "$PUID" != "33" ] || [ "$PGID" != "33" ]; then
    log_mig "Applying app UID/GID to /var/www/bbs (container filesystem)..."
    chown -R www-data:www-data /var/www/bbs
fi

# --- Storage directories (unified) ---
# All persistent directories are created and permissioned in one place.
# This ensures correct ownership AND write permissions on filesystems
# like btrfs (Synology) that may create directories without write bits.
ensure_dir /var/bbs                 www-data:www-data   755
ensure_dir /var/bbs/home            www-data:www-data   755
ensure_dir /var/bbs/cache           www-data:www-data   755
ensure_dir /var/bbs/backups         www-data:www-data   750
ensure_dir /var/bbs/tmp             www-data:www-data   1777
ensure_dir /var/bbs/config          www-data:www-data   755
ensure_dir /var/bbs/clickhouse      clickhouse:clickhouse 750
ensure_dir /var/bbs/mysql           mysql:mysql         750
ensure_dir /run/mysqld              mysql:mysql         755
ensure_dir /run/sshd                root:root           755
ensure_dir /var/log/clickhouse-server clickhouse:clickhouse 755

# --- Self-heal service data ownership (issue #158) ---
# ensure_dir above only sets ownership on the top-level directory. Volumes
# that were touched by old image builds (which ran `chown -R www-data:www-data
# /var/bbs` at some point) still carry www-data-owned files under
# /var/bbs/clickhouse and /var/bbs/mysql, and those services refuse to start
# on mismatched ownership:
#   Code: 430. DB::Exception: Effective user of the process (clickhouse)
#   does not match the owner of the data (www-data).
# The UID/GID migration block above doesn't catch this case because it keys
# off the recorded OWNERSHIP_FILE, not the actual filesystem state. This
# scan inspects the filesystem directly: if ANY entry under the data dir
# is owned by the wrong user or group, repair it. Idempotent — on a clean
# volume the initial `find ... -print -quit` short-circuits on the first
# check and does no work.
fix_service_dir_ownership() {
    local dir="$1" user="$2" group="${3:-$2}"
    [ -d "$dir" ] || return 0
    local u g
    u=$(id -u "$user" 2>/dev/null) || return 0
    g=$(getent group "$group" | cut -d: -f3 2>/dev/null)
    [ -z "$g" ] && g=$(id -g "$user" 2>/dev/null)
    [ -z "$g" ] && return 0
    # Short-circuit on clean volumes. Flag entries with wrong uid, wrong
    # gid, or mode bits that deny the owner traversal/read. Chown'ing to
    # clickhouse doesn't help if a part directory is mode 000.
    local wrong
    wrong=$(find "$dir" \
        \( ! -uid "$u" -o ! -gid "$g" \
        -o \( -type d ! -perm -0500 \) \
        -o \( -type f ! -perm -0400 \) \
        \) -print -quit 2>/dev/null || true)
    [ -z "$wrong" ] && return 0
    log_mig "Repairing permissions under $dir → $user:$group (first wrong entry: $wrong)"
    log_mig "  this can take a minute on large data dirs — do not cancel"
    # Surface real errors. The original self-heal silenced them, which hid
    # #189: on FUSE filesystems that don't honor chown, the repair would
    # claim success while ClickHouse still couldn't read its own data and
    # failed async table load with a directory_iterator error on one part.
    local fail=0
    find "$dir" ! -uid "$u" -exec chown -h "$u" {} + || fail=1
    find "$dir" ! -gid "$g" -exec chgrp -h "$g" {} + || fail=1
    # u+rX only adds bits (never removes), so this is safe on clean data.
    # Fixes part directories left unreadable by a crash mid-write and any
    # files/dirs where the owner somehow lost read/execute access.
    find "$dir" \
        \( \( -type d ! -perm -0500 \) -o \( -type f ! -perm -0400 \) \) \
        -exec chmod u+rX {} + || fail=1
    if [ "$fail" = "1" ]; then
        log_mig "  !! one or more repair calls failed — see stderr above"
        log_mig "  !! If the data dir is on a FUSE filesystem (e.g. Unraid /mnt/user)"
        log_mig "     that doesn't honor ownership changes, bind-mount from a native"
        log_mig "     path such as /mnt/cache or /mnt/disk1 and restart the container."
    else
        log_mig "  repair complete"
    fi
}
fix_service_dir_ownership /var/bbs/clickhouse clickhouse
fix_service_dir_ownership /var/bbs/mysql      mysql

export TMPDIR=/var/bbs/tmp

# --- SSH host key persistence ---
# Persist host keys on the data volume so agents don't see "host key changed"
# errors after a container rebuild
SSH_KEY_DIR="/var/bbs/.ssh-host-keys"
if [ -d "$SSH_KEY_DIR" ] && [ "$(ls -A $SSH_KEY_DIR 2>/dev/null)" ]; then
    echo "Restoring SSH host keys from volume..."
    cp "$SSH_KEY_DIR"/ssh_host_* /etc/ssh/ 2>/dev/null || true
    chmod 600 /etc/ssh/ssh_host_*_key 2>/dev/null || true
    chmod 644 /etc/ssh/ssh_host_*_key.pub 2>/dev/null || true
else
    echo "Saving SSH host keys to volume..."
    mkdir -p "$SSH_KEY_DIR"
    cp /etc/ssh/ssh_host_* "$SSH_KEY_DIR/" 2>/dev/null || true
fi

# NOTE: sshd is started AFTER SSH users are recreated (see below)

# --- MariaDB ---
MYSQL_DATADIR="/var/bbs/mysql"

echo "Starting MariaDB..."
if [ ! -d "$MYSQL_DATADIR/mysql" ]; then
    echo "Initializing MariaDB data directory..."
    mysql_install_db --user=mysql --datadir="$MYSQL_DATADIR" --skip-test-db > /dev/null 2>&1
fi

# Force MariaDB to use UTC so CURRENT_TIMESTAMP values are consistent
# with what TimeHelper::format() expects, regardless of the Docker host timezone.
mkdir -p /etc/mysql/conf.d
cat > /etc/mysql/conf.d/timezone.cnf << 'MYCNF'
[mysqld]
default-time-zone = '+00:00'
tmpdir = /var/bbs/tmp
MYCNF

mariadbd-safe --datadir="$MYSQL_DATADIR" &
sleep 3

# Wait for MySQL to be ready
echo "Waiting for MariaDB..."
for i in {1..30}; do
    if mysqladmin ping -h localhost --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

# Start ClickHouse (catalog engine)
echo "Starting ClickHouse..."
if command -v clickhouse-server &>/dev/null; then
    mkdir -p /etc/clickhouse-server/config.d
    # Install config override to disable system log tables (reduces idle disk I/O)
    if [ -f "/var/www/bbs/config/clickhouse-server-override.xml" ]; then
        cp /var/www/bbs/config/clickhouse-server-override.xml /etc/clickhouse-server/config.d/bbs-override.xml
    fi
    # Self-heal carried over from #252: containers that previously ran
    # clickhouse-server in --daemon mode left a /var/log/clickhouse-server/
    # stderr.log file behind, sometimes multi-GB after Poco logger
    # rotation got stuck. Newer launches (see below) don't create that
    # file at all — Docker captures stdout/stderr directly. This truncate
    # cleans up any leftover from a prior image so a freshly-upgraded
    # container doesn't carry decades of accumulated noise on the volume.
    for f in /var/log/clickhouse-server/stderr.log /var/log/clickhouse-server/stdout.log; do
        if [ -f "$f" ] && [ "$(stat -c%s "$f" 2>/dev/null || echo 0)" -gt 104857600 ]; then
            echo "  Truncating oversized $(basename "$f") ($(du -h "$f" | cut -f1))"
            : > "$f"
        fi
    done
    # Point ClickHouse data to persistent volume so it survives container recreation
    cat > /etc/clickhouse-server/config.d/bbs-docker-paths.xml << 'CHXML'
<clickhouse>
    <path>/var/bbs/clickhouse/</path>
    <tmp_path>/var/bbs/clickhouse/tmp/</tmp_path>
</clickhouse>
CHXML
    # Launch in the foreground and background it from the entrypoint shell
    # so stdout/stderr are inherited by Docker's log stream instead of
    # being redirected by ClickHouse itself into /var/log/clickhouse-server/
    # stderr.log. The --daemon flag used to redirect its own stderr to a
    # file, which would grow unbounded when Poco's RotateBySizeStrategy
    # got into a bad state (#252, #266) — multi-GB stderr.log files were
    # routine on long-running containers. Bare-metal installs (systemd)
    # never hit this because they run clickhouse-server in the foreground
    # under journald; matching that pattern here gives Docker users the
    # same well-behaved log routing.
    #
    # MISMATCHING_USERS_FOR_PROCESS_AND_DATA and other fatal startup
    # errors still surface — they're now in `docker logs` directly instead
    # of buried in stderr.log (which was the original concern in #158).
    TMPDIR=/var/bbs/tmp sudo -u clickhouse clickhouse-server --config-file=/etc/clickhouse-server/config.xml &
    CH_PID=$!
    for i in {1..30}; do
        curl -sf http://localhost:8123/ping >/dev/null 2>&1 && break
        sleep 1
    done
    if ! curl -sf http://localhost:8123/ping >/dev/null 2>&1; then
        echo "!! ClickHouse did not respond on port 8123 after 30s — catalog features will be disabled"
    fi
    if curl -sf http://localhost:8123/ping >/dev/null 2>&1; then
        echo "  ClickHouse started"
        # Drop old system log tables to reclaim disk space
        for tbl in trace_log text_log metric_log asynchronous_metric_log part_log \
                   processors_profile_log query_log query_thread_log query_views_log \
                   query_metric_log session_log opentelemetry_span_log \
                   asynchronous_insert_log backup_log s3_queue_log blob_storage_log \
                   background_schedule_pool_log error_log; do
            clickhouse-client --query "DROP TABLE IF EXISTS system.$tbl" 2>/dev/null || true
        done
    else
        echo "  Warning: ClickHouse failed to start"
    fi
else
    echo "  Warning: ClickHouse not installed — catalog features will not work"
fi

# --- Application configuration ---
# Extract hostname from APP_URL (strip protocol and trailing path)
SERVER_HOST="$(echo "${APP_URL:-http://localhost}" | sed -E 's|https?://||' | sed 's|/.*||')"

# Persist .env on the data volume so it survives container recreation.
# The APP_KEY inside .env encrypts SSH keys and S3 credentials — if lost,
# all encrypted data becomes unrecoverable.
ENV_VOLUME="/var/bbs/config/.env"
ENV_APP="/var/www/bbs/config/.env"
mkdir -p /var/www/bbs/config

# Migration: move existing .env from container filesystem to volume
if [ -f "$ENV_APP" ] && [ ! -L "$ENV_APP" ] && [ ! -f "$ENV_VOLUME" ]; then
    echo "Migrating .env to persistent volume..."
    cp "$ENV_APP" "$ENV_VOLUME"
    rm -f "$ENV_APP"
fi

# Generate APP_KEY if not provided via env
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(openssl rand -hex 32)"
fi

# Generate a random DB password for new installs
DB_PASS="$(openssl rand -base64 16 | tr -d '/+=' | head -c 20)"

# Create .env on volume if it doesn't exist (first run)
if [ ! -f "$ENV_VOLUME" ]; then
    echo "Creating .env configuration..."
    cat > "$ENV_VOLUME" << EOF
DB_HOST=127.0.0.1
DB_NAME=bbs
DB_USER=bbs
DB_PASS=$DB_PASS
APP_URL=${APP_URL:-http://localhost}
APP_KEY=$APP_KEY
EOF
fi

chown www-data:www-data "$ENV_VOLUME"
chmod 600 "$ENV_VOLUME"

# Symlink so the app reads from the expected path
ln -sf "$ENV_VOLUME" "$ENV_APP"

# Ensure APP_KEY exists in .env (for containers upgraded from older versions)
if ! grep -q '^APP_KEY=' "$ENV_VOLUME" 2>/dev/null; then
    echo "Adding APP_KEY to existing .env..."
    echo "APP_KEY=$APP_KEY" >> "$ENV_VOLUME"
fi

# Add ClickHouse env vars if missing
if ! grep -q 'CLICKHOUSE_HOST' "$ENV_VOLUME" 2>/dev/null; then
    echo "" >> "$ENV_VOLUME"
    echo "CLICKHOUSE_HOST=localhost" >> "$ENV_VOLUME"
    echo "CLICKHOUSE_PORT=8123" >> "$ENV_VOLUME"
    echo "CLICKHOUSE_DB=bbs" >> "$ENV_VOLUME"
fi

# Read DB password from .env (may be the random one we just wrote, or an existing one)
DB_PASS=$(grep '^DB_PASS=' "$ENV_VOLUME" | cut -d= -f2-)

# Create database and user if needed
if ! mysql -e "SELECT 1 FROM mysql.user WHERE user='bbs'" 2>/dev/null | grep -q 1; then
    echo "Creating BBS database and user..."
    mysql -e "CREATE DATABASE IF NOT EXISTS bbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'bbs'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "CREATE USER IF NOT EXISTS 'bbs'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'localhost';"
    mysql -e "GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'127.0.0.1';"
    mysql -e "FLUSH PRIVILEGES;"
fi

# Set permissions on backups (recursive for nested content)
chown -R www-data:www-data /var/bbs/backups

# Create ClickHouse database and tables
if curl -sf http://localhost:8123/ping >/dev/null 2>&1; then
    echo "Setting up ClickHouse catalog tables..."
    clickhouse-client --query "CREATE DATABASE IF NOT EXISTS bbs" 2>/dev/null || true
    if [ -f "/var/www/bbs/schema-clickhouse.sql" ]; then
        clickhouse-client -d bbs --multiquery < /var/www/bbs/schema-clickhouse.sql 2>/dev/null || true
    fi
    echo "  ClickHouse catalog tables ready"
fi

# --- Database setup ---
FRESH_INSTALL=0
if ! mysql -u bbs -p"$DB_PASS" bbs -e "SELECT 1 FROM settings LIMIT 1" 2>/dev/null; then
    FRESH_INSTALL=1
fi

# Import schema on fresh install
if [ -f "/var/www/bbs/schema.sql" ]; then
    if [ "$FRESH_INSTALL" -eq 1 ]; then
        echo "Importing database schema..."
        mysql -u bbs -p"$DB_PASS" bbs < /var/www/bbs/schema.sql
    fi
fi

# Set essential settings
echo "Configuring server settings..."
mysql -u bbs -p"$DB_PASS" bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('storage_path', '/var/bbs/home') ON DUPLICATE KEY UPDATE \`value\` = IF(\`value\` = '' OR \`value\` IS NULL, '/var/bbs/home', \`value\`);"
mysql -u bbs -p"$DB_PASS" bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('server_host', '$SERVER_HOST') ON DUPLICATE KEY UPDATE \`value\` = IF(\`value\` = '' OR \`value\` IS NULL, '$SERVER_HOST', \`value\`);"

# Set SSH port (must match the host-side port mapping for borg agent connections)
if [ -n "$SSH_PORT" ]; then
    # SSH_PORT explicitly provided via env — sync to database and mark Docker setup complete
    mysql -u bbs -p"$DB_PASS" bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('ssh_port', '$SSH_PORT') ON DUPLICATE KEY UPDATE \`value\` = '$SSH_PORT';"
    mysql -u bbs -p"$DB_PASS" bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('docker_setup_complete', '1') ON DUPLICATE KEY UPDATE \`value\` = '1';"
else
    # Not provided — seed default on fresh install, don't overwrite existing
    mysql -u bbs -p"$DB_PASS" bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('ssh_port', '22') ON DUPLICATE KEY UPDATE \`value\` = \`value\`;"
fi

# Set admin password on fresh install
if [ "$FRESH_INSTALL" -eq 1 ]; then
    if [ -z "$ADMIN_PASS" ]; then
        ADMIN_PASS="$(openssl rand -base64 12 | tr -d '/+=' | head -c 16)"
        echo ""
        echo "========================================"
        echo "  ADMIN CREDENTIALS (SAVE THESE!)"
        echo "========================================"
        echo "  URL:      ${APP_URL:-http://localhost}"
        echo "  Username: admin"
        echo "  Password: $ADMIN_PASS"
        echo "========================================"
        echo ""
    fi

    # Generate bcrypt hash and update admin password
    export ADMIN_PASS
    ADMIN_HASH=$(php -r "echo password_hash(\$_SERVER['ADMIN_PASS'], PASSWORD_BCRYPT, ['cost' => 12]);")
    mysql -u bbs -p"$DB_PASS" bbs -e "UPDATE users SET password_hash = '$ADMIN_HASH' WHERE username = 'admin';"
fi

# Run pending migrations
if [ -d "/var/www/bbs/migrations" ]; then
    echo "Running migrations..."
    for migration in /var/www/bbs/migrations/*.sql; do
        if [ -f "$migration" ]; then
            mysql -u bbs -p"$DB_PASS" bbs < "$migration" 2>/dev/null || true
        fi
    done
fi

# Sync borg versions from GitHub on fresh install or if table is empty
BORG_COUNT=$(mysql -u bbs -p"$DB_PASS" bbs -N -e "SELECT COUNT(*) FROM borg_versions" 2>/dev/null || echo "0")
if [ "$BORG_COUNT" -eq 0 ]; then
    echo "Syncing borg versions from GitHub..."
    cd /var/www/bbs && php -r "
        require 'vendor/autoload.php';
        \$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/config');
        \$dotenv->load();
        \$svc = new BBS\Services\BorgVersionService();
        \$result = \$svc->syncVersionsFromGitHub();
        echo isset(\$result['added']) ? \"{$result['added']} versions synced\" : (\$result['error'] ?? 'unknown error');
        echo PHP_EOL;
    " 2>/dev/null || echo "Warning: Could not sync borg versions"
fi

# --- Regenerate allowed-storage-paths from database ---
# This file lives in the container filesystem and is lost on recreation.
# bbs-ssh-helper uses it to validate repo paths outside /var/bbs/.
echo "Regenerating allowed storage paths..."
STORAGE_LOCATIONS=$(mysql -u bbs -p"$DB_PASS" bbs -N -e "SELECT path FROM storage_locations" 2>/dev/null)
if [ -n "$STORAGE_LOCATIONS" ]; then
    mkdir -p /etc/bbs
    echo "$STORAGE_LOCATIONS" > /etc/bbs/allowed-storage-paths
    echo "  $(echo "$STORAGE_LOCATIONS" | wc -l) storage location(s) registered"
fi

# --- Recreate SSH users from database (needed after container restart) ---
# Home directories are named by agent ID (e.g., /var/bbs/home/1), not by username.
# Query the database for the username-to-directory mapping.
STORAGE_PATH=$(mysql -u bbs -p"$DB_PASS" bbs -N -e "SELECT value FROM settings WHERE \`key\` = 'storage_path'" 2>/dev/null)
STORAGE_PATH="${STORAGE_PATH:-/var/bbs/home}"
RESTORED_USERS=0

echo "Recreating SSH users from database..."
mysql -u bbs -p"$DB_PASS" bbs -N -e "SELECT ssh_unix_user, id, IFNULL(ssh_home_dir, '') FROM agents WHERE ssh_unix_user IS NOT NULL AND ssh_unix_user != ''" 2>/dev/null | while read SSH_USER AGENT_ID SSH_HOME_DIR; do
    # Use stored ssh_home_dir if available, fall back to STORAGE_PATH/AGENT_ID for pre-migration agents
    USER_HOME="${SSH_HOME_DIR:-$STORAGE_PATH/$AGENT_ID}"
    [ -d "$USER_HOME" ] || continue

    # If user already exists, just fix ownership (may have been clobbered by old entrypoint)
    if id "$SSH_USER" &>/dev/null; then
        SSH_UID=$(id -u "$SSH_USER")
        SSH_GID=$(id -g "$SSH_USER")
        if [ -d "$USER_HOME/.ssh" ]; then
            chown -R "$SSH_UID:$SSH_GID" "$USER_HOME/.ssh"
        fi
        # Fix per-user borg cache dir (old entrypoint's chown -R on /var/bbs/cache clobbered it)
        CACHE_DIR="/var/bbs/cache/$SSH_USER"
        if [ -d "$CACHE_DIR" ]; then
            chown -R "$SSH_UID:$SSH_GID" "$CACHE_DIR"
        fi
        continue
    fi

    # Get UID from stored file, or allocate a fresh one
    if [ -f "$USER_HOME/.uid" ]; then
        STORED_UID=$(cat "$USER_HOME/.uid")
    else
        # Try directory ownership, but skip if it belongs to an existing user
        # (e.g., www-data uid 33 from old chown -R that clobbered ownership)
        STORED_UID=$(stat -c %u "$USER_HOME" 2>/dev/null || echo "0")
        if [ -z "$STORED_UID" ] || [ "$STORED_UID" = "0" ] || getent passwd "$STORED_UID" >/dev/null 2>&1; then
            # UID is root, empty, or already belongs to another user — allocate fresh
            STORED_UID=$(awk -F: '($3>=1000)&&($3<60000){print $3}' /etc/passwd | sort -n | tail -1)
            STORED_UID=$((STORED_UID + 1))
            [ "$STORED_UID" -lt 1000 ] && STORED_UID=1000
        fi
    fi

    # Validate chosen UID/GID doesn't collide with existing system users or groups
    EXISTING_GROUP=$(getent group "$STORED_UID" 2>/dev/null | cut -d: -f1)
    EXISTING_USER=$(getent passwd "$STORED_UID" 2>/dev/null | cut -d: -f1)
    if { [ -n "$EXISTING_GROUP" ] && [ "$EXISTING_GROUP" != "$SSH_USER" ]; } || \
       { [ -n "$EXISTING_USER" ] && [ "$EXISTING_USER" != "$SSH_USER" ]; }; then
        echo "  UID/GID $STORED_UID collides with existing user/group '$EXISTING_GROUP$EXISTING_USER' — reallocating"
        STORED_UID=$(awk -F: '($3>=1000)&&($3<60000){print $3}' /etc/passwd | sort -n | tail -1)
        STORED_UID=$((STORED_UID + 1))
        [ "$STORED_UID" -lt 1000 ] && STORED_UID=1000
    fi

    # Create group and user with the chosen UID
    groupadd -g "$STORED_UID" "$SSH_USER" 2>/dev/null || true
    useradd -u "$STORED_UID" -g "$STORED_UID" -d "$USER_HOME" -s /bin/bash "$SSH_USER" 2>/dev/null || true

    # Verify the user was actually created before fixing ownership
    if ! id "$SSH_USER" &>/dev/null; then
        echo "  Warning: could not create user $SSH_USER (uid=$STORED_UID) — skipping"
        continue
    fi

    # Fix ownership — home dir user:www-data (750), .ssh dir user:user (700)
    chown "$SSH_USER:www-data" "$USER_HOME"
    chmod 750 "$USER_HOME"
    if [ -d "$USER_HOME/.ssh" ]; then
        chown -R "$STORED_UID:$STORED_UID" "$USER_HOME/.ssh"
        chmod 700 "$USER_HOME/.ssh"
        chmod 600 "$USER_HOME/.ssh/authorized_keys" 2>/dev/null || true
    fi

    # Fix per-user borg cache dir if it exists
    CACHE_DIR="/var/bbs/cache/$SSH_USER"
    if [ -d "$CACHE_DIR" ]; then
        chown -R "$STORED_UID:$STORED_UID" "$CACHE_DIR"
    fi

    # Save UID for future restarts
    echo "$STORED_UID" > "$USER_HOME/.uid"

    echo "  Restored user: $SSH_USER (uid=$STORED_UID)"
done

# --- Legacy SSH compatibility ---
# OpenSSH 10+ dropped ssh-rsa (SHA-1) from default accepted algorithms.
# Re-enable it so agents on older OS (CentOS 6/7, Ubuntu 14/16) can connect.
# This config lives inside the container filesystem and is lost on recreation,
# so we must re-create it every startup (not just during bbs-update).
SSHD_LEGACY="/etc/ssh/sshd_config.d/bbs-legacy-compat.conf"
SSHD_CONF_DIR="/etc/ssh/sshd_config.d"
if [ -d "$SSHD_CONF_DIR" ] && [ ! -f "$SSHD_LEGACY" ]; then
    cat > "$SSHD_LEGACY" <<'SSHEOF'
# Added by BBS to support agents on older OS with legacy SSH clients
HostKeyAlgorithms +ssh-rsa
PubkeyAcceptedAlgorithms +ssh-rsa
SSHEOF
    chmod 644 "$SSHD_LEGACY"
    echo "  Enabled legacy SSH compatibility"
fi

# --- Start SSH server (after users are recreated and config is ready) ---
echo "Starting SSH server..."
/usr/sbin/sshd

# --- Cron ---
echo "Setting up scheduler cron..."
touch /var/log/bbs-scheduler.log
chown www-data:www-data /var/log/bbs-scheduler.log
cat > /etc/cron.d/bbs-scheduler << 'CRON'
TMPDIR=/var/bbs/tmp
* * * * * www-data cd /var/www/bbs && /usr/local/bin/php scheduler.php >> /var/log/bbs-scheduler.log 2>&1
# Save UIDs for any user home dirs that have .ssh/ but no .uid file yet
*/5 * * * * root for d in /var/bbs/home/*/; do [ -d "$d/.ssh" ] && [ ! -f "$d/.uid" ] && stat -c \%u "$d" > "$d/.uid" 2>/dev/null; done
# Cap ClickHouse stderr/stdout at ~50MB. ClickHouse runs as --daemon and
# inherits stderr/stdout to these files; a Poco logger rotation glitch can
# cause a tight loop of multi-line stack traces (#79, #252). Truncate
# rather than rotate — ClickHouse holds the FD open and we don't want to
# orphan it. We keep the file (so the next write goes to offset 0 cleanly)
# instead of unlinking it.
*/5 * * * * root for f in /var/log/clickhouse-server/stderr.log /var/log/clickhouse-server/stdout.log; do [ -f "$f" ] && [ "$(stat -c\%s "$f" 2>/dev/null || echo 0)" -gt 52428800 ] && : > "$f"; done
CRON
chmod 644 /etc/cron.d/bbs-scheduler
cron

echo "=== BBS Container Ready ==="

exec apache2-foreground

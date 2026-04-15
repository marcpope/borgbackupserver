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

# --- UID/GID remapping for bind mount compatibility ---
PUID=${PUID:-33}
PGID=${PGID:-33}

if [ "$PGID" != "33" ]; then
    echo "Remapping www-data group to GID $PGID..."
    # Avoid conflict if GID is already in use by another group
    EXISTING_GROUP=$(getent group "$PGID" 2>/dev/null | cut -d: -f1)
    if [ -n "$EXISTING_GROUP" ] && [ "$EXISTING_GROUP" != "www-data" ]; then
        groupmod -g 9999 "$EXISTING_GROUP"
    fi
    groupmod -g "$PGID" www-data
fi

if [ "$PUID" != "33" ]; then
    echo "Remapping www-data user to UID $PUID..."
    EXISTING_USER=$(getent passwd "$PUID" 2>/dev/null | cut -d: -f1)
    if [ -n "$EXISTING_USER" ] && [ "$EXISTING_USER" != "www-data" ]; then
        usermod -u 9999 "$EXISTING_USER"
    fi
    usermod -u "$PUID" www-data
fi

# Update ownership on static app files (only needed when remapped)
if [ "$PUID" != "33" ] || [ "$PGID" != "33" ]; then
    echo "Updating file ownership for UID=$PUID GID=$PGID..."
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
ensure_dir /var/bbs/clickhouse      clickhouse:clickhouse 755
ensure_dir /var/bbs/mysql           mysql:mysql         755
ensure_dir /run/mysqld              mysql:mysql         755
ensure_dir /run/sshd                root:root           755
ensure_dir /var/log/clickhouse-server clickhouse:clickhouse 755

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
    # Point ClickHouse data to persistent volume so it survives container recreation
    cat > /etc/clickhouse-server/config.d/bbs-docker-paths.xml << 'CHXML'
<clickhouse>
    <path>/var/bbs/clickhouse/</path>
    <tmp_path>/var/bbs/clickhouse/tmp/</tmp_path>
</clickhouse>
CHXML
    TMPDIR=/var/bbs/tmp sudo -u clickhouse clickhouse-server --daemon --config-file=/etc/clickhouse-server/config.xml 2>/dev/null || true
    for i in {1..30}; do
        curl -sf http://localhost:8123/ping >/dev/null 2>&1 && break
        sleep 1
    done
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
CRON
chmod 644 /etc/cron.d/bbs-scheduler
cron

echo "=== BBS Container Ready ==="

exec apache2-foreground

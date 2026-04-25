#!/usr/bin/env bash
#
# Borg Backup Server Agent Installer
# Usage: curl -s https://your-server/get-agent | sudo bash -s -- --server https://your-server --key API_KEY
#
set -e

INSTALL_DIR="/opt/bbs-agent"
CONFIG_DIR="/etc/bbs-agent"
SERVER_URL=""
API_KEY=""

# ═══════════════════════════════════════════════════════════════════════════════
# Colors and formatting
# ═══════════════════════════════════════════════════════════════════════════════
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m' # No Color

# Symbols
CHECK="${GREEN}✓${NC}"
CROSS="${RED}✗${NC}"
ARROW="${CYAN}→${NC}"
BULLET="${DIM}•${NC}"

# ═══════════════════════════════════════════════════════════════════════════════
# Spinner for long-running tasks
# ═══════════════════════════════════════════════════════════════════════════════
spinner_pid=""
spin() {
    local chars="⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏"
    local i=0
    while true; do
        printf "\r  ${CYAN}%s${NC} %s" "${chars:$i:1}" "$1"
        i=$(( (i + 1) % 10 ))
        sleep 0.1
    done
}

start_spinner() {
    spin "$1" &
    spinner_pid=$!
    disown
}

stop_spinner() {
    if [ -n "$spinner_pid" ]; then
        kill "$spinner_pid" 2>/dev/null || true
        wait "$spinner_pid" 2>/dev/null || true
        spinner_pid=""
        printf "\r\033[K"  # Clear line
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Output helpers
# ═══════════════════════════════════════════════════════════════════════════════
print_header() {
    echo ""
    echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${BLUE}║${NC}             ${BOLD}Borg Backup Server — Agent Installer${NC}             ${BOLD}${BLUE}║${NC}"
    echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

print_step() {
    echo -e "  ${ARROW} ${BOLD}$1${NC}"
}

print_success() {
    echo -e "  ${CHECK} $1"
}

print_warning() {
    echo -e "  ${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "  ${CROSS} ${RED}$1${NC}"
}

print_info() {
    echo -e "  ${BULLET} ${DIM}$1${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Parse arguments
# ═══════════════════════════════════════════════════════════════════════════════
while [[ $# -gt 0 ]]; do
    case "$1" in
        --server) SERVER_URL="$2"; shift 2 ;;
        --key)    API_KEY="$2";    shift 2 ;;
        *)        echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
    esac
done

if [ -z "$SERVER_URL" ] || [ -z "$API_KEY" ]; then
    echo -e "${RED}Usage: install.sh --server https://your-server --key API_KEY${NC}"
    exit 1
fi

# Must be root
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root (use sudo)${NC}"
    exit 1
fi

# ═══════════════════════════════════════════════════════════════════════════════
# Detect OS
# ═══════════════════════════════════════════════════════════════════════════════
detect_os() {
    print_step "Detecting operating system..."

    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
        OS_PRETTY=$PRETTY_NAME
    elif [ "$(uname)" = "Darwin" ]; then
        OS="macos"
        OS_VERSION=$(sw_vers -productVersion 2>/dev/null || echo "unknown")
        OS_PRETTY="macOS $OS_VERSION"
    elif [ "$(uname)" = "FreeBSD" ]; then
        OS="freebsd"
        OS_VERSION=$(freebsd-version 2>/dev/null || uname -r)
        OS_PRETTY="FreeBSD $OS_VERSION"
    else
        OS="unknown"
        OS_PRETTY="Unknown OS"
    fi

    print_success "Detected: ${BOLD}$OS_PRETTY${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install borg via OS package manager
# ═══════════════════════════════════════════════════════════════════════════════
install_borg() {
    print_step "Checking for Borg Backup..."

    # Check for borg - prefer /usr/local/bin (where agent installs newer versions)
    local borg_cmd=""
    if [ -x /usr/local/bin/borg ]; then
        borg_cmd="/usr/local/bin/borg"
    elif command -v borg &>/dev/null; then
        borg_cmd="borg"
    fi

    if [ -n "$borg_cmd" ]; then
        local borg_ver=$($borg_cmd --version 2>/dev/null | head -1)
        print_success "Already installed: ${BOLD}$borg_ver${NC}"
        BORG_INSTALLED="existing"
        BORG_VERSION="$borg_ver"
        return
    fi

    print_info "Borg not found, installing via package manager..."
    start_spinner "Installing borgbackup and python3..."

    case "$OS" in
        ubuntu|debian|pop|linuxmint)
            apt-get update -qq >/dev/null 2>&1
            apt-get install -y -qq borgbackup python3 >/dev/null 2>&1
            ;;
        centos|rhel|rocky|almalinux)
            if command -v dnf &>/dev/null; then
                dnf install -y epel-release >/dev/null 2>&1 || true
                dnf config-manager --set-enabled powertools >/dev/null 2>&1 || \
                    dnf config-manager --set-enabled crb >/dev/null 2>&1 || \
                    dnf config-manager --set-enabled PowerTools >/dev/null 2>&1 || true
                dnf install -y python3-packaging >/dev/null 2>&1 || true
                dnf install -y borgbackup python3 >/dev/null 2>&1 || {
                    dnf install -y python3 >/dev/null 2>&1
                }
            else
                yum install -y epel-release >/dev/null 2>&1 || true
                yum install -y python3-packaging >/dev/null 2>&1 || true
                yum install -y borgbackup python3 >/dev/null 2>&1 || {
                    yum install -y python3 >/dev/null 2>&1
                }
            fi
            ;;
        fedora)
            dnf install -y borgbackup python3 >/dev/null 2>&1
            ;;
        arch|manjaro|endeavouros|cachyos)
            pacman -Sy --noconfirm borg python >/dev/null 2>&1
            ;;
        opensuse*|sles)
            zypper install -y borgbackup python3 >/dev/null 2>&1
            ;;
        freebsd)
            pkg install -y curl >/dev/null 2>&1 || true
            pkg install -y borgbackup python3 >/dev/null 2>&1 || {
                # Try versioned package if meta-package not available
                pkg install -y py311-borgbackup python311 >/dev/null 2>&1 || \
                pkg install -y py39-borgbackup python39 >/dev/null 2>&1 || true
            }
            ;;
        macos)
            if command -v brew &>/dev/null; then
                brew install borgbackup python3 >/dev/null 2>&1
            else
                stop_spinner
                print_error "Homebrew required on macOS. Install from https://brew.sh"
                exit 1
            fi
            ;;
        *)
            stop_spinner
            print_error "Unsupported OS '$OS'. Install borg and python3 manually, then re-run."
            exit 1
            ;;
    esac

    stop_spinner

    if command -v borg &>/dev/null; then
        local borg_ver=$(borg --version 2>/dev/null | head -1)
        print_success "Installed: ${BOLD}$borg_ver${NC}"
        BORG_INSTALLED="new"
        BORG_VERSION="$borg_ver"
    else
        print_warning "Borg not available in package manager"
        print_info "Agent will install borg on first run based on server settings"
        BORG_INSTALLED="pending"
        BORG_VERSION="will be installed"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Verify python3 >= 3.4 is available (required for the agent)
# Sets PYTHON3 to the best available python3 path (prefers newest).
# ═══════════════════════════════════════════════════════════════════════════════
PYTHON3=""

check_python3() {
    # Find the best Python 3.4+ available (prefer highest version)
    find_best_python3() {
        local candidates=(
            /opt/rh/rh-python38/root/usr/bin/python3
            /opt/rh/rh-python36/root/usr/bin/python3
            /usr/local/bin/python3
            /usr/local/bin/python3.11
            /usr/local/bin/python3.12
            /usr/local/bin/python3.13
            /usr/local/bin/python3.9
            /usr/bin/python3
        )
        for p in "${candidates[@]}"; do
            if [ -x "$p" ]; then
                local ver
                ver=$("$p" -c 'import sys; print(sys.version_info.minor)' 2>/dev/null) || continue
                if [ "$ver" -ge 4 ] 2>/dev/null; then
                    PYTHON3="$p"
                    return 0
                fi
            fi
        done
        return 1
    }

    if find_best_python3; then
        return
    fi

    # python3 >= 3.4 not found — try to install it
    print_warning "python3 >= 3.4 not found, attempting to install..."

    case "$OS" in
        ubuntu|debian|pop|linuxmint)
            apt-get install -y -qq python3 >/dev/null 2>&1 || true
            ;;
        centos|rhel|rocky|almalinux)
            if command -v dnf &>/dev/null; then
                dnf install -y python3 >/dev/null 2>&1 || true
            else
                yum install -y epel-release >/dev/null 2>&1 || true
                yum install -y python3 >/dev/null 2>&1 || true
            fi
            ;;
        fedora)
            dnf install -y python3 >/dev/null 2>&1 || true
            ;;
        freebsd)
            pkg install -y python3 >/dev/null 2>&1 || \
            pkg install -y python311 >/dev/null 2>&1 || true
            ;;
        macos)
            brew install python3 >/dev/null 2>&1 || true
            ;;
    esac

    if find_best_python3; then
        print_success "python3 found: $PYTHON3"
    else
        print_error "python3 >= 3.4 is required but could not be found."
        print_info "Install python3 manually, then re-run this installer."
        exit 1
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Parse a JSON field (works with or without python3)
# ═══════════════════════════════════════════════════════════════════════════════
parse_json_field() {
    local json="$1"
    local field="$2"

    # Try python3 first (handles all valid JSON correctly)
    local py3=""
    if command -v python3 &>/dev/null; then
        py3="python3"
    elif [ -n "$PYTHON3" ] && [ -x "$PYTHON3" ]; then
        py3="$PYTHON3"
    fi
    if [ -n "$py3" ]; then
        echo "$json" | "$py3" -c "import sys,json; d=json.load(sys.stdin); print(d.get('$field',''))" 2>/dev/null && return
    fi

    # Fallback: simple grep/sed extraction for flat JSON
    echo "$json" | grep -o "\"${field}\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" | sed 's/.*:.*"\(.*\)"/\1/' 2>/dev/null || echo ""
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install agent files
# ═══════════════════════════════════════════════════════════════════════════════
install_agent() {
    print_step "Installing agent files..."

    mkdir -p "$INSTALL_DIR"
    mkdir -p "$CONFIG_DIR"

    start_spinner "Downloading agent from server..."

    # Download agent script from server
    if command -v curl &>/dev/null; then
        curl -sf -o "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    elif command -v fetch &>/dev/null; then
        fetch -q -o "$INSTALL_DIR/bbs-agent.py" "$SERVER_URL/api/agent/download?file=bbs-agent.py"
    else
        stop_spinner
        print_error "curl, wget, or fetch required"
        exit 1
    fi

    chmod +x "$INSTALL_DIR/bbs-agent.py"

    # Download the startup wrapper (provides auto-recovery from bad updates)
    if command -v curl &>/dev/null; then
        curl -sf -o "$INSTALL_DIR/bbs-agent-start.sh" "$SERVER_URL/api/agent/download?file=bbs-agent-start.sh" 2>/dev/null || true
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/bbs-agent-start.sh" "$SERVER_URL/api/agent/download?file=bbs-agent-start.sh" 2>/dev/null || true
    elif command -v fetch &>/dev/null; then
        fetch -q -o "$INSTALL_DIR/bbs-agent-start.sh" "$SERVER_URL/api/agent/download?file=bbs-agent-start.sh" 2>/dev/null || true
    fi
    chmod +x "$INSTALL_DIR/bbs-agent-start.sh" 2>/dev/null || true

    # Download uninstaller
    if command -v curl &>/dev/null; then
        curl -sf -o "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh" 2>/dev/null || true
    elif command -v wget &>/dev/null; then
        wget -q -O "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh" 2>/dev/null || true
    elif command -v fetch &>/dev/null; then
        fetch -q -o "$INSTALL_DIR/uninstall.sh" "$SERVER_URL/api/agent/download?file=uninstall.sh" 2>/dev/null || true
    fi
    chmod +x "$INSTALL_DIR/uninstall.sh" 2>/dev/null || true

    stop_spinner
    print_success "Agent downloaded to ${DIM}$INSTALL_DIR${NC}"

    # Write config
    cat > "$CONFIG_DIR/config.ini" <<EOF
[server]
url = $SERVER_URL
api_key = $API_KEY

[agent]
poll_interval = 30
EOF

    chmod 600 "$CONFIG_DIR/config.ini"
    print_success "Configuration saved to ${DIM}$CONFIG_DIR/config.ini${NC}"
}

# ═══════════════════════════════════════════════════════════════════════════════
# Download SSH key for borg access
# ═══════════════════════════════════════════════════════════════════════════════
install_ssh_key() {
    print_step "Setting up SSH keys..."

    start_spinner "Downloading SSH key from server..."

    local response
    if command -v curl &>/dev/null; then
        response=$(curl -sf -H "Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key" 2>/dev/null || echo "")
    elif command -v wget &>/dev/null; then
        response=$(wget -q -O - --header="Authorization: Bearer $API_KEY" "$SERVER_URL/api/agent/ssh-key" 2>/dev/null || echo "")
    elif command -v fetch &>/dev/null; then
        response=$(fetch -q -o - "$SERVER_URL/api/agent/ssh-key" 2>/dev/null || echo "")
    fi

    stop_spinner

    if [ -z "$response" ]; then
        print_warning "SSH key not available yet"
        print_info "Key will be configured when SSH is provisioned on server"
        SSH_STATUS="pending"
        return
    fi

    # Extract SSH key from JSON response
    local ssh_key
    ssh_key=$(parse_json_field "$response" "ssh_private_key")
    local ssh_host
    ssh_host=$(parse_json_field "$response" "server_host")

    if [ -n "$ssh_key" ] && [ "$ssh_key" != "" ]; then
        echo "$ssh_key" > "$CONFIG_DIR/ssh_key"
        # Check if we have Dropbear ssh (common on embedded devices) and convert if needed
        if ssh -V 2>&1 | grep -q "Dropbear"; then
            if command -v dropbearconvert >/dev/null 2>&1; then 
                print_info "Converting SSH key to Dropbear format"
                if dropbearconvert openssh dropbear "$CONFIG_DIR/ssh_key" "$CONFIG_DIR/ssh_key.dropbear" 2>/dev/null; then
                    mv -f "$CONFIG_DIR/ssh_key.dropbear" "$CONFIG_DIR/ssh_key"
                else
                    print_warning "dropbearconvert failed; leaving key in OpenSSH format"
                    rm -f "$CONFIG_DIR/ssh_key.dropbear"
                fi
            else
                print_warning "dropbearconvert not found; leaving key in OpenSSH format"
                print_info "Install dropbearconvert and re-run installer to convert key if using Dropbear SSH"
            fi
        fi

        chmod 600 "$CONFIG_DIR/ssh_key"
        print_success "SSH key installed to ${DIM}$CONFIG_DIR/ssh_key${NC}"
        SSH_STATUS="installed"

        # Remove stale host keys
        if [ -n "$ssh_host" ]; then
            ssh-keygen -R "$ssh_host" >/dev/null 2>&1 || true
        fi
    else
        print_warning "SSH key not provisioned yet"
        print_info "Key will be configured when you add a repository"
        SSH_STATUS="pending"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Install service
# ═══════════════════════════════════════════════════════════════════════════════
install_service() {
    print_step "Installing system service..."

    if [ "$OS" = "macos" ]; then
        start_spinner "Configuring launchd service..."

        # Download the compiled macOS wrapper binary (handles FDA permissions)
        curl -sf -o "$INSTALL_DIR/bbs-mac-agent" \
            "$SERVER_URL/api/agent/download?file=bbs-mac-agent" 2>/dev/null || true
        chmod 755 "$INSTALL_DIR/bbs-mac-agent"

        # Create a minimal .app bundle so macOS shows it properly in Full Disk Access
        APP_BUNDLE="$INSTALL_DIR/BBS Agent.app"
        mkdir -p "$APP_BUNDLE/Contents/MacOS"
        cp "$INSTALL_DIR/bbs-mac-agent" "$APP_BUNDLE/Contents/MacOS/bbs-mac-agent"
        cat > "$APP_BUNDLE/Contents/Info.plist" <<INFOPLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleIdentifier</key>
    <string>com.borgbackupserver.agent</string>
    <key>CFBundleName</key>
    <string>BBS Agent</string>
    <key>CFBundleExecutable</key>
    <string>bbs-mac-agent</string>
    <key>CFBundleVersion</key>
    <string>1.0</string>
    <key>LSUIElement</key>
    <true/>
</dict>
</plist>
INFOPLIST
        # Code sign the app bundle
        codesign -s - -f --deep "$APP_BUNDLE" 2>/dev/null || true

        # Generate launchd plist pointing to the app bundle's binary
        cat > /Library/LaunchDaemons/com.borgbackupserver.agent.plist <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.borgbackupserver.agent</string>
    <key>ProgramArguments</key>
    <array>
        <string>$APP_BUNDLE/Contents/MacOS/bbs-mac-agent</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>/var/log/bbs-agent.log</string>
    <key>StandardErrorPath</key>
    <string>/var/log/bbs-agent.log</string>
</dict>
</plist>
PLIST

        # Use bootout/bootstrap (modern launchctl) with fallback to load/unload
        launchctl bootout system/com.borgbackupserver.agent 2>/dev/null || \
            launchctl unload /Library/LaunchDaemons/com.borgbackupserver.agent.plist 2>/dev/null || true
        launchctl bootstrap system /Library/LaunchDaemons/com.borgbackupserver.agent.plist 2>/dev/null || \
            launchctl load /Library/LaunchDaemons/com.borgbackupserver.agent.plist

        stop_spinner
        print_success "Service installed ${DIM}(launchd)${NC}"
        SERVICE_TYPE="launchd"
    elif [ "$OS" = "freebsd" ]; then
        start_spinner "Configuring rc.d service..."

        mkdir -p /usr/local/etc/rc.d
        cat > /usr/local/etc/rc.d/bbsagent <<RCEOF
#!/bin/sh

# PROVIDE: bbsagent
# REQUIRE: NETWORKING
# KEYWORD: shutdown

. /etc/rc.subr

name="bbsagent"
rcvar="bbsagent_enable"
pidfile="/var/run/\${name}.pid"

start_cmd="\${name}_start"
stop_cmd="\${name}_stop"
status_cmd="\${name}_status"

bbsagent_start()
{
    echo "Starting \${name}."
    /usr/sbin/daemon -f -p \${pidfile} $PYTHON3 $INSTALL_DIR/bbs-agent.py
}

bbsagent_stop()
{
    if [ -f \${pidfile} ]; then
        echo "Stopping \${name}."
        kill \$(cat \${pidfile}) 2>/dev/null
        rm -f \${pidfile}
    else
        echo "\${name} is not running."
    fi
}

bbsagent_status()
{
    if [ -f \${pidfile} ] && kill -0 \$(cat \${pidfile}) 2>/dev/null; then
        echo "\${name} is running as pid \$(cat \${pidfile})."
    else
        echo "\${name} is not running."
        return 1
    fi
}

load_rc_config \$name
run_rc_command "\$1"
RCEOF

        chmod +x /usr/local/etc/rc.d/bbsagent
        sysrc bbsagent_enable=YES >/dev/null 2>&1
        service bbsagent restart >/dev/null 2>&1 || /usr/local/etc/rc.d/bbsagent restart >/dev/null 2>&1

        stop_spinner
        print_success "Service installed and started ${DIM}(rc.d)${NC}"
        SERVICE_TYPE="rcd"
    elif [ -d /run/systemd/system ] || command -v systemctl &>/dev/null; then
        start_spinner "Configuring systemd service..."

        cat > /etc/systemd/system/bbs-agent.service <<EOF
[Unit]
Description=Borg Backup Server Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Environment=LC_ALL=C.UTF-8 LANG=C.UTF-8 BBS_AGENT_DIR=$INSTALL_DIR BBS_PYTHON=$PYTHON3
ExecStart=/bin/bash $INSTALL_DIR/bbs-agent-start.sh
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
        systemctl daemon-reload >/dev/null 2>&1
        systemctl enable bbs-agent >/dev/null 2>&1
        systemctl restart bbs-agent >/dev/null 2>&1

        stop_spinner
        print_success "Service installed and started ${DIM}(systemd)${NC}"
        SERVICE_TYPE="systemd"
    else
        # SysV init (CentOS 6 and similar)
        start_spinner "Configuring SysV init service..."

        cat > /etc/init.d/bbs-agent <<'INITEOF'
#!/bin/bash
# chkconfig: 2345 95 05
# description: Borg Backup Server Agent
# processname: bbs-agent

### BEGIN INIT INFO
# Provides:          bbs-agent
# Required-Start:    $network $remote_fs
# Required-Stop:     $network $remote_fs
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Description:       Borg Backup Server Agent
### END INIT INFO

INITEOF
        # Append the runtime parts with variable expansion
        cat >> /etc/init.d/bbs-agent <<EOF
PYTHON="$PYTHON3"
AGENT_DIR="$INSTALL_DIR"
WRAPPER="$INSTALL_DIR/bbs-agent-start.sh"
EOF
        cat >> /etc/init.d/bbs-agent <<'INITEOF'
PIDFILE="/var/run/bbs-agent.pid"
LOGFILE="/var/log/bbs-agent.log"

# Set UTF-8 locale if available (some old systems lack C.UTF-8)
if locale -a 2>/dev/null | grep -qi 'c\.utf'; then
    export LC_ALL=C.UTF-8
    export LANG=C.UTF-8
elif locale -a 2>/dev/null | grep -qi 'en_us\.utf'; then
    export LC_ALL=en_US.UTF-8
    export LANG=en_US.UTF-8
fi

export BBS_AGENT_DIR="$AGENT_DIR"
export BBS_PYTHON="$PYTHON"
export BBS_AGENT_LOG="$LOGFILE"

start() {
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "bbs-agent is already running (PID $(cat "$PIDFILE"))"
        return 0
    fi
    echo -n "Starting bbs-agent: "
    nohup bash "$WRAPPER" >> "$LOGFILE" 2>&1 &
    echo $! > "$PIDFILE"
    echo "OK"
}

stop() {
    if [ ! -f "$PIDFILE" ] || ! kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "bbs-agent is not running"
        rm -f "$PIDFILE"
        return 0
    fi
    echo -n "Stopping bbs-agent: "
    kill "$(cat "$PIDFILE")"
    rm -f "$PIDFILE"
    echo "OK"
}

status() {
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "bbs-agent is running (PID $(cat "$PIDFILE"))"
    else
        echo "bbs-agent is not running"
        return 1
    fi
}

restart() {
    stop
    sleep 1
    start
}

case "$1" in
    start)   start ;;
    stop)    stop ;;
    restart) restart ;;
    status)  status ;;
    *)       echo "Usage: $0 {start|stop|restart|status}"; exit 1 ;;
esac
INITEOF

        chmod +x /etc/init.d/bbs-agent
        if command -v chkconfig &>/dev/null; then
            chkconfig --add bbs-agent >/dev/null 2>&1
            chkconfig bbs-agent on >/dev/null 2>&1
        elif command -v update-rc.d &>/dev/null; then
            update-rc.d bbs-agent defaults >/dev/null 2>&1
        fi
        /etc/init.d/bbs-agent restart >/dev/null 2>&1

        stop_spinner
        print_success "Service installed and started ${DIM}(SysV init)${NC}"
        SERVICE_TYPE="sysvinit"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# Print final summary
# ═══════════════════════════════════════════════════════════════════════════════
print_summary() {
    # Re-check borg version - prefer /usr/local/bin (where agent installs newer versions)
    if [ -x /usr/local/bin/borg ]; then
        BORG_VERSION=$(/usr/local/bin/borg --version 2>/dev/null | head -1)
    elif command -v borg &>/dev/null; then
        BORG_VERSION=$(borg --version 2>/dev/null | head -1)
    fi

    echo ""
    echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BOLD}${GREEN}║${NC}                    ${BOLD}${GREEN}Installation Complete!${NC}                    ${BOLD}${GREEN}║${NC}"
    echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""

    # Installation details
    echo -e "  ${BOLD}Installation Details${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${BULLET} Agent location:  ${CYAN}$INSTALL_DIR/bbs-agent.py${NC}"
    echo -e "  ${BULLET} Configuration:   ${CYAN}$CONFIG_DIR/config.ini${NC}"
    echo -e "  ${BULLET} Borg version:    ${CYAN}$BORG_VERSION${NC}"
    echo -e "  ${BULLET} SSH key:         ${CYAN}${SSH_STATUS:-pending}${NC}"
    echo ""

    # Server connection
    echo -e "  ${BOLD}Server Connection${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${BULLET} Server URL:      ${CYAN}$SERVER_URL${NC}"
    echo ""

    # Useful commands
    echo -e "  ${BOLD}Useful Commands${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    if [ "$SERVICE_TYPE" = "launchd" ]; then
        echo -e "  ${BULLET} Check status:    ${YELLOW}sudo launchctl list | grep borgbackupserver${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}tail -f /var/log/bbs-agent.log${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}sudo launchctl kickstart -k system/com.borgbackupserver.agent${NC}"
    elif [ "$SERVICE_TYPE" = "rcd" ]; then
        echo -e "  ${BULLET} Check status:    ${YELLOW}service bbsagent status${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}tail -f /var/log/bbs-agent.log${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}service bbsagent restart${NC}"
    elif [ "$SERVICE_TYPE" = "sysvinit" ]; then
        echo -e "  ${BULLET} Check status:    ${YELLOW}/etc/init.d/bbs-agent status${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}tail -f /var/log/bbs-agent.log${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}/etc/init.d/bbs-agent restart${NC}"
    else
        echo -e "  ${BULLET} Check status:    ${YELLOW}systemctl status bbs-agent${NC}"
        echo -e "  ${BULLET} View logs:       ${YELLOW}journalctl -u bbs-agent -f${NC}"
        echo -e "  ${BULLET} Restart agent:   ${YELLOW}systemctl restart bbs-agent${NC}"
    fi
    echo -e "  ${BULLET} Uninstall:       ${YELLOW}$INSTALL_DIR/uninstall.sh${NC}"
    echo ""

    # macOS Full Disk Access notice
    if [ "$SERVICE_TYPE" = "launchd" ]; then
        echo -e "  ${BOLD}${YELLOW}macOS: Grant Full Disk Access${NC}"
        echo -e "  ─────────────────────────────────────────────────────────────"
        echo -e "  The agent needs Full Disk Access to back up protected directories."
        echo -e "  1. Open ${BOLD}System Settings > Privacy & Security > Full Disk Access${NC}"
        echo -e "  2. Click ${BOLD}+${NC} (unlock with your password if needed)"
        echo -e "  3. Press ${BOLD}Cmd+Shift+G${NC} and type:"
        echo -e "     ${CYAN}$INSTALL_DIR/BBS Agent.app${NC}"
        echo -e "  4. Click Add, then restart the agent:"
        echo -e "     ${YELLOW}sudo launchctl kickstart -k system/com.borgbackupserver.agent${NC}"
        echo ""
    fi

    # Next steps
    echo -e "  ${BOLD}Next Steps${NC}"
    echo -e "  ─────────────────────────────────────────────────────────────"
    echo -e "  ${ARROW} Return to your BBS dashboard to configure backup plans"
    echo -e "  ${ARROW} The agent will automatically check in and appear online"
    echo ""
}

# ═══════════════════════════════════════════════════════════════════════════════
# Main
# ═══════════════════════════════════════════════════════════════════════════════
trap 'stop_spinner' EXIT

print_header
echo -e "  ${BULLET} Server: ${CYAN}$SERVER_URL${NC}"
echo ""

detect_os
install_borg
check_python3
install_agent
install_ssh_key
install_service
print_summary

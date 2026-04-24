#!/bin/sh
# Render /etc/bbs-agent/config.ini from env vars, then exec the agent as PID 1.
set -eu

: "${BBS_SERVER:?BBS_SERVER env var is required (e.g. https://backups.example.com)}"
: "${BBS_API_KEY:?BBS_API_KEY env var is required}"

mkdir -p /etc/bbs-agent

{
    printf '[server]\n'
    printf 'url = %s\n' "${BBS_SERVER%/}"
    printf 'api_key = %s\n' "${BBS_API_KEY}"
    if [ -n "${BBS_POLL_INTERVAL:-}" ]; then
        printf '\n[agent]\n'
        printf 'poll_interval = %s\n' "${BBS_POLL_INTERVAL}"
    fi
} > /etc/bbs-agent/config.ini
chmod 600 /etc/bbs-agent/config.ini

export BBS_AGENT_CONFIG=/etc/bbs-agent/config.ini
export BBS_AGENT_LOG=/dev/stdout

exec python3 /opt/bbs-agent/bbs-agent.py

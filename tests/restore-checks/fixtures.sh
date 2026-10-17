#!/usr/bin/env bash
#
# Synthetic borg repositories for the restore check tests. Run as root
# inside the disposable development container (see README.md); it refuses to
# run anywhere that does not look like one. Everything it creates lives under
# /var/bbs/rc-test, /var/bbs/home/rct* and two test users, and is removed by
# `fixtures.sh clean` or by deleting the container volume.
#
set -euo pipefail

if [ "$(id -u)" != "0" ] || [ ! -f /.dockerenv ] || [ "$(hostname)" = "" ]; then
    echo "fixtures.sh must run as root inside the development container" >&2
    exit 1
fi
if [ "${BBS_RC_TEST:-}" != "1" ]; then
    echo "Set BBS_RC_TEST=1 to confirm this is the disposable test container" >&2
    exit 1
fi

ROOT=/var/bbs/rc-test
LOCAL_USER=bbs-rct1
LOCAL_HOME=/var/bbs/home/rct1
REMOTE_USER=rcremote
REMOTE_HOME=/home/rcremote
PASS_LOCAL='rc-pass-local-Zq8'
PASS_REMOTE='rc-pass-remote-K3w'

if [ "${1:-}" = "clean" ]; then
    rm -rf "$ROOT" "$LOCAL_HOME" "$REMOTE_HOME" /var/bbs/cache/$LOCAL_USER
    userdel "$LOCAL_USER" 2>/dev/null || true
    userdel "$REMOTE_USER" 2>/dev/null || true
    exit 0
fi

rm -rf "$ROOT" "$LOCAL_HOME" "$REMOTE_HOME"
mkdir -p "$ROOT/src" "$LOCAL_HOME" "$REMOTE_HOME"

id "$LOCAL_USER" >/dev/null 2>&1 || useradd -M -d "$LOCAL_HOME" -s /usr/sbin/nologin "$LOCAL_USER"
usermod -a -G www-data "$LOCAL_USER"
id "$REMOTE_USER" >/dev/null 2>&1 || useradd -M -d "$REMOTE_HOME" -s /bin/sh "$REMOTE_USER"
# sshd refuses accounts that are locked; give it an unusable password instead.
usermod -p '*' "$REMOTE_USER"

export BORG_BASE_DIR="$ROOT/borg-base"
export BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=yes

mk() { mkdir -p "$(dirname "$1")"; printf '%s' "$2" > "$1"; }

# --- content set A: names that must be taken literally -----------------------
A="$ROOT/src/a"
mk "$A/etc/hostname" $'rc-test-host\n'
mk "$A/data/we ird/sp ace:colon.txt" 'spaces and a colon'
mk "$A/data/ünïcode-файл.txt" 'unicode'
mk "$A/data/-dash.txt" 'leading dash'
mk "$A/data/empty.txt" ''
mk "$A/data/sh:*" 'a file literally named sh:*'
mk "$A/data/sh_x" 'must not be selected by sh:*'
mk "$A/data/dir/a.txt" 'a'
mk "$A/data/dir/sub/b.txt" 'b'
mk "$A/data/<script>alert(1)<"'!'"--x.txt" 'markup in a name'
mk "$A/data/mode000.txt" 'unreadable until granted'
chmod 000 "$A/data/mode000.txt"
mkdir -p "$A/data/locked"; mk "$A/data/locked/inner.txt" 'inside a 000 dir'; chmod 000 "$A/data/locked"

# --- content set B: a second backup plan in the same repository -----------
B="$ROOT/src/b"
mk "$B/etc/hostname" $'plan-b-host\n'
mk "$B/srv/b-only.txt" 'only in plan B'

# --- full archive coverage ------------------------------------------------
F="$ROOT/src/full"
mk "$F/docs/readme.txt" 'readme'
mkdir -p "$F/empty-dir/nested-empty"
ln -s readme.txt "$F/docs/link-to-readme"
ln -s /nonexistent/absolute "$F/docs/dangling-absolute"
mk "$F/docs/hard-1" 'hardlinked content'
ln "$F/docs/hard-1" "$F/docs/hard-2"
truncate -s 8M "$F/docs/sparse.bin"
mkfifo "$F/docs/a-fifo"
head -c 3000000 /dev/urandom > "$F/docs/random.bin"

D="$ROOT/src/dev"
mk "$D/x.txt" 'x'
mknod "$D/null-like" c 1 3
printf 'latin-1 name' > "$D/caf"$'\xe9'".txt"

# --- symlink-parent escape attempt -----------------------------------------
mkdir -p "$ROOT/outside"
echo ORIGINAL > "$ROOT/outside/sentinel"
chown "$LOCAL_USER" "$ROOT/outside"
E="$ROOT/src/evil"
mkdir -p "$E"
ln -s "$ROOT/outside" "$E/escape"
# The same, under a name that is not valid UTF-8 (one raw 0xff byte).
E2="$ROOT/src/evil2"
mkdir -p "$E2"
BAD=$'bad\xffname'
ln -s "$ROOT/outside" "$E2/$BAD"

# --- repositories ----------------------------------------------------------
create() { # repo archive dir [extra...]
    local repo="$1" archive="$2" dir="$3"; shift 3
    (cd "$dir" && borg create --numeric-ids "$repo::$archive" "${@:-.}" >/dev/null 2>&1) || {
        local rc=$?; [ $rc -eq 1 ] || { echo "borg create $archive failed ($rc)" >&2; exit 1; }
    }
}

export BORG_PASSPHRASE="$PASS_LOCAL"
for r in main full dev evil evil2; do
    borg init -e repokey-blake2 "$LOCAL_HOME/repo-$r" >/dev/null 2>&1
done
create "$LOCAL_HOME/repo-main" plan-a-2026-10-01 "$A"
sleep 1
create "$LOCAL_HOME/repo-main" plan-b-2026-10-01 "$B"
sleep 1
create "$LOCAL_HOME/repo-main" plan-a-2026-10-02 "$A"
create "$LOCAL_HOME/repo-full" full-2026-10-02 "$F"
create "$LOCAL_HOME/repo-dev" dev-2026-10-02 "$D"
# The archive stores escape (a symlink) and escape/sentinel through it.
create "$LOCAL_HOME/repo-evil" evil-2026-10-02 "$E" escape escape/sentinel
# borg 1.4 cannot store a non-UTF-8 command line, so the paths go via stdin.
(cd "$E2" && printf '%s\n%s\n' "$BAD" "$BAD/sentinel" | borg create --numeric-ids --paths-from-stdin "$LOCAL_HOME/repo-evil2::evil2-2026-10-02" >/dev/null 2>&1)

chown -R "$LOCAL_USER":www-data "$LOCAL_HOME"
chmod 750 "$LOCAL_HOME"
mkdir -p /var/bbs/cache/$LOCAL_USER && chown "$LOCAL_USER":"$LOCAL_USER" /var/bbs/cache/$LOCAL_USER

# --- remote SSH repository on this same host -------------------------------
export BORG_PASSPHRASE="$PASS_REMOTE"
borg init -e repokey-blake2 "$REMOTE_HOME/repo-remote" >/dev/null 2>&1
create "$REMOTE_HOME/repo-remote" remote-2026-10-02 "$A"
mkdir -p "$REMOTE_HOME/.ssh"
rm -f "$ROOT/remote_key" "$ROOT/remote_key.pub" "$ROOT/wrong_key" "$ROOT/wrong_key.pub"
ssh-keygen -q -t ed25519 -N '' -f "$ROOT/remote_key"
ssh-keygen -q -t ed25519 -N '' -f "$ROOT/wrong_key"
cp "$ROOT/remote_key.pub" "$REMOTE_HOME/.ssh/authorized_keys"
chown -R "$REMOTE_USER":"$REMOTE_USER" "$REMOTE_HOME"
chmod 700 "$REMOTE_HOME" "$REMOTE_HOME/.ssh"
chmod 600 "$REMOTE_HOME/.ssh/authorized_keys"
chmod 644 "$ROOT/remote_key" "$ROOT/wrong_key"   # read by the www-data test harness only

unset BORG_PASSPHRASE
chmod 755 "$ROOT"
cat > "$ROOT/fixtures.json" <<JSON
{
  "local_user": "$LOCAL_USER",
  "local_home": "$LOCAL_HOME",
  "pass_local": "$PASS_LOCAL",
  "pass_remote": "$PASS_REMOTE",
  "remote_user": "$REMOTE_USER",
  "remote_home": "$REMOTE_HOME",
  "remote_key": "$ROOT/remote_key",
  "wrong_key": "$ROOT/wrong_key",
  "sentinel": "$ROOT/outside/sentinel",
  "hostname_sha256": "$(sha256sum "$A/etc/hostname" | cut -d' ' -f1)"
}
JSON
echo "fixtures ready"

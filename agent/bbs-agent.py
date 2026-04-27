#!/usr/bin/env python3
"""
Borg Backup Server Agent
Polls the BBS server for tasks, executes borg commands, reports progress/status.
"""

import datetime
import json
import logging
import os
import platform
import shlex
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from configparser import ConfigParser

# Python 3.4 compatibility: subprocess.run() was added in Python 3.5.
# Provide a minimal polyfill so all code can use subprocess.run() uniformly.
if not hasattr(subprocess, "run"):
    class _CompletedProcess(object):
        def __init__(self, args, returncode, stdout=None, stderr=None):
            self.args = args
            self.returncode = returncode
            self.stdout = stdout
            self.stderr = stderr

    def _subprocess_run(cmd, stdin=None, stdout=None, stderr=None, timeout=None,
                        env=None, cwd=None, universal_newlines=False, **kwargs):
        proc = subprocess.Popen(cmd, stdin=stdin, stdout=stdout, stderr=stderr,
                                env=env, cwd=cwd, universal_newlines=universal_newlines)
        try:
            out, err = proc.communicate(timeout=timeout)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.communicate()
            raise
        return _CompletedProcess(cmd, proc.returncode, out, err)

    subprocess.run = _subprocess_run
    subprocess.CompletedProcess = _CompletedProcess

AGENT_VERSION = "2.29.6"
BORG_PATH = None  # Resolved in get_system_info()
IS_WINDOWS = sys.platform == "win32"

# Ensure UTF-8 filesystem encoding for handling filenames with non-ASCII characters.
# CentOS 7 and older systems may default to ASCII, causing encoding errors.
# Setting env vars after Python starts does NOT change sys.getfilesystemencoding(),
# so we must re-exec with the correct locale if needed.
if not IS_WINDOWS and sys.getfilesystemencoding().lower() in ("ascii", "ansi_x3.4-1968") and \
   not os.environ.get("_BBS_LOCALE_RETRY"):
    os.environ["_BBS_LOCALE_RETRY"] = "1"
    import locale
    for loc in ("C.UTF-8", "en_US.UTF-8", "en_US.utf8"):
        try:
            locale.setlocale(locale.LC_ALL, loc)
            os.environ["LC_ALL"] = loc
            os.environ["LANG"] = loc
            os.execv(sys.executable, [sys.executable] + sys.argv)
        except locale.Error:
            continue

# Platform-specific paths
if IS_WINDOWS:
    _APPDATA = os.environ.get("ProgramData", r"C:\ProgramData")
    _AGENT_DIR = os.path.join(_APPDATA, "bbs-agent")
    CONFIG_PATH      = os.path.join(_AGENT_DIR, "config.ini")
    LOG_PATH         = os.path.join(_AGENT_DIR, "bbs-agent.log")
    SSH_KEY_PATH     = os.path.join(_AGENT_DIR, "ssh_key")
    BORG_SOURCE_PATH = os.path.join(_AGENT_DIR, "borg_source")
    SSH_INFO_PATH    = os.path.join(_AGENT_DIR, "ssh_info.json")
    REMOTE_KEY_PATH  = os.path.join(os.environ.get("TEMP", "."), "bbs-remote-ssh-key")
    _SSH_PATH_FILE   = os.path.join(_AGENT_DIR, "ssh-path")
else:
    CONFIG_PATH      = "/etc/bbs-agent/config.ini"
    LOG_PATH         = "/var/log/bbs-agent.log"
    SSH_KEY_PATH     = "/etc/bbs-agent/ssh_key"
    BORG_SOURCE_PATH = "/etc/bbs-agent/borg_source"
    SSH_INFO_PATH    = "/etc/bbs-agent/ssh_info.json"
    REMOTE_KEY_PATH  = "/tmp/bbs-remote-ssh-key"

# Allow overrides for development
if os.environ.get("BBS_AGENT_CONFIG"):
    CONFIG_PATH = os.environ["BBS_AGENT_CONFIG"]
if os.environ.get("BBS_AGENT_LOG"):
    LOG_PATH = os.environ["BBS_AGENT_LOG"]

logger = logging.getLogger("bbs-agent")
running = True
task_running = False  # Set True while executing a task, enables heartbeat thread
current_job_id = None  # Job ID of currently executing task (for stall check response)
current_borg_proc = None  # The running borg subprocess, set by execute_task for stall-kill

# Resolve the SSH executable for Windows. The built-in Windows OpenSSH client
# has a stdin forwarding bug that hangs borg over ssh:// repos. The installer
# writes the path to a known-good ssh.exe (Git for Windows or bundled MinGit)
# to a file; we read it once at import time.
SSH_CMD = "ssh"
if IS_WINDOWS:
    try:
        _ssh_path = open(_SSH_PATH_FILE).read().strip()
        if _ssh_path and os.path.isfile(_ssh_path):
            SSH_CMD = _ssh_path.replace("\\", "/")
    except (FileNotFoundError, OSError):
        pass


def _lockdown_key_windows(path):
    """Set SSH key permissions so only SYSTEM and Administrators can read it.

    Uses PowerShell to create a clean ACL from scratch — no leftover ACEs.
    SIDs are used instead of group names for locale independence.
    Falls back to icacls if PowerShell is unavailable.
    """
    # PowerShell: build a fresh ACL with only SYSTEM + Administrators read access
    ps_cmd = (
        "$acl = New-Object System.Security.AccessControl.FileSecurity; "
        "$acl.SetAccessRuleProtection($true, $false); "
        "$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule("
        "(New-Object System.Security.Principal.SecurityIdentifier 'S-1-5-18'), "
        "[System.Security.AccessControl.FileSystemRights]::Read, "
        "[System.Security.AccessControl.AccessControlType]::Allow))); "
        "$acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule("
        "(New-Object System.Security.Principal.SecurityIdentifier 'S-1-5-32-544'), "
        "[System.Security.AccessControl.FileSystemRights]::Read, "
        "[System.Security.AccessControl.AccessControlType]::Allow))); "
        "Set-Acl -Path '{}' -AclObject $acl"
    ).format(path.replace("'", "''"))
    result = subprocess.run(
        ["powershell", "-NoProfile", "-NonInteractive", "-Command", ps_cmd],
        stdout=subprocess.DEVNULL, stderr=subprocess.PIPE
    )
    if result.returncode == 0:
        return

    # Fallback: icacls with well-known SIDs
    logger.warning("PowerShell ACL set failed (rc={}), falling back to icacls".format(result.returncode))
    subprocess.run(
        ["icacls", path, "/inheritance:r"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
    )
    for sid in [
        "*S-1-5-32-545", "*S-1-5-11", "*S-1-1-0",
        "*S-1-5-32-546", "*S-1-3-0", "*S-1-3-1",
    ]:
        subprocess.run(
            ["icacls", path, "/remove", sid],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
        )
    subprocess.run(
        ["icacls", path, "/grant:r", "*S-1-5-18:(R)", "*S-1-5-32-544:(R)"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
    )


def _verify_key_readable(path):
    """Verify the SSH key file is readable and log diagnostics if not."""
    try:
        with open(path, "r") as f:
            content = f.read(32)
        if content:
            logger.info("SSH key file is readable ({})".format(path))
            return True
    except PermissionError:
        logger.error("SSH key file NOT readable (PermissionError): {}".format(path))
    except Exception as e:
        logger.error("SSH key file read check failed: {}".format(e))
    # Log current ACL for diagnostics
    try:
        result = subprocess.run(
            ["icacls", path], stdout=subprocess.PIPE, stderr=subprocess.PIPE
        )
        acl_output = result.stdout.decode("utf-8", errors="replace").strip()
        logger.error("Current ACL: {}".format(acl_output))
    except Exception:
        pass
    return False


def setup_logging():
    log_dir = os.path.dirname(LOG_PATH)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir, exist_ok=True)

    handlers = [logging.FileHandler(LOG_PATH, encoding="utf-8")]
    # Only add stdout handler if stdout is a real terminal (not redirected to the
    # same log file by launchd/systemd, which would cause duplicate lines)
    try:
        is_tty = os.isatty(sys.stdout.fileno())
    except Exception:
        is_tty = False
    if is_tty:
        handlers.append(logging.StreamHandler(sys.stdout))
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=handlers,
    )


def load_ssh_info():
    """Load SSH connection info for catalog streaming."""
    if not os.path.exists(SSH_INFO_PATH):
        return None
    try:
        with open(SSH_INFO_PATH, "r") as f:
            return json.load(f)
    except Exception:
        return None


def load_config():
    if not os.path.exists(CONFIG_PATH):
        logger.error("Config file not found: {}".format(CONFIG_PATH))
        sys.exit(1)

    config = ConfigParser()
    config.read(CONFIG_PATH)

    return {
        "server_url": config.get("server", "url").rstrip("/"),
        "api_key": config.get("server", "api_key"),
        "poll_interval": config.getint("agent", "poll_interval", fallback=30),
    }


def api_request(config, endpoint, method="GET", data=None, timeout=60):
    """Make an authenticated request to the BBS server."""
    url = "{}{}".format(config['server_url'], endpoint)
    headers = {
        "Authorization": "Bearer {}".format(config['api_key']),
        "Content-Type": "application/json",
    }

    body = None
    if data is not None:
        body = json.dumps(data).encode("utf-8")

    req = urllib.request.Request(url, data=body, headers=headers, method=method)

    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        error_body = e.read().decode("utf-8", errors="replace")
        logger.error("API error {} on {}: {}".format(e.code, endpoint, error_body))
        return None
    except urllib.error.URLError as e:
        logger.error("Connection error on {}: {}".format(endpoint, e.reason))
        return None
    except Exception as e:
        logger.error("Request error on {}: {}".format(endpoint, e))
        return None


def report_status(config, data, timeout=600):
    """Report task status to server with retry on failure.

    Status reports are critical — a lost report leaves the job stuck in
    'running' on the server forever. Retries with exponential backoff.
    """
    max_retries = 5
    for attempt in range(max_retries + 1):
        result = api_request(config, "/api/agent/status", method="POST", data=data, timeout=timeout)
        if result is not None:
            return result
        if attempt < max_retries:
            wait = min(5 * (2 ** attempt), 120)  # 5, 10, 20, 40, 80s
            logger.warning("Status report failed (attempt {}/{}), retrying in {}s...".format(
                attempt + 1, max_retries + 1, wait))
            time.sleep(wait)
    logger.error("Status report failed after {} attempts for job #{}".format(
        max_retries + 1, data.get("job_id", "?")))
    return None


def get_borg_source():
    """Get the stored borg source (official/server/unknown)."""
    try:
        if os.path.exists(BORG_SOURCE_PATH):
            with open(BORG_SOURCE_PATH) as f:
                source = f.read().strip()
                if source in ("official", "server"):
                    return source
    except Exception:
        pass
    return "unknown"


def set_borg_source(source):
    """Store the borg source for future reporting."""
    try:
        os.makedirs(os.path.dirname(BORG_SOURCE_PATH), exist_ok=True)
        with open(BORG_SOURCE_PATH, "w") as f:
            f.write(source)
    except Exception as e:
        logger.warning("Failed to save borg source: {}".format(e))


def get_system_info():
    """Gather system information for registration."""
    info = {
        "hostname": socket.gethostname() or socket.getfqdn(),
        "os_info": "{} {} {}".format(platform.system(), platform.release(), platform.machine()),
        "agent_version": AGENT_VERSION,
    }

    # Try to get more detailed OS info
    if IS_WINDOWS:
        info["os_info"] = "{} {} {}".format(platform.system(), platform.version(), platform.machine())
    else:
        try:
            with open("/etc/os-release") as f:
                os_release = {}
                for line in f:
                    if "=" in line:
                        key, val = line.strip().split("=", 1)
                        os_release[key] = val.strip('"')
                if "PRETTY_NAME" in os_release:
                    info["os_info"] = "{} {}".format(os_release['PRETTY_NAME'], platform.machine())
        except FileNotFoundError:
            pass

    # Platform and architecture info (for borg binary matching)
    info["platform"] = platform.system().lower()  # linux, darwin, freebsd, windows
    arch = platform.machine()
    if arch in ("aarch64", "arm64"):
        info["architecture"] = "arm64"
    elif arch in ("x86_64", "amd64"):
        info["architecture"] = "x86_64"
    else:
        info["architecture"] = arch

    # Detect glibc version on Linux (for binary compatibility matching)
    if info["platform"] == "linux":
        try:
            import ctypes
            libc = ctypes.CDLL("libc.so.6")
            gnu_get_libc_version = libc.gnu_get_libc_version
            gnu_get_libc_version.restype = ctypes.c_char_p
            glibc_ver = gnu_get_libc_version().decode("utf-8")
            # Convert "2.31" to "glibc231"
            info["glibc_version"] = "glibc" + glibc_ver.replace(".", "")
        except Exception:
            info["glibc_version"] = None

    # Get borg version and detect installation method
    global BORG_PATH
    borg_path = None
    if IS_WINDOWS:
        candidates = [r"C:\Program Files\BorgBackup\borg.exe", r"C:\Program Files (x86)\BorgBackup\borg.exe"]
    else:
        candidates = ["/usr/local/bin/borg", "/usr/bin/borg", "/opt/homebrew/bin/borg",
                      os.path.expanduser("~/.local/bin/borg"), "/root/.local/bin/borg"]
    for candidate in candidates:
        if os.path.exists(candidate):
            borg_path = candidate
            break
    if not borg_path:
        try:
            which_cmd = "where" if IS_WINDOWS else "which"
            which_result = subprocess.run(
                [which_cmd, "borg"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5
            )
            if which_result.returncode == 0:
                borg_path = which_result.stdout.decode("utf-8", errors="replace").strip()
        except Exception:
            pass

    BORG_PATH = borg_path
    if borg_path:
        info["borg_binary_path"] = borg_path
        # Detect install method based on path
        if "/usr/local/bin" in borg_path:
            info["borg_install_method"] = "binary"
        elif "site-packages" in borg_path or ".local/bin" in borg_path:
            info["borg_install_method"] = "pip"
        else:
            info["borg_install_method"] = "package"
    else:
        info["borg_install_method"] = "unknown"

    # Get borg source (official/server) from stored state
    info["borg_source"] = get_borg_source()

    try:
        borg_cmd = borg_path if borg_path else "borg"
        result = subprocess.run(
            [borg_cmd, "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if result.returncode == 0:
            info["borg_version"] = result.stdout.decode("utf-8", errors="replace").strip().replace("borg ", "")
    except FileNotFoundError:
        logger.warning("borg not found in PATH")
        info["borg_version"] = "not installed"
    except Exception:
        pass

    # Get primary IP
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("8.8.8.8", 80))
        info["ip_address"] = s.getsockname()[0]
        s.close()
    except Exception:
        pass

    return info


def register(config):
    """Register this agent with the server."""
    info = get_system_info()
    logger.info("Registering with server: {}".format(config['server_url']))

    result = api_request(config, "/api/agent/register", method="POST", data=info)

    if result and result.get("status") == "ok":
        logger.info(
            "Registered as agent #{} ({})".format(result['agent_id'], result.get('name', ''))
        )
        # Update poll interval from server
        if "poll_interval" in result:
            config["poll_interval"] = result["poll_interval"]

        # Update SSH connection info from server (handles server changes/re-installs)
        if result.get("server_host") and result.get("ssh_unix_user"):
            _save_ssh_info(result)

        # Download SSH key for borg SSH access
        download_ssh_key(config)

        # Test SSH connectivity and re-download key if broken
        test_ssh_connection(config)

        return True
    else:
        logger.error("Registration failed")
        return False


def download_ssh_key(config):
    """Download SSH private key from the server if not already present."""
    have_key = os.path.exists(SSH_KEY_PATH)
    have_info = os.path.exists(SSH_INFO_PATH)

    if have_key and have_info:
        if IS_WINDOWS:
            _lockdown_key_windows(SSH_KEY_PATH)
            _verify_key_readable(SSH_KEY_PATH)
        return True

    if have_key and not have_info:
        # Key exists but ssh_info.json missing (upgraded from older agent).
        # Fetch connection info from server without re-downloading the key.
        logger.info("SSH key present but ssh_info.json missing -- fetching connection info")
        result = api_request(config, "/api/agent/ssh-key")
        if result and result.get("status") == "ok":
            _save_ssh_info(result)
        return True

    logger.info("Downloading SSH key from server...")
    result = api_request(config, "/api/agent/ssh-key")

    if not result or result.get("status") != "ok":
        logger.warning("No SSH key available from server (may not be provisioned yet)")
        return False

    private_key = result.get("ssh_private_key", "")
    if not private_key:
        logger.warning("Server returned empty SSH key")
        return False

    try:
        key_dir = os.path.dirname(SSH_KEY_PATH)
        os.makedirs(key_dir, exist_ok=True)
        with open(SSH_KEY_PATH, "w") as f:
            f.write(private_key)
        if IS_WINDOWS:
            _lockdown_key_windows(SSH_KEY_PATH)
        else:
            os.chmod(SSH_KEY_PATH, 0o600)
            _maybe_convert_dropbear_key(SSH_KEY_PATH)
        logger.info("SSH key saved to {}".format(SSH_KEY_PATH))
        _save_ssh_info(result)
        return True
    except Exception as e:
        logger.error("Failed to save SSH key: {}".format(e))
        return False


def _maybe_convert_dropbear_key(key_path):
    """If the local SSH client is Dropbear (common on embedded systems
    like OpenATV/Enigma2 receivers), convert the OpenSSH-format key the
    server hands us into Dropbear's binary format. install.sh does this
    once on first install, but the agent re-downloads on auth failure
    after a server-side key rotation — without a runtime conversion the
    rewritten OpenSSH key would be unreadable by dbclient and the agent
    would loop. Mirrors the install.sh fallback: warn and leave the key
    in OpenSSH format if dropbearconvert isn't available."""
    try:
        ver = subprocess.run(["ssh", "-V"], stdout=subprocess.PIPE,
                             stderr=subprocess.STDOUT, timeout=5)
        banner = (ver.stdout or b"").decode("utf-8", errors="replace")
        if "Dropbear" not in banner:
            return
    except Exception:
        return

    try:
        which = subprocess.run(["dropbearconvert", "-h"],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=5)
        if which.returncode not in (0, 1):  # -h often exits 1 but proves the binary exists
            raise FileNotFoundError
    except FileNotFoundError:
        logger.warning("Dropbear SSH detected but dropbearconvert not found; "
                       "key left in OpenSSH format (may not authenticate)")
        return
    except Exception:
        return

    tmp_path = key_path + ".dropbear"
    try:
        conv = subprocess.run(
            ["dropbearconvert", "openssh", "dropbear", key_path, tmp_path],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        if conv.returncode == 0 and os.path.exists(tmp_path):
            os.replace(tmp_path, key_path)
            os.chmod(key_path, 0o600)
            logger.info("Converted SSH key to Dropbear format")
        else:
            err = conv.stderr.decode("utf-8", errors="replace").strip()
            logger.warning("dropbearconvert failed: {}".format(err[:200]))
            try: os.unlink(tmp_path)
            except OSError: pass
    except Exception as e:
        logger.warning("Dropbear key conversion error: {}".format(e))
        try: os.unlink(tmp_path)
        except OSError: pass


def _save_ssh_info(result):
    """Save SSH connection info for catalog streaming."""
    ssh_user = result.get("ssh_unix_user", "")
    server_host = result.get("server_host", "")
    ssh_port = result.get("ssh_port", 22)
    if ssh_user and server_host:
        ssh_info = {
            "ssh_unix_user": ssh_user,
            "server_host": server_host,
            "ssh_port": ssh_port,
        }
        with open(SSH_INFO_PATH, "w") as f:
            json.dump(ssh_info, f)
        logger.info("SSH configured: {}@{}:{}".format(ssh_user, server_host, ssh_port))


def test_ssh_connection(config):
    """Test SSH connectivity to the server and re-download key if broken."""
    ssh_info = load_ssh_info()
    if not ssh_info or not os.path.exists(SSH_KEY_PATH):
        return  # No SSH setup yet, nothing to test

    ssh_user = ssh_info.get("ssh_unix_user", "")
    server_host = ssh_info.get("server_host", "")
    ssh_port = str(ssh_info.get("ssh_port", 22))
    if not ssh_user or not server_host:
        return

    def _try_ssh():
        """Attempt SSH connection, return (success, stderr_output).

        Uses 'ping' command via bbs-ssh-gate. Return code 0 means
        auth + command both succeeded. 'command not allowed' means
        auth succeeded but gate doesn't support ping yet (still OK).
        'Permission denied' means auth actually failed (key mismatch).
        """
        try:
            known_hosts_null = "NUL" if IS_WINDOWS else "/dev/null"
            proc = subprocess.Popen(
                [
                    SSH_CMD,
                    "-i", SSH_KEY_PATH,
                    "-p", ssh_port,
                    "-o", "StrictHostKeyChecking=no",
                    "-o", "UserKnownHostsFile={}".format(known_hosts_null),
                    "-o", "BatchMode=yes",
                    "-o", "ConnectTimeout=10",
                    "{}@{}".format(ssh_user, server_host),
                    "ping",
                ],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            stdout, stderr = proc.communicate(timeout=30)
            stdout_str = stdout.decode("utf-8", errors="replace").strip()
            stderr_str = stderr.decode("utf-8", errors="replace").strip()
            if proc.returncode == 0 and "pong" in stdout_str:
                return True, ""
            # Gate rejected command = auth worked, gate just needs update
            if "command not allowed" in stderr_str:
                return True, ""
            # Auth failure = key mismatch, needs re-download
            if "Permission denied" in stderr_str:
                return False, stderr_str
            # Other errors (network, timeout) -- not a key issue
            return True, ""
        except Exception as e:
            return True, ""  # Network issues aren't key problems

    ok, err = _try_ssh()
    if ok:
        logger.info("SSH connectivity test passed")
        return

    logger.warning("SSH connectivity test failed: {}".format(err))

    # Force re-download the key
    logger.info("Re-downloading SSH key from server...")
    try:
        os.unlink(SSH_KEY_PATH)
    except OSError:
        pass
    try:
        os.unlink(SSH_INFO_PATH)
    except OSError:
        pass

    if not download_ssh_key(config):
        logger.error("SSH key re-download failed")
        return

    # Retry with fresh key
    ok, err = _try_ssh()
    if ok:
        logger.info("SSH connectivity test passed after key re-download")
    else:
        logger.error("SSH connectivity test still failing after key re-download: {}".format(err))


def count_files(directories, one_file_system=False):
    """Pre-count files in directories for progress tracking.

    When one_file_system is True, stays on the same filesystem as the
    top-level directory (mirrors borg's --one-file-system behavior).
    This prevents descending into /proc, /sys, NFS mounts, etc. when
    backing up /.
    """
    total = 0
    for dir_path in directories.splitlines():
        dir_path = dir_path.strip()
        if not dir_path or not os.path.exists(dir_path):
            continue
        try:
            root_dev = os.lstat(dir_path).st_dev if one_file_system else None
            for root, dirs, files in os.walk(dir_path, followlinks=False):
                if one_file_system:
                    # Remove subdirs on different filesystems so os.walk skips them
                    kept = []
                    for d in dirs:
                        try:
                            if os.lstat(os.path.join(root, d)).st_dev == root_dev:
                                kept.append(d)
                        except OSError:
                            pass
                    dirs[:] = kept
                total += len(files)
        except (PermissionError, OSError):
            continue
    return total


def execute_update_borg(config, task):
    """Update borg via binary download, with optional pip fallback."""
    job_id = task.get("job_id")
    target_version = task.get("target_version", "")
    download_url = task.get("download_url")
    install_method = task.get("install_method", "binary")
    binary_path = task.get("binary_path", "/usr/local/bin/borg")
    fallback_to_pip = task.get("fallback_to_pip", True)
    source = task.get("source", "official")  # 'official' or 'server'

    logger.info("Executing borg update job #{} to v{} via {} (source={})".format(job_id, target_version, install_method, source))

    # Handle skip - agent is incompatible with selected server version
    if install_method == "skip":
        logger.info("Skipping borg update - no compatible binary for this agent")
        report_status(config, {
            "job_id": job_id,
            "result": "completed",
            "output_log": "Skipped - no compatible binary available for this platform",
        })
        return

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    update_output = ""
    result = "failed"

    try:
        if IS_WINDOWS:
            # Windows: always update from borg-windows GitHub releases
            result, update_output, error_output = _install_borg_windows()

        elif install_method == "binary" and download_url:
            result, update_output, error_output = _install_borg_binary(
                download_url, binary_path, target_version
            )
            # If binary install failed, try package manager fallback
            if result == "failed" and fallback_to_pip:
                logger.warning("Binary install failed ({}), falling back to package manager".format(error_output))
                result, update_output, error_output = _install_borg_package_manager()
                # If package manager also failed, try pip as last resort
                if result == "failed":
                    logger.warning("Package manager failed ({}), falling back to pip".format(error_output))
                    result, update_output, error_output = _install_borg_pip(target_version)

        elif install_method == "pip":
            # Server explicitly requested pip (no binary available)
            # First try package manager (more reliable), then pip
            result, update_output, error_output = _install_borg_package_manager()
            if result == "failed":
                logger.warning("Package manager failed ({}), falling back to pip".format(error_output))
                result, update_output, error_output = _install_borg_pip(target_version)

        else:
            error_output = "No download URL or install method provided"

    except Exception as e:
        error_output = str(e)
        logger.error("Borg update error: {}".format(e))

    # Report status
    status_data = {"job_id": job_id, "result": result}
    if error_output:
        status_data["error_log"] = error_output[:10000]
    elif result == "completed":
        status_data["output_log"] = update_output[:10000]
    report_status(config, status_data)

    # Re-report system info so borg_version gets updated
    if result == "completed":
        # Save the source for future reporting
        set_borg_source(source)
        info = get_system_info()
        api_request(config, "/api/agent/info", method="POST", data=info)
        logger.info("Updated borg version: {} (source={})".format(info.get('borg_version', 'unknown'), source))


def _install_borg_binary(download_url, binary_path, target_version):
    """Download a pre-compiled borg binary from GitHub and install it."""
    logger.info("Downloading borg binary from {}".format(download_url))

    req = urllib.request.Request(
        download_url,
        headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)}
    )

    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            binary_data = resp.read()
    except urllib.error.HTTPError as e:
        return "failed", "", "Download failed: HTTP {}".format(e.code)
    except Exception as e:
        return "failed", "", "Download failed: {}".format(e)

    # Basic size check (borg binaries are typically 10MB+)
    if len(binary_data) < 1 * 1024 * 1024:
        return "failed", "", "Downloaded file too small ({} bytes), likely not a valid binary".format(len(binary_data))

    # Write to temp file
    tmp_path = binary_path + ".tmp"
    try:
        os.makedirs(os.path.dirname(binary_path), exist_ok=True)
        with open(tmp_path, "wb") as f:
            f.write(binary_data)
        if not IS_WINDOWS:
            os.chmod(tmp_path, 0o755)
    except Exception as e:
        return "failed", "", "Failed to write binary: {}".format(e)

    # Test the binary — run both --version and a functional test (help create)
    # to catch glibc incompatibilities that --version alone may not trigger
    try:
        test_proc = subprocess.run(
            [tmp_path, "--version"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if test_proc.returncode != 0:
            os.remove(tmp_path)
            stderr = test_proc.stderr.decode("utf-8", errors="replace")
            return "failed", "", "Downloaded binary failed version check: {}".format(stderr)

        actual_version = test_proc.stdout.decode("utf-8", errors="replace").strip().replace("borg ", "")
        logger.info("Binary version check passed: {}".format(actual_version))

        # Functional test: exercises more of the binary to catch glibc issues
        func_proc = subprocess.run(
            [tmp_path, "help", "create"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10
        )
        if func_proc.returncode != 0:
            os.remove(tmp_path)
            stderr = func_proc.stderr.decode("utf-8", errors="replace")
            return "failed", "", "Downloaded binary failed functional test: {}".format(stderr)
        logger.info("Binary functional test passed")
    except Exception as e:
        if os.path.exists(tmp_path):
            os.remove(tmp_path)
        return "failed", "", "Binary test failed: {}".format(e)

    # Backup old binary and install new one
    try:
        backup_path = binary_path + ".bak"
        if os.path.exists(binary_path):
            os.rename(binary_path, backup_path)
        os.rename(tmp_path, binary_path)
        # Keep .bak around for manual recovery if needed — it will be
        # overwritten on the next successful update anyway
    except Exception as e:
        # Try to restore backup
        if os.path.exists(backup_path) and not os.path.exists(binary_path):
            os.rename(backup_path, binary_path)
        return "failed", "", "Failed to install binary: {}".format(e)

    # Remove package manager borg to avoid having two versions (Unix only)
    # /usr/local/bin takes precedence in PATH, but it's cleaner to have just one
    pkg_borg = "/usr/bin/borg"
    if not IS_WINDOWS and os.path.exists(pkg_borg) and binary_path != pkg_borg:
        try:
            # Try to uninstall via package manager
            if os.path.exists("/usr/bin/apt-get"):
                subprocess.run(["apt-get", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/dnf"):
                subprocess.run(["dnf", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/yum"):
                subprocess.run(["yum", "remove", "-y", "borgbackup"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            elif os.path.exists("/usr/bin/pacman"):
                subprocess.run(["pacman", "-R", "--noconfirm", "borg"],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
            logger.info("Removed package manager borg to avoid duplicate installations")
        except Exception as e:
            logger.warning("Could not remove package manager borg: {}".format(e))

    output = "Borg updated to v{} via binary install at {}".format(target_version, binary_path)
    logger.info(output)
    return "completed", output, ""


def _install_borg_windows():
    """Update borg on Windows from the borg-windows GitHub releases."""
    import zipfile
    import tempfile

    borg_dir = os.path.join(os.environ.get("ProgramFiles", r"C:\Program Files"), "BorgBackup")
    borg_exe = os.path.join(borg_dir, "borg", "borg.exe")
    api_url = "https://api.github.com/repos/marcpope/borg-windows/releases/latest"

    # Get current version for comparison
    old_version = ""
    if os.path.isfile(borg_exe):
        try:
            r = subprocess.run([borg_exe, "--version"], capture_output=True, timeout=10)
            old_version = r.stdout.decode("utf-8", errors="replace").strip()
        except Exception:
            pass

    # Query GitHub API for latest release
    logger.info("Querying borg-windows latest release...")
    try:
        req = urllib.request.Request(api_url, headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)})
        with urllib.request.urlopen(req, timeout=30) as resp:
            release = json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        return "failed", "", "Failed to query GitHub releases: {}".format(e)

    tag = release.get("tag_name", "unknown")
    assets = release.get("assets", [])
    zip_url = None
    for asset in assets:
        if asset.get("name") == "borg-windows.zip":
            zip_url = asset.get("browser_download_url")
            break

    if not zip_url:
        return "failed", "", "No borg-windows.zip found in release {}".format(tag)

    logger.info("Downloading borg-windows {} from {}".format(tag, zip_url))

    # Download zip
    try:
        req = urllib.request.Request(zip_url, headers={"User-Agent": "bbs-agent/{}".format(AGENT_VERSION)})
        with urllib.request.urlopen(req, timeout=300) as resp:
            zip_data = resp.read()
    except Exception as e:
        return "failed", "", "Failed to download borg-windows.zip: {}".format(e)

    if len(zip_data) < 1 * 1024 * 1024:
        return "failed", "", "Downloaded zip too small ({} bytes)".format(len(zip_data))

    # Extract to borg directory
    zip_path = os.path.join(tempfile.gettempdir(), "borg-windows-update.zip")
    try:
        with open(zip_path, "wb") as f:
            f.write(zip_data)
        os.makedirs(borg_dir, exist_ok=True)
        with zipfile.ZipFile(zip_path, "r") as zf:
            zf.extractall(borg_dir)
        os.remove(zip_path)
    except Exception as e:
        return "failed", "", "Failed to extract borg-windows.zip: {}".format(e)

    # Verify new binary works
    if not os.path.isfile(borg_exe):
        return "failed", "", "borg.exe not found after extraction at {}".format(borg_exe)

    try:
        r = subprocess.run([borg_exe, "--version"], capture_output=True, timeout=10)
        new_version = r.stdout.decode("utf-8", errors="replace").strip()
        if r.returncode != 0:
            return "failed", "", "New borg binary failed version check"
    except Exception as e:
        return "failed", "", "Failed to test new borg binary: {}".format(e)

    output = "Borg updated from '{}' to '{}' (release {})".format(old_version, new_version, tag)
    logger.info(output)
    return "completed", output, ""


def _install_borg_pip(target_version):
    """Install borg via pip. Removes any existing non-pip binary first."""
    if IS_WINDOWS:
        return "failed", "", "pip install not supported on Windows"
    # Check if there's an existing binary at /usr/local/bin/borg that's not from pip
    # (pip-installed borg is a script, not a large ELF binary)
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            # Check if it's a large binary (ELF binaries are typically 10MB+)
            size = os.path.getsize(existing_binary)
            if size > 5 * 1024 * 1024:  # > 5MB = likely a compiled binary, not pip
                logger.info("Removing existing binary at {} ({} bytes) to allow pip install".format(existing_binary, size))
                backup_path = existing_binary + ".bak"
                os.rename(existing_binary, backup_path)
                # Keep backup in case pip fails - we'll clean up on success
        except Exception as e:
            logger.warning("Could not check/remove existing binary: {}".format(e))

    version_spec = "borgbackup=={}".format(target_version) if target_version and target_version != "latest" else "borgbackup"
    # Use pip3 if available, fall back to pip (FreeBSD uses pip, not pip3)
    pip_cmd = "pip3"
    try:
        pip_check = subprocess.run(["pip3", "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
        if pip_check.returncode != 0:
            pip_stderr = pip_check.stderr.decode("utf-8", errors="replace").strip()
            # pip3 exists but is broken (e.g. Python library issue)
            return "failed", "", "pip3 is installed but broken (exit {}): {}".format(
                pip_check.returncode, pip_stderr[:500] if pip_stderr else "unknown error")
    except FileNotFoundError:
        pip_cmd = "pip"
    except subprocess.TimeoutExpired:
        return "failed", "", "pip3 --version timed out"
    except Exception as e:
        return "failed", "", "pip3 check failed: {}".format(e)
    cmd = [pip_cmd, "install", "--upgrade", "--break-system-packages", version_spec]
    logger.info("Installing borg via pip: {}".format(' '.join(cmd)))

    try:
        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
        stdout_text = proc.stdout.decode("utf-8", errors="replace").strip()
        stderr_text = proc.stderr.decode("utf-8", errors="replace").strip()

        if proc.returncode == 0:
            # Clean up backup if pip succeeded
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path):
                try:
                    os.remove(backup_path)
                except Exception:
                    pass
            # Get actual installed version
            try:
                result = subprocess.run(["borg", "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
                if result.returncode == 0:
                    installed_ver = result.stdout.decode().strip().replace("borg ", "")
                    output = "Borg updated to v{} via pip".format(installed_ver)
                else:
                    output = "Borg updated via pip"
            except Exception:
                output = "Borg updated via pip"
            logger.info(output)
            return "completed", output, ""
        else:
            # Restore backup if pip failed
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(existing_binary):
                try:
                    os.rename(backup_path, existing_binary)
                    logger.info("Restored backup binary after pip failure")
                except Exception:
                    pass
            combined = (stderr_text + "\n" + stdout_text).strip()
            # Extract the most useful line instead of dumping a full traceback
            last_error = ""
            for line in reversed(combined.split("\n")):
                line = line.strip()
                if line and not line.startswith("File ") and not line.startswith("from ") and not line.startswith("import "):
                    last_error = line
                    break
            error = "pip install failed (exit {}): {}".format(proc.returncode, last_error or combined[:500])
            logger.error(error)
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "pip install timed out"
    except Exception as e:
        return "failed", "", str(e)


def _install_borg_package_manager():
    """Install/update borg via OS package manager. Removes any existing /usr/local/bin/borg first."""
    if IS_WINDOWS:
        return "failed", "", "Package manager install not supported on Windows"
    # Remove any existing binary at /usr/local/bin/borg so package manager version is used
    existing_binary = "/usr/local/bin/borg"
    if os.path.exists(existing_binary):
        try:
            size = os.path.getsize(existing_binary)
            logger.info("Removing existing binary at {} ({} bytes) to use package manager".format(existing_binary, size))
            backup_path = existing_binary + ".bak"
            os.rename(existing_binary, backup_path)
        except Exception as e:
            logger.warning("Could not remove existing binary: {}".format(e))

    if os.path.exists("/usr/bin/apt-get"):
        cmd = ["apt-get", "install", "-y", "borgbackup"]
        pre_cmd = ["apt-get", "update", "-qq"]
    elif os.path.exists("/usr/bin/dnf"):
        cmd = ["dnf", "install", "-y", "borgbackup"]
        pre_cmd = None
    elif os.path.exists("/usr/bin/yum"):
        cmd = ["yum", "install", "-y", "borgbackup"]
        pre_cmd = None
    elif os.path.exists("/usr/bin/pacman"):
        cmd = ["pacman", "-Sy", "--noconfirm", "borg"]
        pre_cmd = None
    elif os.path.exists("/usr/local/sbin/pkg"):
        cmd = ["pkg", "install", "-y", "borgbackup"]
        pre_cmd = None
    elif os.path.exists("/usr/local/bin/brew") or os.path.exists("/opt/homebrew/bin/brew"):
        cmd = ["brew", "install", "borgbackup"]
        pre_cmd = None
    else:
        # Restore backup if no package manager
        backup_path = existing_binary + ".bak"
        if os.path.exists(backup_path) and not os.path.exists(existing_binary):
            os.rename(backup_path, existing_binary)
        return "failed", "", "No supported package manager found"

    try:
        if pre_cmd:
            subprocess.run(pre_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)

        proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=600)
        stdout_text = proc.stdout.decode("utf-8", errors="replace").strip()
        stderr_text = proc.stderr.decode("utf-8", errors="replace").strip()

        if proc.returncode == 0:
            # Clean up backup on success
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path):
                try:
                    os.remove(backup_path)
                except Exception:
                    pass
            # Get installed version
            try:
                result = subprocess.run(["borg", "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=10)
                if result.returncode == 0:
                    installed_ver = result.stdout.decode().strip().replace("borg ", "")
                    output = "Borg updated to v{} via package manager".format(installed_ver)
                else:
                    output = "Borg installed via package manager"
            except Exception:
                output = "Borg installed via package manager"
            return "completed", output, ""
        else:
            # Restore backup on failure
            backup_path = existing_binary + ".bak"
            if os.path.exists(backup_path) and not os.path.exists(existing_binary):
                try:
                    os.rename(backup_path, existing_binary)
                    logger.info("Restored backup binary after package manager failure")
                except Exception:
                    pass
            error = stderr_text or stdout_text or "Exit code {}".format(proc.returncode)
            return "failed", "", error
    except subprocess.TimeoutExpired:
        return "failed", "", "Update command timed out"
    except Exception as e:
        return "failed", "", str(e)


def execute_update_agent(config, task):
    """Download and replace the agent script from the server, then restart."""
    job_id = task.get("job_id")
    logger.info("Executing agent update job #{}".format(job_id))

    # Report running
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id, "files_total": 0, "files_processed": 0,
    })

    error_output = ""
    result = "failed"
    update_output = ""

    try:
        # Download new agent script from server
        url = "{}/api/agent/download?file=bbs-agent.py".format(config['server_url'])
        headers = {
            "Authorization": "Bearer {}".format(config['api_key']),
        }
        req = urllib.request.Request(url, headers=headers, method="GET")

        with urllib.request.urlopen(req, timeout=60) as resp:
            new_script = resp.read().decode("utf-8")

        # Validate the downloaded script
        if "AGENT_VERSION" not in new_script or len(new_script) < 1000:
            error_output = "Downloaded script failed validation"
            logger.error(error_output)
        else:
            # Syntax-check the new script BEFORE replacing — a SyntaxError
            # here (e.g. from Python version incompatibility) would brick the
            # agent with no auto-recovery path.
            try:
                import ast
                ast.parse(new_script)
            except SyntaxError as syn_err:
                error_output = "Downloaded script has syntax error on line {}: {} — keeping current version".format(
                    syn_err.lineno, syn_err.msg)
                logger.error(error_output)
                report_status(config, {"job_id": job_id, "result": "failed", "error_log": error_output})
                return

            # On Windows (launcher pattern): update bbs-agent-run.py in the agent dir
            # On Unix: replace the running script in-place
            if IS_WINDOWS:
                script_path = os.path.join(_AGENT_DIR, "bbs-agent-run.py")
            else:
                script_path = os.path.abspath(__file__)
            logger.info("Replacing agent at: {}".format(script_path))

            # Save a backup of the current working script before replacing
            backup_path = script_path + ".bak"
            try:
                import shutil
                shutil.copy2(script_path, backup_path)
            except Exception as bak_err:
                logger.warning("Could not create backup: {}".format(bak_err))

            # Write new script to temp file first, then move
            tmp_path = script_path + ".tmp"
            with open(tmp_path, "w", encoding="utf-8") as f:
                f.write(new_script)
            if not IS_WINDOWS:
                os.chmod(tmp_path, os.stat(script_path).st_mode)
            os.replace(tmp_path, script_path)

            # Extract new version from downloaded script
            new_version = "unknown"
            for line in new_script.split("\n")[:50]:
                m = __import__("re").match(r'^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']', line)
                if m:
                    new_version = m.group(1)
                    break

            result = "completed"
            update_output = "Agent updated to v{}".format(new_version)
            logger.info(update_output)

            # Also download the startup wrapper (provides auto-recovery from
            # bad updates). Non-fatal if it fails — the wrapper is a safety
            # net, not required for normal operation.
            if not IS_WINDOWS:
                try:
                    wrapper_url = "{}/api/agent/download?file=bbs-agent-start.sh".format(config['server_url'])
                    wrapper_req = urllib.request.Request(wrapper_url, headers=headers, method="GET")
                    with urllib.request.urlopen(wrapper_req, timeout=30) as wresp:
                        wrapper_script = wresp.read()
                    wrapper_dir = os.path.dirname(script_path)
                    wrapper_path = os.path.join(wrapper_dir, "bbs-agent-start.sh")
                    with open(wrapper_path, "wb") as wf:
                        wf.write(wrapper_script)
                    os.chmod(wrapper_path, 0o755)
                    logger.info("Updated startup wrapper at {}".format(wrapper_path))
                except Exception as we:
                    logger.debug("Could not update startup wrapper: {}".format(we))

    except urllib.error.HTTPError as e:
        error_output = "Download failed: HTTP {}".format(e.code)
        logger.error(error_output)
    except Exception as e:
        error_output = str(e)
        logger.error("Agent update error: {}".format(e))

    # Report status
    status_data = {"job_id": job_id, "result": result}
    if error_output:
        status_data["error_log"] = error_output[:10000]
    elif result == "completed":
        status_data["output_log"] = update_output[:10000]
    report_status(config, status_data)

    # Re-report system info so agent_version gets updated, then restart
    if result == "completed":
        info = get_system_info()
        api_request(config, "/api/agent/info", method="POST", data=info)
        logger.info("Restarting agent with new script...")
        if IS_WINDOWS:
            # Windows launcher pattern: just exit cleanly. The launcher (bbs-agent.exe)
            # monitors the subprocess and will automatically restart it with the
            # updated bbs-agent-run.py after a short delay.
            sys.exit(0)
        else:
            os.execv(sys.executable, [sys.executable] + sys.argv)


def log_to_server(config, job_id, message, level="info"):
    """Send a log message to the server for display in the job activity log."""
    try:
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "log_message": message,
            "log_level": level,
        })
    except Exception:
        pass  # Don't fail the job over a log message


PLUGIN_DISPLAY_NAMES = {
    "mysql_dump": "MySQL Dump",
    "pg_dump": "PostgreSQL Dump",
    "mongo_dump": "MongoDB Dump",
    "interworx": "InterWorx Backup",
    "shell_hook": "Shell Script Hook",
}


def execute_plugins(plugins, config=None, job_id=None):
    """Execute pre-backup plugins. Returns dict of results keyed by slug."""
    results = {}
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})
        display = PLUGIN_DISPLAY_NAMES.get(slug, slug)
        logger.info("Running pre-backup plugin: {}".format(slug))
        if config and job_id:
            api_request(config, "/api/agent/progress", method="POST", data={
                "job_id": job_id,
                "status_message": "Running plugin: {}".format(display),
            })
        func_name = "execute_plugin_{}".format(slug)
        func = globals().get(func_name)
        if not func:
            logger.warning("Plugin {} not implemented, skipping".format(slug))
            continue
        result = func(cfg)
        results[slug] = result
        logger.info("Plugin {} completed".format(slug))

        # Send plugin summary to server log
        if config and job_id:
            summary = _plugin_summary(slug, cfg, result)
            if summary:
                log_to_server(config, job_id, summary)
    return results


def _plugin_summary(slug, config, result):
    """Build a human-readable summary of what a plugin did."""
    if slug == "mysql_dump":
        dump_files = result.get("dump_files", [])
        dump_dir = result.get("dump_dir", "")
        db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        return "MySQL dump: {} database(s) ({}) dumped to {} ({})".format(len(dump_files), ', '.join(db_names), dump_dir, size_str)
    if slug == "pg_dump":
        dump_files = result.get("dump_files", [])
        dump_dir = result.get("dump_dir", "")
        db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        return "PostgreSQL dump: {} database(s) ({}) dumped to {} ({})".format(len(dump_files), ', '.join(db_names), dump_dir, size_str)
    if slug == "mongo_dump":
        dump_dir = result.get("dump_dir", "")
        databases = result.get("databases", [])
        # Calculate total size of dump directory
        total_size = 0
        for db_name in databases:
            db_path = os.path.join(dump_dir, db_name)
            if os.path.isdir(db_path):
                for f in os.listdir(db_path):
                    fp = os.path.join(db_path, f)
                    if os.path.isfile(fp):
                        total_size += os.path.getsize(fp)
        size_str = _format_size(total_size)
        return "MongoDB dump: {} database(s) ({}) dumped to {} ({})".format(len(databases), ', '.join(databases), dump_dir, size_str)
    if slug == "interworx":
        dump_dir = result.get("dump_dir", "")
        backup_type = result.get("backup_type", "full")
        file_count = result.get("file_count", 0)
        dump_files = result.get("dump_files", [])
        total_size = sum(os.path.getsize(f) for f in dump_files if os.path.exists(f))
        size_str = _format_size(total_size)
        type_label = {"full": "Full", "partial": "Partial", "structure_only": "Structure only"}.get(backup_type, backup_type)
        return "InterWorx {}: {} file(s) in {} ({})".format(type_label, file_count, dump_dir, size_str)
    if slug == "shell_hook":
        parts = []
        pre = result.get("pre_script", "")
        if pre:
            code = result.get("pre_exit_code", "?")
            parts.append("pre-script: {} (exit {})".format(pre, code))
        post = result.get("post_script", "")
        if post:
            parts.append("post-script: {} (pending)".format(post))
        output = result.get("pre_output", "").strip()
        if output:
            # Truncate for summary
            if len(output) > 200:
                output = output[:200] + "..."
            parts.append("output: {}".format(output))
        return "Shell hook: {}".format(' | '.join(parts)) if parts else None
    return None


def _format_size(bytes_val):
    """Format bytes into human-readable size."""
    for unit in ['B', 'KB', 'MB', 'GB', 'TB']:
        if bytes_val < 1024:
            if unit != 'B':
                return "{:.1f} {}".format(bytes_val, unit)
            else:
                return "{} {}".format(bytes_val, unit)
        bytes_val /= 1024
    return "{:.1f} PB".format(bytes_val)


def cleanup_plugins(plugins, plugin_results, config=None, job_id=None, backup_result="completed"):
    """Run post-backup cleanup for plugins.
    Shell hook post-scripts always run (to restart services stopped by pre-scripts).
    File cleanup (dump deletion) only runs on successful backups.
    """
    for plugin in plugins:
        slug = plugin.get("slug", "")
        cfg = plugin.get("config", {})

        # Shell hook post-scripts ALWAYS run regardless of backup result
        if slug == "shell_hook":
            func = globals().get("cleanup_plugin_shell_hook")
            if func:
                try:
                    cleanup_result = func(cfg, plugin_results.get(slug, {}))
                    if config and job_id and cleanup_result:
                        log_to_server(config, job_id, "Shell hook post-script: {}".format(cleanup_result))
                except Exception as e:
                    logger.warning("Shell hook post-script failed: {}".format(e))
                    if config and job_id:
                        log_to_server(config, job_id, "Shell hook post-script failed: {}".format(e), "warning")
            continue

        # Other plugin cleanup (dump file deletion) only on success
        if backup_result != "completed":
            continue

        func = globals().get("cleanup_plugin_{}".format(slug))
        if func:
            try:
                func(cfg, plugin_results.get(slug, {}))
                if config and job_id:
                    if cfg.get("cleanup_after", True):
                        dump_dir = plugin_results.get(slug, {}).get("dump_dir", "")
                        if dump_dir:
                            log_to_server(config, job_id, "Plugin cleanup: removed dump files from {}".format(dump_dir))
            except Exception as e:
                logger.warning("Plugin cleanup for {} failed: {}".format(slug, e))
                if config and job_id:
                    log_to_server(config, job_id, "Plugin cleanup for {} failed: {}".format(slug, e), "warning")


def execute_plugin_mysql_dump(config):
    """Dump MySQL/MariaDB databases before backup."""
    dump_dir = config.get("dump_dir", "/home/bbs/mysql")
    os.makedirs(dump_dir, exist_ok=True)

    host = config.get("host", "localhost")
    port = str(config.get("port", 3306))
    user = config.get("user")
    password = config.get("password")
    databases = config.get("databases", "*")
    per_database = config.get("per_database", True)
    compress = config.get("compress", True)
    exclude = config.get("exclude_databases", ["information_schema", "performance_schema", "sys"])
    extra_options = config.get("extra_options", "--single-transaction --quick --routines --triggers --events")

    if not user or not password:
        raise Exception("MySQL plugin requires user and password")

    if isinstance(databases, str) and databases.strip() == "*":
        # List all databases
        list_cmd = [
            "mysql",
            "--host={}".format(host),
            "--port={}".format(port),
            "--user={}".format(user),
            "--password={}".format(password),
            "-e", "SHOW DATABASES;",
            "-s", "--skip-column-names",
        ]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode != 0:
            raise Exception("Failed to list databases: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        databases = [
            db.strip() for db in result.stdout.decode("utf-8", errors="replace").strip().split("\n")
            if db.strip() and db.strip() not in exclude
        ]
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    base_cmd = ["mysqldump", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user), "--password={}".format(password)]
    if extra_options:
        base_cmd.extend(extra_options.split())

    dump_files = []

    if per_database:
        for db in databases:
            filename = "{}.sql.gz".format(db) if compress else "{}.sql".format(db)
            dump_path = os.path.join(dump_dir, filename)
            logger.info("Dumping database {} to {}".format(db, dump_path))

            cmd = base_cmd + [db]
            if compress:
                dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if IS_WINDOWS:
                    import gzip as gzip_mod
                    with gzip_mod.open(dump_path, "wb") as f:
                        while True:
                            chunk = dump_proc.stdout.read(65536)
                            if not chunk:
                                break
                            f.write(chunk)
                    dump_proc.wait()
                else:
                    with open(dump_path, "wb") as f:
                        gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                    dump_proc.stdout.close()
                    gzip_proc.wait()
                    dump_proc.wait()
                if dump_proc.returncode != 0:
                    stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                    raise Exception("mysqldump failed for {}: {}".format(db, stderr))
            else:
                with open(dump_path, "w") as f:
                    r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                    if r.returncode != 0:
                        raise Exception("mysqldump failed for {}: {}".format(db, r.stderr.decode('utf-8', errors='replace')))

            dump_files.append(dump_path)
    else:
        filename = "all_databases.sql.gz" if compress else "all_databases.sql"
        dump_path = os.path.join(dump_dir, filename)
        logger.info("Dumping all databases to {}".format(dump_path))

        cmd = base_cmd + ["--all-databases"]
        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if IS_WINDOWS:
                import gzip as gzip_mod
                with gzip_mod.open(dump_path, "wb") as f:
                    while True:
                        chunk = dump_proc.stdout.read(65536)
                        if not chunk:
                            break
                        f.write(chunk)
                dump_proc.wait()
            else:
                with open(dump_path, "wb") as f:
                    gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                dump_proc.stdout.close()
                gzip_proc.wait()
                dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception("mysqldump failed: {}".format(stderr))
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE)
                if r.returncode != 0:
                    raise Exception("mysqldump failed: {}".format(r.stderr.decode('utf-8', errors='replace')))

        dump_files.append(dump_path)

    logger.info("MySQL dump complete: {} file(s) in {}".format(len(dump_files), dump_dir))
    db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
    return {
        "dump_files": dump_files,
        "dump_dir": dump_dir,
        "databases": db_names,
        "per_database": per_database,
        "compress": compress,
    }


def cleanup_plugin_mysql_dump(config, plugin_result):
    """Delete dump files after backup if cleanup_after is enabled."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    import shutil
    logger.info("Cleaning up MySQL dumps in {}".format(dump_dir))
    for f in os.listdir(dump_dir):
        fpath = os.path.join(dump_dir, f)
        if os.path.isfile(fpath) and (f.endswith(".sql") or f.endswith(".sql.gz")):
            os.remove(fpath)


def test_plugin_mysql_dump(config):
    """Test MySQL connectivity without dumping."""
    host = config.get("host", "localhost")
    port = str(config.get("port", 3306))
    user = config.get("user")
    password = config.get("password")
    if not user or not password:
        raise Exception("MySQL plugin requires user and password")

    cmd = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user),
           "--password={}".format(password), "-e", "SELECT 1;", "-s", "--skip-column-names"]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    if result.returncode != 0:
        raise Exception("Connection failed: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

    # Test SHOW DATABASES for permissions
    cmd2 = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user),
            "--password={}".format(password), "-e", "SHOW DATABASES;", "-s", "--skip-column-names"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    dbs = result2.stdout.decode("utf-8", errors="replace").strip().split("\n")
    dbs = [d for d in dbs if d]
    return "Connection successful. Found {} database(s): {}".format(len(dbs), ', '.join(dbs[:10]))


def execute_plugin_pg_dump(config):
    """Dump PostgreSQL databases before backup."""
    dump_dir = config.get("dump_dir", "/home/bbs/pgdump")
    os.makedirs(dump_dir, exist_ok=True)

    host = config.get("host", "localhost")
    port = str(config.get("port", 5432))
    user = config.get("user")
    password = config.get("password")
    databases = config.get("databases", "*")
    compress = config.get("compress", True)
    exclude = config.get("exclude_databases", ["template0", "template1", "postgres"])
    extra_options = config.get("extra_options", "--no-owner --no-privileges")

    if not user or not password:
        raise Exception("PostgreSQL plugin requires user and password")

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    if isinstance(databases, str) and databases.strip() == "*":
        # List all databases via psql
        list_cmd = ["psql", "-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
        if result.returncode != 0:
            raise Exception("Failed to list databases: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))
        if isinstance(exclude, str):
            exclude = [x.strip() for x in exclude.split(",")]
        # psql -l -t -A outputs: dbname|owner|encoding|collate|ctype|access
        databases = []
        for line in result.stdout.decode("utf-8", errors="replace").strip().split("\n"):
            parts = line.split("|")
            if parts and parts[0].strip() and parts[0].strip() not in exclude:
                databases.append(parts[0].strip())
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    dump_files = []

    for db in databases:
        filename = "{}.sql.gz".format(db) if compress else "{}.sql".format(db)
        dump_path = os.path.join(dump_dir, filename)
        logger.info("Dumping PostgreSQL database {} to {}".format(db, dump_path))

        cmd = ["pg_dump", "-h", host, "-p", port, "-U", user]
        if extra_options:
            cmd.extend(extra_options.split())
        cmd.append(db)

        if compress:
            dump_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
            if IS_WINDOWS:
                import gzip as gzip_mod
                with gzip_mod.open(dump_path, "wb") as f:
                    while True:
                        chunk = dump_proc.stdout.read(65536)
                        if not chunk:
                            break
                        f.write(chunk)
                dump_proc.wait()
            else:
                with open(dump_path, "wb") as f:
                    gzip_proc = subprocess.Popen(["gzip"], stdin=dump_proc.stdout, stdout=f, stderr=subprocess.PIPE)
                dump_proc.stdout.close()
                gzip_proc.wait()
                dump_proc.wait()
            if dump_proc.returncode != 0:
                stderr = dump_proc.stderr.read().decode() if dump_proc.stderr else ""
                raise Exception("pg_dump failed for {}: {}".format(db, stderr))
        else:
            with open(dump_path, "w") as f:
                r = subprocess.run(cmd, stdout=f, stderr=subprocess.PIPE, env=pg_env)
                if r.returncode != 0:
                    raise Exception("pg_dump failed for {}: {}".format(db, r.stderr.decode('utf-8', errors='replace')))

        dump_files.append(dump_path)

    logger.info("PostgreSQL dump complete: {} file(s) in {}".format(len(dump_files), dump_dir))
    db_names = [os.path.basename(f).split(".")[0] for f in dump_files]
    return {
        "dump_files": dump_files,
        "dump_dir": dump_dir,
        "databases": db_names,
        "per_database": True,
        "compress": compress,
    }


def cleanup_plugin_pg_dump(config, plugin_result):
    """Delete PostgreSQL dump files after backup if cleanup_after is enabled."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    logger.info("Cleaning up PostgreSQL dumps in {}".format(dump_dir))
    for f in os.listdir(dump_dir):
        fpath = os.path.join(dump_dir, f)
        if os.path.isfile(fpath) and (f.endswith(".sql") or f.endswith(".sql.gz")):
            os.remove(fpath)


def test_plugin_pg_dump(config):
    """Test PostgreSQL connectivity without dumping."""
    host = config.get("host", "localhost")
    port = str(config.get("port", 5432))
    user = config.get("user")
    password = config.get("password")
    if not user or not password:
        raise Exception("PostgreSQL plugin requires user and password")

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    cmd = ["psql", "-h", host, "-p", port, "-U", user, "-c", "SELECT 1;", "-t", "-A", "postgres"]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=15)
    if result.returncode != 0:
        raise Exception("Connection failed: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

    # List databases
    cmd2 = ["psql", "-h", host, "-p", port, "-U", user, "-l", "-t", "-A"]
    result2 = subprocess.run(cmd2, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=15)
    dbs = []
    for line in result2.stdout.decode("utf-8", errors="replace").strip().split("\n"):
        parts = line.split("|")
        if parts and parts[0].strip():
            dbs.append(parts[0].strip())
    return "Connection successful. Found {} database(s): {}".format(len(dbs), ', '.join(dbs[:10]))


def _mongo_auth_args(host, port, user, password, auth_db):
    """Build mongodump/mongorestore auth arguments. Returns list of args."""
    args = ["--host={}".format(host), "--port={}".format(port)]
    if user and password:
        args.extend([
            "--username={}".format(user),
            "--password={}".format(password),
            "--authenticationDatabase={}".format(auth_db),
        ])
    return args


def _find_mongosh():
    """Find mongosh or mongo shell binary."""
    for name in ["mongosh", "mongo"]:
        try:
            r = subprocess.run([name, "--version"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=5)
            if r.returncode == 0:
                return name
        except (FileNotFoundError, OSError):
            continue
    return None


def _parse_mongo_db_list(raw_output, exclude=None):
    """Parse mongosh JSON.stringify output to get database names.

    mongosh --quiet may still emit extra lines (warnings, connection info).
    We look for the JSON array line containing the database list.
    """
    import json as _json
    if exclude is None:
        exclude = []
    for line in raw_output.split("\n"):
        line = line.strip()
        if line.startswith("["):
            try:
                names = _json.loads(line)
                if isinstance(names, list):
                    return [n for n in names if isinstance(n, str) and n not in exclude]
            except (ValueError, TypeError):
                continue
    # Fallback: treat each non-empty, single-word line as a DB name
    dbs = []
    for line in raw_output.split("\n"):
        name = line.strip()
        if name and " " not in name and name not in exclude:
            dbs.append(name)
    if dbs:
        return dbs
    raise Exception("Could not parse database list from mongosh output: {}".format(raw_output[:500]))


def execute_plugin_mongo_dump(config):
    """Dump MongoDB databases before backup using mongodump."""
    dump_dir = config.get("dump_dir", "/home/bbs/mongodump")
    os.makedirs(dump_dir, exist_ok=True)

    host = config.get("host", "127.0.0.1")
    port = str(config.get("port", 27017))
    user = config.get("user", "")
    password = config.get("password", "")
    auth_db = config.get("auth_db", "admin")
    databases = config.get("databases", "*")
    compress = config.get("compress", True)
    exclude = config.get("exclude_databases", ["admin", "config", "local"])
    extra_options = config.get("extra_options", "")

    if isinstance(exclude, str):
        exclude = [x.strip() for x in exclude.split(",") if x.strip()]

    if isinstance(databases, str) and databases.strip() == "*":
        # List all databases via mongosh
        shell = _find_mongosh()
        if not shell:
            raise Exception("Neither mongosh nor mongo found. Install MongoDB Database Tools.")

        list_cmd = [shell, "--host", host, "--port", port]
        if user and password:
            list_cmd.extend(["-u", user, "-p", password, "--authenticationDatabase", auth_db])
        list_cmd.extend(["--quiet", "--eval", "JSON.stringify(db.adminCommand('listDatabases').databases.map(d => d.name))"])

        result = subprocess.run(list_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
        if result.returncode != 0:
            raise Exception("Failed to list databases: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

        raw_output = result.stdout.decode("utf-8", errors="replace").strip()
        databases = _parse_mongo_db_list(raw_output, exclude)
    elif isinstance(databases, str):
        databases = [d.strip() for d in databases.split(",") if d.strip()]

    if not databases:
        raise Exception("No databases found to dump")

    dump_files = []
    auth_args = _mongo_auth_args(host, port, user, password, auth_db)

    for db in databases:
        logger.info("Dumping MongoDB database {} to {}".format(db, dump_dir))

        cmd = ["mongodump"] + auth_args + ["--db={}".format(db), "--out={}".format(dump_dir)]
        if compress:
            cmd.append("--gzip")
        if extra_options:
            cmd.extend(extra_options.split())

        r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
        if r.returncode != 0:
            raise Exception("mongodump failed for {}: {}".format(db, r.stderr.decode('utf-8', errors='replace')))

        # mongodump creates dump_dir/db_name/ directory
        db_dump_path = os.path.join(dump_dir, db)
        dump_files.append(db_dump_path)

    logger.info("MongoDB dump complete: {} database(s) in {}".format(len(databases), dump_dir))
    return {
        "dump_files": dump_files,
        "dump_dir": dump_dir,
        "databases": databases,
        "per_database": True,
        "compress": compress,
    }


def cleanup_plugin_mongo_dump(config, plugin_result):
    """Delete MongoDB dump directories after backup if cleanup_after is enabled."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    logger.info("Cleaning up MongoDB dumps in {}".format(dump_dir))
    databases = plugin_result.get("databases", [])
    for db_name in databases:
        db_path = os.path.join(dump_dir, db_name)
        if os.path.isdir(db_path):
            import shutil
            shutil.rmtree(db_path, ignore_errors=True)


def test_plugin_mongo_dump(config):
    """Test MongoDB connectivity without dumping."""
    host = config.get("host", "127.0.0.1")
    port = str(config.get("port", 27017))
    user = config.get("user", "")
    password = config.get("password", "")
    auth_db = config.get("auth_db", "admin")

    shell = _find_mongosh()
    if not shell:
        raise Exception("Neither mongosh nor mongo found. Install MongoDB Database Tools.")

    cmd = [shell, "--host", host, "--port", port]
    if user and password:
        cmd.extend(["-u", user, "-p", password, "--authenticationDatabase", auth_db])
    cmd.extend(["--quiet", "--eval", "JSON.stringify(db.adminCommand('listDatabases').databases.map(d => d.name))"])

    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
    if result.returncode != 0:
        raise Exception("Connection failed: {}".format(result.stderr.decode('utf-8', errors='replace').strip()))

    raw_output = result.stdout.decode("utf-8", errors="replace").strip()
    dbs = _parse_mongo_db_list(raw_output)
    return "Connection successful. Found {} database(s): {}".format(len(dbs), ', '.join(dbs[:10]))


def execute_plugin_interworx(config):
    """Run InterWorx control panel backup before borg archive."""
    output_dir = config.get("output_dir", "/chroot/home/backup/interworx")
    os.makedirs(output_dir, exist_ok=True)

    backup_type = config.get("backup_type", "full")
    domains = config.get("domains", "all")
    compression = str(config.get("compression", 6))
    no_disabled = config.get("no_disabled", True)

    # Build command
    cmd = [os.path.expanduser("~iworx/bin/backup.pex")]
    cmd.extend(["--output-dir", output_dir])
    cmd.extend(["--tmp-dir", output_dir])
    cmd.extend(["--compression", compression])

    # Domains
    if isinstance(domains, str):
        domain_list = [d.strip() for d in domains.replace(",", " ").split() if d.strip()]
    else:
        domain_list = domains
    cmd.extend(["--domains"] + domain_list)

    # Backup type
    if backup_type == "structure_only":
        cmd.append("--structure-only")
    elif backup_type == "partial":
        opts = []
        if config.get("include_web", True):
            opts.append("web")
        if config.get("include_db", True):
            opts.append("db")
        if config.get("include_mail", True):
            opts.append("mail")
        if config.get("no_logs", False):
            opts.append("no-logs")
        if config.get("no_stats", False):
            opts.append("no-stats")
        if opts:
            cmd.extend(["--backup-options"] + opts)
    else:
        # Full backup
        cmd.extend(["--backup-options", "all"])

    if no_disabled:
        cmd.append("--no-disabled")

    logger.info("Running InterWorx backup: {}".format(" ".join(cmd)))

    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=7200)
    if r.returncode != 0:
        stderr = r.stderr.decode("utf-8", errors="replace")
        stdout = r.stdout.decode("utf-8", errors="replace")
        raise Exception("InterWorx backup failed (exit {}): {}".format(r.returncode, (stderr or stdout)[:2000]))

    # Count backup files created
    backup_files = []
    if os.path.isdir(output_dir):
        for f in os.listdir(output_dir):
            fp = os.path.join(output_dir, f)
            if os.path.isfile(fp):
                backup_files.append(fp)

    logger.info("InterWorx backup complete: {} file(s) in {}".format(len(backup_files), output_dir))
    return {
        "dump_dir": output_dir,
        "dump_files": backup_files,
        "backup_type": backup_type,
        "file_count": len(backup_files),
    }


def cleanup_plugin_interworx(config, plugin_result):
    """Delete InterWorx backup files after borg archive completes."""
    if not config.get("cleanup_after", True):
        return
    dump_dir = plugin_result.get("dump_dir")
    if not dump_dir or not os.path.exists(dump_dir):
        return
    logger.info("Cleaning up InterWorx backups in {}".format(dump_dir))
    for f in os.listdir(dump_dir):
        fp = os.path.join(dump_dir, f)
        if os.path.isfile(fp):
            try:
                os.unlink(fp)
            except Exception:
                pass
        elif os.path.isdir(fp):
            import shutil
            shutil.rmtree(fp, ignore_errors=True)


def test_plugin_interworx(config):
    """Test InterWorx availability."""
    backup_pex = os.path.expanduser("~iworx/bin/backup.pex")
    if not os.path.exists(backup_pex):
        raise Exception("InterWorx backup tool not found at {}".format(backup_pex))

    # Check it's executable
    if not os.access(backup_pex, os.X_OK):
        raise Exception("{} exists but is not executable".format(backup_pex))

    output_dir = config.get("output_dir", "/chroot/home/backup/interworx")
    if not os.path.isdir(output_dir):
        try:
            os.makedirs(output_dir, exist_ok=True)
        except Exception as e:
            raise Exception("Cannot create output directory {}: {}".format(output_dir, e))

    return "InterWorx backup tool found at {}. Output directory {} is ready.".format(backup_pex, output_dir)


def _popen_new_group_kwargs():
    """Return Popen kwargs that put the child into its own process group/session.

    This is required so we can kill the entire borg subtree (including the
    orphaned ssh transport child borg spawns for ssh:// repos) on cancel,
    instead of just killing borg and leaving ssh holding our stdout pipe.
    """
    if IS_WINDOWS:
        flags = 0
        for attr in ("CREATE_NEW_PROCESS_GROUP", "CREATE_NEW_CONSOLE"):
            flags |= getattr(subprocess, attr, 0)
        return {"creationflags": flags} if flags else {}
    return {"start_new_session": True}


def _kill_process_tree(proc):
    """Force-kill a child process and any descendants.

    Linux/macOS: kill the whole process group via SIGKILL.
    Windows: terminate via TerminateProcess (CREATE_NEW_PROCESS_GROUP keeps the
    tree together so child handles get cleaned up).
    """
    if proc is None or proc.poll() is not None:
        return
    try:
        if IS_WINDOWS:
            try:
                proc.terminate()
            except Exception:
                pass
            try:
                proc.kill()
            except Exception:
                pass
        else:
            try:
                pgid = os.getpgid(proc.pid)
            except (ProcessLookupError, OSError):
                pgid = proc.pid
            try:
                os.killpg(pgid, signal.SIGKILL)
            except (ProcessLookupError, PermissionError, OSError):
                # Fall back to killing just the immediate child
                try:
                    proc.kill()
                except Exception:
                    pass
    except Exception as e:
        logger.warning("Failed to kill process tree for pid {}: {}".format(proc.pid, e))


def _parse_script_command(value):
    """Split a script field into [executable, *args] using shell-style parsing.

    Allows users to pass arguments in the script path field, e.g.:
        /path/to/script.sh before
        /path/with\\ spaces/script.sh "arg with spaces"
    Returns ([], "") if value is empty. Returns (argv, executable_path).
    """
    value = (value or "").strip()
    if not value:
        return [], ""
    try:
        argv = shlex.split(value, posix=(os.name != "nt"))
    except ValueError:
        # Unbalanced quotes — fall back to treating the whole string as a path
        argv = [value]
    if not argv:
        return [], ""
    return argv, argv[0]


def execute_plugin_shell_hook(config):
    """Run pre-backup shell script hook."""
    pre_script = config.get("pre_script", "").strip()
    post_script = config.get("post_script", "").strip()
    timeout = int(config.get("timeout", 300))
    abort_on_failure = config.get("abort_on_failure", True)

    result = {
        "pre_script": pre_script,
        "post_script": post_script,
        "pre_output": "",
        "pre_exit_code": None,
    }

    if not pre_script:
        logger.info("Shell hook: no pre-script configured, skipping")
        return result

    pre_argv, pre_exe = _parse_script_command(pre_script)

    if not os.path.isfile(pre_exe):
        msg = "Pre-script not found: {}".format(pre_exe)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    if not os.access(pre_exe, os.X_OK):
        msg = "Pre-script not executable: {}".format(pre_exe)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)
        return result

    logger.info("Shell hook: running pre-script {}".format(pre_script))
    try:
        proc = subprocess.run(
            pre_argv,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            universal_newlines=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        result["pre_output"] = output
        result["pre_exit_code"] = proc.returncode

        if proc.returncode != 0:
            msg = "Pre-script exited with code {}: {}".format(proc.returncode, output)
            if abort_on_failure:
                raise Exception(msg)
            logger.warning(msg)
        else:
            logger.info("Pre-script completed successfully (exit 0)")
    except subprocess.TimeoutExpired:
        msg = "Pre-script timed out after {}s: {}".format(timeout, pre_script)
        if abort_on_failure:
            raise Exception(msg)
        logger.warning(msg)

    return result


def cleanup_plugin_shell_hook(config, plugin_result):
    """Run post-backup shell script hook."""
    post_script = config.get("post_script", "").strip()
    timeout = int(config.get("timeout", 300))

    if not post_script:
        return None

    post_argv, post_exe = _parse_script_command(post_script)

    if not os.path.isfile(post_exe):
        logger.warning("Post-script not found: {}".format(post_exe))
        return "{} not found".format(post_exe)

    if not os.access(post_exe, os.X_OK):
        logger.warning("Post-script not executable: {}".format(post_exe))
        return "{} not executable".format(post_exe)

    logger.info("Shell hook: running post-script {}".format(post_script))
    try:
        proc = subprocess.run(
            post_argv,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            universal_newlines=True,
        )
        output = proc.stdout[:10240] if proc.stdout else ""
        if proc.returncode != 0:
            logger.warning("Post-script exited with code {}: {}".format(proc.returncode, output))
            return "{} exited {}: {}".format(post_script, proc.returncode, output[:500])
        else:
            logger.info("Post-script completed successfully (exit 0)")
            return "{} completed (exit 0)".format(post_script) + (": {}".format(output[:500]) if output.strip() else "")
    except subprocess.TimeoutExpired:
        logger.warning("Post-script timed out after {}s: {}".format(timeout, post_script))
        return "{} timed out after {}s".format(post_script, timeout)


def test_plugin_shell_hook(config):
    """Test shell hook scripts by actually running them."""
    pre_script = config.get("pre_script", "").strip()
    post_script = config.get("post_script", "").strip()
    timeout = int(config.get("timeout", 300))
    results = []

    if not pre_script and not post_script:
        raise Exception("No scripts configured. Set at least a pre-script or post-script path.")

    for label, value in [("Pre-script", pre_script), ("Post-script", post_script)]:
        if not value:
            results.append("{}: not configured (skipped)".format(label))
            continue
        argv, exe = _parse_script_command(value)
        if not os.path.isfile(exe):
            raise Exception("{} not found: {}".format(label, exe))
        if not os.access(exe, os.X_OK):
            raise Exception("{} not executable: {} - run: chmod +x {}".format(label, exe, exe))
        # Actually run the script to verify it works under the agent's context
        try:
            proc = subprocess.run(
                argv,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                timeout=timeout,
                universal_newlines=True,
            )
            output = (proc.stdout or "").strip()[:500]
            if proc.returncode != 0:
                raise Exception("{} exited with code {}: {}".format(label, proc.returncode, output))
            results.append("{}: {} exit 0{}".format(label, value, " — {}".format(output) if output else ""))
        except subprocess.TimeoutExpired:
            raise Exception("{} timed out after {}s: {}".format(label, timeout, value))

    return " | ".join(results)


def execute_restore_pg(config, task):
    """Restore PostgreSQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH
    env_vars = task.get("env", {})
    cwd = task.get("cwd")
    databases = task.get("databases", [])
    pg_config = task.get("pg_config", {})

    host = pg_config.get("host", "localhost")
    port = str(pg_config.get("port", 5432))
    user = pg_config.get("user")
    password = pg_config.get("password")
    compress = pg_config.get("compress", True)
    dump_dir = pg_config.get("dump_dir", "/home/bbs/pgdump")

    if not user or not password:
        report_status(config, {
            "job_id": job_id, "result": "failed",
            "error_log": "PostgreSQL restore requires user and password in plugin config",
        })
        return

    # Write temporary SSH key for remote SSH repos
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                _lockdown_key_windows(remote_key_path)
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    pg_env = os.environ.copy()
    pg_env["PGPASSWORD"] = password

    # Report running status immediately so the server tracks accurate start time
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "output_log": "Extracting database dumps from archive...",
    })

    # Step 1: Extract dump files from borg archive
    logger.info("Job #{}: Extracting PostgreSQL dumps from archive".format(job_id))
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    env = os.environ.copy()
    env.update(env_vars)

    try:
        proc = subprocess.run(
            command, env=env, cwd=cwd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=3600,
        )
        if proc.returncode > 1:
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "borg extract failed: {}".format(proc.stderr.decode('utf-8', errors='replace')[:5000]),
            })
            return
    except Exception as e:
        report_status(config, {
            "job_id": job_id, "result": "failed",
            "error_log": "borg extract error: {}".format(e),
        })
        return
    finally:
        # Clean up temporary SSH key
        if remote_ssh_key and os.path.exists(remote_key_path):
            try:
                os.unlink(remote_key_path)
            except Exception:
                pass

    # Step 2: Import each database
    imported = []
    errors = []
    total = len(databases)
    for i, db_entry in enumerate(databases):
        db_name = db_entry.get("database")
        mode = db_entry.get("mode", "replace")
        target_db = db_entry.get("target_name", "{}_copy".format(db_name)) if mode == "rename" else db_name

        # Find the dump file
        if compress:
            dump_file = os.path.join(dump_dir, "{}.sql.gz".format(db_name))
        else:
            dump_file = os.path.join(dump_dir, "{}.sql".format(db_name))

        if not os.path.exists(dump_file):
            errors.append("{}: dump file not found at {}".format(db_name, dump_file))
            continue

        logger.info("Job #{}: Importing {} as {} ({}/{})".format(job_id, db_name, target_db, i+1, total))

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": "Importing {} as {}...".format(db_name, target_db),
        })

        try:
            psql_base = ["psql", "-h", host, "-p", port, "-U", user]
            pg_dump_base = ["pg_dump", "-h", host, "-p", port, "-U", user]

            # Safety backup: dump the current database before replacing it
            if mode == "replace":
                safety_file = os.path.join(dump_dir, "{}_pre_restore.sql.gz".format(target_db))
                logger.info("Job #{}: Creating safety backup of {} to {}".format(job_id, target_db, safety_file))
                try:
                    dump_cmd = pg_dump_base + [target_db]
                    dump_proc = subprocess.Popen(dump_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                    import gzip as _gzip_pg_safety
                    with _gzip_pg_safety.open(safety_file, "wb") as sf:
                        while True:
                            chunk = dump_proc.stdout.read(65536)
                            if not chunk:
                                break
                            sf.write(chunk)
                    dump_proc.wait()
                    if dump_proc.returncode != 0:
                        stderr_out = dump_proc.stderr.read().decode("utf-8", errors="replace")
                        logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(job_id, target_db, stderr_out[:200]))
                    else:
                        logger.info("Job #{}: Safety backup saved to {}".format(job_id, safety_file))
                except Exception as e:
                    logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(job_id, target_db, e))

            # Create target database if renaming
            if mode == "rename":
                create_cmd = psql_base + ["-c", 'CREATE DATABASE "{}";'.format(target_db), "postgres"]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=30)
                if r.returncode != 0:
                    stderr = r.stderr.decode('utf-8', errors='replace')
                    if "already exists" not in stderr:
                        errors.append("{}: failed to create {}: {}".format(db_name, target_db, stderr))
                        continue

            # Import the dump
            import_cmd = psql_base + ["-d", target_db]

            if compress:
                if IS_WINDOWS:
                    import gzip as gzip_mod
                    with gzip_mod.open(dump_file, "rb") as gz:
                        psql_proc = subprocess.Popen(import_cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                        while True:
                            chunk = gz.read(65536)
                            if not chunk:
                                break
                            psql_proc.stdin.write(chunk)
                        psql_proc.stdin.close()
                        psql_proc.wait()
                else:
                    gunzip = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    psql_proc = subprocess.Popen(import_cmd, stdin=gunzip.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env)
                    gunzip.stdout.close()
                    psql_proc.wait()
                    gunzip.wait()
                if psql_proc.returncode != 0:
                    stderr = psql_proc.stderr.read().decode("utf-8", errors="replace")
                    errors.append("{}: import failed: {}".format(db_name, stderr[:500]))
                    continue
            else:
                with open(dump_file, "r") as f:
                    r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=pg_env, timeout=3600)
                    if r.returncode != 0:
                        errors.append("{}: import failed: {}".format(db_name, r.stderr.decode('utf-8', errors='replace')[:500]))
                        continue

            imported.append("{} -> {}".format(db_name, target_db))

        except Exception as e:
            errors.append("{}: {}".format(db_name, e))

    # Report final status
    if errors and not imported:
        result = "failed"
        error_log = "; ".join(errors)
    else:
        result = "completed"
        error_log = "; ".join(errors) if errors else None

    status_data = {
        "job_id": job_id,
        "result": result,
        "files_total": total,
        "files_processed": len(imported),
    }
    if error_log:
        status_data["error_log"] = error_log[:10000]
    if imported:
        status_data["output_log"] = "Imported: {}".format(', '.join(imported))

    report_status(config, status_data)


def execute_restore_mysql(config, task):
    """Restore MySQL databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH
    env_vars = task.get("env", {})
    cwd = task.get("cwd")
    databases = task.get("databases", [])
    mysql_config = task.get("mysql_config", {})

    host = mysql_config.get("host", "localhost")
    port = str(mysql_config.get("port", 3306))
    user = mysql_config.get("user")
    password = mysql_config.get("password")
    compress = mysql_config.get("compress", True)
    per_database = mysql_config.get("per_database", True)
    dump_dir = mysql_config.get("dump_dir", "/home/bbs/mysql")

    if not user or not password:
        report_status(config, {
            "job_id": job_id, "result": "failed",
            "error_log": "MySQL restore requires user and password in plugin config",
        })
        return

    # Write temporary SSH key for remote SSH repos
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                _lockdown_key_windows(remote_key_path)
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    # Report running status immediately so the server tracks accurate start time
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "output_log": "Extracting database dumps from archive...",
    })

    # Step 1: Extract dump files from borg archive
    logger.info("Job #{}: Extracting MySQL dumps from archive".format(job_id))
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    env = os.environ.copy()
    env.update(env_vars)

    try:
        proc = subprocess.run(
            command, env=env, cwd=cwd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=3600,
        )
        if proc.returncode > 1:
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "borg extract failed: {}".format(proc.stderr.decode('utf-8', errors='replace')[:5000]),
            })
            return
    except Exception as e:
        report_status(config, {
            "job_id": job_id, "result": "failed",
            "error_log": "borg extract error: {}".format(e),
        })
        return
    finally:
        # Clean up temporary SSH key
        if remote_ssh_key and os.path.exists(remote_key_path):
            try:
                os.unlink(remote_key_path)
            except Exception:
                pass

    # Step 2: Import each database
    imported = []
    errors = []
    total = len(databases)
    for i, db_entry in enumerate(databases):
        db_name = db_entry.get("database")
        mode = db_entry.get("mode", "replace")  # replace or rename
        target_db = db_entry.get("target_name", "{}_copy".format(db_name)) if mode == "rename" else db_name

        # Find the dump file
        if per_database:
            if compress:
                dump_file = os.path.join(dump_dir, "{}.sql.gz".format(db_name))
            else:
                dump_file = os.path.join(dump_dir, "{}.sql".format(db_name))
        else:
            dump_file = os.path.join(dump_dir, "all_databases.sql.gz" if compress else "all_databases.sql")

        if not os.path.exists(dump_file):
            errors.append("{}: dump file not found at {}".format(db_name, dump_file))
            continue

        logger.info("Job #{}: Importing {} as {} ({}/{})".format(job_id, db_name, target_db, i+1, total))

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": "Importing {} as {}...".format(db_name, target_db),
        })

        try:
            mysql_base = ["mysql", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user), "--password={}".format(password)]
            mysqldump_base = ["mysqldump", "--host={}".format(host), "--port={}".format(port), "--user={}".format(user), "--password={}".format(password), "--single-transaction", "--quick"]

            # Safety backup: dump the current database before replacing it
            if mode == "replace":
                safety_file = os.path.join(dump_dir, "{}_pre_restore.sql.gz".format(target_db))
                logger.info("Job #{}: Creating safety backup of {} to {}".format(job_id, target_db, safety_file))
                try:
                    dump_cmd = mysqldump_base + [target_db]
                    dump_proc = subprocess.Popen(dump_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    import gzip as _gzip_safety
                    with _gzip_safety.open(safety_file, "wb") as sf:
                        while True:
                            chunk = dump_proc.stdout.read(65536)
                            if not chunk:
                                break
                            sf.write(chunk)
                    dump_proc.wait()
                    if dump_proc.returncode != 0:
                        stderr_out = dump_proc.stderr.read().decode("utf-8", errors="replace")
                        logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(job_id, target_db, stderr_out[:200]))
                    else:
                        logger.info("Job #{}: Safety backup saved to {}".format(job_id, safety_file))
                except Exception as e:
                    logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(job_id, target_db, e))

            # Create target database if renaming
            if mode == "rename":
                create_cmd = mysql_base + ["-e", "CREATE DATABASE IF NOT EXISTS `{}`;".format(target_db)]
                r = subprocess.run(create_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
                if r.returncode != 0:
                    errors.append("{}: failed to create {}: {}".format(db_name, target_db, r.stderr.decode('utf-8', errors='replace')))
                    continue

            # Import the dump
            import_cmd = mysql_base + [target_db]

            if per_database:
                if compress:
                    # gunzip | mysql
                    if IS_WINDOWS:
                        import gzip as _gzip
                        decomp = subprocess.Popen(["python", "-c",
                            "import gzip,sys,shutil;shutil.copyfileobj(gzip.open(sys.argv[1],'rb'),sys.stdout.buffer)",
                            dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    else:
                        decomp = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=decomp.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    decomp.stdout.close()
                    mysql_proc.wait()
                    decomp.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append("{}: import failed: {}".format(db_name, stderr[:500]))
                        continue
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append("{}: import failed: {}".format(db_name, r.stderr.decode('utf-8', errors='replace')[:500]))
                            continue
            else:
                # all_databases dump -- import without specifying target db (uses embedded USE statements)
                import_cmd = mysql_base  # no db name
                if compress:
                    if IS_WINDOWS:
                        import gzip as _gzip
                        decomp = subprocess.Popen(["python", "-c",
                            "import gzip,sys,shutil;shutil.copyfileobj(gzip.open(sys.argv[1],'rb'),sys.stdout.buffer)",
                            dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    else:
                        decomp = subprocess.Popen(["gunzip", "-c", dump_file], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    mysql_proc = subprocess.Popen(import_cmd, stdin=decomp.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                    decomp.stdout.close()
                    mysql_proc.wait()
                    decomp.wait()
                    if mysql_proc.returncode != 0:
                        stderr = mysql_proc.stderr.read().decode("utf-8", errors="replace")
                        errors.append("all_databases: import failed: {}".format(stderr[:500]))
                else:
                    with open(dump_file, "r") as f:
                        r = subprocess.run(import_cmd, stdin=f, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                        if r.returncode != 0:
                            errors.append("all_databases: import failed: {}".format(r.stderr.decode('utf-8', errors='replace')[:500]))
                # Only import once for all_databases mode
                imported.append("all databases (from {})".format(dump_file))
                break

            imported.append("{} -> {}".format(db_name, target_db))

        except Exception as e:
            errors.append("{}: {}".format(db_name, e))

    # Report final status
    if errors and not imported:
        result = "failed"
        error_log = "; ".join(errors)
    else:
        result = "completed"
        error_log = "; ".join(errors) if errors else None

    status_data = {
        "job_id": job_id,
        "result": result,
        "files_total": total,
        "files_processed": len(imported),
    }
    if error_log:
        status_data["error_log"] = error_log[:10000]
    if imported:
        status_data["output_log"] = "Imported: {}".format(', '.join(imported))

    report_status(config, status_data)


def execute_restore_mongo(config, task):
    """Restore MongoDB databases from a borg archive."""
    job_id = task.get("job_id")
    command = task.get("command", [])
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH
    env_vars = task.get("env", {})
    cwd = task.get("cwd")
    databases = task.get("databases", [])
    mongo_config = task.get("mongo_config", {})

    host = mongo_config.get("host", "127.0.0.1")
    port = str(mongo_config.get("port", 27017))
    user = mongo_config.get("user", "")
    password = mongo_config.get("password", "")
    auth_db = mongo_config.get("auth_db", "admin")
    compress = mongo_config.get("compress", True)
    dump_dir = mongo_config.get("dump_dir", "/home/bbs/mongodump")

    # Write temporary SSH key for remote SSH repos
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                _lockdown_key_windows(remote_key_path)
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    # Report running status immediately so the server tracks accurate start time
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "output_log": "Extracting database dumps from archive...",
    })

    # Step 1: Extract dump files from borg archive
    logger.info("Job #{}: Extracting MongoDB dumps from archive".format(job_id))
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    env = os.environ.copy()
    env.update(env_vars)

    try:
        proc = subprocess.run(
            command, env=env, cwd=cwd,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=3600,
        )
        if proc.returncode > 1:
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "borg extract failed: {}".format(proc.stderr.decode('utf-8', errors='replace')[:5000]),
            })
            return
    except Exception as e:
        report_status(config, {
            "job_id": job_id, "result": "failed",
            "error_log": "borg extract error: {}".format(e),
        })
        return
    finally:
        # Clean up temporary SSH key
        if remote_ssh_key and os.path.exists(remote_key_path):
            try:
                os.unlink(remote_key_path)
            except Exception:
                pass

    # Step 2: Restore each database using mongorestore
    auth_args = _mongo_auth_args(host, port, user, password, auth_db)
    imported = []
    errors = []
    total = len(databases)
    for i, db_entry in enumerate(databases):
        db_name = db_entry.get("database")
        mode = db_entry.get("mode", "replace")
        target_db = db_entry.get("target_name", "{}_copy".format(db_name)) if mode == "rename" else db_name

        # mongodump creates dump_dir/db_name/ directory
        db_dump_path = os.path.join(dump_dir, db_name)
        if not os.path.isdir(db_dump_path):
            errors.append("{}: dump directory not found at {}".format(db_name, db_dump_path))
            continue

        logger.info("Job #{}: Restoring {} as {} ({}/{})".format(job_id, db_name, target_db, i + 1, total))

        # Report progress
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "files_processed": i,
            "files_total": total,
            "output_log": "Restoring {} as {}...".format(db_name, target_db),
        })

        try:
            # Safety backup: dump the current database before replacing it
            if mode == "replace":
                safety_path = os.path.join(dump_dir, "{}_pre_restore".format(target_db))
                logger.info("Job #{}: Creating safety backup of {} to {}".format(job_id, target_db, safety_path))
                try:
                    safety_cmd = ["mongodump"] + auth_args + ["--db={}".format(target_db), "--out={}".format(dump_dir + "/_pre_restore")]
                    if compress:
                        safety_cmd.append("--gzip")
                    r_safety = subprocess.run(safety_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
                    if r_safety.returncode != 0:
                        logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(
                            job_id, target_db, r_safety.stderr.decode("utf-8", errors="replace")[:200]))
                    else:
                        logger.info("Job #{}: Safety backup saved to {}/_pre_restore/{}".format(job_id, dump_dir, target_db))
                except Exception as e:
                    logger.warning("Job #{}: Safety backup of {} failed (continuing anyway): {}".format(job_id, target_db, e))

            cmd = ["mongorestore"] + auth_args + ["--db={}".format(target_db), "--drop"]
            if compress:
                cmd.append("--gzip")
            cmd.append(db_dump_path)

            r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=3600)
            if r.returncode != 0:
                stderr = r.stderr.decode('utf-8', errors='replace')[:500]
                errors.append("{}: restore failed: {}".format(db_name, stderr))
                continue

            imported.append("{} -> {}".format(db_name, target_db))

        except Exception as e:
            errors.append("{}: {}".format(db_name, e))

    # Report final status
    if errors and not imported:
        result = "failed"
        error_log = "; ".join(errors)
    else:
        result = "completed"
        error_log = "; ".join(errors) if errors else None

    status_data = {
        "job_id": job_id,
        "result": result,
        "files_total": total,
        "files_processed": len(imported),
    }
    if error_log:
        status_data["error_log"] = error_log[:10000]
    if imported:
        status_data["output_log"] = "Imported: {}".format(', '.join(imported))

    report_status(config, status_data)


def _inhibit_sleep():
    """Prevent the OS from sleeping while a task is running.
    Returns state to pass to _allow_sleep() when done."""
    if IS_WINDOWS:
        try:
            import ctypes
            ES_CONTINUOUS = 0x80000000
            ES_SYSTEM_REQUIRED = 0x00000001
            ctypes.windll.kernel32.SetThreadExecutionState(ES_CONTINUOUS | ES_SYSTEM_REQUIRED)
            logger.debug("Sleep inhibited (Windows SetThreadExecutionState)")
            return "windows"
        except Exception as e:
            logger.debug("Could not inhibit sleep: {}".format(e))
    elif sys.platform == "darwin":
        try:
            proc = subprocess.Popen(
                ["caffeinate", "-s"],
                stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            logger.debug("Sleep inhibited (macOS caffeinate pid={})".format(proc.pid))
            return proc
        except Exception as e:
            logger.debug("Could not inhibit sleep: {}".format(e))
    else:
        try:
            proc = subprocess.Popen(
                ["systemd-inhibit", "--what=sleep", "--who=bbs-agent",
                 "--why=Backup in progress", "--mode=block", "sleep", "infinity"],
                stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            logger.debug("Sleep inhibited (systemd-inhibit pid={})".format(proc.pid))
            return proc
        except Exception as e:
            logger.debug("Could not inhibit sleep: {}".format(e))
    return None


def _allow_sleep(state):
    """Re-allow sleep after task completes."""
    if state is None:
        return
    if state == "windows":
        try:
            import ctypes
            ES_CONTINUOUS = 0x80000000
            ctypes.windll.kernel32.SetThreadExecutionState(ES_CONTINUOUS)
            logger.debug("Sleep re-enabled (Windows)")
        except Exception:
            pass
    elif hasattr(state, "terminate"):
        try:
            state.terminate()
            state.wait(timeout=5)
            logger.debug("Sleep re-enabled (killed pid={})".format(state.pid))
        except Exception:
            pass


def execute_task(config, task):
    """Execute a borg task and report progress/status."""
    job_id = task.get("job_id")
    task_type = task.get("task")
    command = task.get("command", [])
    env_vars = task.get("env", {})
    archive_name = task.get("archive_name", "")
    directories = task.get("directories", "")
    plugins = task.get("plugins", [])
    cwd = task.get("cwd")  # Working directory for extract (restore) tasks

    # Replace bare "borg" with detected absolute path (server sends "borg" but
    # it may not be in PATH on systems using SCL or non-standard installs)
    if command and command[0] == "borg" and BORG_PATH:
        command[0] = BORG_PATH

    logger.info("Executing {} job #{}: {}".format(task_type, job_id, ' '.join(command)))

    # Prevent the OS from sleeping during the task
    sleep_state = _inhibit_sleep()
    try:
        _execute_task_inner(config, task, job_id, task_type, command, env_vars,
                           archive_name, directories, plugins, cwd)
    finally:
        _allow_sleep(sleep_state)


def _execute_task_inner(config, task, job_id, task_type, command, env_vars,
                        archive_name, directories, plugins, cwd):
    """Inner task execution logic, wrapped by execute_task for sleep inhibition."""
    # Handle plugin test
    if task_type == "plugin_test":
        plugin_data = task.get("plugin", {})
        slug = plugin_data.get("slug", "")
        cfg = plugin_data.get("config", {})
        test_func = globals().get("test_plugin_{}".format(slug))
        if test_func:
            try:
                result_msg = test_func(cfg)
                report_status(config, {
                    "job_id": job_id, "result": "completed", "output_log": result_msg,
                })
            except Exception as e:
                report_status(config, {
                    "job_id": job_id, "result": "failed", "error_log": str(e),
                })
        else:
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "No test handler for plugin: {}".format(slug),
            })
        return

    # Handle MySQL database restore
    if task_type == "restore_mysql":
        execute_restore_mysql(config, task)
        return

    # Handle PostgreSQL database restore
    if task_type == "restore_pg":
        execute_restore_pg(config, task)
        return

    # Handle MongoDB database restore
    if task_type == "restore_mongo":
        execute_restore_mongo(config, task)
        return

    # Report running immediately so the UI shows activity
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "status_message": "Starting task...",
    })

    # Execute pre-backup plugins
    plugin_results = {}
    if task_type == "backup" and plugins:
        try:
            logger.info("Running {} pre-backup plugin(s)".format(len(plugins)))
            plugin_results = execute_plugins(plugins, config, job_id)
        except Exception as e:
            logger.error("Pre-backup plugin failed: {}".format(e))
            report_status(config, {
                "job_id": job_id,
                "result": "failed",
                "error_log": "Pre-backup plugin failed: {}".format(e),
            })
            return

    # Pre-count files for progress
    files_total = 0
    if task_type == "backup" and directories:
        api_request(config, "/api/agent/progress", method="POST", data={
            "job_id": job_id,
            "status_message": "Counting files...",
        })
        one_fs = "--one-file-system" in command
        files_total = count_files(directories, one_file_system=one_fs)
        logger.info("Pre-counted {} files to backup".format(files_total))

    # Report initial progress with file count
    api_request(config, "/api/agent/progress", method="POST", data={
        "job_id": job_id,
        "files_total": files_total,
        "files_processed": 0,
        "status_message": "Backing up {:,} files...".format(files_total) if task_type == "backup" else None,
    })

    # Build environment
    env = os.environ.copy()
    env.update(env_vars)

    # On Windows, translate Unix SSH paths in BORG_RSH to local paths
    # Use forward slashes - Windows SSH accepts them and backslashes get
    # stripped as escape characters when passed through subprocess/shell
    if IS_WINDOWS and "BORG_RSH" in env:
        env["BORG_RSH"] = env["BORG_RSH"].replace(
            "/etc/bbs-agent/ssh_key", SSH_KEY_PATH.replace("\\", "/")
        ).replace(
            "/tmp/bbs-remote-ssh-key", REMOTE_KEY_PATH.replace("\\", "/")
        ).replace(
            "/dev/null", "NUL"
        )
        # Replace bare "ssh" command with our bundled ssh.exe to avoid the
        # Windows built-in OpenSSH stdin forwarding bug that hangs borg.
        if SSH_CMD != "ssh" and env["BORG_RSH"].startswith("ssh "):
            env["BORG_RSH"] = '"' + SSH_CMD + '"' + env["BORG_RSH"][3:]

    # Always allow relocated repos - common after S3 restore or copying repositories
    # This prevents "repository was previously located at X" interactive prompts
    env["BORG_RELOCATED_REPO_ACCESS_IS_OK"] = "yes"
    env["BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK"] = "yes"

    # Ensure UTF-8 locale for borg (handles filenames with non-ASCII characters)
    if not IS_WINDOWS and ("LC_ALL" not in env or not env["LC_ALL"].endswith("UTF-8")):
        env["LC_ALL"] = "C.UTF-8"
        env["LANG"] = "C.UTF-8"

    # Clear stale cache locks left by crashed borg processes
    clear_stale_cache_locks()

    # Execute borg command
    files_processed = 0
    original_size = 0
    deduplicated_size = 0
    # Restore-only counters. borg extract's progress_percent carries byte
    # offsets (not file counts), and its --list output carries file entries
    # (one per extracted item) — track both so we can report accurate
    # Files Total/Processed + Bytes Total/Processed to the server.
    bytes_processed = 0
    bytes_total = 0
    error_output = ""
    had_warnings = False
    last_progress_time = time.time()
    catalog_count = 0
    catalog_ssh = None  # SSH subprocess for streaming catalog to server
    catalog_pipe_failed = False

    if task_type == "backup":
        ssh_info = load_ssh_info()
        if ssh_info and ssh_info.get("ssh_unix_user") and ssh_info.get("server_host"):
            try:
                known_hosts_null = "NUL" if IS_WINDOWS else "/dev/null"
                catalog_ssh = subprocess.Popen(
                    [
                        SSH_CMD,
                        "-i", SSH_KEY_PATH,
                        "-p", str(ssh_info.get("ssh_port", 22)),
                        "-o", "StrictHostKeyChecking=no",
                        "-o", "UserKnownHostsFile={}".format(known_hosts_null),
                        "-o", "BatchMode=yes",
                        "{}@{}".format(ssh_info['ssh_unix_user'], ssh_info['server_host']),
                        "catalog-write {}".format(job_id),
                    ],
                    stdin=subprocess.PIPE,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                )
                logger.info("Catalog SSH pipe opened for job {}".format(job_id))
            except Exception as e:
                logger.warning("Could not open catalog SSH pipe: {}".format(e))
                catalog_ssh = None
        else:
            logger.info("SSH info not available, catalog streaming disabled")

    # For restore tasks, create and use the target directory
    if cwd:
        os.makedirs(cwd, exist_ok=True)

    # Write temporary SSH key for remote SSH repos (key provided by server in task payload)
    remote_ssh_key = task.get("remote_ssh_key")
    remote_key_path = REMOTE_KEY_PATH
    if remote_ssh_key:
        try:
            # Normalize line endings (Windows \r\n -> Unix \n) and ensure trailing newline
            normalized_key = remote_ssh_key.replace("\r\n", "\n").replace("\r", "\n").rstrip() + "\n"
            with open(remote_key_path, "w") as kf:
                kf.write(normalized_key)
            if IS_WINDOWS:
                _lockdown_key_windows(remote_key_path)
            else:
                os.chmod(remote_key_path, 0o600)
            logger.info("Wrote temporary SSH key for remote repo")
        except Exception as e:
            logger.error("Failed to write remote SSH key: {}".format(e))
            report_status(config, {
                "job_id": job_id, "result": "failed",
                "error_log": "Failed to write remote SSH key: {}".format(e),
            })
            return

    try:
        popen_kwargs = {
            "stdout": subprocess.PIPE,
            "stderr": subprocess.PIPE,
            "env": env,
            "cwd": cwd,
        }
        popen_kwargs.update(_popen_new_group_kwargs())
        proc = subprocess.Popen(command, **popen_kwargs)
        global current_borg_proc
        current_borg_proc = proc

        # Read stderr for JSON log output (borg writes progress to stderr)
        for raw_line in proc.stderr:
            line = raw_line.decode("utf-8", errors="replace")
            line = line.strip()
            if not line:
                continue

            # Try to parse JSON log entries from borg
            try:
                entry = json.loads(line)
                msg_type = entry.get("type", "")

                if msg_type == "archive_progress":
                    files_processed = entry.get("nfiles", files_processed)
                    original_size = entry.get("original_size", original_size)

                    # Report progress every 5 seconds
                    now = time.time()
                    if now - last_progress_time >= 5:
                        progress_data = {
                            "job_id": job_id,
                            "files_total": files_total,
                            "files_processed": files_processed,
                            "bytes_processed": original_size,
                        }
                        progress_resp = api_request(
                            config,
                            "/api/agent/progress",
                            method="POST",
                            data=progress_data,
                        )
                        last_progress_time = now

                        # Check for server-side cancellation
                        if isinstance(progress_resp, dict) and progress_resp.get("cancel"):
                            logger.warning("Job #{} cancelled by server — killing borg process tree".format(job_id))
                            # Kill the whole process group so borg's ssh transport
                            # child gets reaped too — otherwise it stays alive
                            # holding our stdout pipe and the next read() blocks
                            # the agent main loop indefinitely.
                            _kill_process_tree(proc)
                            try:
                                proc.wait(timeout=10)
                            except subprocess.TimeoutExpired:
                                logger.warning("borg did not exit after kill, continuing anyway")
                            result = "failed"
                            error_output = "Cancelled by user"
                            # Close catalog SSH pipe if open
                            if catalog_ssh:
                                try:
                                    catalog_ssh.stdin.close()
                                except Exception:
                                    pass
                                _kill_process_tree(catalog_ssh)
                                catalog_ssh = None
                            break

                elif msg_type == "progress_percent":
                    # Borg emits these during long operations when --progress
                    # is passed — in particular for extract/restore where the
                    # old backup-centric "archive_progress" handler never
                    # fires. Forward "current / total" to /api/agent/progress
                    # so the restore UI shows a live bar instead of staying
                    # pinned at "Starting task..." (#168).
                    cur = entry.get("current")
                    tot = entry.get("total")
                    if cur is not None and tot not in (None, 0):
                        # For extract these are byte offsets, not file
                        # counts — track them as bytes. File counts are
                        # driven separately by file_status events below.
                        if task_type != "backup":
                            bytes_processed = int(cur)
                            bytes_total = int(tot)
                        now = time.time()
                        if now - last_progress_time >= 3:
                            # For backup we never hit this branch (archive_progress
                            # drives the UI), so the "cur/tot as files" usage
                            # below is restore-only and is actually bytes —
                            # sent as bytes_* to the server.
                            api_request(config, "/api/agent/progress", method="POST", data={
                                "job_id": job_id,
                                "bytes_total": int(tot),
                                "bytes_processed": int(cur),
                                "files_processed": files_processed,
                            })
                            last_progress_time = now

                elif msg_type in ("file_status", "file_item") and task_type == "backup" and catalog_ssh:
                    # Stream file entry to server via SSH pipe
                    fpath = entry.get("path", "")
                    fsize = 0
                    fmtime = None
                    if fpath:
                        try:
                            if IS_WINDOWS:
                                stat_path = fpath
                            else:
                                stat_path = "/" + fpath if not fpath.startswith("/") else fpath
                            st = os.stat(stat_path)
                            fsize = st.st_size
                            fmtime = datetime.datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S")
                        except (OSError, UnicodeEncodeError):
                            pass
                    line = json.dumps({
                        "path": fpath,
                        "status": entry.get("status", "U")[0].upper(),
                        "size": fsize,
                        "mtime": fmtime,
                    }) + "\n"
                    try:
                        catalog_ssh.stdin.write(line.encode("utf-8"))
                        catalog_ssh.stdin.flush()
                    except (BrokenPipeError, OSError):
                        try:
                            broken_stderr = catalog_ssh.stderr.read().decode("utf-8", errors="replace").strip()
                        except Exception:
                            broken_stderr = ""
                        logger.error("Catalog SSH pipe broken, catalog streaming stopped{}".format(
                            " — " + broken_stderr if broken_stderr else ""))
                        catalog_ssh = None
                        catalog_pipe_failed = True
                    catalog_count += 1

                elif msg_type == "log_message":
                    log_level = entry.get("levelname", "INFO")
                    message = entry.get("message", "")
                    if log_level in ("WARNING", "ERROR", "CRITICAL"):
                        error_output += message + "\n"
                        logger.warning("borg: {}".format(message))
                    elif (entry.get("name") == "borg.output.list"
                          and task_type != "backup"):
                        # borg extract --list writes one INFO log_message
                        # per extracted item on the "borg.output.list"
                        # logger (confirmed in borg 1.4). That's the only
                        # reliable per-file signal during restore — count
                        # them so the server's Files Processed field is
                        # accurate.
                        files_processed += 1

            except ValueError:
                # Non-JSON output, might be regular progress text
                if "Error" in line or "error" in line:
                    error_output += line + "\n"
                logger.debug("borg: {}".format(line))

        # Wait for process to complete (skip if already killed by cancellation)
        job_cancelled = (error_output == "Cancelled by user")
        if not job_cancelled:
            proc.wait(timeout=86400)  # 24h timeout
            # Safe to read here — borg exited normally so all pipe writers are gone.
            stdout_output = proc.stdout.read().decode("utf-8", errors="replace")
        else:
            # After a cancel kill, never block on stdout.read(): if borg's ssh
            # transport child somehow survived the group kill it would still
            # hold the pipe writer end and read() would hang the agent forever
            # (hangs were observed pre-2.24.1 leaving the agent stuck until
            # service restart). Drain via communicate() with a hard timeout.
            try:
                drained_stdout, _ = proc.communicate(timeout=10)
                if isinstance(drained_stdout, bytes):
                    stdout_output = drained_stdout.decode("utf-8", errors="replace")
                else:
                    stdout_output = drained_stdout or ""
            except subprocess.TimeoutExpired:
                logger.warning("Job #{}: stdout drain timed out after cancel — re-killing tree".format(job_id))
                _kill_process_tree(proc)
                try:
                    proc.wait(timeout=5)
                except Exception:
                    pass
                # Close pipes manually so the agent loop doesn't get stuck
                for pipe in (proc.stdout, proc.stderr, proc.stdin):
                    try:
                        if pipe is not None:
                            pipe.close()
                    except Exception:
                        pass
                stdout_output = ""

        # Parse borg info from stdout if available
        if stdout_output:
            try:
                borg_result = json.loads(stdout_output)
                if "archive" in borg_result:
                    stats = borg_result["archive"].get("stats", {})
                    original_size = stats.get("original_size", original_size)
                    deduplicated_size = stats.get("deduplicated_size", deduplicated_size)
                    files_processed = stats.get("nfiles", files_processed)
            except (ValueError, KeyError):
                pass

        if proc.returncode == 0:
            # borg returned success, but for restore that doesn't guarantee
            # anything was actually written — if the path filter matched
            # zero archive entries, borg extract still exits 0. Catch that
            # so the server doesn't show a green check on a no-op restore.
            if task_type != "backup" and files_processed == 0:
                # borg extract --list emits one file_status per item, so
                # files_processed stays 0 only when the path filter
                # matched nothing at all (directories count too, which
                # is why we use file count rather than bytes).
                result = "failed"
                error_output = "Restore extracted nothing — the requested path may not exist in this archive"
                logger.error("Job #{} failed: nothing extracted".format(job_id))
            else:
                result = "completed"
                if task_type == "backup":
                    logger.info(
                        "Job #{} completed: {} files, "
                        "{} bytes original, {} bytes dedup".format(job_id, files_processed, original_size, deduplicated_size)
                    )
                else:
                    logger.info(
                        "Job #{} completed: {} files, {} bytes restored".format(
                            job_id, files_processed, bytes_processed)
                    )
        elif job_cancelled:
            pass  # Already set result='failed', error_output='Cancelled by user'
        elif proc.returncode == 1:
            # borg returns 1 for warnings. For backup the archive is
            # usually still useful (a file vanished mid-read, or — more
            # importantly — a configured source path didn't exist and was
            # silently skipped, #203). Mark the job completed-with-warnings
            # so the server can decorate the UI and fire a backup_warning
            # notification instead of pretending it was a clean success.
            # For restore (and any other op) exit 1 means at least one
            # file failed to extract — fail the job outright.
            if task_type == "backup":
                result = "completed"
                had_warnings = True
                if not error_output:
                    error_output = "borg exited with warnings (code 1) — see job log for details"
                logger.warning("Job #{} completed with warnings".format(job_id))
            else:
                result = "failed"
                if not error_output:
                    error_output = "borg exited with warnings (code 1) — see job log for details"
                logger.error("Job #{} failed with warnings (borg code 1)".format(job_id))
        else:
            result = "failed"
            logger.error(
                "Job #{} failed with return code {}".format(job_id, proc.returncode)
            )
            if not error_output:
                error_output = "borg exited with code {}".format(proc.returncode)

    except subprocess.TimeoutExpired:
        proc.kill()
        result = "failed"
        error_output = "Task timed out after 24 hours"
        logger.error("Job #{} timed out".format(job_id))
    except FileNotFoundError:
        result = "failed"
        error_output = "borg command not found"
        logger.error("Job #{}: borg not found".format(job_id))
    except Exception as e:
        result = "failed"
        error_output = str(e)
        logger.error("Job #{} error: {}".format(job_id, e))
    finally:
        current_borg_proc = None

        # Close the catalog SSH pipe
        catalog_ssh_error = ""
        if catalog_ssh:
            try:
                catalog_ssh.stdin.close()
                catalog_ssh.wait(timeout=30)
                if catalog_ssh.returncode != 0:
                    catalog_ssh_error = catalog_ssh.stderr.read().decode("utf-8", errors="replace").strip()
                    logger.error("Catalog SSH pipe exited with code {} — {}".format(
                        catalog_ssh.returncode, catalog_ssh_error or "no stderr"))
                    catalog_pipe_failed = True
                else:
                    logger.info("Catalog SSH pipe closed, {} entries streamed".format(catalog_count))
            except Exception as e:
                logger.error("Error closing catalog SSH pipe: {}".format(e))
                catalog_pipe_failed = True
                try:
                    catalog_ssh_error = catalog_ssh.stderr.read().decode("utf-8", errors="replace").strip()
                except Exception:
                    pass
                try:
                    catalog_ssh.kill()
                except Exception:
                    pass

    # Catalog pipe failure is non-critical — the backup data is safely stored.
    # Log a warning but don't override a successful backup result.
    if result == "completed" and catalog_pipe_failed:
        warning = "Backup completed but catalog streaming failed"
        if catalog_ssh_error:
            warning += " — {}".format(catalog_ssh_error)
        if not error_output:
            error_output = warning
        logger.warning("Job #{}: {}".format(job_id, warning))

    # Build status data. For restore (and other non-backup tasks) the
    # byte counts come from progress_percent and the file counts come
    # from file_status events; original_size/deduplicated_size aren't
    # meaningful since borg extract doesn't emit archive stats.
    if task_type == "backup":
        status_data = {
            "job_id": job_id,
            "files_total": files_total if files_total else files_processed,
            "files_processed": files_processed,
            "original_size": original_size,
            "deduplicated_size": deduplicated_size,
            "bytes_total": original_size,
            "bytes_processed": original_size,
        }
    else:
        status_data = {
            "job_id": job_id,
            "files_total": files_processed,
            "files_processed": files_processed,
            "bytes_total": bytes_total if bytes_total else bytes_processed,
            "bytes_processed": bytes_processed,
        }

    if archive_name:
        status_data["archive_name"] = archive_name
    if error_output:
        status_data["error_log"] = error_output[:10000]  # Limit size
    if had_warnings:
        status_data["had_warnings"] = True

    # Report backed-up databases from mysql_dump plugin
    if result == "completed" and task_type == "backup" and plugin_results.get("mysql_dump"):
        mysql_result = plugin_results["mysql_dump"]
        status_data["databases_backed_up"] = {
            "databases": mysql_result.get("databases", []),
            "per_database": mysql_result.get("per_database", True),
            "compress": mysql_result.get("compress", True),
        }

    # Report backed-up databases from pg_dump plugin
    if result == "completed" and task_type == "backup" and plugin_results.get("pg_dump"):
        pg_result = plugin_results["pg_dump"]
        status_data["databases_backed_up"] = {
            "databases": pg_result.get("databases", []),
            "per_database": True,
            "compress": pg_result.get("compress", True),
        }

    # Report backed-up databases from mongo_dump plugin
    if result == "completed" and task_type == "backup" and plugin_results.get("mongo_dump"):
        mongo_result = plugin_results["mongo_dump"]
        status_data["databases_backed_up"] = {
            "databases": mongo_result.get("databases", []),
            "per_database": mongo_result.get("per_database", True),
            "compress": mongo_result.get("compress", True),
        }

    # Report final status to server. For completed backups with catalog,
    # the server will detect and import the catalog file from disk.
    status_data["result"] = result
    report_status(config, status_data)

    # Run post-backup plugin cleanup (always run shell_hook post-scripts to
    # restart services even if backup failed; other cleanup only on success)
    if task_type == "backup" and plugins:
        cleanup_plugins(plugins, plugin_results, config, job_id, backup_result=result)

    # Clean up temporary SSH key for remote repos
    if remote_ssh_key and os.path.exists(remote_key_path):
        try:
            os.unlink(remote_key_path)
            logger.info("Cleaned up temporary SSH key")
        except Exception as e:
            logger.warning("Failed to clean up temporary SSH key: {}".format(e))


def clear_stale_cache_locks():
    """Remove stale borg cache locks that can block operations after a crash."""
    if IS_WINDOWS:
        cache_dir = os.path.join(os.environ.get("LOCALAPPDATA", os.path.expanduser("~")), "borg", "Cache")
    else:
        cache_dir = os.path.expanduser("~/.cache/borg")
    if not os.path.isdir(cache_dir):
        return
    import shutil
    for entry in os.listdir(cache_dir):
        lock_path = os.path.join(cache_dir, entry, "lock.exclusive")
        if os.path.exists(lock_path):
            try:
                if os.path.isdir(lock_path):
                    shutil.rmtree(lock_path)
                else:
                    os.remove(lock_path)
                logger.info("Cleared stale cache lock: {}".format(lock_path))
            except Exception as e:
                logger.warning("Could not clear cache lock {}: {}".format(lock_path, e))


def signal_handler(signum, frame):
    global running
    logger.info("Shutdown signal received")
    running = False


def heartbeat_thread(config):
    """Background thread that sends heartbeats while tasks are running.

    This prevents the agent from appearing offline during long-running
    backup operations. The thread only sends heartbeats when task_running
    is True, and exits when running becomes False.

    Also processes stall-detection signals from the server: if the server
    sees a job with no progress for >10 minutes, the heartbeat response
    includes a check_jobs list. If the stalled job matches the currently
    running borg process, we kill it so the agent can recover.
    """
    global running, task_running, current_job_id, current_borg_proc
    heartbeat_interval = max(config.get("poll_interval", 30), 10)

    while running:
        if task_running:
            try:
                resp = api_request(config, "/api/agent/heartbeat", method="POST", data={})

                if isinstance(resp, dict):
                    # Handle stall check — server says these jobs have no progress
                    stalled_ids = resp.get("check_jobs", [])
                    if stalled_ids and current_job_id in stalled_ids and current_borg_proc is not None:
                        logger.warning("Server reports job #{} stalled (no progress >10min) — killing borg".format(current_job_id))
                        _kill_process_tree(current_borg_proc)

                    # Handle cancel signal relayed through heartbeat
                    cancel_id = resp.get("cancel_job")
                    if cancel_id and cancel_id == current_job_id and current_borg_proc is not None:
                        logger.warning("Job #{} cancelled by server (via heartbeat) — killing borg".format(current_job_id))
                        _kill_process_tree(current_borg_proc)

            except Exception as e:
                logger.debug("Heartbeat failed: {}".format(e))

        # Sleep in small increments so we can exit promptly
        for _ in range(heartbeat_interval):
            if not running:
                break
            time.sleep(1)


def main():
    global running, task_running, current_job_id

    setup_logging()
    logger.info("BBS Agent v{} starting".format(AGENT_VERSION))
    if IS_WINDOWS and SSH_CMD != "ssh":
        logger.info("Using bundled SSH: {}".format(SSH_CMD))

    signal.signal(signal.SIGINT, signal_handler)
    if IS_WINDOWS:
        try:
            signal.signal(signal.SIGBREAK, signal_handler)
        except (AttributeError, OSError):
            pass
    else:
        signal.signal(signal.SIGTERM, signal_handler)

    config = load_config()

    # Register with server
    if not register(config):
        logger.error("Failed to register, retrying in 30s...")
        time.sleep(30)
        if not register(config):
            logger.error("Registration failed after retry, exiting")
            sys.exit(1)

    logger.info(
        "Polling {} every {}s".format(config['server_url'], config['poll_interval'])
    )

    # Start heartbeat thread for keeping alive during long tasks
    hb_thread = threading.Thread(target=heartbeat_thread, args=(config,), daemon=True)
    hb_thread.start()

    # Re-report system info hourly so manual borg updates on clients that
    # can't use auto-update (armv7l etc.) are picked up by the server (#198).
    last_info_report = time.time()
    info_report_interval = 3600

    while running:
        try:
            # Poll for tasks
            result = api_request(config, "/api/agent/tasks")

            if time.time() - last_info_report >= info_report_interval:
                try:
                    api_request(config, "/api/agent/info", method="POST", data=get_system_info())
                except Exception as e:
                    logger.debug("Periodic info report failed: {}".format(e))
                last_info_report = time.time()

            # Update poll interval if server sends one
            if result and "poll_interval" in result:
                config["poll_interval"] = int(result["poll_interval"])

            # Handle stall checks — server asks about jobs it thinks may be stalled.
            # Process BEFORE new tasks so stale jobs get resolved first.
            if result and result.get("check_jobs"):
                for cj_id in result["check_jobs"]:
                    if cj_id == current_job_id:
                        # We're actively running this one — server will see progress
                        logger.debug("Server asked about job #{} — currently running".format(cj_id))
                        continue
                    logger.warning("Server asked about job #{} — not running, reporting abandoned".format(cj_id))
                    report_status(config, {"job_id": cj_id, "result": "abandoned"})

            if result and result.get("tasks"):
                for task in result["tasks"]:
                    if not running:
                        break

                    task_running = True
                    current_job_id = task.get("job_id")
                    try:
                        if task.get("task") == "update_borg":
                            execute_update_borg(config, task)
                        elif task.get("task") == "update_agent":
                            execute_update_agent(config, task)
                        else:
                            execute_task(config, task)
                    finally:
                        task_running = False
                        current_job_id = None
            elif result is None:
                # Connection error -- server might be down
                logger.warning("Failed to poll server, will retry")

        except Exception as e:
            logger.error("Poll loop error: {}".format(e))

        # Wait for next poll
        for _ in range(config["poll_interval"]):
            if not running:
                break
            time.sleep(1)

    logger.info("Agent stopped")


if __name__ == "__main__":
    main()

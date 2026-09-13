#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Borg Backup Server - Windows Agent Installer

.DESCRIPTION
    Installs the BBS agent as a Windows Service with zero dependencies.
    Downloads and installs borg, the agent launcher, and configures the service.

.PARAMETER Server
    The BBS server URL (e.g., https://backups.example.com)

.PARAMETER Key
    The agent API key from the BBS dashboard

.EXAMPLE
    .\install-windows.ps1 -Server https://backups.example.com -Key abc123
#>

param(
    [Parameter(Mandatory=$true)]
    [string]$Server,

    [Parameter(Mandatory=$true)]
    [string]$Key
)

$ErrorActionPreference = "Stop"

# -----------------------------------------------------------------------------
# OS version gate (#274)
# -----------------------------------------------------------------------------
# The agent requires Windows 10 1607 / Server 2016 (build 14393) or newer.
# Older versions ship with PowerShell defaults, TLS stacks, and Python 3
# runtime support that this installer and the agent itself do not target.
# Common failures on older OS versions: TLS handshake errors against the
# BBS server, missing .NET classes for service installation, Python 3
# refusing to install or run. Bail out with a clear message rather than
# letting the user discover incompatibility halfway through a partial
# install. Affected versions that hit this check: Server 2012 / 2012 R2,
# Windows 8 / 8.1, Windows 7, Server 2008 R2 - all are out of mainstream
# Microsoft support and should be upgraded before running the agent.
$osVersion = [Environment]::OSVersion.Version
$osBuild   = $osVersion.Build
$osMajor   = $osVersion.Major
if ($osMajor -lt 10 -or $osBuild -lt 14393) {
    $productName = "Unknown Windows version"
    try {
        $productName = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion' -ErrorAction SilentlyContinue).ProductName
    } catch { }

    Write-Host ""
    Write-Host "===========================================================================" -ForegroundColor Red
    Write-Host "  Unsupported Windows version" -ForegroundColor Red
    Write-Host "===========================================================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "  Detected:  $productName (build $osBuild)" -ForegroundColor Yellow
    Write-Host "  Required:  Windows 10 1607+, Windows 11, Windows Server 2016+, or newer" -ForegroundColor Yellow
    Write-Host "             (NT kernel 10.0, build 14393 or higher)"
    Write-Host ""
    Write-Host "  The BBS agent depends on TLS 1.2 defaults, modern PowerShell"
    Write-Host "  behaviour, and a Python 3 runtime that older Windows builds do not"
    Write-Host "  support. Older Server editions (2012, 2012 R2) and client editions"
    Write-Host "  (7, 8, 8.1) are also out of mainstream Microsoft support."
    Write-Host ""
    Write-Host "  Please upgrade the operating system before installing the agent."
    Write-Host ""
    exit 1
}

# Force TLS 1.2+ (PowerShell 5.1 defaults to TLS 1.0 which most servers reject)
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls13

# -----------------------------------------------------------------------------
# Configuration
# -----------------------------------------------------------------------------
$ServiceName    = "BorgBackupAgent"
$ServiceDisplay = "Borg Backup Server Agent"
$BorgDir        = "$env:ProgramFiles\BorgBackup"
$AgentDir       = "$env:ProgramData\bbs-agent"
$ConfigPath     = "$AgentDir\config.ini"
$Server         = $Server.TrimEnd("/")

# -----------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------
function Write-Step   { param($msg) Write-Host "  -> $msg" -ForegroundColor Cyan }
function Write-Ok     { param($msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Warn   { param($msg) Write-Host "  [!] $msg" -ForegroundColor Yellow }
function Write-Fail   { param($msg) Write-Host "  [X] $msg" -ForegroundColor Red }

# -----------------------------------------------------------------------------
# Banner
# -----------------------------------------------------------------------------
Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host "    Borg Backup Server - Windows Agent Installer" -ForegroundColor Blue
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host ""

# -----------------------------------------------------------------------------
# Validate server connectivity
# -----------------------------------------------------------------------------
Write-Step "Checking server connectivity..."
try {
    $resp = Invoke-WebRequest -Uri "$Server/api/agent/tasks" `
        -Headers @{ "Authorization" = "Bearer $Key" } `
        -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
    Write-Ok "Server reachable"
} catch {
    Write-Fail "Cannot reach server at $Server"
    Write-Fail "Check the URL and API key, then try again."
    exit 1
}

# -----------------------------------------------------------------------------
# Stop existing service if upgrading
# -----------------------------------------------------------------------------
$existingSvc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existingSvc) {
    Write-Step "Stopping existing service..."
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    # Kill any lingering borg processes that may hold locks on DLLs
    Get-Process -Name "borg" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Get-Process -Name "bbs-agent" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Write-Ok "Existing service stopped"
}

# -----------------------------------------------------------------------------
# Install / Update Borg
# -----------------------------------------------------------------------------
# The borg-windows zip layout has shifted between releases. v1.4.3 and earlier
# shipped "borg/borg.exe" under a subdirectory; v1.4.4-win6 ships "borg.exe"
# at the zip root next to its support files. Instead of hardcoding one layout,
# locate borg.exe after extraction wherever it landed.
$borgZip = "$env:TEMP\borg-windows.zip"

# Remove everything under $BorgDir except ssh\ (MinGit is managed separately).
# This clears any leftover files from a previous layout so mixed state from an
# old "borg\" subdir and a new flat layout can't coexist (#180).
if (Test-Path $BorgDir) {
    Write-Step "Removing old Borg installation..."
    $oldItems = Get-ChildItem -Path $BorgDir -Force -ErrorAction SilentlyContinue | Where-Object { $_.Name -ne 'ssh' }
    for ($i = 1; $i -le 5; $i++) {
        $failed = $false
        foreach ($item in $oldItems) {
            try {
                Remove-Item -Path $item.FullName -Recurse -Force -ErrorAction Stop
            } catch {
                $failed = $true
                break
            }
        }
        if (-not $failed) { Write-Ok "Old borg binaries removed"; break }
        if ($i -eq 5) {
            Write-Fail "Cannot clean $BorgDir - files may be locked by another process"
            Write-Fail "Close any open terminals or Explorer windows in that folder and try again"
            exit 1
        }
        Write-Warn "Retrying removal ($i/5)..."
        Start-Sleep -Seconds 2
    }
}

Write-Step "Finding latest Borg for Windows release..."
try {
    $releaseInfo = Invoke-WebRequest -Uri "https://api.github.com/repos/marcpope/borg-windows/releases/latest" `
        -UseBasicParsing -TimeoutSec 15 | ConvertFrom-Json
    $borgZipUrl = ($releaseInfo.assets | Where-Object { $_.name -eq "borg-windows.zip" }).browser_download_url
    if (-not $borgZipUrl) {
        Write-Fail "Could not find borg-windows.zip in latest release"
        exit 1
    }
    Write-Ok "Latest release: $($releaseInfo.tag_name)"
} catch {
    Write-Fail "Failed to query GitHub releases: $_"
    exit 1
}

Write-Step "Downloading $($releaseInfo.tag_name)..."
try {
    Invoke-WebRequest -Uri $borgZipUrl -OutFile $borgZip -UseBasicParsing
    Write-Ok "Downloaded borg-windows.zip"
} catch {
    Write-Fail "Failed to download Borg: $_"
    exit 1
}

Write-Step "Installing Borg to $BorgDir..."
New-Item -ItemType Directory -Path $BorgDir -Force | Out-Null
Expand-Archive -Path $borgZip -DestinationPath $BorgDir -Force
Remove-Item $borgZip -Force -ErrorAction SilentlyContinue

# Locate borg.exe wherever the archive put it (excluding ssh\ so we don't pick
# up an ssh-bundled tool with the same name).
$foundBorg = Get-ChildItem -Path $BorgDir -Filter "borg.exe" -Recurse -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -notmatch '\\ssh\\' } |
    Select-Object -First 1
if (-not $foundBorg) {
    Write-Fail "Borg installation failed - borg.exe not found anywhere under $BorgDir"
    exit 1
}
$borgExe    = $foundBorg.FullName
$borgBinDir = $foundBorg.Directory.FullName
$borgVer = & $borgExe --version 2>&1 | Select-Object -First 1
Write-Ok "Installed: $borgVer"
Write-Ok "Location:  $borgExe"

# Add borg's directory to system PATH
Write-Step "Adding Borg to system PATH..."
$machinePath = [Environment]::GetEnvironmentVariable("Path", "Machine")
if ($machinePath -notlike "*$borgBinDir*") {
    [Environment]::SetEnvironmentVariable("Path", "$machinePath;$borgBinDir", "Machine")
    $env:Path = "$env:Path;$borgBinDir"
    Write-Ok "Added $borgBinDir to system PATH"
} else {
    Write-Ok "Already in PATH"
}

# -----------------------------------------------------------------------------
# Install SSH client (Git for Windows bundled ssh avoids Windows built-in bugs)
# -----------------------------------------------------------------------------
$sshDir = "$BorgDir\ssh"
$sshExe = "$sshDir\usr\bin\ssh.exe"
$sshPathFile = "$AgentDir\ssh-path"

# Ensure the agent directory exists before writing the ssh-path marker file.
# On fresh installs, ProgramData\bbs-agent doesn't exist yet - WriteAllText
# fails with "Parts of the Path could not be found" (#195).
New-Item -ItemType Directory -Path $AgentDir -Force | Out-Null

# Check if Git for Windows is already installed
$gitSshPaths = @(
    "$env:ProgramFiles\Git\usr\bin\ssh.exe",
    "${env:ProgramFiles(x86)}\Git\usr\bin\ssh.exe"
)
$existingGitSsh = $gitSshPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if ($existingGitSsh) {
    Write-Ok "Found Git for Windows SSH: $existingGitSsh"
    [System.IO.File]::WriteAllText($sshPathFile, $existingGitSsh, (New-Object System.Text.UTF8Encoding $false))
} else {
    # Download MinGit (minimal Git for Windows distribution with bundled ssh)
    Write-Step "Finding latest MinGit release for bundled SSH..."
    try {
        $gitRelease = Invoke-WebRequest -Uri "https://api.github.com/repos/git-for-windows/git/releases/latest" `
            -UseBasicParsing -TimeoutSec 15 | ConvertFrom-Json
        $minGitUrl = ($gitRelease.assets | Where-Object {
            $_.name -match "MinGit-.*-busybox-64-bit\.zip$"
        }).browser_download_url | Select-Object -First 1

        if (-not $minGitUrl) {
            # Fall back to non-busybox MinGit if busybox variant isn't available
            $minGitUrl = ($gitRelease.assets | Where-Object {
                $_.name -match "MinGit-.*64-bit\.zip$" -and $_.name -notmatch "portable"
            }).browser_download_url | Select-Object -First 1
        }

        if ($minGitUrl) {
            Write-Ok "Latest MinGit: $($gitRelease.tag_name)"
            $minGitZip = "$env:TEMP\mingit.zip"

            Write-Step "Downloading MinGit (bundled SSH client)..."
            Invoke-WebRequest -Uri $minGitUrl -OutFile $minGitZip -UseBasicParsing
            Write-Ok "Downloaded MinGit"

            Write-Step "Installing MinGit SSH to $sshDir..."
            if (Test-Path $sshDir) {
                Remove-Item -Path $sshDir -Recurse -Force -ErrorAction SilentlyContinue
            }
            New-Item -ItemType Directory -Path $sshDir -Force | Out-Null
            Expand-Archive -Path $minGitZip -DestinationPath $sshDir -Force
            Remove-Item $minGitZip -Force -ErrorAction SilentlyContinue

            if (Test-Path $sshExe) {
                Write-Ok "Installed bundled SSH: $sshExe"
                [System.IO.File]::WriteAllText($sshPathFile, $sshExe, (New-Object System.Text.UTF8Encoding $false))
            } else {
                # MinGit layout might differ -search for ssh.exe
                $foundSsh = Get-ChildItem -Path $sshDir -Recurse -Filter "ssh.exe" | Select-Object -First 1
                if ($foundSsh) {
                    Write-Ok "Installed bundled SSH: $($foundSsh.FullName)"
                    [System.IO.File]::WriteAllText($sshPathFile, $foundSsh.FullName, (New-Object System.Text.UTF8Encoding $false))
                } else {
                    Write-Warn "MinGit extracted but ssh.exe not found -will use system SSH"
                }
            }
        } else {
            Write-Warn "Could not find MinGit download URL -will use system SSH"
        }
    } catch {
        Write-Warn "Failed to install MinGit SSH: $_ -will use system SSH"
    }
}

# -----------------------------------------------------------------------------
# Download agent files
# -----------------------------------------------------------------------------
Write-Step "Creating agent directory..."
New-Item -ItemType Directory -Path $AgentDir -Force | Out-Null

Write-Step "Downloading agent launcher..."
try {
    Invoke-WebRequest -Uri "$Server/api/agent/download?file=bbs-agent.exe" `
        -OutFile "$AgentDir\bbs-agent.exe" -UseBasicParsing
    Write-Ok "Downloaded bbs-agent.exe"
} catch {
    Write-Fail "Failed to download agent launcher: $_"
    exit 1
}

Write-Step "Downloading agent script..."
try {
    Invoke-WebRequest -Uri "$Server/api/agent/download?file=bbs-agent.py" `
        -OutFile "$AgentDir\bbs-agent-run.py" -UseBasicParsing
    Write-Ok "Downloaded bbs-agent-run.py"
} catch {
    Write-Fail "Failed to download agent script: $_"
    exit 1
}

# -----------------------------------------------------------------------------
# Install Python embeddable (zero-dependency Python runtime for the agent)
# -----------------------------------------------------------------------------
$pythonDir = "$AgentDir\python"
$pythonExe = "$pythonDir\python.exe"

if (Test-Path $pythonExe) {
    $pyVer = & $pythonExe --version 2>&1 | Select-Object -First 1
    Write-Ok "Python already installed: $pyVer"
} else {
    Write-Step "Downloading Python embeddable..."
    $pyZipUrl = "https://www.python.org/ftp/python/3.11.4/python-3.11.4-embed-amd64.zip"
    $pyZip = "$env:TEMP\python-embed.zip"

    try {
        Invoke-WebRequest -Uri $pyZipUrl -OutFile $pyZip -UseBasicParsing
        Write-Ok "Downloaded Python embeddable"
    } catch {
        Write-Fail "Failed to download Python: $_"
        Write-Warn "Install Python 3.9+ manually and ensure it is in PATH"
        $pyZip = $null
    }

    if ($pyZip -and (Test-Path $pyZip)) {
        Write-Step "Installing Python to $pythonDir..."
        New-Item -ItemType Directory -Path $pythonDir -Force | Out-Null
        Expand-Archive -Path $pyZip -DestinationPath $pythonDir -Force
        Remove-Item $pyZip -Force -ErrorAction SilentlyContinue

        if (Test-Path $pythonExe) {
            $pyVer = & $pythonExe --version 2>&1 | Select-Object -First 1
            Write-Ok "Installed: $pyVer"
        } else {
            Write-Warn "Python extraction may have failed -agent will try system Python"
        }
    }
}

# -----------------------------------------------------------------------------
# Write config
# -----------------------------------------------------------------------------
Write-Step "Writing configuration..."
# Write config without BOM (Python's configparser rejects BOM)
$configText = "[server]`nurl = $Server`napi_key = $Key`n"
[System.IO.File]::WriteAllText($ConfigPath, $configText, (New-Object System.Text.UTF8Encoding $false))
Write-Ok "Config written to $ConfigPath"

# -----------------------------------------------------------------------------
# Download SSH key from server
# -----------------------------------------------------------------------------
Write-Step "Downloading SSH key..."
try {
    $sshKeyPath = "$AgentDir\ssh_key"
    Invoke-WebRequest -Uri "$Server/api/agent/ssh-key" `
        -Headers @{ "Authorization" = "Bearer $Key" } `
        -OutFile $sshKeyPath -UseBasicParsing
    if ((Get-Item $sshKeyPath).Length -gt 0) {
        # Lock down SSH key permissions (SSH rejects keys readable by others)
        icacls $sshKeyPath /inheritance:r /grant:r "SYSTEM:(R)" "Administrators:(R)" | Out-Null
        Write-Ok "SSH key saved"
    } else {
        Write-Warn "SSH key not yet available (will be downloaded on first run)"
        Remove-Item $sshKeyPath -Force -ErrorAction SilentlyContinue
    }
} catch {
    Write-Warn "SSH key not yet available (will be downloaded on first run)"
}

# -----------------------------------------------------------------------------
# Install Windows Service
# -----------------------------------------------------------------------------
Write-Step "Installing Windows Service..."

$agentExe = "$AgentDir\bbs-agent.exe"

# Remove old service if it exists
if ($existingSvc) {
    & $agentExe remove 2>$null | Out-Null
    Start-Sleep -Seconds 2
}

# Install service using the exe's built-in win32service support
& $agentExe install 2>&1 | Out-Null

if ($LASTEXITCODE -ne 0) {
    Write-Fail "Failed to install service"
    exit 1
}

# Set to auto-start and configure recovery
sc.exe config $ServiceName start= auto | Out-Null
sc.exe failure $ServiceName reset= 86400 actions= restart/30000/restart/60000/restart/120000 | Out-Null

Write-Ok "Service '$ServiceName' installed"

# -----------------------------------------------------------------------------
# Start service
# -----------------------------------------------------------------------------
Write-Step "Starting service..."
try {
    Start-Service -Name $ServiceName
    Start-Sleep -Seconds 3
    $svc = Get-Service -Name $ServiceName
    if ($svc.Status -eq "Running") {
        Write-Ok "Service is running"
    } else {
        Write-Warn "Service status: $($svc.Status)"
        Write-Warn "Check logs at: $AgentDir\bbs-agent.log"
    }
} catch {
    Write-Warn "Could not start service: $_"
    Write-Warn "Try: Start-Service $ServiceName"
}

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host "    Installation Complete!" -ForegroundColor Green
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host ""
$installedSshPath = if (Test-Path $sshPathFile) { Get-Content $sshPathFile -Raw } else { "(system default)" }
Write-Host "  Borg:      $borgExe" -ForegroundColor White
Write-Host "  SSH:       $($installedSshPath.Trim())" -ForegroundColor White
Write-Host "  Agent:     $agentExe" -ForegroundColor White
Write-Host "  Config:    $ConfigPath" -ForegroundColor White
Write-Host "  Logs:      $AgentDir\bbs-agent.log" -ForegroundColor White
Write-Host "  Service:   $ServiceName" -ForegroundColor White
Write-Host ""
Write-Host "  Useful commands:" -ForegroundColor DarkGray
Write-Host "    sc query $ServiceName         # Check service status" -ForegroundColor DarkGray
Write-Host "    Stop-Service $ServiceName      # Stop agent" -ForegroundColor DarkGray
Write-Host "    Start-Service $ServiceName     # Start agent" -ForegroundColor DarkGray
Write-Host "    Restart-Service $ServiceName   # Restart agent" -ForegroundColor DarkGray
Write-Host "    Get-Content `"$AgentDir\bbs-agent.log`" -Tail 50  # View logs" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  The agent should appear online in the BBS dashboard within 30 seconds." -ForegroundColor Cyan
Write-Host ""

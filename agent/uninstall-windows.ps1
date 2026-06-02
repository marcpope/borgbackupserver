#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Borg Backup Server - Windows Agent Uninstaller

.DESCRIPTION
    Stops and removes the BBS agent service, and optionally removes borg.

.PARAMETER KeepBorg
    If specified, keeps Borg installed (only removes the agent)

.EXAMPLE
    .\uninstall-windows.ps1
    .\uninstall-windows.ps1 -KeepBorg
#>

param(
    [switch]$KeepBorg
)

$ErrorActionPreference = "Stop"

$ServiceName = "BorgBackupAgent"
$BorgDir     = "$env:ProgramFiles\BorgBackup"
$AgentDir    = "$env:ProgramData\bbs-agent"

function Write-Step { param($msg) Write-Host "  -> $msg" -ForegroundColor Cyan }
function Write-Ok   { param($msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Warn { param($msg) Write-Host "  [!] $msg" -ForegroundColor Yellow }

Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host "    Borg Backup Server - Windows Agent Uninstaller" -ForegroundColor Blue
Write-Host "  ================================================================" -ForegroundColor Blue
Write-Host ""

# -----------------------------------------------------------------------------
# Stop and remove service
# -----------------------------------------------------------------------------
$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc) {
    Write-Step "Stopping service..."
    Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    Write-Ok "Service stopped"

    Write-Step "Removing service..."
    sc.exe delete $ServiceName 2>$null | Out-Null
    Start-Sleep -Seconds 1
    Write-Ok "Service removed"
} else {
    Write-Warn "Service '$ServiceName' not found (already removed?)"
}

# -----------------------------------------------------------------------------
# Remove agent directory
# -----------------------------------------------------------------------------
if (Test-Path $AgentDir) {
    Write-Step "Removing agent directory..."

    # Older agents (<= 2.60.1) could create a file literally named "NUL" here —
    # a reserved DOS device name that Explorer/PowerShell and a plain
    # Remove-Item refuse to delete ("Invalid MS-DOS function"), which would
    # otherwise abort the whole recursive removal (#300). Delete any
    # reserved-name leftovers via the \\?\ extended-length path so the
    # directory can be cleaned up.
    $reserved = @("NUL", "CON", "PRN", "AUX") +
                (1..9 | ForEach-Object { "COM$_" }) +
                (1..9 | ForEach-Object { "LPT$_" })
    foreach ($name in $reserved) {
        $p = Join-Path $AgentDir $name
        if (Test-Path -LiteralPath "\\?\$p") {
            cmd /c "del /f /q `"\\?\$p`"" 2>$null | Out-Null
        }
    }

    Remove-Item -Path $AgentDir -Recurse -Force
    Write-Ok "Removed $AgentDir"
} else {
    Write-Warn "Agent directory not found: $AgentDir"
}

# -----------------------------------------------------------------------------
# Remove Borg (unless -KeepBorg)
# -----------------------------------------------------------------------------
if (-not $KeepBorg) {
    if (Test-Path $BorgDir) {
        Write-Step "Removing Borg..."
        Remove-Item -Path $BorgDir -Recurse -Force
        Write-Ok "Removed $BorgDir"

        # Remove from PATH
        $borgBinDir = "$BorgDir\borg"
        $machinePath = [Environment]::GetEnvironmentVariable("Path", "Machine")
        if ($machinePath -like "*$borgBinDir*") {
            $newPath = ($machinePath -split ";" | Where-Object { $_ -ne $borgBinDir }) -join ";"
            [Environment]::SetEnvironmentVariable("Path", $newPath, "Machine")
            Write-Ok "Removed Borg from system PATH"
        }
    } else {
        Write-Warn "Borg directory not found: $BorgDir"
    }
} else {
    Write-Ok "Keeping Borg installation (--KeepBorg)"
}

Write-Host ""
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host "    Uninstall Complete" -ForegroundColor Green
Write-Host "  ================================================================" -ForegroundColor Green
Write-Host ""

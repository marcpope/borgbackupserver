<?php

namespace BBS\Core;

/**
 * A client's operating system for lists (#534). Agents report a long string
 * such as "Rocky Linux 8.10 (Green Obsidian) x86_64"; lists show the short
 * form ("Rocky Linux 8.10") with an icon, and keep the full one for a tooltip.
 */
final class OsLabel
{
    public static function short(?string $osInfo, ?string $platform = null): string
    {
        $os = trim((string) $osInfo);
        if ($os === '') {
            return '';
        }
        if (strtolower((string) $platform) === 'darwin' || stripos($os, 'Darwin') === 0) {
            return 'macOS';
        }
        // Architecture at the end, release codename in parentheses
        $os = preg_replace('/\s+(x86_64|amd64|arm64|aarch64|i[3-6]86|armv\d+l?|ppc64le|s390x)$/i', '', $os);
        $os = preg_replace('/\s*\([^)]*\)/', '', $os);
        $os = str_replace(' GNU/Linux', '', $os);
        // Windows reports its kernel version; build 22000 and later is 11
        if (preg_match('/^Windows 10\.0\.(\d+)$/i', $os, $m)) {
            return (int) $m[1] >= 22000 ? 'Windows 11' : 'Windows 10';
        }
        return trim(preg_replace('/\s{2,}/', ' ', $os));
    }

    /** Bootstrap icon class for the OS. */
    public static function icon(?string $osInfo, ?string $platform = null): string
    {
        $os = strtolower((string) $osInfo);
        $p = strtolower((string) $platform);
        if ($p === 'windows' || str_contains($os, 'windows')) {
            return 'bi-windows';
        }
        if ($p === 'darwin' || str_contains($os, 'darwin') || str_contains($os, 'macos')) {
            return 'bi-apple';
        }
        if (str_contains($os, 'ubuntu')) {
            return 'bi-ubuntu';
        }
        return 'bi-terminal';
    }
}

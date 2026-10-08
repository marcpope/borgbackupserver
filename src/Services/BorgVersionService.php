<?php

namespace BBS\Services;

use BBS\Core\Database;

class BorgVersionService
{
    private Database $db;

    // Legacy 1.2.x asset name → platform metadata mapping
    private const LEGACY_ASSET_MAP = [
        'borg-linuxold64'   => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc231'],
        'borg-linuxnew64'   => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc235'],
        'borg-linuxnewer64' => ['platform' => 'linux', 'architecture' => 'x86_64', 'glibc_version' => 'glibc238'],
        'borg-macos64'      => ['platform' => 'macos', 'architecture' => 'x86_64', 'glibc_version' => null],
        'borg-freebsd64'    => ['platform' => 'freebsd', 'architecture' => 'x86_64', 'glibc_version' => null],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Fetch borg releases from GitHub and store in database.
     * Returns summary of what was synced.
     */
    public function syncVersionsFromGitHub(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: BorgBackupServer/1.0\r\n",
                'timeout' => 15,
            ],
        ]);

        // Fetch all releases (paginated — get up to 100)
        $url = 'https://api.github.com/repos/borgbackup/borg/releases?per_page=100';
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) {
            return ['error' => 'Could not reach GitHub API'];
        }

        $releases = json_decode($json, true);
        if (!is_array($releases)) {
            return ['error' => 'Invalid response from GitHub API'];
        }

        $added = 0;
        $skipped = 0;

        foreach ($releases as $release) {
            $tag = $release['tag_name'] ?? '';
            $version = ltrim($tag, 'v');

            // Skip pre-releases, betas, release candidates
            if ($release['prerelease'] ?? false) {
                $skipped++;
                continue;
            }
            if (preg_match('/(alpha|beta|rc|dev)/i', $version)) {
                $skipped++;
                continue;
            }

            // Skip borg 2.x — incompatible repo format, no migration path yet
            if (version_compare($version, '2.0.0', '>=')) {
                $skipped++;
                continue;
            }

            // A release already stored still has its assets refreshed: that
            // fills in digests and picks up assets uploaded after the first
            // sync.
            $existing = $this->db->fetchOne(
                "SELECT id FROM borg_versions WHERE version = ?",
                [$version]
            );
            if ($existing) {
                $versionId = (int) $existing['id'];
            } else {
                // Extract release date
                $releaseDate = substr($release['published_at'] ?? $release['created_at'] ?? date('Y-m-d'), 0, 10);

                // Insert version
                $versionId = $this->db->insert('borg_versions', [
                    'version' => $version,
                    'release_tag' => $tag,
                    'release_date' => $releaseDate,
                    'is_prerelease' => 0,
                    'release_notes' => $release['body'] ?? '',
                ]);
            }

            // Process assets
            $assets = $release['assets'] ?? [];
            foreach ($assets as $asset) {
                $name = $asset['name'] ?? '';
                // Skip .tgz, .asc, .tar.gz, README files
                if (preg_match('/\.(tgz|asc|tar\.gz|txt)$/', $name)) {
                    continue;
                }

                $meta = $this->parseAssetMetadata($name);
                if ($meta === null) {
                    continue;
                }

                // GitHub's digest ("sha256:<hex>") lets the server check a
                // download before it is installed.
                $sha256 = null;
                if (preg_match('/^sha256:([0-9a-f]{64})$/', (string) ($asset['digest'] ?? ''), $dm)) {
                    $sha256 = $dm[1];
                }
                // Match by name: the unique key can't catch a repeat when
                // glibc_version is NULL (macOS, FreeBSD).
                $known = $this->db->fetchOne(
                    "SELECT id FROM borg_version_assets WHERE borg_version_id = ? AND asset_name = ?",
                    [$versionId, $name]
                );
                if ($known) {
                    if ($sha256 !== null) {
                        $this->db->update('borg_version_assets', ['sha256' => $sha256], 'id = ?', [$known['id']]);
                    }
                    continue;
                }
                $this->db->query(
                    "INSERT IGNORE INTO borg_version_assets
                     (borg_version_id, platform, architecture, glibc_version, asset_name, download_url, file_size, sha256)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $versionId,
                        $meta['platform'],
                        $meta['architecture'],
                        $meta['glibc_version'],
                        $name,
                        $asset['browser_download_url'] ?? '',
                        $asset['size'] ?? null,
                        $sha256,
                    ]
                );
            }

            if (!$existing) {
                $added++;
            }
        }

        $this->setSetting('last_borg_version_check', date('Y-m-d H:i:s'));

        return [
            'added' => $added,
            'skipped' => $skipped,
            'total' => count($releases),
        ];
    }

    /**
     * Parse a borg binary asset filename into platform metadata.
     * Handles both 1.2.x (legacy) and 1.4.x (modern) naming conventions.
     */
    public function parseAssetMetadata(string $assetName): ?array
    {
        // Check legacy map first (1.2.x)
        if (isset(self::LEGACY_ASSET_MAP[$assetName])) {
            return self::LEGACY_ASSET_MAP[$assetName];
        }

        // 1.4.x Linux with arch: borg-linux-glibc235-x86_64[-gh]
        if (preg_match('/^borg-linux-(glibc\d+)-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'linux',
                'architecture' => $m[2],
                'glibc_version' => $m[1],
            ];
        }

        // 1.4.0 early Linux (no arch): borg-linux-glibc228 — assume x86_64
        if (preg_match('/^borg-linux-(glibc\d+)$/', $assetName, $m)) {
            return [
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'glibc_version' => $m[1],
            ];
        }

        // 1.4.x macOS with arch: borg-macos-14-arm64[-gh]
        if (preg_match('/^borg-macos-\d+-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'macos',
                'architecture' => $m[1],
                'glibc_version' => null,
            ];
        }

        // 1.4.0 early macOS (no arch): borg-macos1012 — assume x86_64
        if (preg_match('/^borg-macos\d+$/', $assetName)) {
            return [
                'platform' => 'macos',
                'architecture' => 'x86_64',
                'glibc_version' => null,
            ];
        }

        // 1.4.x FreeBSD with arch: borg-freebsd-14-x86_64[-gh]
        if (preg_match('/^borg-freebsd-\d+-([a-z0-9_]+?)(-gh)?$/', $assetName, $m)) {
            return [
                'platform' => 'freebsd',
                'architecture' => $m[1],
                'glibc_version' => null,
            ];
        }

        // 1.4.0 early FreeBSD (no arch): borg-freebsd14 — assume x86_64
        if (preg_match('/^borg-freebsd\d+$/', $assetName)) {
            return [
                'platform' => 'freebsd',
                'architecture' => 'x86_64',
                'glibc_version' => null,
            ];
        }

        return null;
    }

    /**
     * Find the best matching binary asset for a given agent platform.
     * For Linux: picks the highest glibc version that is <= agent's glibc.
     */
    public function getAssetForPlatform(string $version, string $platform, string $arch, ?string $agentGlibc): ?array
    {
        $borgVersion = $this->db->fetchOne(
            "SELECT id FROM borg_versions WHERE version = ?",
            [$version]
        );
        if (!$borgVersion) {
            return null;
        }

        if ($platform === 'linux') {
            // Get all Linux assets for this version and arch, ordered by glibc ascending
            $assets = $this->db->fetchAll(
                "SELECT * FROM borg_version_assets
                 WHERE borg_version_id = ? AND platform = 'linux' AND architecture = ?
                 ORDER BY glibc_version ASC",
                [$borgVersion['id'], $arch]
            );

            if (empty($assets)) {
                return null;
            }

            if ($agentGlibc !== null) {
                // Pick highest glibc that is <= agent's glibc
                $best = null;
                foreach ($assets as $asset) {
                    if ($asset['glibc_version'] !== null && $asset['glibc_version'] <= $agentGlibc) {
                        $best = $asset;
                    }
                }
                // If no compatible binary found, return null (don't guess)
                return $best;
            }

            // Unknown glibc — return the lowest available (most compatible)
            return $assets[0];
        }

        // For macOS/FreeBSD: match platform + arch
        $asset = $this->db->fetchOne(
            "SELECT * FROM borg_version_assets
             WHERE borg_version_id = ? AND platform = ? AND architecture = ?
             LIMIT 1",
            [$borgVersion['id'], $platform, $arch]
        );

        return $asset ?: null;
    }

    /**
     * Get all server-hosted fallback binaries grouped by version.
     * Returns: ['1.4.3' => [['filename' => '...', 'platform' => 'linux', 'glibc' => '217', 'arch' => 'x86_64'], ...]]
     */
    public function getServerHostedBinaries(): array
    {
        $borgDir = dirname(__DIR__, 2) . '/public/borg';
        if (!is_dir($borgDir)) {
            return [];
        }

        $result = [];
        $dirs = array_filter(scandir($borgDir), fn($d) => $d !== '.' && $d !== '..' && is_dir($borgDir . '/' . $d));

        foreach ($dirs as $version) {
            $versionDir = $borgDir . '/' . $version;
            $files = scandir($versionDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (preg_match('/^borg-(\w+)-glibc(\d+)-(\w+)$/', $file, $m)) {
                    $result[$version][] = [
                        'filename' => $file,
                        'platform' => $m[1],
                        'glibc' => $m[2],
                        'arch' => $m[3],
                    ];
                }
            }
        }

        // Sort versions descending
        uksort($result, fn($a, $b) => version_compare($b, $a));
        return $result;
    }

    /**
     * Check if a server-hosted fallback binary exists for a given version/platform/arch/glibc.
     */
    public function hasFallbackBinary(string $version, string $platform, string $arch, ?string $agentGlibc): bool
    {
        return $this->getFallbackBinaryUrl($version, $platform, $arch, $agentGlibc) !== null;
    }

    /**
     * Find a server-hosted fallback binary in public/borg/{version}/.
     * Naming convention: borg-{platform}-glibc{NNN}-{arch}
     * Returns full download URL or null.
     */
    public function getFallbackBinaryUrl(string $version, string $platform, string $arch, ?string $agentGlibc): ?string
    {
        $borgDir = dirname(__DIR__, 2) . '/public/borg';
        $versionDir = $borgDir . '/' . $version;

        if (!is_dir($versionDir)) {
            // No exact version — scan all version dirs for best match
            if (!is_dir($borgDir)) {
                return null;
            }
            $dirs = array_filter(scandir($borgDir), fn($d) => $d !== '.' && $d !== '..' && is_dir($borgDir . '/' . $d));
            if (empty($dirs)) {
                return null;
            }
            // Use highest available version
            usort($dirs, 'version_compare');
            $versionDir = $borgDir . '/' . end($dirs);
            $version = end($dirs);
        }

        $files = scandir($versionDir);
        $best = null;
        $bestGlibc = null;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            // Match pattern: borg-{platform}-glibc{NNN}-{arch}
            if (!preg_match('/^borg-' . preg_quote($platform) . '-glibc(\d+)-' . preg_quote($arch) . '$/', $file, $m)) {
                continue;
            }
            $fileGlibc = $m[1];
            if ($agentGlibc !== null && $fileGlibc > $agentGlibc) {
                continue; // requires newer glibc than agent has
            }
            if ($best === null || $fileGlibc > $bestGlibc) {
                $best = $file;
                $bestGlibc = $fileGlibc;
            }
        }

        if (!$best) {
            return null;
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? (\BBS\Core\Database::getInstance()->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'app_url'"
        )['value'] ?? ''), '/');

        return $appUrl . '/borg/' . $version . '/' . $best;
    }

    /**
     * Get all stored borg versions, newest first.
     */
    public function getStoredVersions(): array
    {
        return $this->db->fetchAll(
            "SELECT v.*, COUNT(a.id) as asset_count
             FROM borg_versions v
             LEFT JOIN borg_version_assets a ON a.borg_version_id = v.id
             WHERE v.is_prerelease = 0 AND v.version < '2.0.0'
             GROUP BY v.id
             ORDER BY v.release_date DESC, v.version DESC"
        );
    }

    /**
     * Get assets for a specific version.
     */
    public function getVersionAssets(string $version): array
    {
        return $this->db->fetchAll(
            "SELECT a.* FROM borg_version_assets a
             JOIN borg_versions v ON v.id = a.borg_version_id
             WHERE v.version = ?
             ORDER BY a.platform, a.architecture",
            [$version]
        );
    }

    // ========================================
    // New two-mode update system
    // ========================================

    /**
     * Get the update mode: 'official' or 'server'
     */
    public function getUpdateMode(): string
    {
        return $this->getSetting('borg_update_mode', 'official');
    }

    /**
     * Set the update mode.
     */
    public function setUpdateMode(string $mode): void
    {
        if (!in_array($mode, ['official', 'server'])) {
            $mode = 'official';
        }
        $this->setSetting('borg_update_mode', $mode);
    }

    /**
     * Get the selected server binary version.
     */
    public function getServerVersion(): string
    {
        return $this->getSetting('borg_server_version', '');
    }

    /**
     * Set the selected server binary version.
     */
    public function setServerVersion(string $version): void
    {
        $this->setSetting('borg_server_version', $version);
    }

    /**
     * Check if auto-update is enabled.
     */
    public function isAutoUpdateEnabled(): bool
    {
        return $this->getSetting('borg_auto_update', '0') === '1';
    }

    /**
     * Set auto-update enabled/disabled.
     */
    public function setAutoUpdate(bool $enabled): void
    {
        $this->setSetting('borg_auto_update', $enabled ? '1' : '0');
    }

    /**
     * Get available server-hosted versions (directories in /public/borg/).
     */
    public function getServerVersions(): array
    {
        $borgDir = dirname(__DIR__, 2) . '/public/borg';
        if (!is_dir($borgDir)) {
            return [];
        }

        $dirs = array_filter(
            scandir($borgDir),
            fn($d) => $d !== '.' && $d !== '..' && is_dir($borgDir . '/' . $d)
        );

        // Sort versions descending
        usort($dirs, fn($a, $b) => version_compare($b, $a));
        return array_values($dirs);
    }

    /**
     * Check if an agent is compatible with a server-hosted version.
     * Returns true if a matching binary exists.
     */
    public function isAgentCompatibleWithServerVersion(array $agent, string $version): bool
    {
        $platform = $agent['platform'] ?? 'linux';
        $arch = $agent['architecture'] ?? 'x86_64';
        $glibc = $agent['glibc_version'] ?? null;

        // Windows and macOS agents have their own borg installation paths
        // (borg-windows.zip / Homebrew) — glibc compatibility doesn't apply
        if (in_array($platform, ['windows', 'darwin'], true)) {
            return true;
        }

        // Extract numeric glibc for comparison (e.g., 'glibc217' -> '217')
        $agentGlibcNum = $glibc ? preg_replace('/[^0-9]/', '', $glibc) : null;

        $borgDir = dirname(__DIR__, 2) . '/public/borg/' . $version;
        if (!is_dir($borgDir)) {
            return false;
        }

        $files = scandir($borgDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            // Match pattern: borg-{platform}-glibc{NNN}-{arch}
            if (preg_match('/^borg-' . preg_quote($platform) . '-glibc(\d+)-' . preg_quote($arch) . '$/', $file, $m)) {
                $fileGlibc = $m[1];
                // Compatible if agent's glibc >= binary's required glibc
                if ($agentGlibcNum === null || $fileGlibc <= $agentGlibcNum) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get server binary URL for a specific version and agent.
     * Returns null if no compatible binary exists.
     */
    public function getServerBinaryForAgent(string $version, array $agent): ?string
    {
        $platform = $agent['platform'] ?? 'linux';
        $arch = $agent['architecture'] ?? 'x86_64';
        $glibc = $agent['glibc_version'] ?? null;

        // Extract numeric glibc for comparison
        $agentGlibcNum = $glibc ? preg_replace('/[^0-9]/', '', $glibc) : null;

        $borgDir = dirname(__DIR__, 2) . '/public/borg/' . $version;
        if (!is_dir($borgDir)) {
            return null;
        }

        $files = scandir($borgDir);
        $best = null;
        $bestGlibc = null;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!preg_match('/^borg-' . preg_quote($platform) . '-glibc(\d+)-' . preg_quote($arch) . '$/', $file, $m)) {
                continue;
            }
            $fileGlibc = $m[1];
            // Skip if requires newer glibc than agent has
            if ($agentGlibcNum !== null && $fileGlibc > $agentGlibcNum) {
                continue;
            }
            // Pick highest compatible glibc
            if ($best === null || $fileGlibc > $bestGlibc) {
                $best = $file;
                $bestGlibc = $fileGlibc;
            }
        }

        if (!$best) {
            return null;
        }

        $appUrl = rtrim($_ENV['APP_URL'] ?? ($this->db->fetchOne(
            "SELECT `value` FROM settings WHERE `key` = 'app_url'"
        )['value'] ?? ''), '/');

        return $appUrl . '/borg/' . $version . '/' . $best;
    }

    /**
     * For Official mode: get the best binary URL from GitHub for an agent.
     * Returns ['version' => '1.4.3', 'url' => '...'] or null.
     * Excludes borg 2.0+ due to breaking changes.
     */
    public function getOfficialBinaryForAgent(array $agent): ?array
    {
        $platform = $agent['platform'] ?? 'linux';
        $arch = $agent['architecture'] ?? 'x86_64';
        $glibc = $agent['glibc_version'] ?? null;

        // Find latest version < 2.0 with a compatible binary
        if ($platform === 'linux' && $glibc) {
            $asset = $this->db->fetchOne(
                "SELECT bv.version, bva.download_url, bva.sha256
                 FROM borg_versions bv
                 JOIN borg_version_assets bva ON bva.borg_version_id = bv.id
                 WHERE bva.platform = 'linux' AND bva.architecture = ?
                   AND bva.glibc_version IS NOT NULL AND bva.glibc_version <= ?
                   AND bv.version < '2.0'
                 ORDER BY bv.release_date DESC, bva.glibc_version DESC
                 LIMIT 1",
                [$arch, $glibc]
            );
        } else {
            $asset = $this->db->fetchOne(
                "SELECT bv.version, bva.download_url, bva.sha256
                 FROM borg_versions bv
                 JOIN borg_version_assets bva ON bva.borg_version_id = bv.id
                 WHERE bva.platform = ? AND bva.architecture = ?
                   AND bv.version < '2.0'
                 ORDER BY bv.release_date DESC
                 LIMIT 1",
                [$platform, $arch]
            );
        }

        if ($asset && !empty($asset['download_url'])) {
            return [
                'version' => $asset['version'],
                'url' => $asset['download_url'],
                'sha256' => $asset['sha256'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Get the best borg version for an agent based on current mode.
     * - Official mode: use GitHub binaries, pip as last resort
     * - Server mode: use server binary if it's newer than official, otherwise use official
     * Returns ['version' => '1.4.3', 'url' => '...', 'source' => 'server|official|pip'].
     */
    public function getBestVersionForAgent(array $agent): ?array
    {
        $mode = $this->getUpdateMode();
        $official = $this->getOfficialBinaryForAgent($agent);

        if ($mode === 'server') {
            $serverVersion = $this->getServerVersion();
            if (!empty($serverVersion)) {
                $serverUrl = $this->getServerBinaryForAgent($serverVersion, $agent);
                if ($serverUrl) {
                    // Use server binary if:
                    // 1. No official binary available, OR
                    // 2. Server version is newer than official version
                    if (!$official || version_compare($serverVersion, $official['version'], '>')) {
                        return [
                            'version' => $serverVersion,
                            'url' => $serverUrl,
                            'source' => 'server',
                        ];
                    }
                }
            }
        }

        // Use official binary if available
        if ($official) {
            return [
                'version' => $official['version'],
                'url' => $official['url'],
                'source' => 'official',
            ];
        }

        // Last resort: pip (agent will remove any existing binary first)
        return [
            'version' => 'latest',
            'url' => null,
            'source' => 'pip',
        ];
    }

    /**
     * Update server borg using current mode settings.
     * In server mode: use server binary if newer than official, otherwise use official.
     * In official mode: use official binary.
     */
    public function updateServerBorgByMode(): array
    {
        $mode = $this->getUpdateMode();
        $platform = $this->getServerPlatformInfo();
        $official = $this->getOfficialBinaryForAgent($platform);

        if ($mode === 'server') {
            $serverVersion = $this->getServerVersion();
            if (empty($serverVersion)) {
                return ['success' => false, 'error' => 'No server version selected'];
            }
            $serverUrl = $this->getServerBinaryForAgent($serverVersion, $platform);
            if ($serverUrl) {
                // Use server binary if no official available or server version is newer
                if (!$official || version_compare($serverVersion, $official['version'], '>')) {
                    // The server installs its own copy from disk; the URL is
                    // for agents. The helper checks the copy's signature.
                    $localPath = dirname(__DIR__, 2) . '/public/borg/' . $serverVersion . '/' . basename(parse_url($serverUrl, PHP_URL_PATH));
                    return $this->updateServerBorgFromUrl($localPath, $serverVersion);
                }
            }
        }

        // Use official binary if available
        if ($official) {
            return $this->updateServerBorgFromUrl($official['url'], $official['version'], $official['sha256'] ?? null);
        }

        return ['success' => false, 'error' => 'No compatible binary for server platform'];
    }

    /**
     * Update server borg from a specific URL.
     */
    public function updateServerBorgFromUrl(string $url, string $version, ?string $sha256 = null): array
    {
        // Official downloads need the release digest; versions synced before
        // digests were stored get it from a fresh sync.
        if (str_starts_with($url, 'https://') && empty($sha256)) {
            $this->syncVersionsFromGitHub();
            $sha256 = $this->db->fetchOne(
                "SELECT sha256 FROM borg_version_assets WHERE download_url = ? AND sha256 IS NOT NULL LIMIT 1",
                [$url]
            )['sha256'] ?? null;
            if (empty($sha256)) {
                return ['success' => false, 'error' => 'GitHub did not provide a SHA-256 digest for this borg release, so it cannot be verified'];
            }
        }
        $cmd = 'sudo /usr/local/bin/bbs-ssh-helper update-borg '
            . escapeshellarg($url) . ' '
            . escapeshellarg($version)
            . ($sha256 ? ' ' . escapeshellarg($sha256) : '') . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            return ['success' => false, 'error' => 'Install failed (exit ' . $exitCode . '): ' . $outputStr];
        }

        return ['success' => true, 'version' => $version, 'output' => $outputStr];
    }

    // ========================================
    // Legacy methods (kept for compatibility)
    // ========================================

    public function getTargetVersion(): string
    {
        return $this->getSetting('target_borg_version', '');
    }

    public function setTargetVersion(string $version): void
    {
        $this->setSetting('target_borg_version', $version);
    }

    public function getLastCheckTime(): string
    {
        return $this->getSetting('last_borg_version_check', '');
    }

    public function isFallbackToPipEnabled(): bool
    {
        return $this->getSetting('fallback_to_pip', '1') === '1';
    }

    /**
     * Get agents that are not at the target version.
     */
    public function getOutdatedAgents(string $targetVersion): array
    {
        if (empty($targetVersion)) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT id, name, hostname, borg_version, borg_install_method, status
             FROM agents
             WHERE (borg_version IS NULL OR borg_version != ?)
             AND status != 'setup'
             ORDER BY name",
            [$targetVersion]
        );
    }

    /**
     * Get all agents with borg version info.
     */
    public function getAllAgentVersions(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, hostname, borg_version, borg_install_method, borg_source, borg_binary_path, glibc_version, platform, architecture, os_info, status
             FROM agents
             WHERE status != 'setup'
             ORDER BY name"
        );
    }

    /**
     * Find the highest borg version that has a compatible binary for a given agent's platform/arch/glibc.
     * Checks both GitHub release assets and server-hosted fallback binaries.
     * Returns null if no compatible version exists or if agent info is incomplete.
     */
    public function getMaxCompatibleVersion(array $agent): ?string
    {
        $platform = $agent['platform'] ?? null;
        $arch = $agent['architecture'] ?? null;
        $glibc = $agent['glibc_version'] ?? null;

        if (!$platform || !$arch) {
            return null;
        }

        $githubMax = null;

        if ($platform === 'linux' && $glibc) {
            $row = $this->db->fetchOne(
                "SELECT bv.version FROM borg_versions bv
                 JOIN borg_version_assets bva ON bva.borg_version_id = bv.id
                 WHERE bva.platform = 'linux' AND bva.architecture = ?
                   AND bva.glibc_version IS NOT NULL AND bva.glibc_version <= ?
                 ORDER BY bv.version DESC
                 LIMIT 1",
                [$arch, $glibc]
            );
            $githubMax = $row['version'] ?? null;
        } else {
            $row = $this->db->fetchOne(
                "SELECT bv.version FROM borg_versions bv
                 JOIN borg_version_assets bva ON bva.borg_version_id = bv.id
                 WHERE bva.platform = ? AND bva.architecture = ?
                 ORDER BY bv.version DESC
                 LIMIT 1",
                [$platform, $arch]
            );
            $githubMax = $row['version'] ?? null;
        }

        // Also check server-hosted fallback binaries
        $fallbackMax = null;
        $serverBinaries = $this->getServerHostedBinaries();
        foreach ($serverBinaries as $version => $binaries) {
            foreach ($binaries as $bin) {
                if ($bin['platform'] !== $platform || $bin['arch'] !== $arch) continue;
                if ($platform === 'linux' && $glibc && $bin['glibc'] > $glibc) continue;
                if ($fallbackMax === null || version_compare($version, $fallbackMax, '>')) {
                    $fallbackMax = $version;
                }
            }
        }

        // Return the higher of the two
        if ($githubMax === null) return $fallbackMax;
        if ($fallbackMax === null) return $githubMax;
        return version_compare($githubMax, $fallbackMax, '>=') ? $githubMax : $fallbackMax;
    }

    /**
     * Check if setting a target version would be a downgrade for any agent.
     */
    public function getAgentsAboveVersion(string $version): array
    {
        $agents = $this->db->fetchAll(
            "SELECT id, name, borg_version FROM agents
             WHERE borg_version IS NOT NULL AND status != 'setup'"
        );

        $above = [];
        foreach ($agents as $agent) {
            $agentVer = preg_replace('/^borg\s+/', '', $agent['borg_version']);
            if (version_compare($agentVer, $version, '>')) {
                $above[] = $agent;
            }
        }

        return $above;
    }

    /**
     * Get cached server borg version (fast, for page loads).
     */
    public function getServerBorgVersionCached(): ?string
    {
        $cached = $this->getSetting('server_borg_version_cached', '');
        return $cached === '' ? null : $cached;
    }

    /**
     * Detect the server's currently installed borg version (slow, runs shell command).
     * Also updates the cache.
     */
    public function getServerBorgVersion(): ?string
    {
        $output = @shell_exec('borg --version 2>/dev/null');
        if ($output === null || $output === false) {
            return null;
        }
        // Output is like "borg 1.2.0" or "borg 1.4.3"
        $output = trim($output);
        $version = preg_replace('/^borg\s+/', '', $output);
        $version = $version ?: null;

        // Update cache
        if ($version) {
            $this->setSetting('server_borg_version_cached', $version);
        }

        return $version;
    }

    /**
     * Detect the server's platform info for binary matching.
     */
    public function getServerPlatformInfo(): array
    {
        $arch = trim(shell_exec('uname -m 2>/dev/null') ?: 'x86_64');
        // Normalize: aarch64 → arm64
        if ($arch === 'aarch64') {
            $arch = 'arm64';
        }

        $glibc = null;
        $lddOutput = @shell_exec('ldd --version 2>&1 | head -1');
        if ($lddOutput && preg_match('/(\d+)\.(\d+)/', $lddOutput, $m)) {
            $glibc = 'glibc' . $m[1] . $m[2];
        }

        return [
            'platform' => 'linux',
            'architecture' => $arch,
            'glibc_version' => $glibc,
        ];
    }

    /**
     * Update the server's borg binary to the target version.
     * Uses bbs-ssh-helper update-borg command (runs as root via sudo).
     */
    public function updateServerBorg(string $version): array
    {
        $platform = $this->getServerPlatformInfo();
        $asset = $this->getAssetForPlatform(
            $version,
            $platform['platform'],
            $platform['architecture'],
            $platform['glibc_version']
        );

        if (!$asset) {
            return ['success' => false, 'error' => 'No matching binary found for server platform (' . $platform['architecture'] . '/' . ($platform['glibc_version'] ?? 'unknown glibc') . ')'];
        }

        $downloadUrl = $asset['download_url'];
        if (empty($downloadUrl)) {
            return ['success' => false, 'error' => 'No download URL for matching asset'];
        }

        // Use bbs-ssh-helper to download, verify and install (needs root for /usr/local/bin)
        $result = $this->updateServerBorgFromUrl($downloadUrl, $version, $asset['sha256'] ?? null);
        unset($result['version']);
        return $result;
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->query("INSERT INTO settings (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        }
    }
}

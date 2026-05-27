<?php

namespace BBS\Services;

/**
 * Two-tier app cache. Uses memcached when available (fast in-memory,
 * shared across PHP-FPM workers), falls back to file-based storage
 * when memcached isn't installed or the daemon isn't running.
 *
 * File mode writes one file per key under /var/bbs/cache/app keyed by
 * sha1(key). TTL is stored alongside the value and checked on read;
 * expired files are unlinked on access (no separate sweeper needed —
 * a key that's never read again just leaks a few KB, and the file
 * dir lives on the same volume as BBS state so it shares snapshots).
 */
class Cache
{
    private static ?Cache $instance = null;
    private ?\Memcached $mc = null;
    private bool $memcached = false;
    private string $fileDir;

    private function __construct()
    {
        $this->fileDir = \BBS\Core\Config::get('CACHE_DIR', '/var/bbs/cache/app');
        if (!is_dir($this->fileDir)) {
            // @mkdir suppresses the warning when the parent is missing or
            // unwritable; we just degrade to a no-op cache in that case.
            @mkdir($this->fileDir, 0775, true);
        }

        if (!extension_loaded('memcached')) {
            return;
        }

        try {
            $this->mc = new \Memcached();
            $host = \BBS\Core\Config::get('MEMCACHED_HOST', '127.0.0.1');
            $port = (int) \BBS\Core\Config::get('MEMCACHED_PORT', '11211');
            $this->mc->addServer($host, $port);

            $this->mc->get('_ping');
            if ($this->mc->getResultCode() !== \Memcached::RES_NOTFOUND
                && $this->mc->getResultCode() !== \Memcached::RES_SUCCESS) {
                $this->mc = null;
                return;
            }
            $this->memcached = true;
        } catch (\Exception $e) {
            $this->mc = null;
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * True if any cache backend is usable. File backend works whenever
     * the cache directory is writable, so this is effectively
     * "is the file cache dir writable, or is memcached up".
     */
    public function isAvailable(): bool
    {
        return $this->memcached || is_writable($this->fileDir);
    }

    public function get(string $key): mixed
    {
        if ($this->memcached) {
            $value = $this->mc->get($key);
            if ($this->mc->getResultCode() === \Memcached::RES_SUCCESS) {
                return $value;
            }
            // Fall through to file lookup — covers the case where a value
            // was set in file mode before memcached came online.
        }
        $path = $this->filePath($key);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !isset($payload['exp'], $payload['val'])) {
            @unlink($path);
            return null;
        }
        if ($payload['exp'] < time()) {
            @unlink($path);
            return null;
        }
        return $payload['val'];
    }

    public function set(string $key, mixed $value, int $ttl = 60): bool
    {
        $ok = false;
        if ($this->memcached) {
            $ok = $this->mc->set($key, $value, $ttl);
        }
        $path = $this->filePath($key);
        $payload = serialize(['exp' => time() + $ttl, 'val' => $value]);
        $written = @file_put_contents($path, $payload, LOCK_EX);
        return $ok || ($written !== false);
    }

    public function delete(string $key): bool
    {
        if ($this->memcached) {
            $this->mc->delete($key);
        }
        $path = $this->filePath($key);
        if (is_file($path)) @unlink($path);
        return true;
    }

    public function flush(): bool
    {
        if ($this->memcached) {
            $this->mc->flush();
        }
        // Wipe file cache too. glob() is fine for our scale (10s of
        // entries, not 100Ks).
        foreach (glob($this->fileDir . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
        return true;
    }

    /**
     * Get or set: returns cached value, or calls $callback and caches
     * its result. Cache misses re-execute the callback; failures to
     * persist still return the freshly computed value.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    private function filePath(string $key): string
    {
        return $this->fileDir . '/' . sha1($key) . '.cache';
    }
}

<?php

namespace BBS\Core;

/**
 * Checks for outbound requests to addresses a user typed in.
 *
 * In hosted mode, tenants share a host. A notification, S3, SMTP or OIDC
 * address that resolves to loopback, a private network or the cloud
 * metadata service would let a tenant reach the host's internal services.
 * Self-hosted installs keep LAN targets (a Gotify or MinIO box on the local
 * network is normal there).
 */
final class UrlGuard
{
    /** The host of a URL ("scheme://[user@]host[:port]/..."), or null. */
    public static function hostOf(string $url): ?string
    {
        // Not parse_url(): it gives up on valid Apprise URLs such as
        // "tgram://123:token/chat", and a URL it can't read must not pass
        // unchecked.
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://([^/?\#]*)#i', trim($url), $m)) {
            return null;
        }
        $authority = $m[1];
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if (preg_match('/^\[([^\]]+)\]/', $authority, $v6)) {
            return $v6[1];
        }
        $host = explode(':', $authority, 2)[0];
        return $host === '' ? null : strtolower($host);
    }

    /**
     * The addresses a host resolves to; the host itself when it is an IP.
     * Empty when it does not resolve.
     */
    public static function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            if (!empty($rec['ip'])) {
                $ips[] = $rec['ip'];
            } elseif (!empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if (!$ips) {
            $v4 = @gethostbynamel($host);
            $ips = $v4 ?: [];
        }
        return array_values(array_unique($ips));
    }

    /** Whether an IP is publicly routable. */
    public static function isPublicIp(string $ip): bool
    {
        // ::ffff:a.b.c.d is the IPv4 address in disguise
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            // 100.64.0.0/10 carrier-grade NAT, 0.0.0.0/8
            if (($n & 0xFFC00000) === (100 << 24 | 64 << 16) || ($n >> 24) === 0) {
                return false;
            }
        } else {
            $lower = strtolower($ip);
            // unique local fc00::/7, link-local fe80::/10, loopback, unspecified
            if (preg_match('/^(f[cd]|fe[89ab])/', $lower) || $lower === '::1' || $lower === '::') {
                return false;
            }
        }
        return true;
    }

    /** Whether an IP is loopback or link-local (which includes cloud metadata). */
    public static function isLocalIp(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || str_starts_with($ip, '0.');
        }
        $lower = strtolower($ip);
        return $lower === '::1' || $lower === '::' || (bool) preg_match('/^fe[89ab]/', $lower);
    }

    /**
     * Whether a host resolves to any address that is not public. A host that
     * does not resolve passes: many notification URLs carry a token where
     * the host would be, and nothing can be reached through a name that
     * does not resolve.
     */
    public static function reachesInternal(string $host): bool
    {
        foreach (self::resolve($host) as $ip) {
            if (!self::isPublicIp($ip)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a host resolves to any loopback or link-local address. */
    public static function reachesLocal(string $host): bool
    {
        foreach (self::resolve($host) as $ip) {
            if (self::isLocalIp($ip)) {
                return true;
            }
        }
        return false;
    }

    /** In hosted mode, whether a URL's host reaches an internal address. Always false self-hosted. */
    public static function blockedWhenHosted(string $url): bool
    {
        if (!Config::isHosted()) {
            return false;
        }
        $host = self::hostOf($url);
        return $host !== null && self::reachesInternal($host);
    }
}

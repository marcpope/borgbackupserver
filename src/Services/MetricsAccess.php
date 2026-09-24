<?php

namespace BBS\Services;

/**
 * Who may read the monitoring endpoints, decided without touching the
 * database or the request.
 *
 * A monitoring scraper is a machine on a network, not a person in a session:
 * the gate it needs is an address range plus, optionally, a token. Keeping the
 * decision here — pure, injectable, no superglobals — means the interesting
 * cases (a proxy that lies about the client, a range that overlaps a narrower
 * one, an operator who opens the endpoint to the world) can be exercised
 * without a web server, which is the only way to be sure the deny paths deny.
 */
class MetricsAccess
{
    /** Every reply this class can produce. */
    public const ALLOW          = 'allow';
    public const DENY_DISABLED  = 'disabled';
    public const DENY_NOT_LISTED = 'not_listed';

    /**
     * The access list, as stored: a JSON array of entries. Each entry is a
     * CIDR, whether that range must present a token, and a note for the
     * operator who reads this a year from now.
     *
     * Invalid entries are dropped rather than throwing: a malformed row in
     * settings must not take the endpoint — or the settings page — down.
     *
     * @return list<array{cidr:string,token:bool,note:string}>
     */
    public static function parseAcl(?string $json): array
    {
        $rows = json_decode((string) ($json ?: '[]'), true);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cidr = self::normaliseCidr((string) ($row['cidr'] ?? ''));
            if ($cidr === null) {
                continue;
            }
            $out[] = [
                'cidr' => $cidr,
                // Absent means "token required": the safe reading of a row
                // someone hand-edited in the database.
                'token' => !isset($row['token']) || !empty($row['token']),
                'note' => trim((string) ($row['note'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * A plain list of CIDRs (trusted proxies), same tolerance.
     *
     * @return list<string>
     */
    public static function parseCidrList(?string $json): array
    {
        $rows = json_decode((string) ($json ?: '[]'), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $cidr = self::normaliseCidr(is_array($row) ? (string) ($row['cidr'] ?? '') : (string) $row);
            if ($cidr !== null) {
                $out[] = $cidr;
            }
        }
        return $out;
    }

    /**
     * "10.0.0.5/27" → "10.0.0.0/27", "192.168.1.10" → "192.168.1.10/32".
     *
     * The host bits are cleared deliberately and the result is shown back on
     * the settings page: an operator who types their own address with a /24
     * and sees it become the network address has learned what the row will
     * actually match, at the moment they can still change it.
     *
     * Returns null for anything that is not an address or range.
     */
    public static function normaliseCidr(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $parts = explode('/', $raw);
        if (count($parts) > 2) {
            return null;
        }
        $addr = trim($parts[0]);
        $bin = @inet_pton($addr);
        if ($bin === false) {
            return null;
        }

        $bits = strlen($bin) * 8;              // 32 for IPv4, 128 for IPv6
        if (isset($parts[1])) {
            $prefix = trim($parts[1]);
            if ($prefix === '' || !ctype_digit($prefix)) {
                return null;
            }
            $prefix = (int) $prefix;
            if ($prefix > $bits) {
                return null;
            }
        } else {
            $prefix = $bits;                    // a bare address is a host route
        }

        $network = self::maskBinary($bin, $prefix);
        $text = @inet_ntop($network);
        if ($text === false) {
            return null;
        }
        return $text . '/' . $prefix;
    }

    /** Is this address inside this range? IPv4 and IPv6, never mixed. */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        $parts = explode('/', $cidr);
        $netBin = @inet_pton($parts[0] ?? '');
        if ($netBin === false || strlen($netBin) !== strlen($ipBin)) {
            // Different families never match — an IPv4 scraper is not covered
            // by an IPv6 range, and ::ffff:10.0.0.1 is not 10.0.0.1 here.
            return false;
        }

        $prefix = isset($parts[1]) ? (int) $parts[1] : strlen($netBin) * 8;
        return self::maskBinary($ipBin, $prefix) === self::maskBinary($netBin, $prefix);
    }

    /**
     * The entry that governs this address, most specific first.
     *
     * Longest prefix wins, so a narrow exception beats the wide range it sits
     * inside — 10.0.2.40/27 with a token over 10.0.0.0/8 without one — which
     * is the order an operator expects from routing and firewall rules. Equal
     * prefixes fall back to list order.
     *
     * @param list<array{cidr:string,token:bool,note:string}> $acl
     * @return array{cidr:string,token:bool,note:string}|null
     */
    public static function match(array $acl, string $ip): ?array
    {
        $best = null;
        $bestPrefix = -1;
        foreach ($acl as $entry) {
            if (!self::ipInCidr($ip, $entry['cidr'])) {
                continue;
            }
            $prefix = (int) (explode('/', $entry['cidr'])[1] ?? 0);
            if ($prefix > $bestPrefix) {
                $best = $entry;
                $bestPrefix = $prefix;
            }
        }
        return $best;
    }

    /**
     * The address the access list is checked against.
     *
     * X-Forwarded-For is only consulted when the machine that actually opened
     * the connection is a declared proxy, and only its last hop is taken —
     * every earlier entry was written by whoever called the proxy. Without
     * that rule the header is a bypass: anyone could send
     * "X-Forwarded-For: 127.0.0.1" and inherit a localhost allowance.
     *
     * @param list<string> $trustedProxies
     */
    public static function resolveClientIp(string $remoteAddr, ?string $forwardedFor, array $trustedProxies): string
    {
        $remoteAddr = trim($remoteAddr);
        if ($remoteAddr === '' || $forwardedFor === null || $trustedProxies === []) {
            return $remoteAddr;
        }

        $trusted = false;
        foreach ($trustedProxies as $cidr) {
            if (self::ipInCidr($remoteAddr, $cidr)) {
                $trusted = true;
                break;
            }
        }
        if (!$trusted) {
            return $remoteAddr;
        }

        $hops = array_values(array_filter(array_map('trim', explode(',', $forwardedFor))));
        if ($hops === []) {
            return $remoteAddr;
        }
        $last = end($hops);
        // Some proxies append a port to IPv6 or IPv4 entries; keep the address.
        $last = preg_replace('/^\[(.+)\](?::\d+)?$/', '$1', $last);
        if (substr_count($last, ':') === 1 && strpos($last, '.') !== false) {
            $last = explode(':', $last)[0];
        }
        return @inet_pton($last) === false ? $remoteAddr : $last;
    }

    /**
     * The whole decision: is this caller allowed, and must it show a token?
     *
     * @param list<array{cidr:string,token:bool,note:string}> $acl
     * @return array{verdict:string,ip:string,require_token:bool,entry:?array}
     */
    public static function decide(bool $enabled, array $acl, string $ip): array
    {
        if (!$enabled) {
            return ['verdict' => self::DENY_DISABLED, 'ip' => $ip, 'require_token' => true, 'entry' => null];
        }

        $entry = self::match($acl, $ip);
        if ($entry === null) {
            return ['verdict' => self::DENY_NOT_LISTED, 'ip' => $ip, 'require_token' => true, 'entry' => null];
        }

        return [
            'verdict' => self::ALLOW,
            'ip' => $ip,
            'require_token' => (bool) $entry['token'],
            'entry' => $entry,
        ];
    }

    /**
     * The one combination the settings page refuses to save: reachable from
     * anywhere AND no token. Everything else is a judgement call the operator
     * is entitled to make; this one is only ever an accident.
     *
     * @param list<array{cidr:string,token:bool,note:string}> $acl
     */
    public static function isWorldOpenWithoutToken(array $acl): bool
    {
        foreach ($acl as $entry) {
            if (empty($entry['token']) && in_array($entry['cidr'], ['0.0.0.0/0', '::/0'], true)) {
                return true;
            }
        }
        return false;
    }

    /** Zero every bit past the prefix. */
    private static function maskBinary(string $bin, int $prefix): string
    {
        $bytes = strlen($bin);
        $full = intdiv($prefix, 8);
        $rest = $prefix % 8;

        $out = substr($bin, 0, $full);
        if ($full < $bytes) {
            $mask = $rest === 0 ? 0 : (0xFF << (8 - $rest)) & 0xFF;
            $out .= chr(ord($bin[$full]) & $mask);
            $out = str_pad($out, $bytes, "\0");
        }
        return $out;
    }
}

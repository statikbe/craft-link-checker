<?php

namespace statikbe\linkchecker\helpers;

/**
 * Which external URLs may be requested, and at which address: only http(s) on ports 80 and 443, and only when every address
 * of the host (IPv4 and IPv6) is a public one. The request then connects to the address that was checked.
 */
class AddressPolicy
{
    /** Ranges PHP's FILTER_FLAG_GLOBAL_RANGE lets through but that aren't a public website: multicast, NAT64, Teredo */
    private const EXTRA_BLOCKED = [
        ['224.0.0.0', 4],
        ['ff00::', 8],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['2001::', 32],
    ];

    /**
     * Whether an IP address is a public one (not private, reserved, loopback, link-local, carrier-grade NAT, multicast, …).
     */
    public static function isPublic(string $ip): bool
    {
        $ip = trim($ip, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        foreach (self::EXTRA_BLOCKED as [$network, $bits]) {
            if (self::inRange($ip, $network, $bits)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Where to connect for a URL: [host, port, ip], or the reason not to request it ('not-http', 'port', 'dns',
     * 'private-address').
     *
     * @param callable(string): string[] $resolve the host's addresses (IPv4 and IPv6)
     * @return array{0: string, 1: int, 2: string}|string
     */
    public static function target(string $url, callable $resolve): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = rtrim(strtolower($parts['host'] ?? ''), '.');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'not-http';
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            return 'port';
        }
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            $ips = [$literal];
        } else {
            $host = function_exists('idn_to_ascii') ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host) : $host;
            $ips = array_values(array_unique($resolve($host)));
        }
        if (!$ips) {
            return 'dns';
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                return 'private-address';
            }
        }
        // IPv4 first: it's what most servers answer on
        usort($ips, fn(string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));
        return [$host, $port, $ips[0]];
    }

    /**
     * Every address of a host: A records (also numeric forms such as 2130706433) and AAAA records.
     *
     * @return string[]
     */
    public static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host . '.', DNS_AAAA);
        foreach ($records ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        return $ips;
    }

    /**
     * The CURLOPT_RESOLVE entry that pins a host to an address (IPv6 between brackets).
     */
    public static function pin(string $host, int $port, string $ip): string
    {
        return sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[$ip]" : $ip);
    }

    /**
     * Whether the address a request connected to is the one that was checked.
     */
    public static function sameAddress(?string $connected, string $pinned): bool
    {
        if ($connected === null || $connected === '') {
            return false;
        }
        $a = @inet_pton(trim($connected, '[]'));
        $b = @inet_pton(trim($pinned, '[]'));
        return $a !== false && $a === $b;
    }

    private static function inRange(string $ip, string $network, int $bits): bool
    {
        $ip = @inet_pton($ip);
        $network = @inet_pton($network);
        if ($ip === false || $network === false || strlen($ip) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($ip, $network, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}

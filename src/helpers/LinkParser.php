<?php

namespace statikbe\linkchecker\helpers;

/**
 * Turns stored link values into link records, without Craft: reference tags ({entry:12@1:url}), the links in HTML, and URLs
 * classified against the sites' base URLs.
 *
 * A link record: ['kind' => …, 'targetId' => ?int, 'targetSiteId' => ?int, 'uri' => ?string, 'url' => string,
 * 'suffix' => ?string, 'issue' => ?string, 'fallbackUri' => ?string, 'fallbackSiteId' => ?int, 'host' => ?string]. Kinds:
 * - entry, asset, category: a reference to an element (targetId, targetSiteId);
 * - url: a URL of one of the sites (targetSiteId, uri), checked against the element URIs;
 * - external: a URL of another website: its domain (host) is checked (Hosts), the page itself in a later version;
 * - anchor: a link to an id on the same page (#contact);
 * - email, phone: mailto: and tel: links, with an issue when the address or number is invalid.
 */
class LinkParser
{
    public const KIND_ENTRY = 'entry';
    public const KIND_ASSET = 'asset';
    public const KIND_CATEGORY = 'category';
    public const KIND_URL = 'url';
    public const KIND_EXTERNAL = 'external';
    public const KIND_ANCHOR = 'anchor';
    public const KIND_EMAIL = 'email';
    public const KIND_PHONE = 'phone';

    public const ELEMENT_KINDS = [self::KIND_ENTRY, self::KIND_ASSET, self::KIND_CATEGORY];

    /**
     * A reference tag: {entry:12@1:url||fallback}, {asset:5:url}, {entry:12}. Null when it isn't one we check.
     *
     * The fallback is the URL Craft renders when the element can't be found (a deleted entry): {entry:12@1:url||https://…}.
     *
     * @return array{kind: string, targetId: int, targetSiteId: int|null, fallback: string|null}|null
     */
    public static function parseRef(string $value): ?array
    {
        if (!preg_match('/^\{(entry|asset|category):(\d+)(?:@(\d+))?(?::[^}|]*)?(?:\|\|([^}]*))?\}/', trim($value), $m)) {
            return null;
        }
        return [
            'kind' => $m[1],
            'targetId' => (int)$m[2],
            'targetSiteId' => isset($m[3]) && $m[3] !== '' ? (int)$m[3] : null,
            'fallback' => isset($m[4]) && $m[4] !== '' ? $m[4] : null,
        ];
    }

    /**
     * The links in an HTML string, in order: the href of <a> and the src of <img> (only references there: an image with a
     * plain URL isn't a link an editor made).
     *
     * @return array<array{url: string, text: string}>
     */
    public static function htmlLinks(string $html): array
    {
        if (trim($html) === '' || (stripos($html, '<a') === false && stripos($html, '<img') === false)) {
            return [];
        }
        $links = [];
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($doc->getElementsByTagName('*') as $node) {
            /** @var \DOMElement $node */
            $tag = strtolower($node->tagName);
            if ($tag === 'a' && $node->hasAttribute('href')) {
                $links[] = [
                    'url' => trim($node->getAttribute('href')),
                    'text' => trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''),
                ];
            } elseif ($tag === 'img' && self::parseRef($node->getAttribute('src')) !== null) {
                $links[] = ['url' => trim($node->getAttribute('src')), 'text' => trim($node->getAttribute('alt'))];
            }
        }
        return $links;
    }

    /**
     * The anchors an HTML string defines: the id and name attributes of its elements (headings, footnotes, …).
     *
     * @return string[]
     */
    public static function htmlIds(string $html): array
    {
        if (!preg_match_all('/\s(?:id|name)\s*=\s*(["\'])([^"\']+)\1/i', $html, $matches)) {
            return [];
        }
        return array_values(array_unique(array_map(fn(string $id) => html_entity_decode(trim($id), ENT_QUOTES | ENT_HTML5), $matches[2])));
    }

    /**
     * The anchor of a link's suffix or URL fragment ('#team' → 'team'), or null without one.
     */
    public static function fragment(?string $suffix): ?string
    {
        if ($suffix === null || !str_contains($suffix, '#')) {
            return null;
        }
        $fragment = rawurldecode(trim(substr($suffix, strpos($suffix, '#') + 1)));
        return $fragment !== '' && $fragment !== '/' && !str_starts_with($fragment, '!') ? $fragment : null;
    }

    /**
     * Classifies a link value (a URL, a reference tag, mailto:, tel:, #anchor) for a page in a site.
     *
     * @param array<array{id: int, baseUrl: string}> $sites the sites' base URLs (absolute), to tell own URLs from external ones
     * @param string[] $internalHosts other hosts that are this site (an old domain, www and non-www)
     * @return array{kind: string, targetId: int|null, targetSiteId: int|null, uri: string|null, url: string, suffix: string|null, issue: string|null, fallbackUri: string|null, fallbackSiteId: int|null, host: string|null}|null
     *   null for a value that isn't a link we check (empty, javascript:, a relative path without a slash)
     */
    public static function classify(string $value, int $pageSiteId, array $sites, array $internalHosts = []): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $record = [
            'kind' => '',
            'targetId' => null,
            'targetSiteId' => null,
            'uri' => null,
            'url' => $value,
            'suffix' => null,
            'issue' => null,
            'fallbackUri' => null,
            'fallbackSiteId' => null,
            'host' => null,
        ];

        if ($ref = self::parseRef($value)) {
            // The fallback URL, when it's one of the sites: the link may still work when the element is gone
            $fallback = $ref['fallback'] !== null ? self::classify($ref['fallback'], $pageSiteId, $sites, $internalHosts) : null;
            unset($ref['fallback']);
            // An anchor after the reference tag: {entry:12@1:url}#team
            $rest = substr($value, strpos($value, '}') + 1);
            return array_merge($record, $ref, [
                'suffix' => str_contains($rest, '#') ? substr($rest, strpos($rest, '#')) : null,
                'targetSiteId' => $ref['targetSiteId'] ?? $pageSiteId,
                'fallbackUri' => ($fallback['kind'] ?? null) === self::KIND_URL ? $fallback['uri'] : null,
                'fallbackSiteId' => ($fallback['kind'] ?? null) === self::KIND_URL ? $fallback['targetSiteId'] : null,
            ]);
        }

        $lower = strtolower($value);
        if (str_starts_with($lower, 'mailto:')) {
            $address = rawurldecode(explode('?', substr($value, 7), 2)[0]);
            $valid = array_filter(array_map('trim', explode(',', $address)));
            $ok = $valid && count(array_filter($valid, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) === false)) === 0;
            return array_merge($record, ['kind' => self::KIND_EMAIL, 'issue' => $ok ? null : 'invalid-email']);
        }
        if (str_starts_with($lower, 'tel:')) {
            $number = preg_replace('/[\s().\-\/]/', '', rawurldecode(substr($value, 4))) ?? '';
            $ok = (bool)preg_match('/^\+?\d{3,17}$/', $number);
            return array_merge($record, ['kind' => self::KIND_PHONE, 'issue' => $ok ? null : 'invalid-phone']);
        }
        if (str_starts_with($value, '#')) {
            return strlen($value) > 1 ? array_merge($record, ['kind' => self::KIND_ANCHOR, 'suffix' => $value]) : null;
        }
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value) && !preg_match('/^https?:/i', $value)) {
            // javascript:, sms:, data:, … aren't checked
            return null;
        }

        // An absolute URL, a protocol-relative one, or a root-relative path
        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }
        if (str_starts_with($value, '/')) {
            $site = self::siteById($sites, $pageSiteId);
            $parts = parse_url($site['baseUrl'] ?? '');
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $value = $origin . $value;
        } elseif (!preg_match('/^https?:\/\//i', $value)) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['host'])) {
            return array_merge($record, ['kind' => self::KIND_EXTERNAL, 'issue' => 'invalid-url']);
        }
        $host = strtolower($parts['host']);
        $path = '/' . ltrim($parts['path'] ?? '', '/');
        $suffix = isset($parts['fragment']) ? '#' . $parts['fragment'] : null;

        // The longest matching base URL wins (/nl/ before /)
        $best = null;
        $bestLength = -1;
        foreach ($sites as $site) {
            $base = parse_url($site['baseUrl']);
            if ($base === false || empty($base['host'])) {
                continue;
            }
            $baseHost = strtolower($base['host']);
            $sameHost = $host === $baseHost || in_array($host, array_map('strtolower', $internalHosts), true)
                || self::stripWww($host) === self::stripWww($baseHost);
            if (!$sameHost) {
                continue;
            }
            $basePath = '/' . trim($base['path'] ?? '', '/');
            $basePath = $basePath === '/' ? '/' : $basePath . '/';
            if ((str_starts_with(rtrim($path, '/') . '/', $basePath)) && strlen($basePath) > $bestLength) {
                $best = ['site' => $site, 'basePath' => $basePath];
                $bestLength = strlen($basePath);
            }
        }
        if ($best === null) {
            return array_merge($record, ['kind' => self::KIND_EXTERNAL, 'suffix' => $suffix, 'host' => $host]);
        }

        $uri = trim(substr(rtrim($path, '/') . '/', strlen($best['basePath'])), '/');
        return array_merge($record, [
            'kind' => self::KIND_URL,
            'targetSiteId' => $best['site']['id'],
            'uri' => $uri === '' ? '__home__' : rawurldecode($uri),
            'suffix' => $suffix,
        ]);
    }

    private static function stripWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param array<array{id: int, baseUrl: string}> $sites
     * @return array{id: int, baseUrl: string}|null
     */
    private static function siteById(array $sites, int $id): ?array
    {
        foreach ($sites as $site) {
            if ($site['id'] === $id) {
                return $site;
            }
        }
        return $sites[0] ?? null;
    }
}

<?php

use statikbe\linkchecker\models\Settings;
use statikbe\linkchecker\services\Report;
use statikbe\linkchecker\services\Urls;

/** A link record as the Extractor makes it */
function linkRecord(array $values): array
{
    return $values + [
        'kind' => 'external', 'url' => 'https://example.org/', 'host' => 'example.org', 'targetId' => null, 'targetSiteId' => null,
        'uri' => null, 'suffix' => null, 'label' => null, 'issue' => null, 'fallbackUri' => null, 'fallbackSiteId' => null,
        'location' => 'Content', 'sourceId' => 1, 'siteId' => 1,
    ];
}

/** The reason of a link as it's edited (Report::evaluate()), with the target's state */
function reasonOf(array $link, ?array $element = null, ?array $site = null, bool $hostUnresolved = false, ?array $check = null, array $settings = [], array $live = []): ?string
{
    $row = Report::stateRow($link, 1, 1, $element, $site, $hostUnresolved, $check);
    return Report::reasonFor($row, $live, '2026-10-30 12:00:00', new Settings($settings), fn() => false);
}

it('reads which domains don\'t exist from the hosts table', function() {
    // The host is the key, whatever the order of the columns
    expect(Report::unresolvedHosts([
        ['host' => 'example.org', 'resolves' => 1],
        ['host' => 'typo.cmo', 'resolves' => 0],
        ['resolves' => '0', 'host' => 'gone.example'],
    ]))->toBe(['typo.cmo' => true, 'gone.example' => true]);
});

it('reports a link to a domain that doesn\'t exist in the panel and on publish', function() {
    expect(reasonOf(linkRecord(['host' => 'typo.cmo']), hostUnresolved: true))->toBe('domain-not-found')
        ->and(Report::REASONS['domain-not-found'])->toBe(Report::BROKEN)
        ->and(reasonOf(linkRecord([])))->toBeNull()
        // Not when domains aren't checked
        ->and(reasonOf(linkRecord(['host' => 'typo.cmo']), hostUnresolved: true, settings: ['checkDomains' => false]))->toBeNull();
});

it('reports external pages that were checked', function() {
    expect(reasonOf(linkRecord([]), check: ['status' => Urls::BROKEN, 'httpCode' => 404, 'finalUrl' => null]))->toBe('external-not-found')
        ->and(reasonOf(linkRecord([]), check: ['status' => Urls::REDIRECT, 'httpCode' => 200, 'finalUrl' => 'https://example.org/new']))->toBe('moved-permanently')
        ->and(reasonOf(linkRecord([]), check: ['status' => Urls::BROKEN, 'httpCode' => 404, 'finalUrl' => null], settings: ['checkExternal' => false]))->toBeNull();
});

it('works out the state of a link to an entry', function() {
    $entry = linkRecord(['kind' => 'entry', 'url' => '{entry:5@1:url}', 'host' => null, 'targetId' => 5, 'targetSiteId' => 1]);
    $element = ['id' => 5, 'dateDeleted' => null, 'enabled' => 1, 'archived' => 0, 'postDate' => '2026-01-01 00:00:00', 'expiryDate' => null];
    $site = ['id' => 50, 'enabled' => 1, 'uri' => 'about', 'title' => 'About'];

    expect(reasonOf($entry, $element, $site))->toBeNull()
        ->and(reasonOf($entry))->toBe('deleted')
        ->and(reasonOf($entry, ['dateDeleted' => '2026-10-01 00:00:00'] + $element, $site))->toBe('trashed')
        ->and(reasonOf($entry, $element))->toBe('not-in-site')
        ->and(reasonOf($entry, ['enabled' => 0] + $element, $site))->toBe('disabled')
        ->and(reasonOf($entry, $element, ['uri' => null] + $site))->toBe('no-url')
        ->and(reasonOf($entry, ['expiryDate' => '2026-10-29 00:00:00'] + $element, $site))->toBe('expired')
        ->and(reasonOf($entry, ['postDate' => '2026-11-01 00:00:00'] + $element, $site))->toBe('pending')
        ->and(reasonOf(['targetSiteId' => 2] + $entry, $element, $site, settings: ['crossSiteLinks' => 'warning']))->toBe('other-site')
        // Deleted, but its saved URL still leads to a live page
        ->and(reasonOf(['fallbackUri' => 'about', 'fallbackSiteId' => 1] + $entry, live: ['1:about' => 7]))->toBe('deleted-fallback');
});

it('checks own-site URLs against the live pages and the redirects', function() {
    $url = linkRecord(['kind' => 'url', 'url' => 'https://example.be/old', 'host' => null, 'targetSiteId' => 1, 'uri' => 'Old']);
    $row = Report::stateRow($url, 1, 1, null, null, false, null);
    $settings = new Settings();

    expect(Report::reasonFor($row, ['1:old' => 3], 'now', $settings, fn() => false))->toBeNull()
        ->and(Report::reasonFor($row, [], 'now', $settings, fn() => false))->toBe('page-not-found')
        ->and(Report::reasonFor($row, [], 'now', $settings, fn() => true))->toBe('redirected')
        ->and(Report::reasonFor(['issue' => 'route'] + $row, [], 'now', $settings, fn() => false))->toBeNull();
});

<?php

use statikbe\linkchecker\helpers\LinkParser;

$sites = [
    ['id' => 1, 'baseUrl' => 'https://example.be/nl/'],
    ['id' => 2, 'baseUrl' => 'https://example.be/fr/'],
    ['id' => 3, 'baseUrl' => 'https://example.be/'],
];

it('reads reference tags with site, fallback and anchor', function() use ($sites) {
    expect(LinkParser::parseRef('{entry:12@2:url||https://example.be/fr/page}'))
        ->toBe(['kind' => 'entry', 'targetId' => 12, 'targetSiteId' => 2, 'fallback' => 'https://example.be/fr/page'])
        ->and(LinkParser::parseRef('{asset:5:url}'))->toBe(['kind' => 'asset', 'targetId' => 5, 'targetSiteId' => null, 'fallback' => null])
        ->and(LinkParser::parseRef('{user:1}'))->toBeNull()
        ->and(LinkParser::parseRef('https://example.be'))->toBeNull();

    $link = LinkParser::classify('{entry:12:url||https://example.be/fr/page}#team', 2, $sites);
    expect($link['kind'])->toBe('entry')
        ->and($link['targetSiteId'])->toBe(2)
        ->and($link['suffix'])->toBe('#team')
        ->and($link['fallbackUri'])->toBe('page')
        ->and($link['fallbackSiteId'])->toBe(2);
});

it('finds the links in HTML, images only with a reference', function() {
    $html = '<p><a href="{entry:3@1:url}">Our team</a> and <a href="https://other.org">other</a>'
        . '<img src="{asset:9@1:url}" alt="Logo"><img src="https://cdn.org/x.png"></p>';
    expect(LinkParser::htmlLinks($html))->toBe([
        ['url' => '{entry:3@1:url}', 'text' => 'Our team'],
        ['url' => 'https://other.org', 'text' => 'other'],
        ['url' => '{asset:9@1:url}', 'text' => 'Logo'],
    ])->and(LinkParser::htmlLinks('<p>No links</p>'))->toBe([]);
});

it('tells the sites\' own URLs from external ones, the longest base URL first', function() use ($sites) {
    expect(LinkParser::classify('https://example.be/nl/over-ons/', 1, $sites))
        ->toMatchArray(['kind' => 'url', 'targetSiteId' => 1, 'uri' => 'over-ons'])
        ->and(LinkParser::classify('https://www.example.be/contact', 1, $sites))
        ->toMatchArray(['kind' => 'url', 'targetSiteId' => 3, 'uri' => 'contact'])
        ->and(LinkParser::classify('/fr/', 1, $sites))
        ->toMatchArray(['kind' => 'url', 'targetSiteId' => 2, 'uri' => '__home__'])
        ->and(LinkParser::classify('https://example.be/nl/team#jan', 1, $sites))
        ->toMatchArray(['kind' => 'url', 'uri' => 'team', 'suffix' => '#jan'])
        ->and(LinkParser::classify('https://other.org/page', 1, $sites)['kind'])->toBe('external')
        ->and(LinkParser::classify('https://old-domain.be/nl/page', 1, $sites, ['old-domain.be'])['kind'])->toBe('url');
});

it('checks email addresses and phone numbers', function() use ($sites) {
    expect(LinkParser::classify('mailto:info@example.be?subject=Hi', 1, $sites)['issue'])->toBeNull()
        ->and(LinkParser::classify('mailto:bib.bg.@example.be', 1, $sites)['issue'])->toBe('invalid-email')
        ->and(LinkParser::classify('tel:+32 9 123 45 67', 1, $sites)['issue'])->toBeNull()
        ->and(LinkParser::classify('tel:call us', 1, $sites)['issue'])->toBe('invalid-phone');
});

it('skips what is not a link we check', function() use ($sites) {
    expect(LinkParser::classify('', 1, $sites))->toBeNull()
        ->and(LinkParser::classify('javascript:void(0)', 1, $sites))->toBeNull()
        ->and(LinkParser::classify('#', 1, $sites))->toBeNull()
        ->and(LinkParser::classify('relative/path', 1, $sites))->toBeNull()
        ->and(LinkParser::classify('#contact', 1, $sites))->toMatchArray(['kind' => 'anchor', 'suffix' => '#contact']);
});

it('finds the anchors HTML defines and the anchor of a suffix', function() {
    expect(LinkParser::htmlIds('<h2 id="intro">Intro</h2><a name="_ftn1"></a><p class="x">'))->toBe(['intro', '_ftn1'])
        ->and(LinkParser::fragment('#team'))->toBe('team')
        ->and(LinkParser::fragment('?a=1#caf%C3%A9'))->toBe('café')
        ->and(LinkParser::fragment('#'))->toBeNull()
        ->and(LinkParser::fragment('#!/route'))->toBeNull()
        ->and(LinkParser::fragment(null))->toBeNull();
});

it('normalizes external URLs: no fragment, lowercase scheme and host, a path', function() {
    expect(LinkParser::normalizeUrl('HTTPS://Example.ORG/Page?a=1#top'))->toBe('https://example.org/Page?a=1')
        ->and(LinkParser::normalizeUrl('https://example.org'))->toBe('https://example.org/')
        ->and(LinkParser::normalizeUrl('//Example.org/x'))->toBe('https://example.org/x')
        ->and(LinkParser::classify('https://www.statik.be/team', 1, [['id' => 1, 'baseUrl' => 'https://example.be/']])['host'])->toBe('www.statik.be');
});

it('compares URLs without the scheme, www. and a trailing slash', function() {
    expect(LinkParser::comparableUrl('http://statik.be'))->toBe(LinkParser::comparableUrl('https://www.statik.be/'))
        ->and(LinkParser::comparableUrl('https://example.org/a/'))->toBe(LinkParser::comparableUrl('https://example.org/a'))
        ->and(LinkParser::comparableUrl('https://example.org/old'))->not->toBe(LinkParser::comparableUrl('https://example.org/new'))
        ->and(LinkParser::comparableUrl('https://example.org/?a=1'))->not->toBe(LinkParser::comparableUrl('https://example.org/?a=2'));
});

<?php

use statikbe\linkchecker\services\Redirects;

/** Redirects with these rules, without a database */
function redirects(array $rules): Redirects
{
    $redirects = new Redirects();
    (new ReflectionProperty(Redirects::class, 'redirects'))->setValue($redirects, $rules);
    return $redirects;
}

it('finds exact redirects, case-insensitive, per site and match type', function() {
    $redirects = redirects([
        ['siteId' => null, 'pattern' => '/Old-Page', 'match' => 'path', 'regex' => false],
        ['siteId' => 2, 'pattern' => '/fr/ancien', 'match' => 'pathWithoutParams', 'regex' => false],
        ['siteId' => null, 'pattern' => 'https://example.be/full', 'match' => 'url', 'regex' => false],
    ]);
    expect($redirects->matches('https://example.be/old-page/', 1))->toBeTrue()
        ->and($redirects->matches('https://example.be/old-page?x=1', 1))->toBeTrue()
        ->and($redirects->matches('https://example.be/fr/ancien?utm=1', 2))->toBeTrue()
        ->and($redirects->matches('https://example.be/fr/ancien', 1))->toBeFalse()
        ->and($redirects->matches('https://example.be/full#top', 3))->toBeTrue()
        ->and($redirects->matches('https://example.be/other', 1))->toBeFalse();
});

it('tries the regular expressions', function() {
    $redirects = redirects([
        ['siteId' => null, 'pattern' => '^/news/(\d+)$', 'match' => 'path', 'regex' => true],
        ['siteId' => 1, 'pattern' => '^/blog/.*', 'match' => 'path', 'regex' => true],
    ]);
    expect($redirects->matches('https://example.be/news/12', 3))->toBeTrue()
        ->and($redirects->matches('https://example.be/news/abc', 3))->toBeFalse()
        ->and($redirects->matches('https://example.be/blog/x', 1))->toBeTrue()
        ->and($redirects->matches('https://example.be/blog/x', 2))->toBeFalse();
});

<?php

use statikbe\linkchecker\models\Settings;

it('checks every section or the listed ones', function() {
    expect((new Settings())->checksSection('news'))->toBeTrue()
        ->and((new Settings(['sections' => ['pages']]))->checksSection('news'))->toBeFalse()
        ->and((new Settings(['sections' => ['pages']]))->checksSection('pages'))->toBeTrue();
});

it('ignores links that match a pattern', function() {
    $settings = new Settings(['ignorePatterns' => ['#^https?://(www\.)?linkedin\.com/#']]);
    expect($settings->isIgnored('https://www.linkedin.com/company/x'))->toBeTrue()
        ->and($settings->isIgnored('https://example.be'))->toBeFalse();
});

it('knows the anchors the templates render, as strings or patterns', function() {
    $settings = new Settings(['knownAnchors' => ['main', '#^form-\d+$#']]);
    expect($settings->isKnownAnchor('main'))->toBeTrue()
        ->and($settings->isKnownAnchor('form-12'))->toBeTrue()
        ->and($settings->isKnownAnchor('team'))->toBeFalse();
});

it('skips the hosts that block link checkers, with or without www and subdomains', function() {
    $settings = new Settings();
    expect($settings->skipsHost('www.facebook.com'))->toBeTrue()
        ->and($settings->skipsHost('nl-be.facebook.com'))->toBeTrue()
        ->and($settings->skipsHost('notfacebook.com'))->toBeFalse()
        ->and($settings->skipsHost('www.statik.be'))->toBeFalse();
});

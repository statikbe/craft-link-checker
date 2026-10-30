<?php

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use statikbe\linkchecker\controllers\UtilityController;
use statikbe\linkchecker\helpers\HeadersReceived;
use statikbe\linkchecker\services\Urls;

it('sends every request through curl, never streamed, without a proxy or redirects', function() {
    $client = Urls::createClient(10, 'Test');
    $stack = $client->getConfig('handler');
    expect($stack)->toBeInstanceOf(HandlerStack::class);
    $handler = (new ReflectionProperty(HandlerStack::class, 'handler'))->getValue($stack);
    expect($handler)->toBeInstanceOf(CurlHandler::class)
        ->and($client->getConfig('allow_redirects'))->toBeFalse()
        ->and($client->getConfig('proxy'))->toBe('');
});

it('pins every request to the checked address and stops a GET after its headers', function() {
    foreach (['HEAD', 'GET'] as $method) {
        $options = Urls::requestOptions($method, ['example.org', 443, '93.184.215.14'], fn() => null, fn() => null);
        expect($options)->not->toHaveKey('stream')
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['example.org:443:93.184.215.14'])
            ->and($options)->toHaveKey('on_stats');
    }
    $options = Urls::requestOptions('GET', ['example.org', 443, '93.184.215.14'], fn() => null, fn() => null);
    expect(fn() => $options['on_headers'](new GuzzleHttp\Psr7\Response(405)))->toThrow(HeadersReceived::class)
        ->and(Urls::requestOptions('HEAD', ['example.org', 443, '93.184.215.14'], fn() => null, fn() => null))->not->toHaveKey('on_headers');
});

it('only counts a failure when the previous check was long enough ago', function() {
    $now = strtotime('2026-10-30 12:00:00 UTC');
    expect(Urls::failures(0, null, $now))->toBe(1)
        // A second job a minute later
        ->and(Urls::failures(1, '2026-10-30 11:59:00', $now))->toBe(1)
        // The retry an hour later (±10%)
        ->and(Urls::failures(1, '2026-10-30 11:05:00', $now))->toBe(2)
        ->and(Urls::failures(2, '2026-10-29 12:00:00', $now))->toBe(3);
});

it('keeps spreadsheet formulas out of the CSV export', function() {
    expect(UtilityController::csvCell('=HYPERLINK("https://evil.test","Click")'))->toBe('\'=HYPERLINK("https://evil.test","Click")')
        ->and(UtilityController::csvCell('+32 2 123'))->toBe('\'+32 2 123')
        ->and(UtilityController::csvCell('-1'))->toBe('\'-1')
        ->and(UtilityController::csvCell('@SUM(A1)'))->toBe('\'@SUM(A1)')
        ->and(UtilityController::csvCell("\tx"))->toBe("'\tx")
        ->and(UtilityController::csvCell('Our team'))->toBe('Our team')
        ->and(UtilityController::csvCell(null))->toBe('');
});

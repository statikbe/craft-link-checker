<?php

/**
 * Every message in the link-checker category, in PHP (Craft::t), Twig (|t) and the control panel JS (Craft.t), has a
 * translation in every language, and the JS ones are registered for the JS.
 */
function linkCheckerMessages(string $pattern, string $glob): array
{
    $messages = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/' . $glob, GLOB_BRACE) as $file) {
        preg_match_all($pattern, file_get_contents($file), $matches);
        array_push($messages, ...$matches[1]);
    }
    return array_values(array_unique($messages));
}

$php = linkCheckerMessages("/Craft::t\\('link-checker', '((?:[^'\\\\]|\\\\.)*)'/", '{*,*/*,*/*/*}.php');
$twig = linkCheckerMessages("/'((?:[^'\\\\]|\\\\.)*)'\\|t\\('link-checker'/", 'templates/{*,*/*,*/*/*}.twig');
$js = linkCheckerMessages("/Craft\\.t\\('link-checker', '((?:[^'\\\\]|\\\\.)*)'/", 'web/assets/cp/dist/*.js');

it('finds the messages', function() use ($php, $twig, $js) {
    expect(count($php))->toBeGreaterThan(1)
        ->and(count($twig))->toBeGreaterThan(8);
});

it('translates every message', function(string $language) use ($php, $twig, $js) {
    $translations = require dirname(__DIR__, 2) . "/src/translations/{$language}/link-checker.php";
    $messages = array_map(fn(string $message) => stripslashes($message), array_unique([...$php, ...$twig, ...$js]));
    expect(array_values(array_diff($messages, array_keys($translations))))->toBe([]);
})->with(['nl', 'fr']);

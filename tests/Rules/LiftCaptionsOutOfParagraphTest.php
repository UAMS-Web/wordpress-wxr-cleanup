<?php

declare(strict_types=1);
use UamsWeb\WxrCleanup\Rules\CollapseExtraLineBreaks;
use UamsWeb\WxrCleanup\Rules\LiftCaptionsOutOfParagraph;
use UamsWeb\WxrCleanup\Tests\Fixture;
use UamsWeb\WxrCleanup\Transform;

/**
 * @return iterable<string, array{string, string}>
 */
dataset('fixturePairs', function () {
    $fixtureDir = __DIR__.'/../fixtures/lift-captions-out-of-paragraph';
    $beforeFiles = glob($fixtureDir.'/*.before.xml');

    if ($beforeFiles === false) {
        return;
    }

    foreach ($beforeFiles as $beforeFile) {
        $afterFile = str_replace('.before.xml', '.after.xml', $beforeFile);

        if (! file_exists($afterFile)) {
            continue;
        }

        $label = basename($beforeFile, '.before.xml');

        yield $label => [$beforeFile, $afterFile];
    }
});
test('fixture round trip', function (string $beforeFile, string $afterFile) {
    // CollapseExtraLineBreaks pairs with this rule -- the lift
    // emits stacked `\n\n` text nodes around the chunk sequence
    // that the collapse normalizes back to a single blank line.
    $transform = new Transform([
        new LiftCaptionsOutOfParagraph,
        new CollapseExtraLineBreaks,
    ]);

    $before = Fixture::contents($beforeFile);
    $expectedAfter = Fixture::contents($afterFile);

    $actualAfter = $transform->apply($before);

    expect($actualAfter)->toBe($expectedAfter);
})->with('fixturePairs');

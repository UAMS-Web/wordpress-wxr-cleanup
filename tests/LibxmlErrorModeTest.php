<?php

declare(strict_types=1);
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;

afterEach(function () {
    libxml_use_internal_errors(false);
    libxml_clear_errors();
});

test('apply leaves libxml internal-errors mode as it found it', function (bool $mode) {
    $wxr = (string) file_get_contents(__DIR__.'/fixtures/full-pipeline/01-press-release.before.xml');
    expect($wxr)->not->toBe('');

    libxml_use_internal_errors($mode);

    Transform::withDefaultRules()->apply($wxr);

    expect(libxml_use_internal_errors())->toBe($mode);
})->with([
    'off' => [false],
    'on' => [true],
]);

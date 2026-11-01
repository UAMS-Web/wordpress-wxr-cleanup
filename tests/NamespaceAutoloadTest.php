<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;

test('the package namespace autoloads from src', function () {
    expect(class_exists(Transform::class))->toBeTrue();

    $file = (new ReflectionClass(Transform::class))->getFileName();

    expect($file)->toBeString()
        ->and(realpath((string) $file))->toBe(realpath(__DIR__.'/../src/Transform.php'));
});

test('the pre-rename namespace no longer resolves', function () {
    expect(class_exists('UamsWeb\\WxrCleanup\\Transform'))->toBeFalse();
});

test('composer.json maps the new prefix to src and the test prefix to tests', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer)->toBeArray();

    /** @var array{name: string, autoload: array{'psr-4': array<string, string>}, 'autoload-dev': array{'psr-4': array<string, string>}} $composer */
    expect($composer['name'])->toBe('uams-web/aether-wordpress-importer-cleanup')
        ->and($composer['autoload']['psr-4'])->toBe(['UAMSWeb\\Aether\\WordPressImporter\\Cleanup\\' => 'src/'])
        ->and($composer['autoload-dev']['psr-4'])->toBe(['UAMSWeb\\Aether\\WordPressImporter\\Cleanup\\Tests\\' => 'tests/']);
});

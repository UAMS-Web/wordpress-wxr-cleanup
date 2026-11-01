<?php

declare(strict_types=1);
use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;

test('apply rejects an empty input with a stated error', function (string $wxr) {
    Transform::withDefaultRules()->apply($wxr);
})->with([
    'empty string' => [''],
    'only invalid XML characters' => ["\x01\x02\x0B"],
])->throws(InvalidArgumentException::class, 'WXR input is empty.');

test('cli reports an empty input file and exits 1', function () {
    $input = tempnam(sys_get_temp_dir(), 'wxr-cleanup-empty-');
    if ($input === false) {
        throw new RuntimeException('Could not create temporary file');
    }

    try {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/wxr-cleanup').' '.escapeshellarg($input);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start CLI process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        expect($exitCode)->toBe(1)
            ->and($stdout)->toBe('')
            ->and($stderr)->toContain('Error: WXR input is empty.');
    } finally {
        unlink($input);
    }
});

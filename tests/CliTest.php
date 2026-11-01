<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Cleanup\Tests\Disposition\Support;
use UAMSWeb\Aether\WordPressImporter\Cleanup\Tests\Fixture;

beforeEach(function () {
    $this->fixtureBefore = __DIR__.'/fixtures/strip-html-comments/01-basic.before.xml';
});

test('file output matches stdout output', function () {
    $outputFile = tempnam(sys_get_temp_dir(), 'wxr-cleanup-out-');

    if ($outputFile === false) {
        throw new RuntimeException('Could not create temporary output file');
    }

    try {
        [$stdoutExitCode, $stdout] = runCli([$this->fixtureBefore]);
        [$fileExitCode] = runCli([$this->fixtureBefore, $outputFile]);

        expect($stdoutExitCode)->toBe(0);
        expect($fileExitCode)->toBe(0);
        expect($stdout)->not->toBe('');
        expect(Fixture::contents($outputFile))->toBe($stdout);
        expect($stdout)->not->toBe(Fixture::contents($this->fixtureBefore));
    } finally {
        @unlink($outputFile);
    }
});

test('rejects missing input file', function () {
    [$exitCode] = runCli([__DIR__.'/fixtures/does-not-exist.xml']);

    expect($exitCode)->toBe(1);
});

test('rejects same input and output path', function () {
    [$exitCode] = runCli([$this->fixtureBefore, $this->fixtureBefore]);

    expect($exitCode)->toBe(1);
});

test('drops a disposition\'s duplicates before cleaning', function () {
    $input = tempnam(sys_get_temp_dir(), 'wxr-cleanup-in-');
    $disposition = tempnam(sys_get_temp_dir(), 'wxr-cleanup-disposition-');
    if ($input === false || $disposition === false) {
        throw new RuntimeException('Could not create temporary files');
    }

    try {
        file_put_contents($input, Support::wxr([
            ['id' => 1, 'slug' => 'turnage-named-chairman'],
            ['id' => 2, 'slug' => 'turnagenamedchairman'],
        ]));
        file_put_contents($disposition, Support::disposition([[
            'canonical_slug' => 'turnage-named-chairman',
            'keep' => Support::ref(1, 'turnage-named-chairman'),
            'drop' => [Support::ref(2, 'turnagenamedchairman')],
        ]])->toJson());

        [$withExit, $with] = runCli(['--disposition='.$disposition, $input]);
        [$withoutExit, $without] = runCli([$input]);

        expect($withExit)->toBe(0);
        expect($withoutExit)->toBe(0);
        expect(substr_count($with, '<item>'))->toBe(1);
        expect(substr_count($without, '<item>'))->toBe(2);
    } finally {
        @unlink($input);
        @unlink($disposition);
    }
});

test('rejects an unknown option and an unreadable disposition', function (string $option) {
    [$exitCode, $stdout] = runCli([$option, $this->fixtureBefore]);

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
})->with([
    'unknown option' => ['--dispose=x.json'],
    'missing disposition file' => ['--disposition='.__DIR__.'/fixtures/does-not-exist.json'],
]);

test('the disposition builder rejects an unknown option', function () {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/build-duplicate-disposition').' --exclude=x.txt --site=a --ticket=b --excluded=c out.json in.xml 2>&1';
    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(1);
    expect(implode("\n", $output))->toContain('unknown option: --exclude=x.txt');
});

/**
 * @param  list<string>  $args
 * @return array{0: int, 1: string}
 */
function runCli(array $args): array
{
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/wxr-cleanup');
    foreach ($args as $arg) {
        $command .= ' '.escapeshellarg($arg);
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start CLI process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout];
}

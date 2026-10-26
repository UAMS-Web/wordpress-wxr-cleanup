<?php

declare(strict_types=1);

namespace UamsWeb\WxrCleanup\Tests;

final class Fixture
{
    public static function contents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Could not read fixture: {$path}");
        }

        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
}

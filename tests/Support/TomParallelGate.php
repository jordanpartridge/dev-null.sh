<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Sleep;

final class TomParallelGate
{
    public static function enter(string $path, string $label): string
    {
        file_put_contents($path, "1\n", FILE_APPEND | LOCK_EX);
        $deadline = microtime(true) + 5;

        do {
            clearstatcache(true, $path);

            if (count(file($path, FILE_IGNORE_NEW_LINES) ?: []) >= 2) {
                return 'overlapped:'.$label;
            }

            Sleep::usleep(20_000);
        } while (microtime(true) < $deadline);

        return 'alone:'.$label;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use JordanPartridge\ParallelTools\Attributes\Concurrent;
use ReflectionClass;

final class TomParallelAttributes
{
    public static function marked(string $class): bool
    {
        return new ReflectionClass($class)->getAttributes(Concurrent::class) !== [];
    }
}

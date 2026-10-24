<?php

declare(strict_types=1);

namespace Tests\Support;

use JordanPartridge\ParallelTools\Attributes\Concurrent;

#[Concurrent]
final class TomParallelAlpha extends TomParallelTimedProbe
{
    protected function label(): string
    {
        return 'alpha';
    }
}

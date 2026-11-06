<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final readonly class TomParallelSequential implements Tool
{
    public function __construct(private string $earlier, private string $later) {}

    public function description(): string
    {
        return 'Test lookup that must stay in place.';
    }

    public function handle(Request $request): string
    {
        clearstatcache(true, $this->earlier);
        clearstatcache(true, $this->later);
        $earlier = is_file($this->earlier) ? count(file($this->earlier, FILE_IGNORE_NEW_LINES) ?: []) : 0;
        $later = is_file($this->later) ? count(file($this->later, FILE_IGNORE_NEW_LINES) ?: []) : 0;

        return 'earlier:'.$earlier.',later:'.$later;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

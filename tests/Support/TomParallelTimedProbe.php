<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

abstract class TomParallelTimedProbe implements Tool
{
    public function __construct(private readonly string $path) {}

    abstract protected function label(): string;

    final public function description(): string
    {
        return 'Test lookup '.$this->label();
    }

    final public function handle(Request $request): string
    {
        return TomParallelGate::enter($this->path, $this->label());
    }

    /** @return array<string, Type> */
    final public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

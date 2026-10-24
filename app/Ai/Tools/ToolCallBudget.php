<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The two-call cap for one tool instance.
 *
 * A concurrent batch serializes the tool into each child, and the child does not write the parent's
 * properties back. An in-memory counter would stay where the parent left it, so a second wave of calls
 * could run past the cap. The count is a file the parent and those children lock.
 */
final readonly class ToolCallBudget
{
    private const int Limit = 2;

    private string $path;

    public function __construct()
    {
        $this->path = storage_path('framework/cache/data/tom-tool-'.Str::uuid()->toString());
    }

    public function exceeded(): bool
    {
        $handle = fopen($this->path, 'c+') ?: throw new RuntimeException('Unable to track the tool call budget.');

        flock($handle, LOCK_EX);
        $count = ((int) stream_get_contents($handle)) + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) $count);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $count > self::Limit;
    }
}

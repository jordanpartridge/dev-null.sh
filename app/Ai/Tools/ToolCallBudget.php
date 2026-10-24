<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ReleasesToolCallBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The two-call cap for one tool instance.
 *
 * A concurrent batch serializes the tool into each child, and the child does not write the parent's
 * properties back. The count is a file-cache entry those children share, taken under the store's lock.
 * Tests keep the array store as the default, and a cache row still inside the reply's transaction is
 * invisible to a child, so the count uses the file store. The entry expires if a worker dies mid-reply,
 * and the reply releases it when the request finishes.
 */
final readonly class ToolCallBudget
{
    private const string Store = 'file';

    private const int Limit = 2;

    private const int TtlSeconds = 900;

    private const int LockSeconds = 10;

    private string $key;

    public function __construct()
    {
        $this->key = 'tom-tool-budget:'.Str::uuid()->toString();
    }

    /**
     * @param  iterable<mixed>  $tools
     */
    public static function releaseAll(iterable $tools): void
    {
        foreach ($tools as $tool) {
            if (! $tool instanceof ReleasesToolCallBudget) {
                continue;
            }

            $tool->releaseToolCallBudget();
        }
    }

    public function exceeded(): bool
    {
        $cache = Cache::repository(Cache::store(self::Store)->getStore(), ['store' => self::Store]);

        $count = $cache->withoutOverlapping($this->key, function () use ($cache): int {
            $count = $cache->integer($this->key, 0) + 1;
            $cache->put($this->key, $count, self::TtlSeconds);

            return $count;
        }, self::LockSeconds, self::LockSeconds);

        return $count > self::Limit;
    }

    public function release(): void
    {
        Cache::store(self::Store)->forget($this->key);
    }
}

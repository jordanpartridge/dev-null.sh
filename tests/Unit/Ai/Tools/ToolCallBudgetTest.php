<?php

declare(strict_types=1);

use App\Actions\CreateTomFriendship;
use App\Actions\CreateTomReply;
use App\Ai\Tools\ToolCallBudget;
use App\Jobs\EmbedCommit;
use App\Jobs\ModerateCommit;
use App\Jobs\ReplyAsTom;
use App\Models\Commit;
use App\Models\User;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Embeddings;

it('removes the tool budget when a reply finishes', function (bool $toolThrows): void {
    config(['ai.providers.openai.key' => 'test', 'ai.providers.opencode.key' => 'test']);
    Queue::fake([ModerateCommit::class, EmbedCommit::class, ReplyAsTom::class]);

    if (! $toolThrows) {
        Embeddings::fake([[embeddingVector(0)]]);
    }

    $written = [];
    $store = Cache::store('file')->getStore();

    throw_unless($store instanceof FileStore, RuntimeException::class, 'The tool budget is not on the file store.');

    Event::listen(function (KeyWritten $event) use (&$written, $store): void {
        if ($event->storeName !== 'file' || ! str_starts_with($event->key, 'tom-tool-budget:')) {
            return;
        }

        $written[] = $event->key;

        expect($event->seconds)->toBe(900)
            ->and(File::exists($store->path($event->key)))->toBeTrue();
    });

    $tom = User::factory()->create(['username' => 'tom', 'github_id' => CreateTomFriendship::GITHUB_ID]);
    $commit = Commit::factory()->create([
        'body' => '@tom where did the badges come from?',
        'mentions' => ['tom' => $tom->id],
    ]);
    Http::fake([
        'api.openai.com/v1/decisions' => Http::response(['answers' => [
            ['name' => 'SearchCommits', 'type' => 'predicate', 'probability' => 1],
        ]]),
        'opencode.ai/zen/v1/chat/completions' => Http::sequence()
            ->push(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
                'role' => 'assistant', 'content' => null, 'tool_calls' => [
                    ['id' => 'call-search', 'type' => 'function', 'function' => [
                        'name' => 'SearchCommits', 'arguments' => '{"query":"badges"}',
                    ]],
                ],
            ]]]])
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => [
                'role' => 'assistant', 'content' => 'Nothing in the posts.',
            ]]]]),
    ]);

    $reply = fn () => resolve(CreateTomReply::class)->handle($commit->id);

    if ($toolThrows) {
        expect($reply)->toThrow(RuntimeException::class);
        expect($commit->replies()->count())->toBe(0);
    } else {
        $reply();
        expect($commit->replies()->sole()->body)->toBe('Nothing in the posts.');
    }

    expect($written)->not->toBeEmpty();

    foreach (array_unique($written) as $key) {
        expect(Cache::store('file')->has($key))->toBeFalse()
            ->and(File::exists($store->path($key)))->toBeFalse()
            ->and(File::exists($store->path('file-store-lock:'.$key)))->toBeFalse();
    }

    Event::forget(KeyWritten::class);
})->with([
    'after the tools return' => false,
    'when a tool throws' => true,
]);

it('skips tools that do not carry a budget', function (): void {
    expect(fn () => ToolCallBudget::releaseAll([new stdClass]))->not->toThrow(Throwable::class);
});

it('fails closed when the budget lock stays held past the wait window', function (): void {
    // The suite freezes time and fakes Sleep, so advance the clock on each faked
    // sleep; that lets the lock's bounded wait expire deterministically instead
    // of spinning forever.
    Sleep::fake(syncWithCarbon: true);

    $budget = new ToolCallBudget;

    $key = (new ReflectionProperty($budget, 'key'))->getValue($budget);

    $lock = Cache::store('file')->lock($key, 30);

    expect($lock->acquire())->toBeTrue();

    try {
        // The counter is never incremented while the lock is held elsewhere, so
        // the cap must still be enforced instead of letting the call slip past.
        expect($budget->exceeded())->toBeTrue();
    } finally {
        $lock->forceRelease();
    }
});

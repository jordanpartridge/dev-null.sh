<?php

declare(strict_types=1);

use App\Ai\Agents\Tom;
use App\Ai\Tools\CommunityStats;
use App\Ai\Tools\RepositoryInsights;
use App\Ai\Tools\SearchCommits;
use App\Ai\Tools\SearchKnowledgeBase;
use App\Ai\Tools\SearchRepositoryHistory;
use App\Ai\Tools\ToolCallBudget;
use App\Models\Commit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JordanPartridge\ParallelTools\ParallelAiManager;
use JordanPartridge\ParallelTools\ParallelTextGenerationLoop;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
use Tests\Support\TomParallelAlpha;
use Tests\Support\TomParallelAttributes;
use Tests\Support\TomParallelBeta;
use Tests\Support\TomParallelDelta;
use Tests\Support\TomParallelEpsilon;
use Tests\Support\TomParallelGateway;
use Tests\Support\TomParallelScript;
use Tests\Support\TomParallelSequential;

it('marks the read-only lookups concurrent', function (): void {
    $flags = collect(glob(app_path('Ai/Tools/*.php')))
        ->map(fn (string $path): string => 'App\\Ai\\Tools\\'.basename($path, '.php'))
        ->sort()
        ->mapWithKeys(fn (string $class): array => [
            $class => TomParallelAttributes::marked($class),
        ])
        ->all();

    expect($flags)->toBe([
        CommunityStats::class => true,
        RepositoryInsights::class => true,
        SearchCommits::class => true,
        SearchKnowledgeBase::class => true,
        SearchRepositoryHistory::class => true,
        ToolCallBudget::class => false,
    ]);
});

it('runs concurrent calls from one step together and hands results back in call order', function (): void {
    $first = sys_get_temp_dir().'/tom-parallel-'.uniqid('', true);
    $second = sys_get_temp_dir().'/tom-parallel-'.uniqid('', true);
    $tools = [
        new TomParallelAlpha($first),
        new TomParallelBeta($first),
        new TomParallelSequential($first, $second),
        new TomParallelDelta($second),
        new TomParallelEpsilon($second),
    ];
    $names = array_map(class_basename(...), $tools);

    try {
        [$response, $gateway] = promptTom($tools, [
            TomParallelScript::calls([
                ['call-alpha', $names[0]],
                ['call-beta', $names[1]],
                ['call-sequential', $names[2]],
                ['call-delta', $names[3]],
                ['call-epsilon', $names[4]],
            ]),
            TomParallelScript::text('Done.'),
        ], prior: $names);

        $results = delivered($gateway);

        expect($response->text)->toBe('Done.')
            ->and($results->pluck('id')->all())->toBe(['call-alpha', 'call-beta', 'call-sequential', 'call-delta', 'call-epsilon'])
            ->and($results->pluck('result')->all())->toBe([
                'overlapped:alpha',
                'overlapped:beta',
                'earlier:2,later:0',
                'overlapped:delta',
                'overlapped:epsilon',
            ]);
    } finally {
        File::delete($first);
        File::delete($second);
    }
});

it('runs community statistics from one step in child processes and keeps call order', function (): void {
    $stats = new CommunityStats;

    try {
        [, $gateway] = promptTom([$stats], [
            TomParallelScript::calls([
                ['call-members', 'CommunityStats', ['metric' => 'members']],
                ['call-signups', 'CommunityStats', ['metric' => 'new_members', 'days' => 7]],
            ]),
            TomParallelScript::text('Counted.'),
        ]);

        $results = delivered($gateway);

        expect($results->pluck('id')->all())->toBe(['call-members', 'call-signups'])
            ->and($results[0]->result)->toContain('"metric":"members"')
            ->and($results[1]->result)->toContain('"metric":"new_members"');
    } finally {
        $stats->releaseToolCallBudget();
    }
});

it('keeps the two-call cap when parallel children share the tool', function (): void {
    $stats = new CommunityStats;
    $again = new Request(['metric' => 'members']);

    try {
        expect($stats->handle($again))->not->toContain('budget exhausted');

        [, $gateway] = promptTom([$stats], [
            TomParallelScript::calls([
                ['call-signups', 'CommunityStats', ['metric' => 'new_members', 'days' => 7]],
                ['call-authors', 'CommunityStats', ['metric' => 'public_authors', 'days' => 30]],
            ]),
            TomParallelScript::text('Counted.'),
        ]);

        $bodies = delivered($gateway)->pluck('result');

        expect(delivered($gateway)->pluck('id')->all())->toBe(['call-signups', 'call-authors'])
            ->and($bodies->filter(fn (string $result): bool => str_contains($result, 'budget exhausted')))->toHaveCount(1)
            ->and($bodies->reject(fn (string $result): bool => str_contains($result, 'budget exhausted')))->toHaveCount(1)
            ->and($stats->handle($again))->toContain('budget exhausted');
    } finally {
        $stats->releaseToolCallBudget();
    }
});

/**
 * @param  iterable<int, Tool>  $tools
 * @param  list<StepResponse>  $steps
 * @param  list<string>  $prior
 * @return array{0: AgentResponse, 1: TomParallelGateway}
 */
function promptTom(iterable $tools, array $steps, array $prior = []): array
{
    config(['ai.providers.opencode.key' => 'test-opencode', 'concurrency.default' => 'process']);
    Process::preventStrayProcesses(false);

    $provider = resolve(AiManager::class)->textProvider('opencode');
    $gateway = new TomParallelGateway($steps);
    $original = $provider->textGateway();

    expect(resolve(AiManager::class))->toBeInstanceOf(ParallelAiManager::class);

    try {
        $provider->useTextGateway($gateway);

        expect($provider->textGenerationLoop())->toBeInstanceOf(ParallelTextGenerationLoop::class);

        $lookup = $prior === [] ? [] : [TomParallelScript::prior($prior)];
        $response = new Tom(Commit::factory()->create(), $tools, $lookup)->prompt('How many members joined?');
    } finally {
        $provider->useTextGateway($original);
    }

    return [$response, $gateway];
}

/**
 * Tool results from the step that followed the tool calls, in the order the model received them.
 *
 * @return Collection<int, ToolResult>
 */
function delivered(TomParallelGateway $gateway): Collection
{
    return collect($gateway->prompts[1] ?? [])
        ->whereInstanceOf(ToolResultMessage::class)
        ->flatMap(fn (ToolResultMessage $message): Collection => $message->toolResults)
        ->reject(fn (ToolResult $result): bool => str_starts_with($result->id, 'prior-'))
        ->values();
}

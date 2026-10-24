<?php

declare(strict_types=1);

namespace Tests\Support;

use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

final class TomParallelScript
{
    /**
     * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $calls
     */
    public static function calls(array $calls): StepResponse
    {
        return new StepResponse(
            '',
            array_map(
                fn (array $call): ToolCall => new ToolCall($call[0], $call[1], $call[2] ?? []),
                $calls,
            ),
            FinishReason::ToolCalls,
            new TextUsage,
            new Meta('opencode', 'deepseek-v4.1-flash'),
        );
    }

    public static function text(string $text): StepResponse
    {
        return new StepResponse($text, [], FinishReason::Stop, new TextUsage, new Meta('opencode', 'deepseek-v4.1-flash'));
    }

    /**
     * @param  list<string>  $names
     */
    public static function prior(array $names): ToolResultMessage
    {
        return new ToolResultMessage(collect($names)->map(fn (string $name): ToolResult => new ToolResult(
            'prior-'.$name,
            $name,
            [],
            'prior',
        ))->values());
    }
}

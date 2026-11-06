<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use RuntimeException;

final class TomParallelGateway implements StepTextGateway
{
    /** @var list<array<int, Message>> */
    public array $prompts = [];

    private int $index = 0;

    /** @param list<StepResponse> $steps */
    public function __construct(private array $steps) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->prompts[] = $messages;

        return $this->steps[$this->index++] ?? throw new RuntimeException('The scripted model has no step left.');
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        yield from [];

        return $this->generateTextStep(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }
}

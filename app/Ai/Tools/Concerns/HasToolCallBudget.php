<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Tools\ToolCallBudget;

/**
 * @property-read ToolCallBudget $budget
 *
 * @phpstan-require-implements ReleasesToolCallBudget
 */
trait HasToolCallBudget
{
    public function releaseToolCallBudget(): void
    {
        $this->budget->release();
    }
}

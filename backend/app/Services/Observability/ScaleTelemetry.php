<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Models\Attempt;

interface ScaleTelemetry
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function attemptStarted(Attempt $attempt, array $meta = []): void;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function attemptSubmitted(Attempt $attempt, array $meta = []): void;

    /**
     * @param  array<string,mixed>  $scoreDto
     */
    public function attemptScored(Attempt $attempt, array $scoreDto = []): void;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function reportViewed(Attempt $attempt, array $meta = []): void;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function unlocked(Attempt $attempt, array $meta = []): void;

    /**
     * @param  array<string,mixed>  $meta
     */
    public function crisisTriggered(Attempt $attempt, array $meta = []): void;
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

interface NarrativeProviderInterface
{
    public function name(): string;

    public function generate(NarrativeGenerationRequest $request): NarrativeGenerationResponse;
}

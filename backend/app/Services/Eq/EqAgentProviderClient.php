<?php

declare(strict_types=1);

namespace App\Services\Eq;

interface EqAgentProviderClient
{
    public function isConfigured(): bool;

    public function unavailableReason(): ?string;

    public function generate(EqAgentProviderRequest $request): EqAgentProviderResponse;
}

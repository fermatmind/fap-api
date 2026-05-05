<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

final readonly class BigFiveV2CouplingResolution
{
    public function __construct(
        public string $requestedKey,
        public string $decisionType,
        public ?string $resolvedKey,
        public ?string $sourcePackage,
        public bool $selectable,
        public string $surface,
        public ?string $assetRole = null,
        public ?string $suppressionReason = null,
        public ?string $aliasDecisionType = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'requested_key' => $this->requestedKey,
            'decision_type' => $this->decisionType,
            'resolved_key' => $this->resolvedKey,
            'source_package' => $this->sourcePackage,
            'selectable' => $this->selectable,
            'surface' => $this->surface,
            'asset_role' => $this->assetRole,
            'suppression_reason' => $this->suppressionReason,
            'alias_decision_type' => $this->aliasDecisionType,
        ];
    }
}

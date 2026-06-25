<?php

declare(strict_types=1);

namespace App\Services\Eq;

final class EqAgentProviderResponse
{
    /**
     * @param  list<string>  $summaryPoints
     * @param  list<string>  $sourceAssetIds
     * @param  list<string>  $boundaryClaimIds
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $text,
        public readonly array $summaryPoints,
        public readonly string $followUpQuestion,
        public readonly array $sourceAssetIds,
        public readonly array $boundaryClaimIds,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function assistantResponse(): array
    {
        return [
            'role' => 'assistant',
            'text' => $this->text,
            'summary_points' => $this->summaryPoints,
            'follow_up_question' => $this->followUpQuestion,
            'source_asset_ids' => $this->sourceAssetIds,
            'boundary_claim_ids' => $this->boundaryClaimIds,
        ];
    }
}

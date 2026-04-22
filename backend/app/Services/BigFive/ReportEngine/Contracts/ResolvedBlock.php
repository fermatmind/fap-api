<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Contracts;

final class ResolvedBlock
{
    /**
     * @param  array<string,mixed>  $resolvedCopy
     * @param  array<string,mixed>  $provenance
     * @param  array<string,mixed>  $analytics
     */
    public function __construct(
        public readonly string $blockUid,
        public readonly string $kind,
        public readonly string $component,
        public readonly string $blockId,
        public readonly array $resolvedCopy,
        public readonly array $provenance,
        public readonly array $analytics = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'block_uid' => $this->blockUid,
            'kind' => $this->kind,
            'component' => $this->component,
            'block_id' => $this->blockId,
            'resolved_copy' => $this->resolvedCopy,
            'provenance' => $this->provenance,
            'analytics' => $this->analytics,
        ];
    }
}

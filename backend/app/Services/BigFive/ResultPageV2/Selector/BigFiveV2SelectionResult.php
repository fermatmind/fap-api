<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

final readonly class BigFiveV2SelectionResult
{
    /**
     * @param  list<BigFiveV2SelectedAssetRef>  $selectedAssetRefs
     * @param  list<array<string,mixed>>  $suppressedAssetRefs
     * @param  list<array<string,mixed>>  $unresolvedRefSuppressions
     * @param  list<string>  $pendingSurfaces
     * @param  array<string,mixed>  $safetyDecisions
     * @param  array<string,mixed>  $selectionTraceInternal
     */
    public function __construct(
        public array $selectedAssetRefs,
        public array $suppressedAssetRefs,
        public array $unresolvedRefSuppressions,
        public array $pendingSurfaces,
        public array $safetyDecisions,
        public array $selectionTraceInternal,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'selected_asset_refs' => array_map(
                static fn (BigFiveV2SelectedAssetRef $ref): array => $ref->toArray(),
                $this->selectedAssetRefs,
            ),
            'suppressed_asset_refs' => $this->suppressedAssetRefs,
            'unresolved_ref_suppressions' => $this->unresolvedRefSuppressions,
            'pending_surfaces' => $this->pendingSurfaces,
            'safety_decisions' => $this->safetyDecisions,
            'selection_trace_internal' => $this->selectionTraceInternal,
        ];
    }
}

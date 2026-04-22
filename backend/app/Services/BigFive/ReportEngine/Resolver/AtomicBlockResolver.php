<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Contracts\ResolvedBlock;

final class AtomicBlockResolver
{
    private const TRAIT_CODES = ['O', 'C', 'E', 'A', 'N'];

    public function __construct(
        private readonly ProvenanceRecorder $provenanceRecorder = new ProvenanceRecorder,
    ) {}

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,list<ResolvedBlock>>
     */
    public function resolve(ReportContext $context, array $registry): array
    {
        $blocks = [];
        foreach (self::TRAIT_CODES as $traitCode) {
            $band = $this->atomicBand($context->domainBand($traitCode));
            $slots = $registry['atomic'][$traitCode]['bands'][$band]['slots'] ?? [];
            if (! is_array($slots)) {
                continue;
            }

            foreach ($slots as $sectionKey => $copy) {
                if (! is_array($copy)) {
                    continue;
                }

                $blocks[(string) $sectionKey][] = new ResolvedBlock(
                    blockUid: "{$sectionKey}.atomic.{$traitCode}.{$band}",
                    kind: 'trait_atomic',
                    component: 'BigFiveTraitBlock',
                    blockId: "atomic_{$traitCode}_{$band}",
                    resolvedCopy: $copy,
                    provenance: $this->provenanceRecorder->record(["atomic/{$traitCode}.json#bands.{$band}.slots.{$sectionKey}"]),
                    analytics: [
                        'trait_code' => $traitCode,
                        'band' => $band,
                        'percentile' => $context->domainPercentile($traitCode),
                    ],
                );
            }
        }

        return $blocks;
    }

    private function atomicBand(string $band): string
    {
        return match ($band) {
            'low', 'low_mid' => 'low',
            'high', 'high_mid' => 'high',
            default => 'mid',
        };
    }
}

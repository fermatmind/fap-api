<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixRow;

final readonly class BigFiveV2SelectorInput
{
    /**
     * @param  array<string,string>  $domainBands
     * @param  array<string,int>  $domainScores
     * @param  list<array<string,mixed>>  $facetSignals
     * @param  list<string>  $includeSlots
     * @param  list<string>  $includeRegistryKeys
     */
    public function __construct(
        public string $scaleCode,
        public string $formCode,
        public array $domainBands,
        public array $domainScores,
        public array $facetSignals,
        public string $qualityStatus,
        public string $normStatus,
        public string $readingMode,
        public ?string $scenario,
        public BigFiveV2RouteMatrixRow $routeRow,
        public array $includeSlots = [],
        public array $includeRegistryKeys = [],
    ) {}

    /**
     * @param  array<string,mixed>  $goldenCase
     */
    public static function fromGoldenCase(array $goldenCase, BigFiveV2RouteMatrixRow $routeRow): self
    {
        $projection = (array) ($goldenCase['input_projection'] ?? []);
        $expectedSelection = (array) ($goldenCase['expected_selection'] ?? []);

        return new self(
            scaleCode: (string) ($projection['scale_code'] ?? 'BIG5_OCEAN'),
            formCode: (string) ($projection['form_code'] ?? 'big5_120'),
            domainBands: self::stringMap((array) ($projection['domain_bands'] ?? [])),
            domainScores: self::intMap((array) ($projection['domain_scores'] ?? [])),
            facetSignals: array_values((array) ($projection['facet_patterns'] ?? [])),
            qualityStatus: (string) ($projection['quality_status'] ?? 'valid'),
            normStatus: (string) ($projection['norm_status'] ?? 'available'),
            readingMode: (string) ($projection['reading_mode'] ?? 'standard'),
            scenario: isset($projection['scenario']) ? (string) $projection['scenario'] : null,
            routeRow: $routeRow,
            includeSlots: array_values(array_map('strval', (array) ($expectedSelection['include_slots'] ?? []))),
            includeRegistryKeys: array_values(array_map('strval', (array) ($expectedSelection['include_registry_keys'] ?? []))),
        );
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return array<string,string>
     */
    private static function stringMap(array $values): array
    {
        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[(string) $key] = (string) $value;
        }

        return $mapped;
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return array<string,int>
     */
    private static function intMap(array $values): array
    {
        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[(string) $key] = (int) $value;
        }

        return $mapped;
    }
}

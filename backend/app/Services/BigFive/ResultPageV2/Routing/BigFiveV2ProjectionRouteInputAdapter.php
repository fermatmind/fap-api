<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Routing;

use InvalidArgumentException;

final class BigFiveV2ProjectionRouteInputAdapter
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @var list<string>
     */
    private array $errors = [];

    public function __construct(
        private readonly BigFiveV2BandMapper $bandMapper = new BigFiveV2BandMapper(),
    ) {}

    /**
     * @param  array<string,mixed>  $scoreResult
     */
    public function fromScoreResult(array $scoreResult): ?BigFiveV2RouteInput
    {
        $this->errors = [];
        $domainPercentiles = data_get($scoreResult, 'scores_0_100.domains_percentile');
        if (! is_array($domainPercentiles)) {
            $this->errors[] = 'score_result.scores_0_100.domains_percentile missing';

            return null;
        }

        return $this->buildRouteInput(
            $domainPercentiles,
            $this->facetSignalsFromPercentileMap((array) data_get($scoreResult, 'scores_0_100.facets_percentile', [])),
            (array) ($scoreResult['quality'] ?? []),
            (array) ($scoreResult['norms'] ?? []),
        );
    }

    /**
     * @param  array<string,mixed>  $projection
     */
    public function fromProjection(array $projection): ?BigFiveV2RouteInput
    {
        $this->errors = [];
        $meta = (array) ($projection['_meta'] ?? []);
        if (($meta['redacted'] ?? false) === true || ($meta['locked'] ?? false) === true) {
            $this->errors[] = 'projection is locked or redacted';

            return null;
        }

        $traitVector = $projection['trait_vector'] ?? null;
        if (! is_array($traitVector)) {
            $this->errors[] = 'projection.trait_vector missing';

            return null;
        }

        $domainPercentiles = [];
        foreach ($traitVector as $trait) {
            if (! is_array($trait)) {
                continue;
            }
            $key = strtoupper(trim((string) ($trait['key'] ?? '')));
            if ($key === '' || ! in_array($key, self::DOMAIN_ORDER, true)) {
                continue;
            }
            if (! array_key_exists('percentile', $trait)) {
                $this->errors[] = "projection.trait_vector.{$key}.percentile missing";

                return null;
            }
            $domainPercentiles[$key] = $trait['percentile'];
        }

        return $this->buildRouteInput(
            $domainPercentiles,
            $this->facetSignalsFromProjection((array) ($projection['facet_vector'] ?? [])),
            (array) ($projection['quality'] ?? []),
            (array) ($projection['norms'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param  array<string,mixed>  $domainPercentiles
     * @param  list<array<string,mixed>>  $facetSignals
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $norms
     */
    private function buildRouteInput(array $domainPercentiles, array $facetSignals, array $quality, array $norms): ?BigFiveV2RouteInput
    {
        try {
            $domainRouteBands = $this->bandMapper->mapDomainPercentiles($domainPercentiles);
            $combinationKey = $this->bandMapper->combinationKey($domainRouteBands);
            $displayBandLabels = $this->bandMapper->displayBandLabels($domainRouteBands);
        } catch (InvalidArgumentException $exception) {
            $this->errors[] = $exception->getMessage();

            return null;
        }

        $qualityStatus = $this->qualityStatus($quality);
        $normStatus = $this->normStatus($norms);
        $suppressionHints = [];

        if ($qualityStatus !== 'valid') {
            $suppressionHints[] = 'quality_degraded';
        }

        if ($normStatus === 'missing') {
            $suppressionHints[] = 'norm_missing';
        }

        return new BigFiveV2RouteInput(
            domainRouteBands: $domainRouteBands,
            combinationKey: $combinationKey,
            displayBandLabels: $displayBandLabels,
            qualityStatus: $qualityStatus,
            normStatus: $normStatus,
            facetRouteSignals: $facetSignals,
            suppressionHints: array_values(array_unique($suppressionHints)),
        );
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function qualityStatus(array $quality): string
    {
        $level = strtoupper(trim((string) ($quality['level'] ?? '')));
        if ($level === '' && isset($quality['status'])) {
            $status = strtolower(trim((string) $quality['status']));

            return in_array($status, ['valid', 'degraded'], true) ? $status : 'degraded';
        }

        return in_array($level, ['A', 'B'], true) ? 'valid' : 'degraded';
    }

    /**
     * @param  array<string,mixed>  $norms
     */
    private function normStatus(array $norms): string
    {
        $status = strtoupper(trim((string) ($norms['status'] ?? '')));

        return match ($status) {
            'CALIBRATED' => 'available',
            'PROVISIONAL' => 'provisional',
            default => 'missing',
        };
    }

    /**
     * @param  array<string,mixed>  $facetPercentiles
     * @return list<array<string,mixed>>
     */
    private function facetSignalsFromPercentileMap(array $facetPercentiles): array
    {
        $signals = [];
        foreach ($facetPercentiles as $facet => $percentile) {
            $facetKey = strtoupper(trim((string) $facet));
            if ($facetKey === '') {
                continue;
            }
            $signals[] = [
                'key' => $facetKey,
                'percentile' => is_numeric($percentile) ? (int) $percentile : null,
            ];
        }

        return $signals;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function facetSignalsFromProjection(array $facetVector): array
    {
        $signals = [];
        foreach ($facetVector as $facet) {
            if (! is_array($facet)) {
                continue;
            }
            $key = strtoupper(trim((string) ($facet['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $signals[] = [
                'key' => $key,
                'percentile' => array_key_exists('percentile', $facet) && is_numeric($facet['percentile']) ? (int) $facet['percentile'] : null,
                'bucket' => isset($facet['bucket']) ? (string) $facet['bucket'] : null,
            ];
        }

        return $signals;
    }
}

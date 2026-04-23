<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Bridge;

use App\Models\Attempt;
use App\Models\Result;

final class LiveReportContextAdapter
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @var array<string,list<string>>
     */
    private const FACET_ORDER_BY_DOMAIN = [
        'O' => ['O1', 'O2', 'O3', 'O4', 'O5', 'O6'],
        'C' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'],
        'E' => ['E1', 'E2', 'E3', 'E4', 'E5', 'E6'],
        'A' => ['A1', 'A2', 'A3', 'A4', 'A5', 'A6'],
        'N' => ['N1', 'N2', 'N3', 'N4', 'N5', 'N6'],
    ];

    /**
     * @return array<string,mixed>|null
     */
    public function adapt(Attempt $attempt, Result $result): ?array
    {
        $scoreResult = $this->extractScoreResult($result);
        $domainsPercentile = $this->domainsPercentile($result, $scoreResult);
        if (! $this->hasAllDomains($domainsPercentile)) {
            return null;
        }

        return [
            'locale' => $this->locale($attempt),
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => $this->formCode($attempt),
            'score_vector' => [
                'domains' => $this->domains($domainsPercentile),
                'facets' => $this->facets($scoreResult),
            ],
            'quality' => [
                'level' => (string) data_get($scoreResult, 'quality.level', 'D'),
                'norms_status' => (string) data_get($scoreResult, 'norms.status', 'MISSING'),
            ],
            'meta' => [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'bridge_source' => 'live_report',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $resultJson = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $resultJson['normed_json'] ?? null,
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
            $resultJson['score_result'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,int>
     */
    private function domainsPercentile(Result $result, array $scoreResult): array
    {
        $fromScore = is_array(data_get($scoreResult, 'scores_0_100.domains_percentile'))
            ? data_get($scoreResult, 'scores_0_100.domains_percentile')
            : [];
        $fromResult = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        $raw = $fromScore !== [] ? $fromScore : $fromResult;

        $out = [];
        foreach (self::DOMAIN_ORDER as $traitCode) {
            if (array_key_exists($traitCode, $raw)) {
                $out[$traitCode] = $this->clampPercentile($raw[$traitCode]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string,int>  $domainsPercentile
     */
    private function hasAllDomains(array $domainsPercentile): bool
    {
        foreach (self::DOMAIN_ORDER as $traitCode) {
            if (! array_key_exists($traitCode, $domainsPercentile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,int>  $domainsPercentile
     * @return array<string,array{percentile:int,band:string,gradient_id:string}>
     */
    private function domains(array $domainsPercentile): array
    {
        $out = [];
        foreach (self::DOMAIN_ORDER as $traitCode) {
            $percentile = $domainsPercentile[$traitCode];
            $out[$traitCode] = [
                'percentile' => $percentile,
                'band' => $this->bandFor($percentile),
                'gradient_id' => strtolower($traitCode).'_'.$this->gradientFor($percentile),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,array{percentile:int,domain:string}>
     */
    private function facets(array $scoreResult): array
    {
        $raw = is_array(data_get($scoreResult, 'scores_0_100.facets_percentile'))
            ? data_get($scoreResult, 'scores_0_100.facets_percentile')
            : [];

        $out = [];
        foreach (self::FACET_ORDER_BY_DOMAIN as $domain => $facetCodes) {
            foreach ($facetCodes as $facetCode) {
                if (! array_key_exists($facetCode, $raw)) {
                    continue;
                }
                $out[$facetCode] = [
                    'percentile' => $this->clampPercentile($raw[$facetCode]),
                    'domain' => $domain,
                ];
            }
        }

        return $out;
    }

    private function locale(Attempt $attempt): string
    {
        $locale = trim((string) ($attempt->locale ?? ''));

        return $locale !== '' ? $locale : 'zh-CN';
    }

    private function formCode(Attempt $attempt): string
    {
        $fromSummary = (string) data_get($attempt->answers_summary_json, 'meta.form_code', '');
        if ($fromSummary !== '') {
            return $fromSummary;
        }

        return ((int) ($attempt->question_count ?? 0)) === 90 ? 'big5_90' : 'big5_120';
    }

    private function clampPercentile(mixed $value): int
    {
        return max(0, min(100, (int) round((float) $value)));
    }

    private function bandFor(int $percentile): string
    {
        return match (true) {
            $percentile <= 25 => 'low',
            $percentile <= 39 => 'low_mid',
            $percentile <= 59 => 'mid',
            $percentile <= 79 => 'high_mid',
            default => 'high',
        };
    }

    private function gradientFor(int $percentile): string
    {
        return match (true) {
            $percentile <= 19 => 'g1',
            $percentile <= 39 => 'g2',
            $percentile <= 59 => 'g3',
            $percentile <= 79 => 'g4',
            default => 'g5',
        };
    }
}

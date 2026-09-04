<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Evaluation;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;
use Throwable;

final readonly class Platform12MonthlyFamilyEvaluator
{
    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function evaluate(array $evidence): array
    {
        try {
            $evaluatedAt = $this->evaluatedAt($evidence);
            [$authority, $publicRefs, $parityKeys] = $this->authority($evidence['authority_inventory'] ?? null);
            $runtime = $this->runtime($evidence['runtime_observation'] ?? null, $publicRefs);
            $maturity = $this->maturity($evidence['family_maturity'] ?? null);
            $parity = $this->parity($parityKeys);
            $funnel = $this->funnel($evidence['public_funnel'] ?? null);
            $state = 'READY';
        } catch (Throwable) {
            $evaluatedAt = '1970-01-01T00:00:00Z';
            $authority = $this->unavailableAuthority();
            $runtime = $this->unavailableRuntime();
            $maturity = [];
            $parity = ['zh_count' => null, 'en_count' => null, 'paired_count' => null, 'denominator' => null, 'state' => 'UNAVAILABLE'];
            $funnel = ['availability' => 'UNAVAILABLE', 'landing_count' => null, 'start_count' => null, 'result_count' => null, 'aggregation_level' => 'PUBLIC_TOTALS_ONLY'];
            $state = 'MEASUREMENT_HOLD';
        }

        $artifact = [
            'artifact_version' => 'seo.platform12_monthly_family_maturity.v1',
            'mission_id' => 'seo.platform12.monthly_family_maturity_parity_public_url_set',
            'evaluated_at' => $evaluatedAt,
            'state' => $state,
            'family_maturity' => $maturity,
            'parity' => $parity,
            'authority' => $authority,
            'runtime_observation' => $runtime,
            'public_funnel' => $funnel,
            'authority_runtime_separated' => true,
            'redirect_aliases_in_public_set' => false,
            'artifact_only' => true,
            'read_only' => true,
            'execution_allowed' => false,
        ];
        $artifact['artifact_hash'] = $this->hasher->hash($artifact);

        return $artifact;
    }

    /** @return array{0:array<string,mixed>,1:list<string>,2:array<string,list<string>>} */
    private function authority(mixed $source): array
    {
        if (! is_array($source)
            || ! is_string($source['authority_revision'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $source['authority_revision']) !== 1
            || ! is_array($source['urls'] ?? null)
            || ! array_is_list($source['urls'])
            || count($source['urls']) > 100000) {
            throw new InvalidArgumentException('AUTHORITY_INVENTORY_INVALID');
        }

        $publicRefs = [];
        $redirectCount = 0;
        $canonicalValid = 0;
        $hreflangValid = 0;
        $indexable = 0;
        $parityKeys = [];
        foreach ($source['urls'] as $row) {
            if (! is_array($row)
                || ! is_string($row['url_ref'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['url_ref']) !== 1
                || ! is_string($row['parity_key'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['parity_key']) !== 1
                || ! is_string($row['family'] ?? null)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $row['family']) !== 1
                || ! in_array($row['locale'] ?? null, ['zh-CN', 'en'], true)
                || ! in_array($row['identity_state'] ?? null, ['CANONICAL', 'REDIRECT_ONLY', 'PRIVATE'], true)
                || ! is_bool($row['canonical_ok'] ?? null)
                || ! is_bool($row['hreflang_ok'] ?? null)
                || ! is_bool($row['indexable'] ?? null)) {
                throw new InvalidArgumentException('AUTHORITY_URL_INVALID');
            }
            if ($row['identity_state'] === 'PRIVATE') {
                throw new InvalidArgumentException('PRIVATE_URL_FORBIDDEN');
            }
            if ($row['identity_state'] === 'REDIRECT_ONLY') {
                $redirectCount++;

                continue;
            }
            if (isset($publicRefs[$row['url_ref']])) {
                throw new InvalidArgumentException('PUBLIC_URL_DUPLICATE');
            }
            $publicRefs[$row['url_ref']] = true;
            $parityKeys[$row['parity_key']][] = $row['locale'];
            $canonicalValid += (int) $row['canonical_ok'];
            $hreflangValid += (int) $row['hreflang_ok'];
            $indexable += (int) $row['indexable'];
        }

        $refs = array_keys($publicRefs);
        sort($refs);
        $denominator = count($refs);

        return [[
            'authority_revision' => $source['authority_revision'],
            'public_url_refs' => $refs,
            'public_url_count' => $denominator,
            'public_url_denominator' => $denominator,
            'public_url_inventory_hash' => $this->hasher->hash($refs),
            'redirect_alias_count' => $redirectCount,
            'private_url_count' => 0,
            'canonical_valid_count' => $canonicalValid,
            'hreflang_valid_count' => $hreflangValid,
            'indexable_count' => $indexable,
            'denominator_method' => 'SORTED_CANONICAL_AUTHORITY_URL_REFS',
        ], $refs, $parityKeys];
    }

    /** @param list<string> $publicRefs @return array<string,mixed> */
    private function runtime(mixed $source, array $publicRefs): array
    {
        if (! is_array($source)
            || ! is_string($source['source_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $source['source_hash']) !== 1
            || ! is_array($source['observed_public_refs'] ?? null)
            || ! array_is_list($source['observed_public_refs'])
            || count($source['observed_public_refs']) > 100000) {
            throw new InvalidArgumentException('RUNTIME_OBSERVATION_INVALID');
        }
        $observed = array_values(array_unique($source['observed_public_refs']));
        foreach ($observed as $ref) {
            if (! is_string($ref) || preg_match('/^[a-f0-9]{64}$/D', $ref) !== 1) {
                throw new InvalidArgumentException('RUNTIME_URL_REF_INVALID');
            }
        }

        return [
            'source_hash' => $source['source_hash'],
            'observed_public_count' => count($observed),
            'missing_from_runtime_count' => count(array_diff($publicRefs, $observed)),
            'unexpected_runtime_count' => count(array_diff($observed, $publicRefs)),
            'observation_only' => true,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function maturity(mixed $source): array
    {
        if (! is_array($source) || ! array_is_list($source) || count($source) > 100) {
            throw new InvalidArgumentException('FAMILY_MATURITY_INVALID');
        }

        return array_map(static function (mixed $row): array {
            if (! is_array($row)
                || ! is_string($row['family'] ?? null)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $row['family']) !== 1
                || ! in_array($row['locale'] ?? null, ['zh-CN', 'en'], true)
                || ! is_int($row['maturity_bp'] ?? null)
                || $row['maturity_bp'] < 0
                || $row['maturity_bp'] > 10000) {
                throw new InvalidArgumentException('FAMILY_MATURITY_ROW_INVALID');
            }

            return array_intersect_key($row, array_flip(['family', 'locale', 'maturity_bp']));
        }, $source);
    }

    /** @param array<string,list<string>> $parityKeys @return array<string,int|string> */
    private function parity(array $parityKeys): array
    {
        $zhCount = 0;
        $enCount = 0;
        $paired = 0;
        foreach ($parityKeys as $locales) {
            $unique = array_values(array_unique($locales));
            $zhCount += (int) in_array('zh-CN', $unique, true);
            $enCount += (int) in_array('en', $unique, true);
            $paired += (int) (count($unique) === 2);
        }

        return [
            'zh_count' => $zhCount,
            'en_count' => $enCount,
            'paired_count' => $paired,
            'denominator' => count($parityKeys),
            'state' => $paired === count($parityKeys) ? 'PARITY_READY' : 'PARITY_GAP',
        ];
    }

    /** @return array<string,mixed> */
    private function funnel(mixed $source): array
    {
        if (! is_array($source) || ($source['availability'] ?? null) !== 'AVAILABLE') {
            throw new InvalidArgumentException('PUBLIC_FUNNEL_INVALID');
        }
        foreach (['landing_count', 'start_count', 'result_count'] as $field) {
            if (! is_int($source[$field] ?? null) || $source[$field] < 0 || $source[$field] > 100000000) {
                throw new InvalidArgumentException('PUBLIC_FUNNEL_INVALID');
            }
        }
        if ($source['landing_count'] < $source['start_count'] || $source['start_count'] < $source['result_count']) {
            throw new InvalidArgumentException('PUBLIC_FUNNEL_INVALID');
        }

        return [...array_intersect_key($source, array_flip(['availability', 'landing_count', 'start_count', 'result_count'])), 'aggregation_level' => 'PUBLIC_TOTALS_ONLY'];
    }

    /** @return array<string,mixed> */
    private function unavailableAuthority(): array
    {
        return ['authority_revision' => null, 'public_url_refs' => [], 'public_url_count' => null, 'public_url_denominator' => null, 'public_url_inventory_hash' => null, 'redirect_alias_count' => null, 'private_url_count' => 0, 'canonical_valid_count' => null, 'hreflang_valid_count' => null, 'indexable_count' => null, 'denominator_method' => 'SORTED_CANONICAL_AUTHORITY_URL_REFS'];
    }

    /** @return array<string,mixed> */
    private function unavailableRuntime(): array
    {
        return ['source_hash' => null, 'observed_public_count' => null, 'missing_from_runtime_count' => null, 'unexpected_runtime_count' => null, 'observation_only' => true];
    }

    /** @param array<string,mixed> $evidence */
    private function evaluatedAt(array $evidence): string
    {
        $value = $evidence['evaluated_at'] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
            throw new InvalidArgumentException('EVALUATION_TIME_INVALID');
        }

        return $value;
    }
}

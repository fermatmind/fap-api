<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\UrlTruthInventoryRecord;

final class UrlTruthReconciliationSnapshot
{
    public const SCHEMA_VERSION = 'seo-platform-url-truth-reconciliation.v1';

    public function __construct(
        private readonly ?EffectivePublicUrlEvaluator $evaluator = null,
    ) {}

    /**
     * @param  list<UrlTruthInventoryRecord>  $authorityRecords
     * @param  list<array<string,mixed>>|null  $urlTruthRows
     * @param  list<array<string,mixed>>|null  $entityRows
     * @param  array<string,list<string>|null>  $consumerUrls
     * @param  array<string,mixed>  $liveHttp
     * @return array<string,mixed>
     */
    public function build(
        array $authorityRecords,
        ?array $urlTruthRows,
        ?array $entityRows,
        array $consumerUrls,
        array $liveHttp = ['state' => 'measurement_hold'],
        array $sourceState = [],
    ): array {
        $authorityAvailable = ($sourceState['authority'] ?? 'available') === 'available';
        $evaluator = $this->evaluator ?? new EffectivePublicUrlEvaluator;
        $authority = [];
        $familyLocale = [];
        $classificationCounts = ['classified' => 0, 'unclassified' => 0, 'ambiguous' => 0, 'private_excluded' => 0];
        $privateCount = 0;
        $traceableRevisionCount = 0;

        foreach ($authorityRecords as $record) {
            $evaluation = $evaluator->evaluate($record);
            $classificationCounts[$evaluation['classification_status']] = ($classificationCounts[$evaluation['classification_status']] ?? 0) + 1;
            if (is_string($evaluation['authority_revision']) && $evaluation['authority_revision'] !== '') {
                $traceableRevisionCount++;
            }
            if ($record->isPrivateFlow || $evaluation['classification_status'] === 'private_excluded') {
                $privateCount++;
            }
            if (! $evaluation['effective_public']) {
                continue;
            }

            $key = $this->urlKey($record->canonicalUrl, $record->locale);
            $authority[$key] = [
                'hash' => $this->urlHash($record->canonicalUrl),
                'locale' => $record->locale,
                'family' => $evaluation['family_id'],
                'page_entity_type' => $record->pageEntityType,
                'entity_id_or_slug' => $record->entityIdOrSlug,
                'source_authority' => $record->sourceAuthority,
                'authority_revision' => $evaluation['authority_revision'],
            ];
            $distributionKey = $evaluation['family_id'].'|'.$record->locale;
            $familyLocale[$distributionKey] = ($familyLocale[$distributionKey] ?? 0) + 1;
        }

        $truth = [];
        foreach ($urlTruthRows ?? [] as $row) {
            $canonical = trim((string) ($row['canonical_url'] ?? ''));
            $locale = trim((string) ($row['locale'] ?? ''));
            if ($canonical !== '' && $locale !== '') {
                $truth[$this->urlKey($canonical, $locale)][] = $row;
            }
        }

        $bindings = [];
        foreach ($entityRows ?? [] as $row) {
            if (! in_array(strtolower((string) ($row['authority_status'] ?? '')), ['active', 'published', 'published_approved'], true)) {
                continue;
            }
            $key = implode('|', [
                strtolower(trim((string) ($row['page_entity_type'] ?? ''))),
                trim((string) ($row['entity_id_or_slug'] ?? '')),
                trim((string) ($row['locale'] ?? '')),
            ]);
            $bindings[$key][] = $row;
        }

        $missing = 0;
        $valid = 0;
        $localeBindings = [];
        foreach ($authority as $key => $record) {
            $entityIdentity = trim((string) ($record['entity_id_or_slug'] ?? ''));
            $entityKey = $record['page_entity_type'].'|'.($entityIdentity === '' ? $record['hash'] : $entityIdentity);
            $localeBindings[$entityKey][$record['locale']] = true;
            if ($urlTruthRows === null) {
                continue;
            }
            $rows = $truth[$key] ?? [];
            if ($rows === []) {
                $missing++;

                continue;
            }
            $bindingKey = implode('|', [
                strtolower((string) $record['page_entity_type']),
                (string) ($record['entity_id_or_slug'] ?? ''),
                (string) $record['locale'],
            ]);
            $currentBindings = $bindings[$bindingKey] ?? [];
            if (count($rows) === 1
                && count($currentBindings) === 1
                && $this->truthMatchesAuthority($rows[0], $record)
                && hash_equals((string) $record['hash'], (string) ($currentBindings[0]['canonical_url_hash'] ?? ''))) {
                $valid++;
            }
        }
        $duplicateUrls = $urlTruthRows === null ? null : count(array_filter($truth, static fn (array $rows): bool => count($rows) > 1));
        $duplicateBindings = $entityRows === null ? null : count(array_filter($bindings, static fn (array $rows): bool => count($rows) > 1));
        $retired = $urlTruthRows === null ? null : count(array_diff_key($truth, $authority));
        $counterpartMissing = count(array_filter(
            $localeBindings,
            static fn (array $locales): bool => ! isset($locales['en'], $locales['zh-CN']),
        ));

        $consumerDiffs = [];
        $authorityHashes = array_fill_keys(array_column($authority, 'hash'), true);
        foreach ($consumerUrls as $name => $urls) {
            if ($urls === null || ! $authorityAvailable) {
                $consumerDiffs[$name] = ['state' => 'measurement_hold', 'missing' => null, 'extra' => null];

                continue;
            }
            $hashes = [];
            foreach ($urls as $url) {
                $normalized = rtrim(trim($url), '/');
                $hashes[$this->urlHash($normalized)] = true;
            }
            $consumerDiffs[$name] = [
                'state' => 'available',
                'missing' => count(array_diff_key($authorityHashes, $hashes)),
                'extra' => count(array_diff_key($hashes, $authorityHashes)),
            ];
        }

        ksort($familyLocale);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'policy_version' => PageFamilyPolicyRegistry::VERSION,
            'policy_hash' => (new PageFamilyPolicyRegistry)->policyHash(),
            'generated_at_utc' => now('UTC')->toIso8601String(),
            'source_state' => [
                'authority' => (string) ($sourceState['authority'] ?? 'available'),
                'url_truth' => $urlTruthRows === null ? 'measurement_hold' : 'available',
                'entity_bindings' => $entityRows === null ? 'measurement_hold' : 'available',
                'live_http' => (string) ($liveHttp['state'] ?? 'measurement_hold'),
            ],
            'counts' => [
                'authority_total' => $authorityAvailable ? count($authorityRecords) : null,
                'effective_public' => $authorityAvailable ? count($authority) : null,
                'authority_revision_traceable' => $authorityAvailable ? $traceableRevisionCount : null,
                'authority_revision_untraceable' => $authorityAvailable ? count($authorityRecords) - $traceableRevisionCount : null,
                'url_truth_total' => $urlTruthRows === null ? null : array_sum(array_map('count', $truth)),
                'url_truth_valid' => $urlTruthRows === null || ! $authorityAvailable ? null : $valid,
                'authority_missing_url_truth' => $urlTruthRows === null || ! $authorityAvailable ? null : $missing,
                'url_truth_duplicate' => $duplicateUrls,
                'current_binding_duplicate' => $duplicateBindings,
                'retired_or_authority_missing' => $authorityAvailable ? $retired : null,
                'locale_counterpart_missing' => $authorityAvailable ? $counterpartMissing : null,
                'private_negative_set' => count((new PageFamilyPolicyRegistry)->negativeSetProbes()),
                'private_authority_excluded' => $authorityAvailable ? $privateCount : null,
                'unclassified' => $authorityAvailable ? $classificationCounts['unclassified'] : null,
                'ambiguous' => $authorityAvailable ? $classificationCounts['ambiguous'] : null,
            ],
            'classification_counts' => $classificationCounts,
            'family_locale_distribution' => $familyLocale,
            'consumer_differences' => $consumerDiffs,
            'live_http' => $liveHttp,
            'boundaries' => [
                'backend_cms_authority_only' => true,
                'consumers_create_authority' => false,
                'database_write' => false,
                'cms_write' => false,
                'search_submission_allowed' => false,
                'read_only_gsc' => true,
                'raw_url_emitted' => false,
                'response_body_emitted' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $authority */
    private function truthMatchesAuthority(array $row, array $authority): bool
    {
        return strtolower((string) ($row['indexability_state'] ?? '')) === 'indexable'
            && ! (bool) ($row['is_private_flow'] ?? false)
            && (string) ($row['page_entity_type'] ?? '') === $authority['page_entity_type']
            && (string) ($row['entity_id_or_slug'] ?? '') === (string) ($authority['entity_id_or_slug'] ?? '')
            && (string) ($row['source_authority'] ?? '') === $authority['source_authority'];
    }

    private function urlKey(string $url, string $locale): string
    {
        return $locale.'|'.$this->urlHash($url);
    }

    private function urlHash(string $url): string
    {
        $normalized = rtrim(trim($url), '/');

        return hash('sha256', $normalized === '' ? '/' : $normalized);
    }
}

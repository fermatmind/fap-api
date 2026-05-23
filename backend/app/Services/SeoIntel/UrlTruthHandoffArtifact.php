<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class UrlTruthHandoffArtifact
{
    public const SCHEMA_VERSION = 'seo-intel-url-truth-handoff.v1';

    public const COLLECTOR = 'url_truth_inventory';

    public const PAGE_ENTITY_TYPE = 'research_report';

    public const SOURCE_AUTHORITY = 'backend_cms';

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @param  array<string, mixed>  $sourceMetadata
     * @return array<string, mixed>
     */
    public function fromRecords(array $records, array $sourceMetadata = [], ?int $limit = null): array
    {
        $boundedRecords = $limit === null ? $records : array_slice($records, 0, max(0, $limit));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'collector' => self::COLLECTOR,
            'mode' => 'two_stage_research_report_url_truth_handoff',
            'generated_at' => now()->toIso8601String(),
            'dry_run_required_on_source' => true,
            'source_environment_role' => 'candidate_export_only',
            'runner_environment_role' => 'validate_then_bounded_write_only',
            'constraints' => [
                'allowed_page_entity_type' => self::PAGE_ENTITY_TYPE,
                'allowed_source_authority' => self::SOURCE_AUTHORITY,
                'allowed_route_regex' => '^/(en|zh)/research/[a-z0-9][a-z0-9-]*$',
                'forbidden_route_fragments' => ['/articles', '/reports', 'turnover-rate-report'],
                'forbidden_states' => ['private', 'draft', 'noindex', 'claim_unsafe'],
                'target_tables' => ['seo_urls', 'seo_url_entities'],
                'no_external_api' => true,
                'no_search_submission' => true,
                'no_crawler_log_read' => true,
            ],
            'target_tables' => ['seo_urls', 'seo_url_entities'],
            'candidate_count' => count($boundedRecords),
            'source_metadata' => $this->safeMetadata($sourceMetadata),
            'candidates' => array_map(fn (UrlTruthInventoryRecord $record): array => $this->serializeRecord($record), array_values($boundedRecords)),
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array{status:string, issues:list<string>, records:list<UrlTruthInventoryRecord>, metadata:array<string, mixed>}
     */
    public function validate(array $artifact, ?int $limit = null): array
    {
        $issues = [];
        $records = [];

        if (($artifact['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $issues[] = 'invalid_schema_version';
        }

        if (($artifact['collector'] ?? null) !== self::COLLECTOR) {
            $issues[] = 'invalid_collector';
        }

        if (($artifact['target_tables'] ?? null) !== ['seo_urls', 'seo_url_entities']) {
            $issues[] = 'invalid_target_tables';
        }

        foreach (['no_external_api', 'no_search_submission', 'no_crawler_log_read'] as $flag) {
            if ((bool) data_get($artifact, 'constraints.'.$flag, false) !== true) {
                $issues[] = 'missing_safety_flag:'.$flag;
            }
        }

        $candidates = $artifact['candidates'] ?? null;
        if (! is_array($candidates)) {
            $issues[] = 'missing_candidates';
            $candidates = [];
        }

        $boundedCandidates = $limit === null ? $candidates : array_slice($candidates, 0, max(0, $limit));

        foreach (array_values($boundedCandidates) as $index => $candidate) {
            if (! is_array($candidate)) {
                $issues[] = 'invalid_candidate:'.$index;

                continue;
            }

            $recordIssues = $this->validateCandidate($candidate, $index);
            if ($recordIssues !== []) {
                array_push($issues, ...$recordIssues);

                continue;
            }

            $records[] = $this->hydrateRecord($candidate);
        }

        return [
            'status' => $issues === [] ? 'success' : 'blocked',
            'issues' => array_values(array_unique($issues)),
            'records' => $records,
            'metadata' => [
                'planned_url_count' => count($records),
                'planned_entity_count' => count($records),
                'target_tables' => ['seo_urls', 'seo_url_entities'],
                'page_entity_type' => self::PAGE_ENTITY_TYPE,
                'source_authority' => self::SOURCE_AUTHORITY,
                'limit' => $limit,
            ],
        ];
    }

    public function writeJson(string $path, array $artifact): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    public function sha256(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRecord(UrlTruthInventoryRecord $record): array
    {
        $payload = [
            'canonical_url' => $record->canonicalUrl,
            'canonical_url_hash' => $record->canonicalUrlHash(),
            'locale' => $record->locale,
            'page_entity_type' => $record->pageEntityType,
            'entity_id_or_slug' => $record->entityIdOrSlug,
            'source_authority' => $record->sourceAuthority,
            'indexability_state' => $record->indexabilityState,
            'lastmod_at' => $this->formatCarbon($record->lastmodAt),
            'lastmod_source' => $record->lastmodSource,
            'cluster' => $record->cluster,
            'entity_source' => $record->entitySource,
            'authority_status' => $record->authorityStatus,
            'source_updated_at' => $this->formatCarbon($record->sourceUpdatedAt),
            'is_private_flow' => $record->isPrivateFlow,
            'metadata' => $this->safeMetadata($record->metadata),
            'attributes' => $this->safeMetadata($record->attributes),
        ];

        $payload['row_fingerprint'] = $this->rowFingerprint($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function validateCandidate(array $candidate, int $index): array
    {
        $issues = [];
        $url = (string) ($candidate['canonical_url'] ?? '');
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $pathLocale = Str::before(Str::after($path, '/'), '/');
        $pathSlug = Str::after($path, '/research/');
        $locale = (string) ($candidate['locale'] ?? '');

        if (($candidate['page_entity_type'] ?? null) !== self::PAGE_ENTITY_TYPE) {
            $issues[] = 'candidate_not_research_report:'.$index;
        }

        if (($candidate['source_authority'] ?? null) !== self::SOURCE_AUTHORITY) {
            $issues[] = 'candidate_source_authority_not_backend_cms:'.$index;
        }

        if (($candidate['entity_source'] ?? null) !== 'research_reports') {
            $issues[] = 'candidate_entity_source_not_research_reports:'.$index;
        }

        if (($candidate['authority_status'] ?? null) !== 'published_approved') {
            $issues[] = 'candidate_not_published_approved:'.$index;
        }

        if (($candidate['indexability_state'] ?? null) !== 'indexable') {
            $issues[] = 'candidate_not_indexable:'.$index;
        }

        if ((bool) ($candidate['is_private_flow'] ?? true)) {
            $issues[] = 'candidate_private_flow:'.$index;
        }

        if (! (bool) data_get($candidate, 'attributes.claim_safe', false)) {
            $issues[] = 'candidate_claim_unsafe:'.$index;
        }

        if (! preg_match('#^/(en|zh)/research/[a-z0-9][a-z0-9-]*$#', $path)) {
            $issues[] = 'candidate_route_not_research:'.$index;
        }

        if ($scheme !== 'https' || ! in_array($host, $this->trustedTenantHosts(), true)) {
            $issues[] = 'candidate_untrusted_tenant_host:'.$index;
        }

        if ($pathSlug === '' || ($candidate['entity_id_or_slug'] ?? null) !== $pathSlug) {
            $issues[] = 'candidate_research_path_slug_mismatch:'.$index;
        }

        $canonicalPathHash = data_get($candidate, 'metadata.canonical_path_hash');
        if ($canonicalPathHash !== null && $canonicalPathHash !== hash('sha256', $path)) {
            $issues[] = 'candidate_canonical_path_hash_mismatch:'.$index;
        }

        foreach (['/articles', '/reports', 'turnover-rate-report'] as $fragment) {
            if (str_contains($path, $fragment)) {
                $issues[] = 'candidate_forbidden_route_fragment:'.$fragment.':'.$index;
            }
        }

        if (! in_array($pathLocale, ['en', 'zh'], true)) {
            $issues[] = 'candidate_locale_path_invalid:'.$index;
        }

        if ($pathLocale === 'en' && $locale !== 'en') {
            $issues[] = 'candidate_locale_mismatch:'.$index;
        }

        if ($pathLocale === 'zh' && ! in_array($locale, ['zh', 'zh-CN'], true)) {
            $issues[] = 'candidate_locale_mismatch:'.$index;
        }

        if (($candidate['canonical_url_hash'] ?? null) !== hash('sha256', $url)) {
            $issues[] = 'candidate_hash_mismatch:'.$index;
        }

        if (($candidate['row_fingerprint'] ?? null) !== $this->rowFingerprint($candidate)) {
            $issues[] = 'candidate_row_fingerprint_mismatch:'.$index;
        }

        if ($this->containsForbiddenDetail((array) ($candidate['metadata'] ?? [])) || $this->containsForbiddenDetail((array) ($candidate['attributes'] ?? []))) {
            $issues[] = 'candidate_forbidden_detail_key:'.$index;
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function trustedTenantHosts(): array
    {
        $hosts = ['fermatmind.com', 'www.fermatmind.com'];

        foreach (['seo_intel.public_canonical_host', 'app.frontend_url'] as $configKey) {
            $host = parse_url((string) config($configKey), PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = Str::lower(trim($host));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function hydrateRecord(array $candidate): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: (string) $candidate['canonical_url'],
            locale: (string) $candidate['locale'],
            pageEntityType: (string) $candidate['page_entity_type'],
            entityIdOrSlug: (string) $candidate['entity_id_or_slug'],
            sourceAuthority: (string) $candidate['source_authority'],
            indexabilityState: (string) $candidate['indexability_state'],
            lastmodAt: $this->parseCarbon($candidate['lastmod_at'] ?? null),
            lastmodSource: $this->nullableString($candidate['lastmod_source'] ?? null),
            cluster: $this->nullableString($candidate['cluster'] ?? null),
            entitySource: (string) $candidate['entity_source'],
            authorityStatus: (string) $candidate['authority_status'],
            sourceUpdatedAt: $this->parseCarbon($candidate['source_updated_at'] ?? null),
            isPrivateFlow: false,
            metadata: (array) ($candidate['metadata'] ?? []),
            attributes: (array) ($candidate['attributes'] ?? []),
        );
    }

    private function rowFingerprint(array $candidate): string
    {
        $payload = [
            'canonical_url' => $candidate['canonical_url'] ?? null,
            'locale' => $candidate['locale'] ?? null,
            'page_entity_type' => $candidate['page_entity_type'] ?? null,
            'entity_id_or_slug' => $candidate['entity_id_or_slug'] ?? null,
            'source_authority' => $candidate['source_authority'] ?? null,
            'indexability_state' => $candidate['indexability_state'] ?? null,
            'entity_source' => $candidate['entity_source'] ?? null,
            'authority_status' => $candidate['authority_status'] ?? null,
            'is_private_flow' => (bool) ($candidate['is_private_flow'] ?? true),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeMetadata(array $payload): array
    {
        ksort($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function containsForbiddenDetail(array $payload): bool
    {
        $forbiddenFragments = [
            'email',
            'order_no',
            'attempt_id',
            'payment_id',
            'provider_event_id',
            'cookie',
            'raw_payload',
            'payment_payload',
            'provider_payload',
            'raw_email',
            'raw_order_no',
            'raw_attempt_id',
            'raw_ip',
            'raw_cookie',
            'raw_user_agent',
            'token',
            'api_key',
            'secret',
        ];

        foreach (array_keys($payload) as $key) {
            $normalized = Str::lower((string) $key);
            foreach ($forbiddenFragments as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function formatCarbon(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function parseCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

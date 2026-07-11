<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UrlTruthHandoffArtifact
{
    public const SCHEMA_VERSION = 'seo-intel-url-truth-handoff.v1';

    public const COLLECTOR = 'url_truth_inventory';

    public const PAGE_ENTITY_TYPE = 'research_report';

    public const ARTICLE_PAGE_ENTITY_TYPE = 'article';

    public const CONTENT_PAGE_ENTITY_TYPE = 'content_page';

    public const PERSONALITY_PROFILE_VARIANT_PAGE_ENTITY_TYPE = 'personality_profile_variant';

    public const PERSONALITY_PROFILE_COMPARISON_PAGE_ENTITY_TYPE = 'personality_profile_comparison';

    public const ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE = 'personality_public_content_asset';

    public const SOURCE_AUTHORITY = 'backend_cms';

    private const PRIVATE_ROUTE_FRAGMENTS = [
        '/results',
        '/orders',
        '/share',
        '/pay',
        '/payment',
        '/history',
        '/private',
        '/account',
        'token=',
        'session=',
        'user=',
        'result_id=',
        'report_id=',
        'order_no=',
    ];

    /**
     * @var array<string, array{
     *     mode:string,
     *     route_regex:string,
     *     entity_source:string,
     *     route_fragment:string,
     *     forbidden_route_fragments:list<string>,
     *     type_issue:string,
     *     entity_source_issue:string,
     *     route_issue:string,
     *     entity_identity_issue:string
     * }>
     */
    private const PAGE_TYPE_POLICIES = [
        self::PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_research_report_url_truth_handoff',
            'route_regex' => '^/(en|zh)/research/[a-z0-9][a-z0-9-]*$',
            'entity_source' => 'research_reports',
            'route_fragment' => '/research/',
            'forbidden_route_fragments' => ['/articles', '/reports', 'turnover-rate-report'],
            'type_issue' => 'candidate_not_research_report',
            'entity_source_issue' => 'candidate_entity_source_not_research_reports',
            'route_issue' => 'candidate_route_not_research',
            'entity_identity_issue' => 'candidate_research_path_slug_mismatch',
        ],
        self::ARTICLE_PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_article_url_truth_handoff',
            'route_regex' => '^/(en|zh)/articles/[a-z0-9][a-z0-9-]*$',
            'entity_source' => 'articles',
            'route_fragment' => '/articles/',
            'forbidden_route_fragments' => ['/research', '/reports', 'turnover-rate-report'],
            'type_issue' => 'candidate_not_article',
            'entity_source_issue' => 'candidate_entity_source_not_articles',
            'route_issue' => 'candidate_route_not_article',
            'entity_identity_issue' => 'candidate_article_entity_id_invalid',
        ],
        self::CONTENT_PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_content_page_url_truth_handoff',
            'route_regex' => '^(?:/(?:en|zh)/(?!results(?:/|$)|orders(?:/|$)|share(?:/|$)|pay(?:/|$)|payment(?:/|$)|history(?:/|$)|private(?:/|$)|account(?:/|$)|admin(?:/|$)|ops(?:/|$))[a-z0-9][a-z0-9/_-]*|/(?!en(?:/|$)|zh(?:/|$)|results(?:/|$)|orders(?:/|$)|share(?:/|$)|pay(?:/|$)|payment(?:/|$)|history(?:/|$)|private(?:/|$)|account(?:/|$)|admin(?:/|$)|ops(?:/|$))[a-z0-9][a-z0-9/_-]*)$',
            'entity_source' => 'content_pages',
            'route_fragment' => '/',
            'forbidden_route_fragments' => self::PRIVATE_ROUTE_FRAGMENTS,
            'type_issue' => 'candidate_not_content_page',
            'entity_source_issue' => 'candidate_entity_source_not_content_pages',
            'route_issue' => 'candidate_route_not_content_page',
            'entity_identity_issue' => 'candidate_content_page_entity_id_invalid',
        ],
        self::PERSONALITY_PROFILE_VARIANT_PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_personality_profile_variant_url_truth_handoff',
            'route_regex' => '^/(en|zh)/personality/[a-z]{4}-[at]$',
            'entity_source' => 'personality_profile_variants',
            'route_fragment' => '/personality/',
            'forbidden_route_fragments' => self::PRIVATE_ROUTE_FRAGMENTS,
            'type_issue' => 'candidate_not_personality_profile_variant',
            'entity_source_issue' => 'candidate_entity_source_not_personality_profile_variants',
            'route_issue' => 'candidate_route_not_personality_profile_variant',
            'entity_identity_issue' => 'candidate_personality_profile_variant_entity_id_invalid',
        ],
        self::PERSONALITY_PROFILE_COMPARISON_PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_personality_profile_comparison_url_truth_handoff',
            'route_regex' => '^/(en|zh)/personality/([a-z]{4})-a-vs-\2-t$',
            'entity_source' => 'personality_profiles',
            'route_fragment' => '/personality/',
            'forbidden_route_fragments' => self::PRIVATE_ROUTE_FRAGMENTS,
            'type_issue' => 'candidate_not_personality_profile_comparison',
            'entity_source_issue' => 'candidate_entity_source_not_personality_profiles',
            'route_issue' => 'candidate_route_not_personality_profile_comparison',
            'entity_identity_issue' => 'candidate_personality_profile_comparison_entity_id_invalid',
        ],
        self::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE => [
            'mode' => 'two_stage_enneagram_personality_public_content_asset_url_truth_handoff',
            'route_regex' => '^/(en|zh)/personality/enneagram(?:/centers/(?:gut|heart|head)|/type-[1-9](?:/instincts/(?:self-preservation|social|one-to-one))?|/wings/(?:1w9|1w2|2w1|2w3|3w2|3w4|4w3|4w5|5w4|5w6|6w5|6w7|7w6|7w8|8w7|8w9|9w8|9w1))?$',
            'entity_source' => 'personality_public_content_assets',
            'route_fragment' => '/personality/enneagram',
            'forbidden_route_fragments' => self::PRIVATE_ROUTE_FRAGMENTS,
            'type_issue' => 'candidate_not_enneagram_personality_public_content_asset',
            'entity_source_issue' => 'candidate_entity_source_not_personality_public_content_assets',
            'route_issue' => 'candidate_route_not_enneagram_personality_public_content_asset',
            'entity_identity_issue' => 'candidate_enneagram_personality_public_content_asset_identity_invalid',
        ],
    ];

    /**
     * @param  list<UrlTruthInventoryRecord>  $records
     * @param  array<string, mixed>  $sourceMetadata
     * @return array<string, mixed>
     */
    public function fromRecords(array $records, array $sourceMetadata = [], ?int $limit = null, string $pageEntityType = self::PAGE_ENTITY_TYPE): array
    {
        $policy = $this->policyFor($pageEntityType);
        $boundedRecords = $limit === null ? $records : array_slice($records, 0, max(0, $limit));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'collector' => self::COLLECTOR,
            'mode' => $policy['mode'],
            'generated_at' => now()->toIso8601String(),
            'dry_run_required_on_source' => true,
            'source_environment_role' => 'candidate_export_only',
            'runner_environment_role' => 'validate_then_bounded_write_only',
            'constraints' => [
                'allowed_page_entity_type' => $pageEntityType,
                'allowed_source_authority' => self::SOURCE_AUTHORITY,
                'allowed_route_regex' => $policy['route_regex'],
                'forbidden_route_fragments' => $policy['forbidden_route_fragments'],
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
    public function validate(array $artifact, ?int $limit = null, ?string $expectedPageEntityType = null): array
    {
        $issues = [];
        $records = [];
        $pageEntityType = (string) data_get($artifact, 'constraints.allowed_page_entity_type', self::PAGE_ENTITY_TYPE);

        if ($expectedPageEntityType !== null && $expectedPageEntityType !== $pageEntityType) {
            $issues[] = 'artifact_page_entity_type_mismatch';
        }

        if (! $this->supportsPageEntityType($pageEntityType)) {
            $issues[] = 'unsupported_page_entity_type';
            $pageEntityType = self::PAGE_ENTITY_TYPE;
        }

        $policy = $this->policyFor($pageEntityType);

        if (($artifact['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $issues[] = 'invalid_schema_version';
        }

        if (($artifact['collector'] ?? null) !== self::COLLECTOR) {
            $issues[] = 'invalid_collector';
        }

        if (($artifact['target_tables'] ?? null) !== ['seo_urls', 'seo_url_entities']) {
            $issues[] = 'invalid_target_tables';
        }

        if (data_get($artifact, 'constraints.allowed_page_entity_type') !== $pageEntityType) {
            $issues[] = 'invalid_allowed_page_entity_type';
        }

        if (data_get($artifact, 'constraints.allowed_source_authority') !== self::SOURCE_AUTHORITY) {
            $issues[] = 'invalid_allowed_source_authority';
        }

        if (data_get($artifact, 'constraints.allowed_route_regex') !== $policy['route_regex']) {
            $issues[] = 'invalid_allowed_route_regex';
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

            $recordIssues = $this->validateCandidate($candidate, $index, $pageEntityType, $policy);
            if ($recordIssues !== []) {
                array_push($issues, ...$recordIssues);

                continue;
            }

            $records[] = $this->hydrateRecord($candidate);
        }

        if ($pageEntityType === self::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE) {
            array_push($issues, ...$this->enneagramCandidateSetIssues($artifact, $boundedCandidates));
        }

        return [
            'status' => $issues === [] ? 'success' : 'blocked',
            'issues' => array_values(array_unique($issues)),
            'records' => $records,
            'metadata' => [
                'planned_url_count' => count($records),
                'planned_entity_count' => count($records),
                'target_tables' => ['seo_urls', 'seo_url_entities'],
                'page_entity_type' => $pageEntityType,
                'source_authority' => self::SOURCE_AUTHORITY,
                'limit' => $limit,
            ],
        ];
    }

    public function writeJson(string $path, array $artifact): void
    {
        $issue = $this->artifactPathSafetyIssue($path, forWrite: true);
        if ($issue !== null) {
            throw new InvalidArgumentException($issue);
        }

        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $path): array
    {
        $issue = $this->artifactPathSafetyIssue($path, forWrite: false);
        if ($issue !== null) {
            throw new InvalidArgumentException($issue);
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    public function sha256(string $path): string
    {
        $issue = $this->artifactPathSafetyIssue($path, forWrite: false);
        if ($issue !== null) {
            throw new InvalidArgumentException($issue);
        }

        return hash_file('sha256', $path) ?: '';
    }

    public function artifactPathSafetyIssue(string $path, bool $forWrite): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return 'artifact_path_empty';
        }

        if (str_contains($path, "\0")) {
            return 'artifact_path_invalid';
        }

        if (parse_url($path, PHP_URL_SCHEME) !== null) {
            return 'artifact_path_stream_wrapper_forbidden';
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return 'artifact_path_must_be_absolute';
        }

        if (Str::lower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            return 'artifact_path_must_be_json';
        }

        $basename = basename($path);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return 'artifact_path_invalid';
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            return 'artifact_directory_missing';
        }

        if (is_link($directory)) {
            return 'artifact_directory_symlink_forbidden';
        }

        if ($forWrite) {
            if (file_exists($path) || is_link($path)) {
                return 'artifact_path_already_exists';
            }

            return null;
        }

        if (! is_file($path) || is_link($path)) {
            return 'artifact_path_not_regular_file';
        }

        if (filesize($path) > 1024 * 1024) {
            return 'artifact_file_too_large';
        }

        return null;
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
     * @param  array{
     *     mode:string,
     *     route_regex:string,
     *     entity_source:string,
     *     route_fragment:string,
     *     forbidden_route_fragments:list<string>,
     *     type_issue:string,
     *     entity_source_issue:string,
     *     route_issue:string,
     *     entity_identity_issue:string
     * }  $policy
     * @return list<string>
     */
    private function validateCandidate(array $candidate, int $index, string $pageEntityType, array $policy): array
    {
        $issues = [];
        $url = (string) ($candidate['canonical_url'] ?? '');
        $urlParts = @parse_url($url);
        if (! is_array($urlParts)) {
            return ['candidate_canonical_url_invalid:'.$index];
        }

        $scheme = Str::lower((string) ($urlParts['scheme'] ?? ''));
        $host = Str::lower((string) ($urlParts['host'] ?? ''));
        $path = (string) ($urlParts['path'] ?? '');
        $pathLocale = preg_match('#^/(en|zh)(?:/|$)#', $path, $localeMatches) === 1
            ? (string) $localeMatches[1]
            : null;
        $pathSlug = Str::after($path, $policy['route_fragment']);
        $locale = (string) ($candidate['locale'] ?? '');

        foreach (['query', 'fragment', 'user', 'pass', 'port'] as $forbiddenComponent) {
            if (array_key_exists($forbiddenComponent, $urlParts)) {
                $issues[] = 'candidate_canonical_url_component_forbidden:'.$forbiddenComponent.':'.$index;
            }
        }

        if (($candidate['page_entity_type'] ?? null) !== $pageEntityType) {
            $issues[] = $policy['type_issue'].':'.$index;
        }

        if (($candidate['source_authority'] ?? null) !== self::SOURCE_AUTHORITY) {
            $issues[] = 'candidate_source_authority_not_backend_cms:'.$index;
        }

        if (($candidate['entity_source'] ?? null) !== $policy['entity_source']) {
            $issues[] = $policy['entity_source_issue'].':'.$index;
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

        if (! preg_match('#'.$policy['route_regex'].'#', $path)) {
            $issues[] = $policy['route_issue'].':'.$index;
        }

        if ($scheme !== 'https' || ! in_array($host, $this->trustedTenantHosts(), true)) {
            $issues[] = 'candidate_untrusted_tenant_host:'.$index;
        }

        if ($this->entityIdentityInvalid($candidate, $pageEntityType, $pathSlug)) {
            $issues[] = $policy['entity_identity_issue'].':'.$index;
        }

        $canonicalPathHash = data_get($candidate, 'metadata.canonical_path_hash');
        if ($canonicalPathHash !== null && $canonicalPathHash !== hash('sha256', $path)) {
            $issues[] = 'candidate_canonical_path_hash_mismatch:'.$index;
        }

        $normalizedUrl = Str::lower($url);
        $normalizedPath = Str::lower($path);
        foreach ($policy['forbidden_route_fragments'] as $fragment) {
            if (str_contains($normalizedPath, $fragment) || str_contains($normalizedUrl, $fragment)) {
                $issues[] = 'candidate_forbidden_route_fragment:'.$fragment.':'.$index;
            }
        }

        if ($pathLocale === null && ! ($pageEntityType === self::CONTENT_PAGE_ENTITY_TYPE && $locale === 'en')) {
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
    public function supportedPageEntityTypes(): array
    {
        return array_keys(self::PAGE_TYPE_POLICIES);
    }

    public function supportsPageEntityType(string $pageEntityType): bool
    {
        return array_key_exists($pageEntityType, self::PAGE_TYPE_POLICIES);
    }

    /**
     * @return array{
     *     mode:string,
     *     route_regex:string,
     *     entity_source:string,
     *     route_fragment:string,
     *     forbidden_route_fragments:list<string>,
     *     type_issue:string,
     *     entity_source_issue:string,
     *     route_issue:string,
     *     entity_identity_issue:string
     * }
     */
    private function policyFor(string $pageEntityType): array
    {
        if (! $this->supportsPageEntityType($pageEntityType)) {
            throw new InvalidArgumentException('unsupported_page_entity_type');
        }

        return self::PAGE_TYPE_POLICIES[$pageEntityType];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function entityIdentityInvalid(array $candidate, string $pageEntityType, string $pathSlug): bool
    {
        $entityIdOrSlug = (string) ($candidate['entity_id_or_slug'] ?? '');

        if ($pageEntityType === self::PAGE_ENTITY_TYPE) {
            return $pathSlug === '' || $entityIdOrSlug !== $pathSlug;
        }

        if ($pageEntityType === self::ARTICLE_PAGE_ENTITY_TYPE) {
            return $entityIdOrSlug === ''
                || ! ctype_digit($entityIdOrSlug)
                || $pathSlug === ''
                || data_get($candidate, 'metadata.slug_hash') !== hash('sha256', $pathSlug)
                || data_get($candidate, 'attributes.article_id_hash') !== hash('sha256', $entityIdOrSlug);
        }

        if ($pageEntityType === self::CONTENT_PAGE_ENTITY_TYPE) {
            return trim($entityIdOrSlug) === '';
        }

        if ($pageEntityType === self::PERSONALITY_PROFILE_VARIANT_PAGE_ENTITY_TYPE) {
            return $entityIdOrSlug === ''
                || ! ctype_digit($entityIdOrSlug)
                || preg_match('/^[a-z]{4}-[at]$/i', $pathSlug) !== 1;
        }

        if ($pageEntityType === self::PERSONALITY_PROFILE_COMPARISON_PAGE_ENTITY_TYPE) {
            return $entityIdOrSlug === ''
                || ! ctype_digit($entityIdOrSlug)
                || preg_match('/^([a-z]{4})-a-vs-\1-t$/i', $pathSlug) !== 1;
        }

        if ($pageEntityType === self::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE) {
            $parts = explode(':', $entityIdOrSlug, 3);
            if (count($parts) !== 3) {
                return true;
            }

            [$entityLocale, $entityType, $entityKey] = $parts;
            $expectedEntityTypes = ['hub', 'center', 'core_type', 'wing', 'instinctual_subtype'];

            return ! in_array($entityLocale, ['en', 'zh-CN'], true)
                || $entityLocale !== (string) ($candidate['locale'] ?? '')
                || ! in_array($entityType, $expectedEntityTypes, true)
                || $entityKey === ''
                || data_get($candidate, 'metadata.asset_entity_type') !== $entityType
                || data_get($candidate, 'metadata.entity_key_hash') !== hash('sha256', $entityKey);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<mixed>  $candidates
     * @return list<string>
     */
    private function enneagramCandidateSetIssues(array $artifact, array $candidates): array
    {
        $issues = [];
        $expectedEntityCounts = [
            'center' => 6,
            'core_type' => 18,
            'hub' => 2,
            'instinctual_subtype' => 54,
            'wing' => 36,
        ];

        if (($artifact['candidate_count'] ?? null) !== count($candidates)) {
            $issues[] = 'enneagram_artifact_candidate_count_mismatch';
        }

        if (count($candidates) !== 116) {
            $issues[] = 'enneagram_exact_candidate_count_mismatch';
        }

        $canonicalUrls = [];
        $localeCounts = [];
        $entityCounts = [];

        foreach ($candidates as $index => $candidate) {
            if (! is_array($candidate)) {
                $issues[] = 'enneagram_candidate_invalid:'.$index;

                continue;
            }

            $canonicalUrls[] = (string) ($candidate['canonical_url'] ?? '');
            $locale = (string) ($candidate['locale'] ?? '');
            $entityType = (string) data_get($candidate, 'metadata.asset_entity_type', '');
            $sourceHash = strtolower(trim((string) data_get($candidate, 'metadata.source_hash', '')));

            $localeCounts[$locale] = ($localeCounts[$locale] ?? 0) + 1;
            $entityCounts[$entityType] = ($entityCounts[$entityType] ?? 0) + 1;

            if (preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1) {
                $issues[] = 'enneagram_candidate_source_hash_invalid:'.$index;
            }

            if (data_get($candidate, 'metadata.content_hash') !== $sourceHash) {
                $issues[] = 'enneagram_candidate_content_provenance_mismatch:'.$index;
            }
        }

        if (count(array_unique($canonicalUrls)) !== count($canonicalUrls)) {
            $issues[] = 'enneagram_canonical_url_set_not_unique';
        }

        ksort($localeCounts);
        if ($localeCounts !== ['en' => 58, 'zh-CN' => 58]) {
            $issues[] = 'enneagram_locale_candidate_count_mismatch';
        }

        ksort($entityCounts);
        if ($entityCounts !== $expectedEntityCounts) {
            $issues[] = 'enneagram_entity_candidate_count_mismatch';
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

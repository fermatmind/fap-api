<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;

final class EnneagramPublicAuthorityV224RuntimeReadback
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RUNTIME-READBACK-22E';

    public function __construct(
        private readonly EnneagramPublicAuthorityV224RuntimeManifest $manifest,
        private readonly PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter $revisionWriter,
    ) {}

    /**
     * @param  array<string,mixed>  $releaseReport
     * @return array<string,mixed>
     */
    public function run(
        string $phase,
        string $batch,
        array $releaseReport,
        string $apiBaseUrl,
        string $frontendBaseUrl,
        string $backendDeployedSha,
        string $frontendDeployedSha,
        bool $requireFreshApiCache = false,
        array $sensitiveValues = [],
    ): array {
        if (! in_array($phase, ['pre', 'post'], true)) {
            throw new RuntimeException('Runtime readback phase must be pre or post.');
        }
        $runtimeOrigins = [
            'api_base_origin' => $this->exactHttpsOrigin($apiBaseUrl, 'API base origin'),
            'frontend_base_origin' => $this->exactHttpsOrigin($frontendBaseUrl, 'frontend base origin'),
        ];
        $deployedRevisions = [
            'backend_deployed_sha' => $this->exactGitSha($backendDeployedSha, 'backend deployed SHA'),
            'frontend_deployed_sha' => $this->exactGitSha($frontendDeployedSha, 'frontend deployed SHA'),
        ];
        $apiBaseUrl = $runtimeOrigins['api_base_origin'];
        $frontendBaseUrl = $runtimeOrigins['frontend_base_origin'];
        $batches = $this->manifest->readbackBatches($releaseReport);
        if ($batch === 'all') {
            $targets = array_merge(...array_values($batches));
        } elseif (isset($batches[$batch])) {
            $targets = $batches[$batch];
        } else {
            throw new RuntimeException('Runtime readback batch is unknown.');
        }

        $rows = [];
        foreach ($targets as $target) {
            $rows[] = $this->readTarget(
                $phase,
                $target,
                (string) $releaseReport['package_sha256'],
                $apiBaseUrl,
                $frontendBaseUrl,
                $requireFreshApiCache,
                $sensitiveValues,
            );
            if (($rows[array_key_last($rows)]['ok'] ?? false) !== true) {
                throw new RuntimeException(
                    'Runtime readback mismatch: '.(string) $target['asset_key'].':'
                    .implode(',', $rows[array_key_last($rows)]['issues']).'.',
                );
            }
        }

        $projection = $this->databaseFingerprints($targets);
        $urlSets = $batch === 'all'
            ? $this->urlSets($frontendBaseUrl, array_column($targets, 'path'))
            : null;

        return [
            'schema_version' => 'enneagram_public_authority_v2_runtime_readback.v1',
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_'.strtoupper($phase).'_RUNTIME_READBACK',
            'observed_at' => now()->utc()->toIso8601String(),
            'phase' => $phase,
            'batch' => $batch,
            'runtime_origins' => $runtimeOrigins,
            'deployed_revisions' => $deployedRevisions,
            'target_count' => count($targets),
            'api_read_count' => count($targets),
            'html_read_count' => count($targets),
            'public_projection_fingerprint' => $projection['public_projection_fingerprint'],
            'stable_identity_discoverability_fingerprint' => $projection['stable_identity_discoverability_fingerprint'],
            'url_sets' => $urlSets,
            'rows' => $rows,
            'private_data_exposed_count' => 0,
            'non_empty_media_count' => 0,
            'writes_committed' => false,
            'production_execution' => false,
        ];
    }

    /** @param array<string,mixed> $releaseReport @return array<string,mixed> */
    public function snapshot(array $releaseReport, string $frontendBaseUrl): array
    {
        $targets = array_merge(...array_values($this->manifest->readbackBatches($releaseReport)));

        return [
            ...$this->databaseFingerprints($targets),
            'url_sets' => $this->urlSets($frontendBaseUrl, array_column($targets, 'path')),
        ];
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function readTarget(
        string $phase,
        array $target,
        string $packageSha256,
        string $apiBaseUrl,
        string $frontendBaseUrl,
        bool $requireFreshApiCache,
        array $sensitiveValues,
    ): array {
        $apiUrl = $this->apiUrl($apiBaseUrl, $target);
        $api = Http::acceptJson()->withoutRedirecting()->timeout(20)->get($apiUrl);
        $issues = [];
        if (! $api->successful()) {
            $issues[] = 'api_http_'.(string) $api->status();
        }
        if ($requireFreshApiCache && strtolower((string) $api->header('X-Fermat-Public-Read-Cache')) !== 'fresh') {
            $issues[] = 'api_cache_not_fresh';
        }
        $payload = $api->json();
        $v1 = is_array($payload) && is_array($payload['personality_public_content_asset_v1'] ?? null)
            ? $payload['personality_public_content_asset_v1']
            : [];
        $v2 = is_array($payload) && is_array($payload['personality_public_content_asset_v2'] ?? null)
            ? $payload['personality_public_content_asset_v2']
            : [];
        if (($payload['ok'] ?? false) !== true || $v1 === []) {
            $issues[] = 'api_contract_missing';
        }
        $this->assertNoSensitiveValues($api->body(), $sensitiveValues, 'api', $issues);
        foreach ([
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => (string) $target['entity_type'],
            'code' => (string) $target['code'],
            'locale' => (string) $target['locale'],
            'canonical_path' => (string) $target['path'],
        ] as $field => $expected) {
            if (($v1[$field] ?? null) !== $expected) {
                $issues[] = 'api_'.$field.'_mismatch';
            }
        }
        if (trim((string) ($v1['title'] ?? '')) === '' || trim((string) ($v1['summary'] ?? '')) === '') {
            $issues[] = 'api_metadata_empty';
        }
        if ($this->normalizedMedia($v1['media'] ?? null) !== ['hero' => null, 'inline' => [], 'og' => null]) {
            $issues[] = 'api_media_not_empty';
        }
        $this->assertApiPayloadMatchesCurrentPublicAsset($target, $v1, $issues);
        $this->validateApiDiscoverabilityUrls($target, $v1, $frontendBaseUrl, $issues);
        if ($phase === 'post') {
            if (($v1['source_hash'] ?? null) !== $target['asset_sha256']
                || ($v1['source_package'] ?? null) !== EnneagramPublicAuthorityV205RevisionWorkspaceWriter::SOURCE_PACKAGE
                || data_get($v2, 'editorial_authority.review_state') !== EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED
                || data_get($v2, 'editorial_authority.reviewer') !== null) {
                $issues[] = 'api_exact_candidate_or_review_mismatch';
            }
            $this->assertApiVisibleEvidenceMatchesCurrentPublicAsset($target, $v2, $issues);
            $this->assertPublishedRevision($target, $packageSha256, $issues);
        }

        $htmlResponse = Http::accept('text/html')->withoutRedirecting()->timeout(20)->get(
            rtrim($frontendBaseUrl, '/').(string) $target['path'],
        );
        $html = $htmlResponse->body();
        if (! $htmlResponse->successful()) {
            $issues[] = 'html_http_'.(string) $htmlResponse->status();
        }
        $this->validateHtml(
            $html,
            $v1,
            $v2,
            (string) $target['path'],
            $frontendBaseUrl,
            $phase,
            $sensitiveValues,
            $issues,
        );

        return [
            'asset_key' => (string) $target['asset_key'],
            'path' => (string) $target['path'],
            'asset_sha256' => (string) $target['asset_sha256'],
            'api_http_status' => $api->status(),
            'api_cache_state' => strtolower((string) $api->header('X-Fermat-Public-Read-Cache')),
            'html_http_status' => $htmlResponse->status(),
            'source_package' => $v1['source_package'] ?? null,
            'source_hash' => $v1['source_hash'] ?? null,
            'media_empty' => $this->normalizedMedia($v1['media'] ?? null) === ['hero' => null, 'inline' => [], 'og' => null],
            'reviewer_public' => false,
            'issues' => $issues,
            'ok' => $issues === [],
        ];
    }

    /** @param array<string,mixed> $target @param list<string> $issues */
    private function assertPublishedRevision(array $target, string $packageSha256, array &$issues): void
    {
        $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', (string) $target['entity_type'])
            ->where('entity_key', (string) $target['code'])
            ->where('locale', (string) $target['locale'])
            ->first();
        $revision = $asset instanceof PersonalityPublicContentAsset && $asset->published_revision_id !== null
            ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->published_revision_id)
            : null;
        if (! $asset instanceof PersonalityPublicContentAsset
            || ! $revision instanceof PersonalityPublicContentAssetRevision
            || (string) $revision->authority_package_sha256 !== $packageSha256
            || (string) $revision->source_hash !== (string) $target['asset_sha256']
            || (string) $revision->workflow_state !== EnneagramPublicAuthorityV206RevisionPromoter::STATE_PUBLISHED) {
            $issues[] = 'database_published_package_or_pointer_mismatch';
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $v1 @param list<string> $issues */
    private function assertApiPayloadMatchesCurrentPublicAsset(array $target, array $v1, array &$issues): void
    {
        $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', (string) $target['entity_type'])
            ->where('entity_key', (string) $target['code'])
            ->where('locale', (string) $target['locale'])
            ->first();
        if (! $asset instanceof PersonalityPublicContentAsset) {
            $issues[] = 'api_current_public_asset_mismatch';

            return;
        }
        $expected = [
            'title' => (string) $asset->title,
            'summary' => (string) $asset->summary,
            'sections' => is_array($asset->content_sections_json) ? $asset->content_sections_json : [],
            'seo' => is_array($asset->seo_json) ? $asset->seo_json : [],
            'robots' => (string) $asset->robots,
            'canonical_path' => (string) data_get($asset->canonical_json, 'path', ''),
            'canonical' => is_array($asset->canonical_json) ? $asset->canonical_json : [],
            'hreflang' => $this->normalizedHreflangUrls($asset->hreflang_json),
            'faq' => is_array($asset->faq_json) ? $asset->faq_json : [],
            'source_package' => $asset->source_package,
            'source_hash' => $asset->source_hash,
            'review_state' => (string) $asset->review_state,
        ];
        $observed = [
            'title' => (string) ($v1['title'] ?? ''),
            'summary' => (string) ($v1['summary'] ?? ''),
            'sections' => is_array($v1['sections'] ?? null) ? $v1['sections'] : [],
            'seo' => is_array($v1['seo'] ?? null) ? $v1['seo'] : [],
            'robots' => (string) ($v1['robots'] ?? ''),
            'canonical_path' => (string) ($v1['canonical_path'] ?? ''),
            'canonical' => is_array($v1['canonical'] ?? null) ? $v1['canonical'] : [],
            'hreflang' => $this->normalizedHreflangUrls($v1['hreflang'] ?? null),
            'faq' => is_array($v1['faq'] ?? null) ? $v1['faq'] : [],
            'source_package' => $v1['source_package'] ?? null,
            'source_hash' => $v1['source_hash'] ?? null,
            'review_state' => (string) ($v1['review_state'] ?? ''),
        ];
        if (! hash_equals($this->fingerprint($expected), $this->fingerprint($observed))) {
            $issues[] = 'api_current_public_asset_mismatch';
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $v1 @param list<string> $issues */
    private function validateApiDiscoverabilityUrls(
        array $target,
        array $v1,
        string $frontendBaseUrl,
        array &$issues,
    ): void {
        $path = (string) $target['path'];
        $canonical = is_array($v1['canonical'] ?? null) ? $v1['canonical'] : [];
        if (trim((string) ($canonical['path'] ?? '')) !== $path
            || (array_key_exists('url', $canonical)
                && ! $this->isExactFrontendUrl(
                    trim((string) $canonical['url']),
                    $frontendBaseUrl,
                    $path,
                ))) {
            $issues[] = 'api_canonical_url_mismatch';
        }

        $requiredLanguages = ['en', 'zh-cn', 'x-default'];
        $hreflang = $this->normalizedHreflangUrls($v1['hreflang'] ?? null);
        foreach (array_keys($hreflang) as $language) {
            $normalizedLanguage = strtolower(trim((string) $language));
            if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $normalizedLanguage) === 1
                && ! in_array($normalizedLanguage, $requiredLanguages, true)) {
                $issues[] = 'api_hreflang_language_set_mismatch';
                break;
            }
        }
        $routeSuffix = preg_replace('#^/(?:en|zh)/#', '/', $path);
        $expectedPaths = is_string($routeSuffix) ? [
            'en' => '/en'.$routeSuffix,
            'zh-cn' => '/zh'.$routeSuffix,
            'x-default' => '/en'.$routeSuffix,
        ] : [];
        foreach ($requiredLanguages as $language) {
            $reference = trim((string) ($hreflang[$language] ?? ''));
            $expectedPath = (string) ($expectedPaths[$language] ?? '');
            if ($expectedPath === ''
                || ! $this->isExactFrontendReference($reference, $frontendBaseUrl, $expectedPath)) {
                $issues[] = 'api_hreflang_url_mismatch_'.$language;
            }
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $v2 @param list<string> $issues */
    private function assertApiVisibleEvidenceMatchesCurrentPublicAsset(array $target, array $v2, array &$issues): void
    {
        $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', (string) $target['entity_type'])
            ->where('entity_key', (string) $target['code'])
            ->where('locale', (string) $target['locale'])
            ->first();
        if (! $asset instanceof PersonalityPublicContentAsset) {
            $issues[] = 'api_visible_evidence_authority_mismatch';

            return;
        }

        $expected = $this->canonicalVisibleEvidenceForAuthority(
            is_array($asset->authority_json) ? $asset->authority_json : [],
        );
        $observedSources = is_array(data_get($v2, 'visible_evidence.sources'))
            ? array_values(data_get($v2, 'visible_evidence.sources'))
            : [];
        $observedClaimMapping = is_array(data_get($v2, 'visible_evidence.claim_mapping'))
            ? array_values(data_get($v2, 'visible_evidence.claim_mapping'))
            : [];
        $observedLimitations = is_array(data_get($v2, 'visible_evidence.limitations'))
            ? array_values(data_get($v2, 'visible_evidence.limitations'))
            : [];
        $observed = [
            'sources' => $observedSources,
            'claim_mapping' => $observedClaimMapping,
            'limitations' => $observedLimitations,
            'eligible' => data_get($v2, 'visible_evidence.eligible') === true,
        ];
        if (! hash_equals($this->fingerprint($expected), $this->fingerprint($observed))) {
            $issues[] = 'api_visible_evidence_authority_mismatch';
        }
    }

    /** @internal @param array<string,mixed> $authority @return array<string,mixed> */
    public function canonicalVisibleEvidenceForAuthority(array $authority): array
    {
        $sources = $this->canonicalEvidenceSources((array) ($authority['sources'] ?? []));
        $claimMapping = $this->canonicalEvidenceClaimMapping(
            (array) ($authority['claim_mapping'] ?? []),
            array_fill_keys(array_column($sources, 'id'), true),
        );

        return [
            'sources' => $sources,
            'claim_mapping' => $claimMapping,
            'limitations' => $this->canonicalEvidenceStringList((array) ($authority['limitations'] ?? [])),
            'eligible' => ($authority['visible_evidence_eligible'] ?? false) === true
                && $sources !== []
                && $claimMapping !== [],
        ];
    }

    /** @param array<int,mixed> $items @return list<array<string,mixed>> */
    private function canonicalEvidenceSources(array $items): array
    {
        $sources = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $this->firstNonEmptyEvidenceString($item['id'] ?? null);
            $title = $this->firstNonEmptyEvidenceString($item['title'] ?? null);
            $authorOrOrganization = $this->firstNonEmptyEvidenceString($item['author_or_organization'] ?? null);
            $sourceType = $this->firstNonEmptyEvidenceString($item['source_type'] ?? null);
            $year = (int) ($item['year'] ?? 0);
            if ($id === null
                || isset($seen[$id])
                || $title === null
                || $authorOrOrganization === null
                || ! in_array($sourceType, PersonalityPublicContentAssetContract::SOURCE_TYPES, true)
                || $year < 1800
                || $year > (int) now()->year) {
                continue;
            }

            $doi = $this->firstNonEmptyEvidenceString($item['doi'] ?? null);
            if ($doi !== null && preg_match('/^10\.\d{4,9}\/[\-._;()\/:a-z0-9]+$/i', $doi) !== 1) {
                $doi = null;
            }
            $seen[$id] = true;
            $sources[] = [
                'id' => $id,
                'title' => $title,
                'author_or_organization' => $authorOrOrganization,
                'year' => $year,
                'source_type' => $sourceType,
                'doi' => $doi,
                'public_url' => $this->publicEvidenceHttpsUrl($item['public_url'] ?? null),
                'accessed_at' => $this->evidenceDateValue($item['accessed_at'] ?? null),
                'claim_ids' => $this->canonicalEvidenceStringList((array) ($item['claim_ids'] ?? [])),
                'limitation' => $this->firstNonEmptyEvidenceString($item['limitation'] ?? null),
            ];
        }

        return $sources;
    }

    /**
     * @param  array<int,mixed>  $items
     * @param  array<string,bool>  $sourceIds
     * @return list<array<string,mixed>>
     */
    private function canonicalEvidenceClaimMapping(array $items, array $sourceIds): array
    {
        $mapping = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $claimId = $this->firstNonEmptyEvidenceString($item['claim_id'] ?? null);
            $resolvedSourceIds = array_values(array_filter(
                $this->canonicalEvidenceStringList((array) ($item['source_ids'] ?? [])),
                static fn (string $sourceId): bool => isset($sourceIds[$sourceId]),
            ));
            if ($claimId === null || $resolvedSourceIds === []) {
                continue;
            }
            $mapping[] = [
                'claim_id' => $claimId,
                'source_ids' => $resolvedSourceIds,
                'limitation' => $this->firstNonEmptyEvidenceString($item['limitation'] ?? null),
            ];
        }

        return $mapping;
    }

    /** @param array<int,mixed> $items @return list<string> */
    private function canonicalEvidenceStringList(array $items): array
    {
        $values = [];
        foreach ($items as $item) {
            $value = $this->firstNonEmptyEvidenceString($item);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function publicEvidenceHttpsUrl(mixed $value): ?string
    {
        $url = $this->firstNonEmptyEvidenceString($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (int) ($parts['port'] ?? 443) !== 443) {
            return null;
        }
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
            || (filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            return null;
        }

        return $url;
    }

    private function evidenceDateValue(mixed $value): ?string
    {
        $date = $this->firstNonEmptyEvidenceString($value);
        if ($date === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($date))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstNonEmptyEvidenceString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $v1 @param array<string,mixed> $v2 @param list<string> $issues */
    private function validateHtml(
        string $html,
        array $v1,
        array $v2,
        string $path,
        string $frontendBaseUrl,
        string $phase,
        array $sensitiveValues,
        array &$issues,
    ): void {
        $lower = strtolower($html);
        foreach (['page not found', '404 -', '页面不存在', 'rollback_token', 'reviewer_name', 'x-fm-content-release-signature'] as $unsafe) {
            if (str_contains($lower, strtolower($unsafe))) {
                $issues[] = 'html_unsafe_or_soft_404_marker';
                break;
            }
        }
        foreach (['authority-hero', 'authority-inline', 'enneagram-authority-media', 'data-authority-media'] as $mediaMarker) {
            if (str_contains($lower, $mediaMarker)) {
                $issues[] = 'html_authority_media_present';
                break;
            }
        }
        $this->assertNoSensitiveValues($html, $sensitiveValues, 'html', $issues);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            $issues[] = 'html_parse_failed';

            return;
        }
        $xpath = new DOMXPath($dom);
        $standardMedia = $xpath->query(
            '//meta[@content and ('
            .'starts-with(translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"og:image")'
            .' or starts-with(translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"twitter:image")'
            .')]'
            .' | //link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="image_src" and @href]'
            .' | //main//img | //main//picture | //article//img | //article//picture'
            .' | //*[@role="main"]//img | //*[@role="main"]//picture'
        );
        if ($standardMedia !== false
            && $standardMedia->length > 0
            && ! in_array('html_authority_media_present', $issues, true)) {
            $issues[] = 'html_authority_media_present';
        }
        if (! in_array('html_authority_media_present', $issues, true)) {
            $cssMedia = $xpath->query(
                '//style | //main[@style] | //main//*[@style]'
                .' | //article[@style] | //article//*[@style]'
                .' | //*[@role="main"][@style] | //*[@role="main"]//*[@style]',
            );
            foreach ($cssMedia ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $css = strtolower($node->tagName) === 'style'
                    ? (string) $node->textContent
                    : $node->getAttribute('style');
                if ($this->cssContainsMediaUrl($css)) {
                    $issues[] = 'html_authority_media_present';
                    break;
                }
            }
        }
        $title = trim((string) $xpath->evaluate('string(//title[1])'));
        $h1 = trim((string) $xpath->evaluate('string(//h1[1])'));
        $description = trim((string) $xpath->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
        $robotsNodes = $xpath->query(
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"][@content]',
        );
        $robotsNode = $robotsNodes !== false && $robotsNodes->length === 1
            ? $robotsNodes->item(0)
            : null;
        $robots = $robotsNode instanceof DOMElement
            ? trim($robotsNode->getAttribute('content'))
            : '';
        $canonicalNodes = $xpath->query(
            '//link[contains(concat(" ",normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," canonical ")]',
        );
        $canonicalNode = $canonicalNodes !== false && $canonicalNodes->length === 1
            ? $canonicalNodes->item(0)
            : null;
        $canonical = $canonicalNode instanceof DOMElement
            ? trim($canonicalNode->getAttribute('href'))
            : '';
        if ($title === '' || $h1 === '' || $description === '') {
            $issues[] = 'html_title_description_or_h1_missing';
        }
        $expectedTitle = trim((string) ($v1['title'] ?? ''));
        $expectedDescription = trim((string) data_get($v1, 'seo.description', $v1['summary'] ?? ''));
        if ($expectedTitle !== '' && (! str_contains($title, $expectedTitle) || ! str_contains($h1, $expectedTitle))) {
            $issues[] = 'html_title_or_h1_mismatch';
        }
        if ($expectedDescription !== '' && $description !== $expectedDescription) {
            $issues[] = 'html_description_mismatch';
        }
        $expectedRobots = $this->normalizedRobotsDirective((string) ($v1['robots'] ?? ''));
        if ($expectedRobots === ''
            || $robotsNodes === false
            || $robotsNodes->length !== 1
            || $this->normalizedRobotsDirective($robots) !== $expectedRobots) {
            $issues[] = 'html_robots_mismatch';
        }
        if ($canonicalNodes === false
            || $canonicalNodes->length !== 1
            || ! $this->isExactFrontendUrl($canonical, $frontendBaseUrl, $path)) {
            $issues[] = 'html_canonical_mismatch';
        }
        $this->validateHreflang(
            $xpath,
            is_array($v1['hreflang'] ?? null) ? $v1['hreflang'] : [],
            $frontendBaseUrl,
            $issues,
        );
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $href = $link->getAttribute('href');
            if ($this->isPrivateRuntimeUrl($href)) {
                $issues[] = 'html_private_link_present';
                break;
            }
        }

        $this->markStylesheetHiddenElements($xpath, $issues);
        $visible = $this->normalizedRenderableBodyText($xpath);
        if (! in_array('html_private_reviewer_exposed', $issues, true)) {
            $this->assertNoSensitiveValues($visible, $sensitiveValues, 'html', $issues);
        }
        $this->validateVisibleSections(
            is_array($v1['sections'] ?? null) ? $v1['sections'] : [],
            $visible,
            $issues,
        );
        $expectedFaqAnswers = [];
        foreach (is_array($v1['faq'] ?? null) ? $v1['faq'] : [] as $faq) {
            $question = is_array($faq) ? trim((string) ($faq['question'] ?? $faq['q'] ?? '')) : '';
            $answer = is_array($faq) ? trim((string) ($faq['answer'] ?? $faq['a'] ?? '')) : '';
            $normalizedQuestion = $this->normalizedVisibleText($question);
            $normalizedAnswer = $this->normalizedMarkdownText($answer);
            if ($question === ''
                || $answer === ''
                || ! str_contains($visible, $normalizedQuestion)
                || ! str_contains($visible, $normalizedAnswer)) {
                $issues[] = 'html_visible_faq_mismatch';
                break;
            }
            $expectedFaqAnswers[$normalizedQuestion] = $normalizedAnswer;
        }
        if ($phase === 'post') {
            $sources = is_array(data_get($v2, 'visible_evidence.sources'))
                ? array_values(data_get($v2, 'visible_evidence.sources'))
                : [];
            $claimMapping = is_array(data_get($v2, 'visible_evidence.claim_mapping'))
                ? data_get($v2, 'visible_evidence.claim_mapping')
                : [];
            if ($sources === [] || $claimMapping === [] || data_get($v2, 'visible_evidence.eligible') !== true) {
                $issues[] = 'api_visible_evidence_missing';
            }
            foreach ($sources as $source) {
                $sourceTitle = is_array($source) ? trim((string) ($source['title'] ?? '')) : '';
                if ($sourceTitle === '' || ! str_contains($visible, $this->normalizedVisibleText($sourceTitle))) {
                    $issues[] = 'html_visible_evidence_mismatch';
                    break;
                }
            }
        }
        $observedFaqAnswers = [];
        $faqSchemaInvalid = false;
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $schema = json_decode((string) $script->textContent, true);
            if (! is_array($schema)) {
                $issues[] = 'html_schema_json_invalid';

                continue;
            }
            if ($this->schemaContainsMedia($schema)
                && ! in_array('html_authority_media_present', $issues, true)) {
                $issues[] = 'html_authority_media_present';
            }
            foreach ($this->faqEntriesFromSchema($schema) as $entry) {
                $question = $this->normalizedVisibleText($entry['question']);
                $answer = $this->normalizedMarkdownText($entry['answer']);
                if ($question === ''
                    || $answer === ''
                    || ! str_contains($visible, $question)
                    || ! str_contains($visible, $answer)
                    || isset($observedFaqAnswers[$question])) {
                    $faqSchemaInvalid = true;
                    break;
                }
                $observedFaqAnswers[$question] = $answer;
            }
        }
        ksort($expectedFaqAnswers);
        ksort($observedFaqAnswers);
        if ($faqSchemaInvalid || $expectedFaqAnswers !== $observedFaqAnswers) {
            $issues[] = 'html_schema_faq_mismatch';
        }
    }

    private function cssContainsMediaUrl(string $css): bool
    {
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $withoutFontFaces = preg_replace('/@font-face\s*\{[^}]*\}/is', '', $withoutComments) ?? $withoutComments;

        return preg_match('/url\s*\(/i', $withoutFontFaces) === 1;
    }

    private function normalizedRobotsDirective(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', '', trim($value)));
    }

    /** @return array<string,mixed> */
    private function normalizedHreflangUrls(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $urls = [];
        foreach ($value as $language => $url) {
            $key = trim((string) $language);
            $normalizedLanguage = strtolower($key);
            if (! in_array($normalizedLanguage, ['en', 'zh-cn', 'x-default'], true)) {
                $urls[$key] = $url;

                continue;
            }
            if (! is_string($url) || trim($url) === '') {
                $urls[$normalizedLanguage] = '';

                continue;
            }
            $urls[$normalizedLanguage] = trim($url);
        }
        ksort($urls);

        return $urls;
    }

    /** @param array<int|string,mixed> $schema */
    private function schemaContainsMedia(array $schema): bool
    {
        foreach ($schema as $key => $value) {
            $normalizedKey = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $key));
            if (in_array($normalizedKey, ['image', 'thumbnailurl', 'primaryimageofpage'], true)
                && $value !== null
                && $value !== ''
                && $value !== []) {
                return true;
            }
            if (is_array($value) && $this->schemaContainsMedia($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $schema @return list<array{question:string,answer:string}> */
    private function faqEntriesFromSchema(array $schema): array
    {
        $entries = [];
        $nodes = array_is_list($schema) ? $schema : [$schema];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['@type'] ?? null) === 'FAQPage') {
                foreach ((array) ($node['mainEntity'] ?? []) as $entity) {
                    $name = is_array($entity) ? trim((string) ($entity['name'] ?? '')) : '';
                    $acceptedAnswer = is_array($entity) && is_array($entity['acceptedAnswer'] ?? null)
                        ? $entity['acceptedAnswer']
                        : [];
                    $entries[] = [
                        'question' => $name,
                        'answer' => trim((string) ($acceptedAnswer['text'] ?? '')),
                    ];
                }
            }
            if (is_array($node['@graph'] ?? null)) {
                $entries = [...$entries, ...$this->faqEntriesFromSchema($node['@graph'])];
            }
        }

        return $entries;
    }

    /** @param list<array<string,mixed>> $targets @return array<string,string> */
    private function databaseFingerprints(array $targets): array
    {
        usort($targets, static fn (array $left, array $right): int => ((string) $left['asset_key']) <=> ((string) $right['asset_key']));
        $projection = [];
        $stable = [];
        foreach ($targets as $target) {
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
                ->where('entity_type', (string) $target['entity_type'])
                ->where('entity_key', (string) $target['code'])
                ->where('locale', (string) $target['locale'])
                ->firstOrFail();
            $projection[] = [
                'asset_key' => (string) $target['asset_key'],
                'fingerprint' => $this->revisionWriter->recordPublicRuntimeFingerprint($asset),
            ];
            $stable[] = [
                'asset_key' => (string) $target['asset_key'],
                'asset_id' => (int) $asset->id,
                'slug' => (string) $asset->slug,
                'canonical_path' => (string) data_get($asset->canonical_json, 'path', ''),
                'robots' => (string) $asset->robots,
                'is_public' => (bool) $asset->is_public,
                'index_eligible' => (bool) $asset->index_eligible,
                'sitemap_eligible' => (bool) $asset->sitemap_eligible,
                'llms_eligible' => (bool) $asset->llms_eligible,
                'launch_state' => (string) $asset->launch_state,
                'published_at' => $asset->published_at?->utc()->toIso8601String(),
            ];
        }
        usort($projection, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        usort($stable, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        return [
            'public_projection_fingerprint' => $this->fingerprint($projection),
            'stable_identity_discoverability_fingerprint' => $this->fingerprint($stable),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function urlSets(string $frontendBaseUrl, array $expectedEnneagramPaths): array
    {
        $expectedEnneagramPaths = $this->normalizedUrlPaths($expectedEnneagramPaths);
        if (count($expectedEnneagramPaths) !== EnneagramPublicAuthorityV224RuntimeManifest::TARGET_COUNT) {
            throw new RuntimeException('Expected Enneagram discoverability set is not exactly 116 paths.');
        }
        $sets = [];
        foreach (['sitemap' => '/sitemap.xml', 'llms' => '/llms.txt', 'llms_full' => '/llms-full.txt'] as $name => $path) {
            $response = Http::withoutRedirecting()->timeout(30)->get(rtrim($frontendBaseUrl, '/').$path);
            if (! $response->successful()) {
                throw new RuntimeException($name.' URL-set readback failed with HTTP '.$response->status().'.');
            }
            $urls = $name === 'sitemap'
                ? $this->sitemapUrls($response, $frontendBaseUrl)
                : $this->textUrls($response->body(), $frontendBaseUrl);
            $enneagram = array_values(array_filter(
                $urls,
                static fn (string $url): bool => str_contains($url, '/personality/enneagram'),
            ));
            if ($enneagram !== $expectedEnneagramPaths) {
                throw new RuntimeException($name.' Enneagram URL subset does not match the exact 116 public paths.');
            }
            $sets[$name] = [
                'url_count' => count($urls),
                'url_set_sha256' => $this->fingerprint($urls),
                'enneagram_url_count' => count($enneagram),
                'enneagram_url_set_sha256' => $this->fingerprint($enneagram),
                'enneagram_urls' => $enneagram,
            ];
        }

        return $sets;
    }

    /** @return list<string> */
    private function sitemapUrls(Response $response, string $frontendBaseUrl): array
    {
        preg_match_all('#<loc>\s*([^<]+)\s*</loc>#i', $response->body(), $matches);

        return $this->normalizedUrlPaths($matches[1] ?? [], $frontendBaseUrl, true);
    }

    /** @return list<string> */
    private function textUrls(string $text, string $frontendBaseUrl): array
    {
        preg_match_all('#https?://[^\s<>()"\']+|(?<![A-Za-z0-9:/])/(?!/)[^\s<>()"\']+#i', $text, $matches);

        return $this->normalizedUrlPaths($matches[0] ?? [], $frontendBaseUrl);
    }

    /** @param list<string> $urls @return list<string> */
    private function normalizedUrlPaths(
        array $urls,
        ?string $frontendBaseUrl = null,
        bool $requireCanonicalSitemapUrl = false,
    ): array {
        $expectedOrigin = $frontendBaseUrl !== null ? $this->canonicalOrigin($frontendBaseUrl) : null;
        $paths = [];
        foreach ($urls as $url) {
            $url = rtrim(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5), '.,;');
            $isRelativePath = str_starts_with($url, '/') && ! str_starts_with($url, '//');
            $parts = parse_url($url);
            if (! is_array($parts)) {
                throw new RuntimeException('Discoverability URL is invalid.');
            }
            $path = (string) ($parts['path'] ?? '');
            if ($this->isPrivateDiscoverabilityPath($path)) {
                throw new RuntimeException('Discoverability URL set contains a prohibited private path.');
            }
            if ($path !== '/' && str_ends_with($path, '/')) {
                throw new RuntimeException('Discoverability URL must not contain a trailing slash on a non-root path.');
            }
            if ($expectedOrigin !== null) {
                if (array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
                    throw new RuntimeException('Discoverability URL must not contain a query or fragment.');
                }
                if ($requireCanonicalSitemapUrl && $isRelativePath) {
                    throw new RuntimeException('Sitemap URL must be absolute on the exact frontend origin.');
                }
                if (! $isRelativePath && ! hash_equals($expectedOrigin, $this->canonicalOrigin($url))) {
                    throw new RuntimeException('Discoverability URL origin does not match the exact frontend origin.');
                }
            }
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        if (count($paths) !== count(array_unique($paths))) {
            throw new RuntimeException('Discoverability URL set contains a duplicate normalized public path.');
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private function isPrivateDiscoverabilityPath(string $path): bool
    {
        $segments = array_values(array_filter(
            explode('/', trim(strtolower(rawurldecode($path)), '/')),
            static fn (string $segment): bool => $segment !== '',
        ));
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', (string) ($segments[0] ?? '')) === 1) {
            array_shift($segments);
        }

        $root = (string) ($segments[0] ?? '');
        if (in_array($root, [
            'admin',
            'api',
            'account',
            'attempt',
            'attempts',
            'claim',
            'checkout',
            'history',
            'lookup',
            'me',
            'order',
            'orders',
            'ops',
            'pay',
            'payment',
            'payments',
            'report',
            'reports',
            'result',
            'results',
            'share',
            'shares',
            'tenant',
        ], true)) {
            return true;
        }

        return ($root === 'tests' && in_array('take', $segments, true))
            || ($root === 'og' && in_array((string) ($segments[1] ?? ''), ['share', 'shares'], true));
    }

    private function canonicalOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new RuntimeException('Discoverability URL must be an absolute HTTP(S) URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$host.($port !== null && ! $defaultPort ? ':'.$port : '');
    }

    private function exactHttpsOrigin(string $value, string $label): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (! filter_var($value, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            throw new RuntimeException($label.' must be an exact HTTPS origin without credentials, path, query, or fragment.');
        }

        return rtrim($value, '/');
    }

    private function isExactFrontendUrl(string $url, string $frontendBaseUrl, string $expectedPath): bool
    {
        $parts = parse_url($url);
        if ($url === ''
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($parts['path'] ?? '') !== $expectedPath) {
            return false;
        }

        try {
            return hash_equals($this->canonicalOrigin($frontendBaseUrl), $this->canonicalOrigin($url));
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isExactFrontendReference(
        string $reference,
        string $frontendBaseUrl,
        string $expectedPath,
    ): bool {
        $parts = parse_url($reference);
        $isRelativePath = str_starts_with($reference, '/') && ! str_starts_with($reference, '//');
        if ($isRelativePath) {
            return is_array($parts)
                && ! array_key_exists('query', $parts)
                && ! array_key_exists('fragment', $parts)
                && (string) ($parts['path'] ?? '') === $expectedPath;
        }

        return $this->isExactFrontendUrl($reference, $frontendBaseUrl, $expectedPath);
    }

    /** @param array<string,mixed> $expectedHreflang @param list<string> $issues */
    private function validateHreflang(
        DOMXPath $xpath,
        array $expectedHreflang,
        string $frontendBaseUrl,
        array &$issues,
    ): void {
        $requiredLanguages = ['en', 'zh-cn', 'x-default'];
        $expected = [];
        foreach ($expectedHreflang as $language => $url) {
            $language = strtolower(trim((string) $language));
            if (! in_array($language, $requiredLanguages, true)) {
                continue;
            }
            $url = trim((string) $url);
            $parts = parse_url($url);
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            if ($language === ''
                || isset($expected[$language])
                || $path === ''
                || ! is_array($parts)
                || array_key_exists('query', $parts)
                || array_key_exists('fragment', $parts)) {
                $issues[] = 'html_hreflang_mismatch_expected_'.($language !== '' ? $language : 'missing');

                return;
            }
            $expected[$language] = $path;
        }
        if (array_diff($requiredLanguages, array_keys($expected)) !== []) {
            $issues[] = 'html_hreflang_incomplete';

            return;
        }

        $actual = [];
        foreach ($xpath->query(
            '//link[contains(concat(" ",normalize-space(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"))," ")," alternate ")][@hreflang][@href]',
        ) ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $language = strtolower(trim($link->getAttribute('hreflang')));
            if ($language === ''
                || ! isset($expected[$language])
                || isset($actual[$language])
                || ! $this->isExactFrontendUrl(
                    trim($link->getAttribute('href')),
                    $frontendBaseUrl,
                    $expected[$language],
                )) {
                $issues[] = 'html_hreflang_mismatch_actual_'.($language !== '' ? $language : 'missing');

                return;
            }
            $actual[$language] = true;
        }
        if (count($actual) !== count($requiredLanguages) || array_diff_key($expected, $actual) !== []) {
            $issues[] = 'html_hreflang_incomplete';
        }
    }

    /** @param array<string,mixed> $target */
    private function apiUrl(string $baseUrl, array $target): string
    {
        return rtrim($baseUrl, '/').'/api/v0.5/personality-content-assets?'.http_build_query([
            'org_id' => 0,
            'locale' => (string) $target['locale'],
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => (string) $target['entity_type'],
            'code' => (string) $target['code'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizedMedia(mixed $media): array
    {
        $media = is_array($media) ? $media : [];

        return [
            'hero' => $media['hero'] ?? null,
            'inline' => array_values(is_array($media['inline'] ?? null) ? $media['inline'] : []),
            'og' => $media['og'] ?? null,
        ];
    }

    private function normalizedVisibleText(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5))) ?? '';
    }

    /** @param list<string> $issues */
    private function markStylesheetHiddenElements(DOMXPath $xpath, array &$issues): void
    {
        foreach ($xpath->query(
            '//*[contains(concat(" ",normalize-space(@class)," ")," hidden ")'
            .' or contains(concat(" ",normalize-space(@class)," ")," invisible ")]',
        ) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $element->setAttribute('data-fermat-runtime-stylesheet-hidden', 'true');
            }
        }

        $converter = new CssSelectorConverter;
        foreach ($xpath->query('//style') ?: [] as $style) {
            $css = preg_replace('#/\*.*?\*/#s', '', (string) $style->textContent) ?? (string) $style->textContent;
            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                $declarations = (string) ($rule[2] ?? '');
                if (preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse))(?:\s*!important)?\s*(?:;|$)/i', $declarations) !== 1) {
                    continue;
                }
                $selector = trim((string) ($rule[1] ?? ''));
                if ($selector === '') {
                    continue;
                }
                if (str_starts_with($selector, '@')) {
                    $issues[] = 'html_stylesheet_visibility_unverifiable';

                    return;
                }
                try {
                    $hidden = $xpath->query($converter->toXPath($selector, '//'));
                } catch (\Throwable) {
                    $issues[] = 'html_stylesheet_visibility_unverifiable';

                    return;
                }
                if ($hidden === false) {
                    $issues[] = 'html_stylesheet_visibility_unverifiable';

                    return;
                }
                foreach ($hidden as $element) {
                    if ($element instanceof DOMElement
                        && $xpath->evaluate('boolean(ancestor-or-self::body)', $element) === true) {
                        $element->setAttribute('data-fermat-runtime-stylesheet-hidden', 'true');
                    }
                }
            }
        }
    }

    private function normalizedRenderableBodyText(DOMXPath $xpath): string
    {
        $nodes = $xpath->query(
            '//body//text()['
            .'not(ancestor::script)'
            .' and not(ancestor::style)'
            .' and not(ancestor::template)'
            .' and not(ancestor::noscript)'
            .' and not(ancestor::head)'
            .' and not(ancestor::*[@hidden])'
            .' and not(ancestor::*[@data-fermat-runtime-stylesheet-hidden="true"])'
            .' and not(ancestor::*[translate(@aria-hidden,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="true"])'
            .' and not(ancestor::*[contains(translate(normalize-space(@style),"ABCDEFGHIJKLMNOPQRSTUVWXYZ ","abcdefghijklmnopqrstuvwxyz"),"display:none")])'
            .' and not(ancestor::*[contains(translate(normalize-space(@style),"ABCDEFGHIJKLMNOPQRSTUVWXYZ ","abcdefghijklmnopqrstuvwxyz"),"visibility:hidden")])'
            .']',
        );
        if ($nodes === false) {
            return '';
        }

        $text = '';
        foreach ($nodes as $node) {
            $text .= (string) $node->nodeValue;
        }

        return $this->normalizedVisibleText($text);
    }

    /** @param list<mixed> $sections @param list<string> $issues */
    private function validateVisibleSections(array $sections, string $visible, array &$issues): void
    {
        if ($sections === []) {
            $issues[] = 'html_visible_section_mismatch';

            return;
        }
        foreach ($sections as $section) {
            if (! is_array($section)) {
                $issues[] = 'html_visible_section_mismatch';

                return;
            }
            $expected = array_values(array_filter([
                $this->normalizedVisibleText((string) ($section['title'] ?? $section['heading'] ?? '')),
                $this->normalizedMarkdownText((string) ($section['body_md'] ?? $section['body'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));
            if ($expected === []) {
                $issues[] = 'html_visible_section_mismatch';

                return;
            }
            foreach ($expected as $text) {
                if (! str_contains($visible, $text)) {
                    $issues[] = 'html_visible_section_mismatch';

                    return;
                }
            }
        }
    }

    private function normalizedMarkdownText(string $value): string
    {
        $html = (string) Str::markdown($value);
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;

        return $this->normalizedVisibleText($text);
    }

    private function exactGitSha(string $value, string $label): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
            throw new RuntimeException($label.' must be an exact lowercase 40-character Git SHA.');
        }

        return $value;
    }

    /** @param list<string> $sensitiveValues @param list<string> $issues */
    private function assertNoSensitiveValues(string $body, array $sensitiveValues, string $surface, array &$issues): void
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $caseFoldedBody = mb_strtolower($body, 'UTF-8');
        $caseFoldedDecodedBody = mb_strtolower($decodedBody, 'UTF-8');
        foreach ($sensitiveValues as $value) {
            $value = trim($value);
            $caseFoldedValue = mb_strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5), 'UTF-8');
            if ($value !== '' && (str_contains($caseFoldedBody, $caseFoldedValue)
                || str_contains($caseFoldedDecodedBody, $caseFoldedValue))) {
                $issues[] = $surface.'_private_reviewer_exposed';

                return;
            }
        }
        if (preg_match('~(?:"reviewer_name"|rollback[_-]?token)~i', $body) === 1
            || $this->containsPrivateRuntimePath($decodedBody)) {
            $issues[] = $surface.'_private_data_marker_exposed';
        }
    }

    private function containsPrivateRuntimePath(string $body): bool
    {
        $body = str_replace('\\/', '/', $body);
        preg_match_all('#https?://[^\s<>()"\']+|(?<![A-Za-z0-9:/])/(?!/)[^\s<>()"\']+#i', $body, $matches);
        foreach ($matches[0] ?? [] as $url) {
            if ($this->isPrivateRuntimeUrl((string) $url)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateRuntimeUrl(string $url): bool
    {
        $url = rtrim(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5), '.,;:]}');
        $parts = parse_url($url);

        return is_array($parts)
            && $this->isPrivateDiscoverabilityPath((string) ($parts['path'] ?? ''));
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->normalizeForHash($child), $value);
    }
}

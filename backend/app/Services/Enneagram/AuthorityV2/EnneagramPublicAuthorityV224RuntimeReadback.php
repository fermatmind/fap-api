<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
        bool $requireFreshApiCache = false,
        array $sensitiveValues = [],
    ): array {
        if (! in_array($phase, ['pre', 'post'], true)) {
            throw new RuntimeException('Runtime readback phase must be pre or post.');
        }
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
        $api = Http::acceptJson()->timeout(20)->get($apiUrl);
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
        if ($phase === 'post') {
            if (($v1['source_hash'] ?? null) !== $target['asset_sha256']
                || ($v1['source_package'] ?? null) !== EnneagramPublicAuthorityV205RevisionWorkspaceWriter::SOURCE_PACKAGE
                || data_get($v2, 'editorial_authority.review_state') !== EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED
                || data_get($v2, 'editorial_authority.reviewer') !== null) {
                $issues[] = 'api_exact_candidate_or_review_mismatch';
            }
            $this->assertPublishedRevision($target, $packageSha256, $issues);
        }

        $htmlResponse = Http::accept('text/html')->timeout(20)->get(
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
        $title = trim((string) $xpath->evaluate('string(//title[1])'));
        $h1 = trim((string) $xpath->evaluate('string(//h1[1])'));
        $description = trim((string) $xpath->evaluate('string(//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content)'));
        $canonical = trim((string) $xpath->evaluate('string(//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href)'));
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
        if (! $this->isExactFrontendUrl($canonical, $frontendBaseUrl, $path)) {
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
            if (preg_match('#/(?:results?|orders?|payments?|pay|share)/#i', $href) === 1) {
                $issues[] = 'html_private_link_present';
                break;
            }
        }

        $visible = $this->normalizedVisibleText($dom->textContent ?? '');
        foreach (is_array($v1['faq'] ?? null) ? $v1['faq'] : [] as $faq) {
            $question = is_array($faq) ? trim((string) ($faq['question'] ?? $faq['q'] ?? '')) : '';
            if ($question !== '' && ! str_contains($visible, $this->normalizedVisibleText($question))) {
                $issues[] = 'html_visible_faq_mismatch';
                break;
            }
        }
        if ($phase === 'post') {
            foreach ((array) data_get($v2, 'visible_evidence.sources', []) as $source) {
                $sourceTitle = is_array($source) ? trim((string) ($source['title'] ?? '')) : '';
                if ($sourceTitle !== '' && ! str_contains($visible, $this->normalizedVisibleText($sourceTitle))) {
                    $issues[] = 'html_visible_evidence_mismatch';
                    break;
                }
            }
        }
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $schema = json_decode((string) $script->textContent, true);
            if (! is_array($schema)) {
                $issues[] = 'html_schema_json_invalid';

                continue;
            }
            foreach ($this->faqQuestionsFromSchema($schema) as $question) {
                if (! str_contains($visible, $this->normalizedVisibleText($question))) {
                    $issues[] = 'html_schema_not_visible';
                    break 2;
                }
            }
        }
    }

    /** @param array<string,mixed> $schema @return list<string> */
    private function faqQuestionsFromSchema(array $schema): array
    {
        $questions = [];
        $nodes = array_is_list($schema) ? $schema : [$schema];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['@type'] ?? null) === 'FAQPage') {
                foreach ((array) ($node['mainEntity'] ?? []) as $entity) {
                    $name = is_array($entity) ? trim((string) ($entity['name'] ?? '')) : '';
                    if ($name !== '') {
                        $questions[] = $name;
                    }
                }
            }
            if (is_array($node['@graph'] ?? null)) {
                $questions = [...$questions, ...$this->faqQuestionsFromSchema($node['@graph'])];
            }
        }

        return $questions;
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
            $response = Http::timeout(30)->get(rtrim($frontendBaseUrl, '/').$path);
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
        preg_match_all('#https?://[^\s<>()"\']+|(?<![A-Za-z0-9])/(?:en|zh)/personality/enneagram[^\s<>()"\']*#i', $text, $matches);

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
            $isEnneagram = str_contains($path, '/personality/enneagram');
            if ($expectedOrigin !== null && ($requireCanonicalSitemapUrl || $isEnneagram)) {
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
                $paths[] = rtrim($path, '/') ?: '/';
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
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
            $isRelativePath = str_starts_with($url, '/') && ! str_starts_with($url, '//');
            if ($language === ''
                || isset($expected[$language])
                || $path === ''
                || ! is_array($parts)
                || array_key_exists('query', $parts)
                || array_key_exists('fragment', $parts)
                || (! $isRelativePath && ! $this->isExactFrontendUrl($url, $frontendBaseUrl, $path))) {
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
        foreach ($xpath->query('//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate"][@hreflang][@href]') ?: [] as $link) {
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

    /** @param list<string> $sensitiveValues @param list<string> $issues */
    private function assertNoSensitiveValues(string $body, array $sensitiveValues, string $surface, array &$issues): void
    {
        $decodedBody = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        foreach ($sensitiveValues as $value) {
            $value = trim($value);
            if ($value !== '' && (str_contains($body, $value) || str_contains($decodedBody, $value))) {
                $issues[] = $surface.'_private_reviewer_exposed';

                return;
            }
        }
        if (preg_match('#(?:"reviewer_name"|rollback[_-]?token|/results?/[^\s"<]+|/orders?/[^\s"<]+|/payments?/[^\s"<]+|/share/[^\s"<]+)#i', $body) === 1) {
            $issues[] = $surface.'_private_data_marker_exposed';
        }
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

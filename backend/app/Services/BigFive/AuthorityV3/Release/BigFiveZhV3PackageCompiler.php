<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\Release;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class BigFiveZhV3PackageCompiler
{
    public const SCHEMA_VERSION = 'big-five-zh-v3-release.v1';

    public const RELEASE_ID = 'big-five-zh-v3-52-page-release-20260718';

    public const SOURCE_CONTENT_SHA256 = '056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5';

    public const ASSET_COUNT = 52;

    public const CLAIM_COUNT = 170;

    public const FAQ_COUNT = 261;

    /** @var array<string,int> */
    public const FAMILY_COUNTS = [
        PersonalityPublicContentAsset::ENTITY_HUB => 1,
        PersonalityPublicContentAsset::ENTITY_DOMAIN => 5,
        PersonalityPublicContentAsset::ENTITY_POLARITY => 15,
        PersonalityPublicContentAsset::ENTITY_FACET_HUB => 1,
        PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 30,
    ];

    /** @var array<string,string> */
    private const ASSET_TYPE_TO_ENTITY_TYPE = [
        'model_hub' => PersonalityPublicContentAsset::ENTITY_HUB,
        'domain' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
        'range' => PersonalityPublicContentAsset::ENTITY_POLARITY,
        'facet_hub' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
        'facet_detail' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
    ];

    /** @var array<string,string> */
    private const SOURCE_TYPE_MAP = [
        'journal_article' => 'peer_reviewed_research',
        'meta_analysis' => 'peer_reviewed_research',
        'meta_synthesis' => 'peer_reviewed_research',
        'official_project_resource' => 'official_documentation',
        'technical_manual' => 'professional_standard',
        'academic_book_metadata' => 'book',
    ];

    /**
     * @return array{release_package:array<string,mixed>,release_json:string,compile_report:array<string,mixed>}
     */
    public function compile(string $sourceRoot, string $expectedContentSha256): array
    {
        $root = $this->resolveRoot($sourceRoot);
        $expectedContentSha256 = strtolower(trim($expectedContentSha256));
        if (! hash_equals(self::SOURCE_CONTENT_SHA256, $expectedContentSha256)) {
            throw new RuntimeException('Expected content SHA-256 is not the locked Big Five zh-CN V3 source SHA.');
        }

        $validator = $this->runSourceValidator($root);

        $manifest = $this->readJson($root.'/package-manifest.json', 'package manifest');
        $this->assertManifest($manifest);

        $pagePaths = $this->pagePaths($root);
        $contentSha256 = $this->contentTreeHash($root, $pagePaths);
        if (! hash_equals($expectedContentSha256, $contentSha256)) {
            throw new RuntimeException('PACKAGE_INPUT_DRIFT: content tree SHA-256 mismatch.');
        }

        $registryPath = $root.'/research/source-registry.json';
        $registry = $this->readJson($registryPath, 'source registry');
        $sources = $this->sourceMap($registry);
        $evidence = $this->evidenceMap($root);

        $assets = [];
        $familyCounts = [];
        $claimCount = 0;
        $runtimeClaimMappingCount = 0;
        $faqCount = 0;
        foreach ($pagePaths as $pagePath) {
            $asset = $this->compilePage($root, $pagePath, $sources, $evidence);
            $assets[] = $asset;
            $entityType = (string) data_get($asset, 'asset.entity_type');
            $familyCounts[$entityType] = ($familyCounts[$entityType] ?? 0) + 1;
            $claimCount += count((array) ($asset['evidence_claims'] ?? []));
            $runtimeClaimMappingCount += count((array) data_get($asset, 'asset.authority.claim_mapping', []));
            $faqCount += count((array) data_get($asset, 'asset.faq', []));
        }

        usort($assets, static fn (array $left, array $right): int => strcmp(
            (string) data_get($left, 'asset.canonical.path'),
            (string) data_get($right, 'asset.canonical.path'),
        ));
        ksort($familyCounts);
        $expectedFamilyCounts = self::FAMILY_COUNTS;
        ksort($expectedFamilyCounts);
        if ($familyCounts !== $expectedFamilyCounts) {
            throw new RuntimeException('Big Five zh-CN V3 family counts do not equal 1/5/15/1/30.');
        }
        if (count($assets) !== self::ASSET_COUNT || $claimCount !== self::CLAIM_COUNT || $faqCount !== self::FAQ_COUNT) {
            throw new RuntimeException('Big Five zh-CN V3 exact inventory, claim, or FAQ count mismatch.');
        }

        $this->assertCanonicalGraph($assets);
        $basePackage = [
            'schema_version' => self::SCHEMA_VERSION,
            'release_id' => self::RELEASE_ID,
            'source_package' => 'fermatmind_big_five_zh_cn_v3_content_package',
            'source_content_sha256' => $contentSha256,
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'locale' => 'zh-CN',
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'org_id' => 0,
            'operator_admin_user_id' => 1,
            'asset_count' => count($assets),
            'family_counts' => $familyCounts,
            'claims_count' => $claimCount,
            'runtime_claim_mapping_count' => $runtimeClaimMappingCount,
            'faq_count' => $faqCount,
            'source_registry_sha256' => hash_file('sha256', $registryPath),
            'source_count' => count($sources),
            'media_supported' => false,
            'search_submit_allowed' => false,
            'assets' => $assets,
        ];
        $payloadSha256 = hash('sha256', $this->stableJson($basePackage));
        $releasePackage = [
            ...$basePackage,
            'package_payload_sha256' => $payloadSha256,
        ];
        $releaseJson = $this->stableJson($releasePackage);
        $fileSha256 = hash('sha256', $releaseJson);

        return [
            'release_package' => $releasePackage,
            'release_json' => $releaseJson,
            'compile_report' => [
                'ok' => true,
                'status' => 'PASS_BIG_FIVE_ZH_V3_PACKAGE_COMPILE',
                'release_id' => self::RELEASE_ID,
                'source_content_sha256' => $contentSha256,
                'package_payload_sha256' => $payloadSha256,
                'package_file_sha256' => $fileSha256,
                'asset_count' => count($assets),
                'family_counts' => $familyCounts,
                'claims_count' => $claimCount,
                'runtime_claim_mapping_count' => $runtimeClaimMappingCount,
                'faq_count' => $faqCount,
                'source_count' => count($sources),
                'source_validator_status' => $validator['status'],
                'source_validator_exit_code' => $validator['validator_exit_code'],
                'database_writes' => 0,
                'media_library_writes' => 0,
                'english_writes' => 0,
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest): void
    {
        $expected = [
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'locale' => 'zh-CN',
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'manifest_status' => 'VERIFIED',
            'qa_status' => 'PASS',
            'scientific_authority_status' => 'PASS',
            'seo_geo_authority_status' => 'PASS',
            'content_hash_sha256' => self::SOURCE_CONTENT_SHA256,
            'content_file_count' => self::ASSET_COUNT,
            'claims_count' => self::CLAIM_COUNT,
            'claims_verified' => self::CLAIM_COUNT,
            'faq_visible_count' => self::FAQ_COUNT,
            'media_supported' => false,
            'search_submit_allowed' => false,
        ];
        foreach ($expected as $field => $value) {
            if (($manifest[$field] ?? null) !== $value) {
                throw new RuntimeException("Package manifest field {$field} is not the locked release value.");
            }
        }
        foreach ([
            'word_count_mismatch', 'research_claims_without_source', 'needs_review_claims',
            'unresolved_claims', 'empirical_claims_without_visible_citation',
            'source_support_mismatch', 'unknown_canonical_links',
            'redirect_alias_links', 'private_route_links', 'orphan_pages',
            'substantive_body_exact_duplicates', 'markdown_images', 'html_images',
            'competitor_copy_matches', 'public_internal_terms',
        ] as $zeroField) {
            if ((int) ($manifest[$zeroField] ?? -1) !== 0) {
                throw new RuntimeException("Package manifest gate {$zeroField} must be zero.");
            }
        }
    }

    /** @return array<string,mixed> */
    private function runSourceValidator(string $root): array
    {
        $validatorPath = $root.'/qa/validate-authority-package.py';
        if (! File::isFile($validatorPath)) {
            throw new RuntimeException('Source package validator is missing.');
        }
        $process = new Process(['python3', 'qa/validate-authority-package.py'], $root);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Source package validator failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result)
            || ($result['status'] ?? null) !== 'PASS'
            || (int) ($result['validator_exit_code'] ?? -1) !== 0
            || ($result['errors'] ?? null) !== []
            || ! hash_equals(self::SOURCE_CONTENT_SHA256, (string) ($result['content_hash_sha256'] ?? ''))) {
            throw new RuntimeException('Source package validator did not return the locked PASS result.');
        }
        $expectedMetrics = [
            'pages' => self::ASSET_COUNT,
            'canonical_pages' => self::ASSET_COUNT,
            'evidence_files' => self::ASSET_COUNT,
            'claims_count' => self::CLAIM_COUNT,
            'claims_verified' => self::CLAIM_COUNT,
            'faq_visible_count' => self::FAQ_COUNT,
            'word_count_mismatch' => 0,
            'research_claims_without_source' => 0,
            'empirical_claims_without_visible_citation' => 0,
            'needs_review_claims' => 0,
            'unresolved_claims' => 0,
            'source_support_mismatch' => 0,
            'unknown_canonical_links' => 0,
            'redirect_alias_links' => 0,
            'private_route_links' => 0,
            'orphan_pages' => 0,
            'substantive_body_exact_duplicates' => 0,
            'markdown_images' => 0,
            'html_images' => 0,
            'competitor_copy_matches' => 0,
            'public_internal_terms' => 0,
        ];
        foreach ($expectedMetrics as $metric => $value) {
            if ((int) data_get($result, 'metrics.'.$metric, -1) !== $value) {
                throw new RuntimeException('Source package validator metric drift: '.$metric.'.');
            }
        }

        return $result;
    }

    /**
     * @param  array<string,array<string,mixed>>  $sources
     * @param  array<string,array<string,mixed>>  $evidence
     * @return array<string,mixed>
     */
    private function compilePage(string $root, string $pagePath, array $sources, array $evidence): array
    {
        [$frontmatter, $body] = $this->parseDocument($pagePath);
        $identity = $this->requiredString($frontmatter, 'content_identity', $pagePath);
        $assetType = $this->requiredString($frontmatter, 'asset_type', $pagePath);
        $entityType = self::ASSET_TYPE_TO_ENTITY_TYPE[$assetType] ?? null;
        if ($entityType === null) {
            throw new RuntimeException("Unsupported asset_type {$assetType} in {$pagePath}.");
        }
        if ($this->requiredString($frontmatter, 'locale', $pagePath) !== 'zh-CN') {
            throw new RuntimeException("Only zh-CN pages are permitted in {$pagePath}.");
        }
        if (($frontmatter['media_supported'] ?? null) !== false) {
            throw new RuntimeException("media_supported must be false in {$pagePath}.");
        }
        if ($this->requiredString($frontmatter, 'author_display_name', $pagePath) !== 'FermatMind Editorial'
            || $this->requiredString($frontmatter, 'reviewer_display_name', $pagePath) !== 'FermatMind Editorial'
            || (int) ($frontmatter['reviewer_admin_user_id'] ?? 0) !== 1) {
            throw new RuntimeException("Editorial authority identity drift in {$pagePath}.");
        }

        $slug = $this->requiredString($frontmatter, 'slug', $pagePath);
        $entityKey = basename($slug);
        $canonicalPath = $this->requiredString($frontmatter, 'canonical_path', $pagePath);
        $expectedPath = BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', $entityType, $entityKey);
        if ($expectedPath === null || $expectedPath !== $canonicalPath) {
            throw new RuntimeException("Canonical catalog mismatch for {$identity}: {$canonicalPath}.");
        }

        $publicTitle = $this->requiredString($frontmatter, 'title', $pagePath);
        $expectedH1 = $this->requiredString($frontmatter, 'h1', $pagePath);
        $parsed = $this->parseBody($body, $expectedH1, $identity);
        $pageEvidence = $evidence[$identity] ?? null;
        if (! is_array($pageEvidence)) {
            throw new RuntimeException("Evidence file missing for {$identity}.");
        }
        $claims = array_values(is_array($pageEvidence['claims'] ?? null) ? $pageEvidence['claims'] : []);
        if ((int) ($pageEvidence['claims_count'] ?? -1) !== count($claims)
            || (int) ($pageEvidence['claims_verified'] ?? -1) !== count($claims)) {
            throw new RuntimeException("Evidence claim counts are not verified for {$identity}.");
        }
        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                throw new RuntimeException("Invalid claim entry for {$identity}.");
            }
            $exactClaim = trim((string) ($claim['exact_claim'] ?? ''));
            if ($exactClaim === '' || ! str_contains($body, $exactClaim)) {
                throw new RuntimeException("Evidence exact_claim is absent from {$identity}.");
            }
            $sourceIds = array_values(is_array($claim['source_ids'] ?? null) ? $claim['source_ids'] : []);
            if (($claim['support_level'] ?? null) !== 'not_required' && $sourceIds === []) {
                throw new RuntimeException("Research claim has no source for {$identity}.");
            }
            foreach ($sourceIds as $sourceId) {
                if (! isset($sources[(string) $sourceId])) {
                    throw new RuntimeException("Unknown claim source {$sourceId} for {$identity}.");
                }
            }
        }

        $frontmatterSourceIds = array_values(is_array($frontmatter['source_ids'] ?? null) ? $frontmatter['source_ids'] : []);
        $evidenceSourceIds = array_values(is_array($pageEvidence['source_ids'] ?? null) ? $pageEvidence['source_ids'] : []);
        if ($frontmatterSourceIds !== $evidenceSourceIds) {
            throw new RuntimeException("Frontmatter/evidence source identity mismatch for {$identity}.");
        }
        $publicSources = $this->publicSources($frontmatterSourceIds, $claims, $sources);
        $claimMapping = $this->publicClaimMapping($claims);
        if ($publicSources === [] || $claimMapping === []) {
            throw new RuntimeException("Visible evidence mapping is empty for {$identity}.");
        }

        $internalLinks = $this->internalLinks($body);
        $asset = [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'code' => $entityKey,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => $publicTitle,
            'summary' => $this->requiredString($frontmatter, 'excerpt', $pagePath),
            'content_sections' => $parsed['sections'],
            'seo' => [
                'title' => $this->requiredString($frontmatter, 'seo_title', $pagePath),
                'description' => $this->requiredString($frontmatter, 'seo_description', $pagePath),
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_path' => $canonicalPath,
            'canonical' => ['path' => $canonicalPath],
            // Existing, backend-authoritative hreflang is preserved by the publisher.
            'hreflang' => [],
            'faq' => $parsed['faq'],
            'schema' => [],
            'method_boundary' => [
                'content_method' => $this->requiredString($frontmatter, 'content_method', $pagePath),
                'clinical_reviewed' => false,
                'expert_endorsement' => false,
                'limitations' => [
                    '用于自我认知与教育说明，不构成临床诊断、招聘筛选或个体结果预测。',
                    '本内容包不提供费马测试的产品级信度、效度、中文常模或个体预测证据。',
                ],
            ],
            'evidence_notes' => array_map(static fn (array $source): array => [
                'source_id' => $source['id'],
                'citation_label' => $source['title'],
                'public_url' => $source['public_url'],
                'limitation' => $source['limitation'],
            ], $publicSources),
            'authority' => [
                'asset_id' => $identity,
                'route' => $canonicalPath,
                'editorial_package_title' => $this->requiredString($frontmatter, 'title', $pagePath),
                'content_method' => $this->requiredString($frontmatter, 'content_method', $pagePath),
                'author' => [
                    'name' => 'FermatMind Editorial',
                    'organization' => 'FermatMind',
                    'role' => 'Editorial',
                ],
                'reviewer' => [
                    'name' => 'FermatMind Editorial',
                    'organization' => 'FermatMind',
                    'role' => 'Scientific and boundary review',
                ],
                'sources' => $publicSources,
                'claim_mapping' => $claimMapping,
                'limitations' => [
                    '模型研究不等于费马测试产品验证。',
                    '不用于临床诊断、招聘筛选、升学录取或个体结果预测。',
                ],
                'visible_evidence_eligible' => true,
                'schema_eligible' => false,
                'clinical_reviewed' => false,
                'expert_endorsement' => false,
                'review_mode' => 'solo_owner',
            ],
            'internal_links' => $internalLinks,
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'operator_v3_release',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => 'big5-zh-v3-52-page-release',
            'last_reviewed_at' => $this->reviewDate($frontmatter, $pagePath),
        ];
        $runtimeProjectionSha256 = hash('sha256', $this->stableJson($asset));
        $asset['source_hash'] = $runtimeProjectionSha256;

        return [
            'authority_asset_key' => $identity,
            'source_file' => $this->relativePath($root, $pagePath),
            'runtime_projection_sha256' => $runtimeProjectionSha256,
            'asset' => $asset,
            'evidence_claims' => $claims,
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function parseDocument(string $path): array
    {
        $raw = File::get($path);
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/su', $raw, $matches) !== 1) {
            throw new RuntimeException("Markdown frontmatter is invalid: {$path}.");
        }
        $frontmatter = Yaml::parse($matches[1]);
        if (! is_array($frontmatter)) {
            throw new RuntimeException("Markdown frontmatter is not an object: {$path}.");
        }

        return [$frontmatter, (string) $matches[2]];
    }

    /** @return array{sections:list<array<string,mixed>>,faq:list<array<string,string>>} */
    private function parseBody(string $body, string $expectedH1, string $identity): array
    {
        if (preg_match_all('/^# (.+)$/mu', $body, $h1Matches, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException("Exactly one H1 is required for {$identity}.");
        }
        $actualH1 = trim((string) $h1Matches[1][0][0]);
        if ($actualH1 !== $expectedH1) {
            throw new RuntimeException("Frontmatter/Markdown H1 mismatch for {$identity}.");
        }
        if (preg_match('/!\[[^\]]*\]\s*\([^\)]*\)|<img\b/iu', $body) === 1) {
            throw new RuntimeException("Images are forbidden in {$identity}.");
        }
        if (preg_match_all('/^## (.+)$/mu', $body, $h2Matches, PREG_OFFSET_CAPTURE) < 1) {
            throw new RuntimeException("At least one H2 is required for {$identity}.");
        }

        $firstH2Offset = (int) $h2Matches[0][0][1];
        $h1End = (int) $h1Matches[0][0][1] + strlen((string) $h1Matches[0][0][0]);
        $introduction = trim(substr($body, $h1End, $firstH2Offset - $h1End));
        $sections = [];
        $faq = [];
        $sectionOrdinal = 0;
        $count = count($h2Matches[0]);
        for ($index = 0; $index < $count; $index++) {
            $heading = trim((string) $h2Matches[1][$index][0]);
            $headingOffset = (int) $h2Matches[0][$index][1];
            $contentStart = $headingOffset + strlen((string) $h2Matches[0][$index][0]);
            $contentEnd = $index + 1 < $count ? (int) $h2Matches[0][$index + 1][1] : strlen($body);
            $content = trim(substr($body, $contentStart, $contentEnd - $contentStart));
            if ($heading === '常见问题') {
                $faq = $this->parseFaq($content, $identity);

                continue;
            }
            if ($heading === '参考来源') {
                continue;
            }
            if ($content === '') {
                throw new RuntimeException("Empty H2 section {$heading} in {$identity}.");
            }
            if ($sections === [] && $introduction !== '') {
                $content = $introduction."\n\n".$content;
            }
            $sectionOrdinal++;
            $sections[] = [
                'key' => sprintf(
                    'section-%02d-%s',
                    $sectionOrdinal,
                    substr(hash('sha256', $identity.'|'.$sectionOrdinal.'|'.$heading), 0, 10),
                ),
                'kind' => 'rich_text',
                'heading' => $heading,
                'body' => $content,
            ];
        }
        if ($sections === [] || $faq === []) {
            throw new RuntimeException("Sections or FAQ are empty for {$identity}.");
        }

        return ['sections' => $sections, 'faq' => $faq];
    }

    /** @return list<array<string,string>> */
    private function parseFaq(string $content, string $identity): array
    {
        $matched = preg_match_all('/^\*\*(.+?[？?])\*\*\s*$/mu', $content, $matches, PREG_OFFSET_CAPTURE);
        if (! is_int($matched) || $matched < 1) {
            throw new RuntimeException("FAQ questions cannot be parsed for {$identity}.");
        }
        $faq = [];
        $seen = [];
        for ($index = 0; $index < $matched; $index++) {
            $question = trim((string) $matches[1][$index][0]);
            $questionLineOffset = (int) $matches[0][$index][1];
            $answerStart = $questionLineOffset + strlen((string) $matches[0][$index][0]);
            $answerEnd = $index + 1 < $matched ? (int) $matches[0][$index + 1][1] : strlen($content);
            $answer = trim(substr($content, $answerStart, $answerEnd - $answerStart));
            $normalized = preg_replace('/[\s\x{3000}，。！？!?：:；;、“”‘’"\']/u', '', $question) ?: '';
            if ($normalized === '' || isset($seen[$normalized]) || $answer === '') {
                throw new RuntimeException("FAQ is empty or duplicated for {$identity}: {$question}.");
            }
            $seen[$normalized] = true;
            $faq[] = ['question' => $question, 'answer' => $answer];
        }

        return $faq;
    }

    /**
     * @param  list<mixed>  $sourceIds
     * @param  list<array<string,mixed>>  $claims
     * @param  array<string,array<string,mixed>>  $sources
     * @return list<array<string,mixed>>
     */
    private function publicSources(array $sourceIds, array $claims, array $sources): array
    {
        $public = [];
        foreach ($sourceIds as $sourceIdValue) {
            $sourceId = (string) $sourceIdValue;
            $source = $sources[$sourceId] ?? null;
            if (! is_array($source)) {
                throw new RuntimeException("Unknown frontmatter source {$sourceId}.");
            }
            $claimIds = [];
            foreach ($claims as $claim) {
                if (in_array($sourceId, (array) ($claim['source_ids'] ?? []), true)) {
                    $claimIds[] = (string) ($claim['claim_id'] ?? '');
                }
            }
            $lastVerifiedAt = trim((string) ($source['last_verified_at'] ?? ''));
            $year = is_int($source['year'] ?? null)
                ? (int) $source['year']
                : (int) substr($lastVerifiedAt, 0, 4);
            $limitation = trim(implode(' ', array_filter([
                (string) ($source['verification_note'] ?? ''),
                ! is_int($source['year'] ?? null)
                    ? '该官方网页未标注出版年；year 记录本次公开核验的访问快照年，不代表出版年份。'
                    : '',
            ])));
            $public[] = [
                'id' => $sourceId,
                'title' => (string) ($source['title'] ?? ''),
                'author_or_organization' => implode('; ', array_map('strval', (array) ($source['authors'] ?? []))),
                'year' => $year,
                'source_type' => self::SOURCE_TYPE_MAP[(string) ($source['source_type'] ?? '')] ?? 'other_public_source',
                'doi' => $source['doi'] ?? null,
                'public_url' => $source['verified_public_url'] ?? null,
                'accessed_at' => $lastVerifiedAt,
                'claim_ids' => array_values(array_filter($claimIds)),
                'limitation' => $limitation,
            ];
        }

        return $public;
    }

    /** @param list<array<string,mixed>> $claims @return list<array<string,mixed>> */
    private function publicClaimMapping(array $claims): array
    {
        $mapping = [];
        foreach ($claims as $claim) {
            $sourceIds = array_values(is_array($claim['source_ids'] ?? null) ? $claim['source_ids'] : []);
            $mapping[] = [
                'claim_id' => (string) ($claim['claim_id'] ?? ''),
                'source_ids' => $sourceIds,
                'support_level' => (string) ($claim['support_level'] ?? ''),
                'limitation' => trim((string) ($claim['limitations'] ?? '')) ?: null,
            ];
        }

        return $mapping;
    }

    /** @return list<array<string,string>> */
    private function internalLinks(string $body): array
    {
        preg_match_all('/\[([^\]]+)\]\((\/zh\/personality\/big-five[^)\s]*)\)/u', $body, $matches, PREG_SET_ORDER);
        $links = [];
        $seen = [];
        foreach ($matches as $match) {
            $href = trim((string) $match[2]);
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $links[] = [
                'href' => $href,
                'label' => trim((string) $match[1]),
                'intent' => $this->linkIntent($href),
            ];
        }

        return $links;
    }

    private function linkIntent(string $href): string
    {
        if ($href === '/zh/personality/big-five') {
            return 'model_hub';
        }
        if ($href === '/zh/personality/big-five/facets') {
            return 'facet_hub';
        }
        if (str_starts_with($href, '/zh/personality/big-five/facets/')) {
            return 'facet_detail';
        }
        if (preg_match('/-(?:high|mid|low)$/', $href) === 1) {
            return 'range';
        }

        return 'domain';
    }

    /** @param list<array<string,mixed>> $assets */
    private function assertCanonicalGraph(array $assets): void
    {
        $paths = [];
        foreach ($assets as $asset) {
            $path = (string) data_get($asset, 'asset.canonical.path');
            if ($path === '' || isset($paths[$path])) {
                throw new RuntimeException("Duplicate or empty canonical path: {$path}.");
            }
            $paths[$path] = true;
        }
        foreach ($assets as $asset) {
            foreach ((array) data_get($asset, 'asset.internal_links', []) as $link) {
                $href = is_array($link) ? (string) ($link['href'] ?? '') : '';
                if (! isset($paths[$href])) {
                    throw new RuntimeException("Unknown canonical internal link: {$href}.");
                }
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function sourceMap(array $registry): array
    {
        if ((int) ($registry['total_sources'] ?? -1) !== 11 || (int) ($registry['unresolved_count'] ?? -1) !== 0) {
            throw new RuntimeException('Source registry is not the verified 11-source registry.');
        }
        $sources = [];
        foreach ((array) ($registry['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                throw new RuntimeException('Source registry entry is invalid.');
            }
            $sourceId = trim((string) ($source['source_id'] ?? ''));
            if ($sourceId === '' || isset($sources[$sourceId])) {
                throw new RuntimeException("Source registry id is empty or duplicated: {$sourceId}.");
            }
            $sources[$sourceId] = $source;
        }

        return $sources;
    }

    /** @return array<string,array<string,mixed>> */
    private function evidenceMap(string $root): array
    {
        $paths = glob($root.'/evidence/*.claims.json') ?: [];
        if (count($paths) !== self::ASSET_COUNT) {
            throw new RuntimeException('Evidence inventory must contain exactly 52 files.');
        }
        $evidence = [];
        foreach ($paths as $path) {
            $payload = $this->readJson($path, 'evidence');
            $identity = trim((string) ($payload['content_identity'] ?? ''));
            if ($identity === '' || isset($evidence[$identity])) {
                throw new RuntimeException("Evidence identity is empty or duplicated: {$identity}.");
            }
            $evidence[$identity] = $payload;
        }

        return $evidence;
    }

    /** @return list<string> */
    private function pagePaths(string $root): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root.'/pages',
            \FilesystemIterator::SKIP_DOTS,
        ));
        $paths = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.md')) {
                $paths[] = $file->getPathname();
            }
        }
        usort($paths, fn (string $left, string $right): int => strcmp(
            $this->relativePath($root, $left),
            $this->relativePath($root, $right),
        ));
        if (count($paths) !== self::ASSET_COUNT) {
            throw new RuntimeException('Page inventory must contain exactly 52 Markdown files.');
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private function contentTreeHash(string $root, array $paths): string
    {
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($context, $this->relativePath($root, $path));
            hash_update($context, "\0");
            hash_update($context, File::get($path));
            hash_update($context, "\0");
        }

        return hash_final($context);
    }

    private function resolveRoot(string $sourceRoot): string
    {
        $resolved = realpath(trim($sourceRoot));
        if (! is_string($resolved) || ! is_dir($resolved)) {
            throw new RuntimeException('Big Five zh-CN V3 source root does not exist.');
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path, string $label): array
    {
        if (! File::isFile($path)) {
            throw new RuntimeException("Missing {$label}: {$path}.");
        }
        $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException("Invalid {$label}: {$path}.");
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field, string $context): string
    {
        $value = $payload[$field] ?? null;
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new RuntimeException("Required string {$field} is missing in {$context}.");
        }

        return trim((string) $value);
    }

    /** @param array<string,mixed> $payload */
    private function reviewDate(array $payload, string $context): string
    {
        $value = $payload['substantive_updated_at'] ?? null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return gmdate('Y-m-d', (int) $value);
        }
        $date = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException("Required date substantive_updated_at is invalid in {$context}.");
        }

        return $date;
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
    }

    /** @param array<string,mixed> $payload */
    public function stableJson(array $payload): string
    {
        $normalized = $this->sortRecursively($payload);

        return json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}

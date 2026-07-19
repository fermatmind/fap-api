<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\Release;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class BigFiveEn52PackageCompiler
{
    public const SCHEMA_VERSION = 'big-five-en52-release.v1';

    public const RELEASE_ID = 'big-five-en52-52-page-release-20260719';

    public const SOURCE_CONTENT_SHA256 = '056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5';

    public const COHORT_SNAPSHOT_SHA256 = '94449467281cffaccc295bab3bbbb574cf817e461ee2fbae8288eedd9a988b3a';

    public const INPUT_PACKAGE_PAYLOAD_SHA256 = 'f008b3379223274f21d29d7a9dea36646d51e6b63b4fee2fdab69267639cfdfe';

    public const INPUT_PACKAGE_FILE_SHA256 = '022f6220dc0a47149dff24b97737b6556694d5e90ec906c32aa0437e4b341b4c';

    public const RELEASE_PACKAGE_PAYLOAD_SHA256 = '86b11c14a9103b65a1a085de2e4102ab94985a55be83fdcbdcb553ca6cbeed89';

    public const RELEASE_PACKAGE_FILE_SHA256 = '1ee709e7d9880540db072bebb39d7001278518c816cae8265f7af4bc2411659c';

    public const ASSET_COUNT = 52;

    public const CLAIM_COUNT = 170;

    public const FAQ_COUNT = 261;

    public const SOURCE_COUNT = 11;

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
        'hub' => PersonalityPublicContentAsset::ENTITY_HUB,
        'domain' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
        'range' => PersonalityPublicContentAsset::ENTITY_POLARITY,
        'polarity' => PersonalityPublicContentAsset::ENTITY_POLARITY,
        'facet_hub' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
        'facet_detail' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
    ];

    /** @var list<string> */
    private const LEGACY_ALIASES = [
        'emotional-stability',
        'high-agreeableness',
        'high-conscientiousness',
        'high-extraversion',
        'high-neuroticism',
        'high-openness',
        'low-agreeableness',
        'low-conscientiousness',
        'low-extraversion',
        'low-openness',
    ];

    /**
     * @return array{release_package:array<string,mixed>,release_json:string,compile_report:array<string,mixed>}
     */
    public function compile(string $sourceRoot): array
    {
        $root = $this->resolveRoot($sourceRoot);
        $validator = $this->runSourceValidator($root);
        $manifestPath = $root.'/package-manifest.json';
        $manifest = $this->readJson($manifestPath, 'English final package manifest');
        $this->assertManifest($manifest, $manifestPath);

        $canonicalManifest = $this->readJson(
            $root.'/manifests/canonical-manifest.en-US.json',
            'English canonical manifest',
        );
        $canonicalEntries = $this->canonicalEntryMap($canonicalManifest);
        $sources = $this->sourceMap($this->readJson(
            $root.'/authority/source-registry.en-US.json',
            'English source registry',
        ));

        $assets = [];
        $familyCounts = [];
        $claimCount = 0;
        $faqCount = 0;
        foreach ($this->lockedFiles($root, $manifest) as $lockedFile) {
            $compiled = $this->compilePage($root, $lockedFile, $canonicalEntries, $sources);
            $assets[] = $compiled;
            $entityType = (string) data_get($compiled, 'asset.entity_type');
            $familyCounts[$entityType] = ($familyCounts[$entityType] ?? 0) + 1;
            $claimCount += count((array) ($compiled['evidence_claims'] ?? []));
            $faqCount += count((array) data_get($compiled, 'asset.faq', []));
        }

        usort($assets, static fn (array $left, array $right): int => strcmp(
            (string) data_get($left, 'asset.canonical.path'),
            (string) data_get($right, 'asset.canonical.path'),
        ));
        ksort($familyCounts);
        $expectedFamilies = self::FAMILY_COUNTS;
        ksort($expectedFamilies);
        if ($familyCounts !== $expectedFamilies) {
            throw new RuntimeException('English release family counts must equal 1/5/15/1/30.');
        }
        if (count($assets) !== self::ASSET_COUNT || $claimCount !== self::CLAIM_COUNT || $faqCount !== self::FAQ_COUNT) {
            throw new RuntimeException('English release asset, claim, or FAQ count drifted.');
        }
        $this->assertCanonicalGraph($assets);

        $basePackage = [
            'schema_version' => self::SCHEMA_VERSION,
            'release_id' => self::RELEASE_ID,
            'source_package' => 'fermatmind-big-five-en52-final',
            'input_hashes' => [
                'source_content_sha256' => self::SOURCE_CONTENT_SHA256,
                'cohort_snapshot_sha256' => self::COHORT_SNAPSHOT_SHA256,
                'final_package_payload_sha256' => self::INPUT_PACKAGE_PAYLOAD_SHA256,
                'final_package_file_sha256' => self::INPUT_PACKAGE_FILE_SHA256,
            ],
            'editorial_locale' => 'en-US',
            'locale' => 'en',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'org_id' => 0,
            'asset_count' => count($assets),
            'family_counts' => $familyCounts,
            'claims_count' => $claimCount,
            'faq_count' => $faqCount,
            'source_count' => count($sources),
            'media_supported' => false,
            'search_submit_allowed' => false,
            'legacy_alias_content_page_count' => 0,
            'assets' => $assets,
        ];
        $payloadSha256 = hash('sha256', $this->stableJson($basePackage));
        $releasePackage = [...$basePackage, 'package_payload_sha256' => $payloadSha256];
        $releaseJson = $this->stableJson($releasePackage);
        $fileSha256 = hash('sha256', $releaseJson);
        if (! hash_equals(self::RELEASE_PACKAGE_PAYLOAD_SHA256, $payloadSha256)
            || ! hash_equals(self::RELEASE_PACKAGE_FILE_SHA256, $fileSha256)) {
            throw new RuntimeException('RUNTIME_RELEASE_DRIFT: compiled English release package hash mismatch.');
        }

        return [
            'release_package' => $releasePackage,
            'release_json' => $releaseJson,
            'compile_report' => [
                'ok' => true,
                'status' => 'PASS_BIG_FIVE_EN52_PACKAGE_COMPILE',
                'release_id' => self::RELEASE_ID,
                'editorial_locale' => 'en-US',
                'backend_locale' => 'en',
                'input_hashes' => $basePackage['input_hashes'],
                'package_payload_sha256' => $payloadSha256,
                'package_file_sha256' => $fileSha256,
                'asset_count' => count($assets),
                'family_counts' => $familyCounts,
                'claims_count' => $claimCount,
                'faq_count' => $faqCount,
                'source_count' => count($sources),
                'source_validator_status' => $validator['status'],
                'database_writes' => 0,
                'cms_writes' => 0,
                'media_library_writes' => 0,
                'search_submission_writes' => 0,
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest, string $manifestPath): void
    {
        if (! hash_equals(self::INPUT_PACKAGE_FILE_SHA256, (string) hash_file('sha256', $manifestPath))) {
            throw new RuntimeException('PACKAGE_INPUT_DRIFT: English final package file SHA-256 mismatch.');
        }
        $expected = [
            'schema_version' => 'big-five-en52-package-manifest.v1',
            'package_id' => 'fermatmind-big-five-en52-final',
            'locale' => 'en-US',
            'backend_locale_contract' => 'en',
            'source_content_sha256' => self::SOURCE_CONTENT_SHA256,
            'cohort_snapshot_sha256' => self::COHORT_SNAPSHOT_SHA256,
            'package_payload_sha256' => self::INPUT_PACKAGE_PAYLOAD_SHA256,
            'page_count' => self::ASSET_COUNT,
            'claim_file_count' => self::ASSET_COUNT,
        ];
        foreach ($expected as $field => $value) {
            if (($manifest[$field] ?? null) !== $value) {
                throw new RuntimeException("English final package manifest field {$field} drifted.");
            }
        }
        $constraints = is_array($manifest['constraints'] ?? null) ? $manifest['constraints'] : [];
        foreach ([
            'pending_page_count', 'word_count_mismatch_count', 'source_id_conflict_count',
            'visible_reference_registry_mismatch_count', 'empty_claim_file_count',
            'invalid_claim_source_id_count', 'true_internal_link_violation_count',
            'unregistered_external_link_count', 'body_media_reference_count',
            'unsupported_rendered_link_syntax_count', 'unexpected_claim_id_count',
            'missing_claim_id_count', 'duplicate_claim_id_count', 'frontmatter_manifest_mismatch_count',
            'sidecar_metadata_mismatch_count', 'claim_row_schema_failure_count',
            'claim_authority_value_mismatch_count', 'cohort_snapshot_mismatch_count',
            'source_release_failure_count', 'authority_input_failure_count',
            'committed_package_input_mismatch_count', 'unknown_canonical_link_count',
            'zh_internal_link_count', 'unresolved_scientific_blocker_count',
            'manifest_audit_failure_count', 'translation_equivalence_failure_count',
            'seo_geo_failure_count', 'faq_failure_count', 'stale_qa_report_count',
            'substantive_body_exact_duplicate_count', 'substantive_high_similarity_count',
            'untranslated_public_chinese_fragment_count', 'legacy_alias_page_count',
        ] as $zeroField) {
            if ((int) ($constraints[$zeroField] ?? -1) !== 0) {
                throw new RuntimeException("English final package gate {$zeroField} must be zero.");
            }
        }
        if (($constraints['qa_status'] ?? null) !== 'PASS'
            || (int) ($constraints['faq_count'] ?? -1) !== self::FAQ_COUNT
            || ($constraints['cms_write_allowed'] ?? null) !== false
            || ($constraints['publish_allowed'] ?? null) !== false
            || ($constraints['writes_committed'] ?? null) !== false) {
            throw new RuntimeException('English final package QA or zero-write boundary drifted.');
        }
    }

    /** @return array<string,mixed> */
    private function runSourceValidator(string $root): array
    {
        $process = new Process(['node', 'validate-authority.mjs', '--expected-translated=52'], $root);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('English authority validator failed.');
        }
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result)
            || ($result['status'] ?? null) !== 'PASS_BIG_FIVE_EN52_TRANSLATION_AUTHORITY'
            || ($result['qa_status'] ?? null) !== 'PASS'
            || ($result['errors'] ?? null) !== []) {
            throw new RuntimeException('English authority validator did not return PASS.');
        }
        foreach ([
            'target_en_page_count' => self::ASSET_COUNT,
            'translated_page_count' => self::ASSET_COUNT,
            'pending_page_count' => 0,
            'alias_target_count' => 0,
            'unknown_identity_count' => 0,
            'invalid_internal_link_count' => 0,
            'zh_internal_link_count' => 0,
            'unknown_canonical_link_count' => 0,
            'cms_write_count' => 0,
            'production_write_count' => 0,
            'media_library_write_count' => 0,
            'search_submission_write_count' => 0,
        ] as $field => $value) {
            if ((int) ($result[$field] ?? -1) !== $value) {
                throw new RuntimeException("English authority validator metric {$field} drifted.");
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array<string,string>>
     */
    private function lockedFiles(string $root, array $manifest): array
    {
        $files = array_values(is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
        if (count($files) !== self::ASSET_COUNT) {
            throw new RuntimeException('English final package must lock exactly 52 page/claim pairs.');
        }
        $locked = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                throw new RuntimeException('English final package file row is invalid.');
            }
            $row = [];
            foreach (['page_identity', 'page_path', 'page_sha256', 'claim_path', 'claim_sha256'] as $field) {
                $row[$field] = $this->requiredString($file, $field, 'package-manifest.json');
            }
            foreach (['page' => 'page_path', 'claim' => 'claim_path'] as $label => $pathField) {
                $path = $root.'/'.$row[$pathField];
                $shaField = $label.'_sha256';
                if (! File::isFile($path) || ! hash_equals($row[$shaField], (string) hash_file('sha256', $path))) {
                    throw new RuntimeException("PACKAGE_INPUT_DRIFT: locked {$label} file mismatch for {$row['page_identity']}.");
                }
            }
            $locked[] = $row;
        }
        usort($locked, static fn (array $left, array $right): int => strcmp($left['page_path'], $right['page_path']));

        return $locked;
    }

    /**
     * @param  array<string,string>  $locked
     * @param  array<string,array<string,mixed>>  $canonicalEntries
     * @param  array<string,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function compilePage(string $root, array $locked, array $canonicalEntries, array $sources): array
    {
        $pagePath = $root.'/'.$locked['page_path'];
        [$frontmatter, $body] = $this->parseDocument($pagePath);
        $identity = $this->requiredString($frontmatter, 'content_identity', $pagePath);
        if ($identity !== $locked['page_identity']) {
            throw new RuntimeException("Locked identity mismatch for {$pagePath}.");
        }
        $assetType = $this->requiredString($frontmatter, 'asset_type', $pagePath);
        $entityType = self::ASSET_TYPE_TO_ENTITY_TYPE[$assetType] ?? null;
        if ($entityType === null) {
            throw new RuntimeException("Unsupported English asset type {$assetType}.");
        }
        if ($this->requiredString($frontmatter, 'locale', $pagePath) !== 'en-US'
            || $this->requiredString($frontmatter, 'backend_locale_contract', $pagePath) !== 'en') {
            throw new RuntimeException("English locale normalization contract drifted for {$identity}.");
        }
        if (($frontmatter['media_supported'] ?? null) !== false
            || ($frontmatter['clinical_reviewed'] ?? null) !== false
            || ($frontmatter['expert_endorsement'] ?? null) !== false) {
            throw new RuntimeException("Text-only or evidence boundary drifted for {$identity}.");
        }
        $slug = $this->requiredString($frontmatter, 'slug', $pagePath);
        $canonicalPath = $this->requiredString($frontmatter, 'canonical_path', $pagePath);
        $canonicalEntry = $canonicalEntries[$identity] ?? null;
        if (! is_array($canonicalEntry)) {
            throw new RuntimeException("Canonical manifest entry is missing for {$identity}.");
        }
        $entityKey = $this->requiredString($canonicalEntry, 'entity_key', 'canonical manifest');
        if (($canonicalEntry['entity_type'] ?? null) !== $entityType
            || ($canonicalEntry['entity_key'] ?? null) !== $entityKey
            || ($canonicalEntry['en_slug'] ?? null) !== $slug
            || ($canonicalEntry['en_canonical_path'] ?? null) !== $canonicalPath) {
            throw new RuntimeException("Canonical manifest mismatch for {$identity}.");
        }
        $expectedPath = BigFiveCanonicalRouteCatalog::expectedPath('en', $entityType, $entityKey);
        if ($expectedPath === null || $expectedPath !== $canonicalPath) {
            throw new RuntimeException("Backend canonical catalog mismatch for {$identity}.");
        }
        $this->assertNoAlias($identity, $slug, $canonicalPath, $body);

        $parsed = $this->parseBody($body, $this->requiredString($frontmatter, 'h1', $pagePath), $identity);
        $evidence = $this->readJson($root.'/'.$locked['claim_path'], "English claims for {$identity}");
        if (($evidence['page_identity'] ?? null) !== $identity
            || ($evidence['translation_status'] ?? null) !== 'completed') {
            throw new RuntimeException("English claim authority identity or status drifted for {$identity}.");
        }
        $claims = array_values(is_array($evidence['claims'] ?? null) ? $evidence['claims'] : []);
        if ((int) ($evidence['claim_count'] ?? -1) !== count($claims)
            || (int) ($evidence['faq_count_en'] ?? -1) !== count($parsed['faq'])) {
            throw new RuntimeException("English claim or FAQ count drifted for {$identity}.");
        }
        foreach ($claims as $claim) {
            if (! is_array($claim)
                || ($claim['page_identity'] ?? null) !== $identity
                || trim((string) ($claim['claim_id'] ?? '')) === ''
                || trim((string) ($claim['visible_claim'] ?? '')) === ''
                || trim((string) ($claim['boundary'] ?? '')) === '') {
                throw new RuntimeException("English claim row is invalid for {$identity}.");
            }
            foreach ((array) ($claim['source_ids'] ?? []) as $sourceId) {
                if (! isset($sources[(string) $sourceId])) {
                    throw new RuntimeException("Unknown English source {$sourceId} for {$identity}.");
                }
            }
        }
        $sourceIds = array_values(is_array($frontmatter['source_ids'] ?? null) ? $frontmatter['source_ids'] : []);
        $evidenceSourceIds = array_values((array) ($evidence['source_ids_en'] ?? []));
        sort($sourceIds);
        sort($evidenceSourceIds);
        if ($sourceIds !== $evidenceSourceIds) {
            throw new RuntimeException("English source identity set drifted for {$identity}.");
        }
        $publicSources = $this->publicSources($sourceIds, $claims, $sources);
        $internalLinks = $this->internalLinks($body);

        $asset = [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'code' => $entityKey,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => 'en',
            'title' => $this->requiredString($frontmatter, 'title', $pagePath),
            'summary' => $this->requiredString($frontmatter, 'excerpt', $pagePath),
            'content_sections' => $parsed['sections'],
            'seo' => [
                'title' => $this->requiredString($frontmatter, 'seo_title', $pagePath),
                'description' => $this->requiredString($frontmatter, 'seo_description', $pagePath),
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_path' => $canonicalPath,
            'canonical' => ['path' => $canonicalPath],
            'hreflang' => [],
            'faq' => $parsed['faq'],
            'schema' => [],
            'method_boundary' => [
                'content_method' => $this->requiredString($frontmatter, 'content_method', $pagePath),
                'clinical_reviewed' => false,
                'expert_endorsement' => false,
                'limitations' => [
                    'For self-understanding and education; not clinical diagnosis, hiring screening, admission, or individual-outcome prediction.',
                    'This package publishes no product-level reliability, validity, norm, percentile, or predictive claim.',
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
                'editorial_locale' => 'en-US',
                'backend_locale' => 'en',
                'content_method' => $this->requiredString($frontmatter, 'content_method', $pagePath),
                'author' => ['name' => 'FermatMind Editorial', 'organization' => 'FermatMind', 'role' => 'Editorial'],
                'reviewer' => ['name' => 'FermatMind Editorial', 'organization' => 'FermatMind', 'role' => 'Scientific and boundary review'],
                'sources' => $publicSources,
                'claim_mapping' => array_map(static fn (array $claim): array => [
                    'claim_id' => (string) $claim['claim_id'],
                    'source_ids' => array_values((array) ($claim['source_ids'] ?? [])),
                    'confidence' => (string) ($claim['confidence'] ?? ''),
                    'limitation' => (string) $claim['boundary'],
                ], $claims),
                'limitations' => [
                    'General Big Five research does not establish FermatMind product validation.',
                    'Not for diagnosis, screening, admission, or individual-outcome prediction.',
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
            'review_state' => 'operator_en52_release',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => self::RELEASE_ID,
            'last_reviewed_at' => $this->reviewDate($frontmatter, $pagePath),
        ];
        $this->assertTextOnly($asset, $identity);
        $runtimeSha = hash('sha256', $this->stableJson($asset));
        $asset['source_hash'] = $runtimeSha;

        return [
            'authority_asset_key' => $identity,
            'source_file' => $locked['page_path'],
            'source_claim_file' => $locked['claim_path'],
            'runtime_projection_sha256' => $runtimeSha,
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
        $yaml = preg_replace_callback(
            '/^([a-z0-9_]+): (.+: .+)$/mi',
            static fn (array $line): string => in_array(substr($line[2], 0, 1), ['"', "'"], true)
                ? $line[0]
                : $line[1].': '.json_encode(
                    $line[2],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            $matches[1],
        );
        $frontmatter = Yaml::parse((string) $yaml);
        if (! is_array($frontmatter)) {
            throw new RuntimeException("Markdown frontmatter is not an object: {$path}.");
        }

        return [$frontmatter, (string) $matches[2]];
    }

    /** @return array{sections:list<array<string,mixed>>,faq:list<array<string,string>>} */
    private function parseBody(string $body, string $expectedH1, string $identity): array
    {
        if (preg_match_all('/^# (.+)$/mu', $body, $h1, PREG_OFFSET_CAPTURE) !== 1
            || trim((string) $h1[1][0][0]) !== $expectedH1) {
            throw new RuntimeException("Exactly one matching H1 is required for {$identity}.");
        }
        if (preg_match('/!\[[^\]]*\]\s*\([^\)]*\)|<img\b|<picture\b|<source\b/iu', $body) === 1) {
            throw new RuntimeException("Markdown and HTML images are forbidden in {$identity}.");
        }
        if (preg_match_all('/^## (.+)$/mu', $body, $h2, PREG_OFFSET_CAPTURE) < 1) {
            throw new RuntimeException("At least one H2 is required for {$identity}.");
        }
        $introStart = (int) $h1[0][0][1] + strlen((string) $h1[0][0][0]);
        $intro = trim(substr($body, $introStart, (int) $h2[0][0][1] - $introStart));
        $sections = [];
        $faq = [];
        for ($index = 0, $count = count($h2[0]); $index < $count; $index++) {
            $heading = trim((string) $h2[1][$index][0]);
            $start = (int) $h2[0][$index][1] + strlen((string) $h2[0][$index][0]);
            $end = $index + 1 < $count ? (int) $h2[0][$index + 1][1] : strlen($body);
            $content = trim(substr($body, $start, $end - $start));
            if ($heading === 'Frequently Asked Questions') {
                $faq = $this->parseFaq($content, $identity);

                continue;
            }
            if ($heading === 'References') {
                continue;
            }
            if ($content === '') {
                throw new RuntimeException("Empty section {$heading} in {$identity}.");
            }
            if ($sections === [] && $intro !== '') {
                $content = $intro."\n\n".$content;
            }
            $ordinal = count($sections) + 1;
            $sections[] = [
                'key' => sprintf('section-%02d-%s', $ordinal, substr(hash('sha256', "{$identity}|{$ordinal}|{$heading}"), 0, 10)),
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
        $matched = preg_match_all('/^\*\*(.+?\?)\*\*\s*$/mu', $content, $questions, PREG_OFFSET_CAPTURE);
        if (! is_int($matched) || $matched < 1) {
            throw new RuntimeException("FAQ questions cannot be parsed for {$identity}.");
        }
        $faq = [];
        $seen = [];
        for ($index = 0; $index < $matched; $index++) {
            $question = trim((string) $questions[1][$index][0]);
            $start = (int) $questions[0][$index][1] + strlen((string) $questions[0][$index][0]);
            $end = $index + 1 < $matched ? (int) $questions[0][$index + 1][1] : strlen($content);
            $answer = trim(substr($content, $start, $end - $start));
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $question));
            if ($normalized === '' || isset($seen[$normalized]) || $answer === '') {
                throw new RuntimeException("FAQ is empty or duplicated for {$identity}.");
            }
            $seen[$normalized] = true;
            $faq[] = ['question' => $question, 'answer' => $answer];
        }

        return $faq;
    }

    /** @param list<mixed> $sourceIds @param list<array<string,mixed>> $claims @param array<string,array<string,mixed>> $sources */
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
                    $claimIds[] = (string) $claim['claim_id'];
                }
            }
            $public[] = [
                'id' => $sourceId,
                'title' => (string) ($source['title'] ?? ''),
                'author_or_organization' => implode('; ', array_map('strval', (array) ($source['authors'] ?? []))),
                'year' => (int) ($source['year'] ?? 0),
                'source_type' => (string) ($source['source_type'] ?? 'other_public_source'),
                'doi' => $source['doi'] ?? null,
                'public_url' => $source['verified_public_url'] ?? null,
                'accessed_at' => $source['last_verified_at'] ?? null,
                'claim_ids' => $claimIds,
                'limitation' => (string) ($source['verification_note'] ?? ''),
            ];
        }

        return $public;
    }

    /** @return list<array<string,string>> */
    private function internalLinks(string $body): array
    {
        preg_match_all('/\[([^\]]+)\]\((\/en\/personality\/big-five[^)\s]*)\)/u', $body, $matches, PREG_SET_ORDER);
        $links = [];
        $seen = [];
        foreach ($matches as $match) {
            $href = trim((string) $match[2]);
            if (isset($seen[$href])) {
                continue;
            }
            $seen[$href] = true;
            $links[] = ['href' => $href, 'label' => trim((string) $match[1]), 'intent' => $this->linkIntent($href)];
        }

        return $links;
    }

    private function linkIntent(string $href): string
    {
        if ($href === '/en/personality/big-five') {
            return 'model_hub';
        }
        if ($href === '/en/personality/big-five/facets') {
            return 'facet_hub';
        }
        if (str_starts_with($href, '/en/personality/big-five/facets/')) {
            return 'facet_detail';
        }

        return preg_match('/-(?:high|mid|low)$/', $href) === 1 ? 'range' : 'domain';
    }

    /** @param list<array<string,mixed>> $assets */
    private function assertCanonicalGraph(array $assets): void
    {
        $paths = [];
        foreach ($assets as $descriptor) {
            $path = (string) data_get($descriptor, 'asset.canonical.path');
            if ($path === '' || isset($paths[$path])) {
                throw new RuntimeException("Duplicate or empty English canonical path {$path}.");
            }
            $paths[$path] = true;
        }
        foreach ($assets as $descriptor) {
            foreach ((array) data_get($descriptor, 'asset.internal_links', []) as $link) {
                $href = is_array($link) ? (string) ($link['href'] ?? '') : '';
                if (! isset($paths[$href])) {
                    throw new RuntimeException("Unknown English canonical internal link {$href}.");
                }
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function canonicalEntryMap(array $manifest): array
    {
        if (($manifest['target_editorial_locale'] ?? null) !== 'en-US'
            || ($manifest['backend_locale_contract'] ?? null) !== 'en'
            || (int) data_get($manifest, 'counts.page_count', -1) !== self::ASSET_COUNT
            || (int) data_get($manifest, 'counts.legacy_alias_page_count', -1) !== 0
            || data_get($manifest, 'constraints.media_supported') !== false) {
            throw new RuntimeException('English canonical manifest boundary drifted.');
        }
        $entries = [];
        foreach ((array) ($manifest['entries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('English canonical manifest row is invalid.');
            }
            $identity = $this->requiredString($entry, 'page_identity', 'canonical manifest');
            if (isset($entries[$identity])) {
                throw new RuntimeException("Duplicate English canonical identity {$identity}.");
            }
            $entries[$identity] = $entry;
        }
        if (count($entries) !== self::ASSET_COUNT) {
            throw new RuntimeException('English canonical manifest must contain exactly 52 entries.');
        }

        return $entries;
    }

    /** @return array<string,array<string,mixed>> */
    private function sourceMap(array $registry): array
    {
        if (($registry['locale'] ?? null) !== 'en-US'
            || (int) ($registry['total_sources'] ?? -1) !== self::SOURCE_COUNT
            || (int) ($registry['unresolved_source_identity_count'] ?? -1) !== 0) {
            throw new RuntimeException('English source registry must contain 11 resolved sources.');
        }
        $sources = [];
        foreach ((array) ($registry['sources'] ?? []) as $source) {
            if (! is_array($source)) {
                throw new RuntimeException('English source registry row is invalid.');
            }
            $id = $this->requiredString($source, 'source_id', 'English source registry');
            if (isset($sources[$id])) {
                throw new RuntimeException("Duplicate English source {$id}.");
            }
            $sources[$id] = $source;
        }
        if (count($sources) !== self::SOURCE_COUNT) {
            throw new RuntimeException('English source registry count drifted.');
        }

        return $sources;
    }

    private function assertNoAlias(string $identity, string $slug, string $canonical, string $body): void
    {
        foreach (self::LEGACY_ALIASES as $alias) {
            if ($identity === $alias || basename($slug) === $alias || str_contains($canonical, '/'.$alias)
                || preg_match('#/en/personality/big-five/(?:[^)\s]+/)?'.preg_quote($alias, '#').'(?=[)\s]|$)#', $body) === 1) {
                throw new RuntimeException("Legacy alias {$alias} is forbidden in English descriptors and links.");
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertTextOnly(array $payload, string $identity): void
    {
        $forbidden = ['hero', 'hero_image', 'inline_media', 'media', 'og_image', 'twitter_image', 'image', 'images'];
        $walk = function (mixed $value) use (&$walk, $forbidden, $identity): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $nested) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    throw new RuntimeException("Media field {$key} is forbidden in English asset {$identity}.");
                }
                $walk($nested);
            }
        };
        $walk($payload);
    }

    private function resolveRoot(string $sourceRoot): string
    {
        $resolved = realpath(trim($sourceRoot));
        if (! is_string($resolved) || ! is_dir($resolved)) {
            throw new RuntimeException('Big Five EN52 source root does not exist.');
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
            throw new RuntimeException("Required substantive_updated_at is invalid in {$context}.");
        }

        return $date;
    }

    /** @param array<string,mixed> $payload */
    public function stableJson(array $payload): string
    {
        return json_encode(
            $this->sortRecursively($payload),
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

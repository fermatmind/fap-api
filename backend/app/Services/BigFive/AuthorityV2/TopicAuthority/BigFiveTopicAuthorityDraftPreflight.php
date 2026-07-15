<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\TopicAuthority;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveTopicAuthorityDraftPreflight
{
    public const SCHEMA_VERSION = 'big5-topic-authority-draft-revision.v1';

    public const TOPIC_COUNT = 2;

    public const SCALE_CODE = 'BIG5_OCEAN';

    public const PRIMARY_SLUG = 'big-five-personality-test-ocean-model';

    /** @var array<string,string> */
    private const CANONICAL_PATHS = [
        'en' => '/en/tests/big-five-personality-test-ocean-model',
        'zh-CN' => '/zh/tests/big-five-personality-test-ocean-model',
    ];

    /** @var list<string> */
    private const SOURCE_IDS = [
        'academic.goldberg-1990-big-five-structure',
        'academic.soto-john-2017-bfi2',
    ];

    /** @var list<string> */
    private const FORBIDDEN_VISIBLE_COPY = [
        'seo cluster',
        'seo clusters',
        'trait-based recommendation',
        'trait-by-trait recommendation',
        'career recommendation',
        'career matcher',
        '职业推荐',
        '职业匹配',
        '特质推荐',
        'seo 主题簇',
        'mbti',
    ];

    /** @return array<string,mixed> */
    public function preflight(string $packagePath, bool $requireRegistry = true): array
    {
        [$package, $resolvedPackage] = $this->readJson($packagePath, 'Topic draft-revision package');
        $recordedHash = $this->readRecordedHash($resolvedPackage);
        $calculatedHash = $this->canonicalSha256($package);
        if (! hash_equals($recordedHash, $calculatedHash)) {
            throw new RuntimeException('Topic draft-revision package hash mismatch.');
        }

        $this->assertSourceInventory($package);
        $topics = $this->validatePackage($package);
        $registry = $requireRegistry ? $this->assertCanonicalRegistryAuthority() : [
            'checked' => false,
            'status' => 'package_source_lock_only',
            'scale_code' => self::SCALE_CODE,
            'primary_slug' => self::PRIMARY_SLUG,
        ];

        return [
            'ok' => true,
            'status' => $requireRegistry
                ? 'PASS_READ_ONLY_TOPIC_DRAFT_PREFLIGHT'
                : 'PASS_PACKAGE_ONLY_TOPIC_DRAFT_PREFLIGHT',
            'mode' => 'working_revision_candidates_zero_write',
            'package_path' => $resolvedPackage,
            'package_sha256' => $calculatedHash,
            'counts' => [
                'topic_candidates' => count($topics),
                'working_revision_candidates' => count($topics),
                'promotion_eligible' => 0,
                'blocked' => count($topics),
            ],
            'canonical_registry' => $registry,
            'canonical_test_targets' => collect($topics)
                ->mapWithKeys(static fn (array $topic): array => [
                    (string) $topic['locale'] => (string) data_get($topic, 'snapshot.authority.canonical_test_target.canonical_path'),
                ])->all(),
            'blockers' => array_values(array_unique(array_merge(...array_map(
                static fn (array $topic): array => array_values($topic['blockers']),
                $topics,
            )))),
            'actions' => $package['actions'],
            'topics' => array_map(static fn (array $topic): array => [
                'asset_id' => $topic['asset_id'],
                'locale' => $topic['locale'],
                'route' => $topic['route'],
                'target_resolution' => data_get($topic, 'revision_contract.target_resolution'),
                'revision_operation' => data_get($topic, 'revision_contract.revision_operation'),
                'workflow_state' => data_get($topic, 'revision_contract.workflow_state'),
                'promotion_authorized' => false,
            ], $topics),
        ];
    }

    /** @param array<string,mixed> $package @return list<array<string,mixed>> */
    private function validatePackage(array $package): array
    {
        if (($package['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($package['mode'] ?? null) !== 'backend_authoritative_working_revision_candidates_zero_write'
            || ($package['topic_count'] ?? null) !== self::TOPIC_COUNT) {
            throw new RuntimeException('Topic draft-revision package identity mismatch.');
        }

        $topics = $package['topics'] ?? null;
        if (! is_array($topics) || ! array_is_list($topics) || count($topics) !== self::TOPIC_COUNT) {
            throw new RuntimeException('Topic draft-revision package must contain exactly two candidates.');
        }

        $byLocale = [];
        foreach ($topics as $topic) {
            if (! is_array($topic)) {
                throw new RuntimeException('Topic draft-revision candidate must be an object.');
            }
            $locale = trim((string) ($topic['locale'] ?? ''));
            if (! isset(self::CANONICAL_PATHS[$locale]) || isset($byLocale[$locale])) {
                throw new RuntimeException('Topic locale coverage must contain unique en and zh-CN candidates.');
            }
            $this->assertTopic($topic, $locale);
            $byLocale[$locale] = $topic;
        }
        ksort($byLocale);
        if (array_keys($byLocale) !== ['en', 'zh-CN']) {
            throw new RuntimeException('Topic locale coverage mismatch.');
        }

        $actions = $package['actions'] ?? null;
        if (! is_array($actions) || ($actions['database_reads'] ?? null) !== 0) {
            throw new RuntimeException('Topic package actions are malformed.');
        }
        foreach ($actions as $name => $count) {
            if ($name !== 'database_reads' && $count !== 0) {
                throw new RuntimeException('Topic package authorizes a forbidden action: '.$name.'.');
            }
        }

        return array_values($byLocale);
    }

    /** @param array<string,mixed> $topic */
    private function assertTopic(array $topic, string $locale): void
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $route = '/'.$segment.'/topics/big-five';
        if (($topic['asset_id'] ?? null) !== 'topic_hub:'.$locale.':'.$route
            || ($topic['route'] ?? null) !== $route
            || ($topic['authority_surface'] ?? null) !== 'CMS topic_profiles'
            || ($topic['identity'] ?? null) !== ['org_id' => 0, 'topic_code' => 'big-five', 'slug' => 'big-five', 'locale' => $locale]) {
            throw new RuntimeException('Topic identity mismatch for '.$locale.'.');
        }

        $contract = $topic['revision_contract'] ?? null;
        if (! is_array($contract)
            || ($contract['target_resolution'] ?? null) !== 'existing_identity_or_block'
            || ($contract['revision_operation'] ?? null) !== 'create_isolated_working_revision'
            || ($contract['workflow_state'] ?? null) !== 'draft_pending_manual_review'
            || ($contract['preserve_primary_record_identity'] ?? null) !== true
            || ($contract['preserve_existing_public_runtime'] ?? null) !== true
            || ($contract['public_reader_selects_working_revision'] ?? null) !== false
            || ($contract['promotion_authorized'] ?? null) !== false) {
            throw new RuntimeException('Topic revision contract is not fail closed for '.$locale.'.');
        }

        $snapshot = $topic['snapshot'] ?? null;
        if (! is_array($snapshot)) {
            throw new RuntimeException('Topic snapshot missing for '.$locale.'.');
        }
        $this->assertProfile($snapshot['profile'] ?? null, $locale, $route);
        $this->assertSections($snapshot['sections'] ?? null, $locale);
        $this->assertTestEntry($snapshot['entries'] ?? null, $locale);
        $this->assertSeo($snapshot['seo_meta'] ?? null, $route);
        $this->assertAuthority($snapshot['authority'] ?? null, $locale);

        $gates = $topic['gates'] ?? null;
        if (! is_array($gates) || array_filter($gates, static fn (mixed $value): bool => $value !== false) !== []) {
            throw new RuntimeException('Topic gates must remain false for '.$locale.'.');
        }
        $blockers = $topic['blockers'] ?? null;
        if (! is_array($blockers)
            || ! in_array('manual_review_missing', $blockers, true)
            || ! in_array('approved_media_missing', $blockers, true)
            || ! in_array('promotion_not_authorized', $blockers, true)) {
            throw new RuntimeException('Topic blockers are incomplete for '.$locale.'.');
        }
    }

    private function assertProfile(mixed $profile, string $locale, string $route): void
    {
        if (! is_array($profile)
            || ($profile['org_id'] ?? null) !== 0
            || ($profile['topic_code'] ?? null) !== 'big-five'
            || ($profile['slug'] ?? null) !== 'big-five'
            || ($profile['locale'] ?? null) !== $locale
            || ($profile['status'] ?? null) !== 'draft'
            || ($profile['is_public'] ?? null) !== false
            || ($profile['is_indexable'] ?? null) !== false
            || ! array_key_exists('published_at', $profile)
            || $profile['published_at'] !== null
            || ! array_key_exists('cover_image_url', $profile)
            || $profile['cover_image_url'] !== null) {
            throw new RuntimeException('Topic profile snapshot is not a fail-closed draft for '.$route.'.');
        }
        foreach (['title', 'subtitle', 'excerpt', 'hero_kicker'] as $field) {
            $this->assertVisibleCopy($profile[$field] ?? null, $locale.'.profile.'.$field);
        }
    }

    private function assertSections(mixed $sections, string $locale): void
    {
        if (! is_array($sections) || ! array_is_list($sections)
            || array_column($sections, 'section_key') !== ['overview', 'key_concepts', 'why_it_matters', 'who_should_read']) {
            throw new RuntimeException('Topic sections mismatch for '.$locale.'.');
        }
        foreach ($sections as $section) {
            if (! is_array($section) || ($section['is_enabled'] ?? null) !== true) {
                throw new RuntimeException('Topic section must be enabled for '.$locale.'.');
            }
            $this->assertVisibleCopy($section['title'] ?? null, $locale.'.section.title');
            $this->assertVisibleCopy($section['body_md'] ?? null, $locale.'.section.body_md');
        }
        if (data_get($sections, '2.payload_json.career_claim_mode') !== 'supplementary_explanation_only'
            || data_get($sections, '2.payload_json.recommendation_authority') !== false) {
            throw new RuntimeException('Topic Career boundary mismatch for '.$locale.'.');
        }
    }

    private function assertTestEntry(mixed $entries, string $locale): void
    {
        if (! is_array($entries) || count($entries) !== 1 || ! is_array($entries[0] ?? null)) {
            throw new RuntimeException('Topic must contain exactly one test entry for '.$locale.'.');
        }
        $entry = $entries[0];
        if (($entry['entry_type'] ?? null) !== 'scale'
            || ($entry['group_key'] ?? null) !== 'tests'
            || ($entry['target_key'] ?? null) !== self::SCALE_CODE
            || ($entry['target_locale'] ?? null) !== $locale
            || ! array_key_exists('target_url_override', $entry)
            || $entry['target_url_override'] !== null
            || data_get($entry, 'payload_json.canonical_authority') !== 'scales_registry.primary_slug'
            || data_get($entry, 'payload_json.expected_canonical_path') !== self::CANONICAL_PATHS[$locale]) {
            throw new RuntimeException('Topic test target does not resolve through Big Five canonical authority for '.$locale.'.');
        }
        foreach (['title_override', 'excerpt_override', 'badge_label', 'cta_label'] as $field) {
            $this->assertVisibleCopy($entry[$field] ?? null, $locale.'.entry.'.$field);
        }
    }

    private function assertSeo(mixed $seo, string $route): void
    {
        if (! is_array($seo)
            || ($seo['canonical_url'] ?? null) !== $route
            || ($seo['robots'] ?? null) !== 'noindex,follow'
            || ! array_key_exists('jsonld_overrides_json', $seo)
            || $seo['jsonld_overrides_json'] !== null
            || ! array_key_exists('og_image_url', $seo)
            || $seo['og_image_url'] !== null
            || ! array_key_exists('twitter_image_url', $seo)
            || $seo['twitter_image_url'] !== null) {
            throw new RuntimeException('Topic SEO draft is not fail closed for '.$route.'.');
        }
        foreach (['seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description'] as $field) {
            $this->assertVisibleCopy($seo[$field] ?? null, $route.'.seo.'.$field);
        }
    }

    private function assertAuthority(mixed $authority, string $locale): void
    {
        if (! is_array($authority)
            || ($authority['claim_mode'] ?? null) !== 'supplementary_explanation_only'
            || ($authority['recommendation_authority'] ?? null) !== false
            || ($authority['diagnostic_authority'] ?? null) !== false
            || ($authority['outcome_prediction_authority'] ?? null) !== false) {
            throw new RuntimeException('Topic claim boundary mismatch for '.$locale.'.');
        }

        $provenance = $authority['visible_provenance'] ?? null;
        $sources = is_array($provenance) ? ($provenance['sources'] ?? null) : null;
        if (! is_array($sources) || array_column($sources, 'source_id') !== self::SOURCE_IDS
            || ! array_key_exists('author', $provenance) || $provenance['author'] !== null
            || ! array_key_exists('reviewer', $provenance) || $provenance['reviewer'] !== null) {
            throw new RuntimeException('Topic source/actor provenance mismatch for '.$locale.'.');
        }
        foreach ($sources as $source) {
            if (! is_array($source)
                || ($source['category'] ?? null) !== 'academic_evidence'
                || ! str_starts_with((string) ($source['authority_ref'] ?? ''), 'source-ledger:academic:')
                || filter_var($source['public_url'] ?? null, FILTER_VALIDATE_URL) === false) {
                throw new RuntimeException('Topic source authority is invalid for '.$locale.'.');
            }
        }

        $dates = $authority['visible_dates'] ?? null;
        if (! is_array($dates)
            || ! array_key_exists('published_at', $dates) || $dates['published_at'] !== null
            || ! array_key_exists('reviewed_at', $dates) || $dates['reviewed_at'] !== null
            || ! array_key_exists('updated_at', $dates) || $dates['updated_at'] !== null
            || ($dates['resolution'] ?? null) !== 'preserve_current_published_revision_authority_or_block_at_promotion'
            || ($dates['forbidden_fallbacks'] ?? null) !== ['revision_created_at', 'imported_at', 'built_at', 'deployed_at', 'model_created_at', 'model_updated_at']) {
            throw new RuntimeException('Topic date authority must remain unresolved and fail closed for '.$locale.'.');
        }

        $media = $authority['media'] ?? null;
        if (! is_array($media)
            || ($media['mapping_status'] ?? null) !== 'missing_pending'
            || ($media['media_eligible'] ?? null) !== false
            || ($media['operator_approval_claimed'] ?? null) !== false
            || array_column((array) ($media['slots'] ?? []), 'slot') !== ['hero', 'inline', 'og']) {
            throw new RuntimeException('Topic media authority must remain missing_pending for '.$locale.'.');
        }
        foreach ($media['slots'] as $slot) {
            if (! is_array($slot) || ($slot['status'] ?? null) !== 'missing_pending') {
                throw new RuntimeException('Topic media slot is not fail closed for '.$locale.'.');
            }
            foreach (['media_asset_id', 'media_asset_key', 'variant_key', 'public_url', 'alt', 'rights', 'license', 'provenance', 'operator_approval_ref'] as $field) {
                if (! array_key_exists($field, $slot) || $slot[$field] !== null) {
                    throw new RuntimeException('Topic media slot invents '.$field.' authority for '.$locale.'.');
                }
            }
        }

        $canonical = $authority['canonical_test_target'] ?? null;
        if (! is_array($canonical)
            || ($canonical['scale_code'] ?? null) !== self::SCALE_CODE
            || ($canonical['primary_slug'] ?? null) !== self::PRIMARY_SLUG
            || ($canonical['canonical_path'] ?? null) !== self::CANONICAL_PATHS[$locale]
            || ($canonical['source'] ?? null) !== 'scales_registry.primary_slug') {
            throw new RuntimeException('Topic canonical test target mismatch for '.$locale.'.');
        }
    }

    /** @return array<string,mixed> */
    private function assertCanonicalRegistryAuthority(): array
    {
        if (! Schema::hasTable('scales_registry')) {
            throw new RuntimeException('scales_registry is required for canonical Topic CTA preflight.');
        }
        $row = DB::table('scales_registry')
            ->select(['code', 'primary_slug', 'is_public', 'is_active'])
            ->where('org_id', 0)
            ->where('code', self::SCALE_CODE)
            ->first();
        if ($row === null
            || trim((string) $row->primary_slug) !== self::PRIMARY_SLUG
            || (bool) $row->is_public !== true
            || (bool) $row->is_active !== true) {
            throw new RuntimeException('BIG5_OCEAN scales_registry canonical authority is missing, inactive, private, or drifted.');
        }

        return [
            'checked' => true,
            'status' => 'pass',
            'scale_code' => (string) $row->code,
            'primary_slug' => (string) $row->primary_slug,
            'is_public' => (bool) $row->is_public,
            'is_active' => (bool) $row->is_active,
        ];
    }

    /** @param array<string,mixed> $package */
    private function assertSourceInventory(array $package): void
    {
        $inventory = $package['source_inventory'] ?? null;
        if (! is_array($inventory) || array_keys($inventory) !== ['release', 'media', 'ledger', 'testLanding']) {
            throw new RuntimeException('Topic source inventory mismatch.');
        }
        foreach ($inventory as $key => $source) {
            if (! is_array($source)) {
                throw new RuntimeException('Topic source inventory row must be an object: '.$key.'.');
            }
            $relative = trim((string) ($source['path'] ?? ''));
            $expectedHash = trim((string) ($source['sha256'] ?? ''));
            if ($relative === '' || preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1) {
                throw new RuntimeException('Topic source inventory identity invalid: '.$key.'.');
            }
            $resolved = base_path('../'.$relative);
            if (! File::isFile($resolved) || ! hash_equals($expectedHash, hash_file('sha256', $resolved))) {
                throw new RuntimeException('Topic source inventory hash mismatch: '.$key.'.');
            }
        }
    }

    private function assertVisibleCopy(mixed $value, string $context): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Topic visible copy is missing: '.$context.'.');
        }
        $normalized = mb_strtolower($value);
        foreach (self::FORBIDDEN_VISIBLE_COPY as $forbidden) {
            if (str_contains($normalized, $forbidden)) {
                throw new RuntimeException('Topic visible copy contains forbidden language at '.$context.': '.$forbidden.'.');
            }
        }
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException($label.' not found: '.$resolved.'.');
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must decode to an object.');
        }

        return [$decoded, $resolved];
    }

    private function readRecordedHash(string $resolvedPackage): string
    {
        $path = preg_replace('/\.json\z/', '.sha256', $resolvedPackage);
        if (! is_string($path) || ! File::isFile($path)) {
            throw new RuntimeException('Topic draft-revision package hash file is missing.');
        }
        $hash = trim(File::get($path));
        if (preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('Topic draft-revision package hash is invalid.');
        }

        return $hash;
    }

    /** @param array<string,mixed> $payload */
    private function canonicalSha256(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}

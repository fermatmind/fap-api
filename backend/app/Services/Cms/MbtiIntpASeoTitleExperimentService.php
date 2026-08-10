<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\Mbti\MbtiPublicProjectionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiIntpASeoTitleExperimentService
{
    private const SCHEMA_VERSION = 'personality.mbti-seo-title-experiment.v1';

    private const EXPERIMENT_ID = 'zh-intp-a-seo-title-20260810-v1';

    private const TARGET_ROUTE = '/zh/personality/intp-a';

    private const TARGET_RUNTIME_TYPE_CODE = 'INTP-A';

    private const TARGET_FIELD = 'personality_profile_variant_seo_meta.seo_title';

    private const CURRENT_TITLE = 'INTP-A 人格特点：分析建模、可能性探索和独立解题 | FermatMind';

    private const PROPOSED_TITLE = 'INTP-A 是什么？人格特点、优势盲点与适合场景 | FermatMind';

    private const CURRENT_DESCRIPTION = '了解 INTP-A 的分析建模、可能性探索和独立解题、适合与不适合的场景、A/T 差异、职业、关系、压力应对、常见误解与 FAQ。内容仅用于自我理解和成长复盘。';

    private const M01_SHA256 = '9b7c470aa39aff0e6062c41fe5d71e2e8164159747953d42bd032046cc10f691';

    private const CANNIBALIZATION_SHA256 = '07a153df4fd2b2bb11a639c6fc18d52f3a39988030407a17489b1d6ddd579a91';

    private const QUERY_PAGE_SHA256 = '1f1b7823d69ce1a309482334469c20df9ddefb21830b1c969ca1f95eb225acba';

    private const SOURCE_MANIFEST_SHA256 = 'c35cf3343c481ff5ce4fa3b11e3d2c4e2202fdb2353ac3b337cdbad668d64b47';

    private const SOURCE_COMMIT = 'a931f8cdb3cc6756d225f592be12847676bdfe99';

    public function __construct(
        private readonly MbtiPublicProjectionService $publicProjectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function plan(array $package, string $packageSha256, string $targetEnvironment): array
    {
        $this->assertPackage($package, $packageSha256);
        [$profile, $variant, $seoMeta, $profileSeoMeta] = $this->resolveAuthority();
        $this->assertLiveBaseline($profile, $variant, $seoMeta, $package);

        $liveFingerprint = $this->liveFingerprint($profile, $variant, $seoMeta, $profileSeoMeta);
        $existing = $this->findExistingExperimentRevision($variant, $packageSha256, $liveFingerprint);

        return $this->receipt(
            status: $existing instanceof PersonalityProfileVariantRevision ? 'idempotent_existing_draft' : 'planned',
            targetEnvironment: $targetEnvironment,
            packageSha256: $packageSha256,
            revision: $existing,
            revisionCreatedCount: 0,
            idempotentCount: $existing instanceof PersonalityProfileVariantRevision ? 1 : 0,
            writesCommitted: false,
            liveFingerprintBefore: $liveFingerprint,
            liveFingerprintAfter: $liveFingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function write(array $package, string $packageSha256, string $targetEnvironment): array
    {
        $this->assertPackage($package, $packageSha256);

        return DB::transaction(function () use ($package, $packageSha256, $targetEnvironment): array {
            [$profile, $variant, $seoMeta, $profileSeoMeta] = $this->resolveAuthority(lock: true);
            $this->assertLiveBaseline($profile, $variant, $seoMeta, $package);

            $liveFingerprintBefore = $this->liveFingerprint($profile, $variant, $seoMeta, $profileSeoMeta);
            $existing = $this->findExistingExperimentRevision($variant, $packageSha256, $liveFingerprintBefore);
            if ($existing instanceof PersonalityProfileVariantRevision) {
                return $this->receipt(
                    status: 'idempotent_existing_draft',
                    targetEnvironment: $targetEnvironment,
                    packageSha256: $packageSha256,
                    revision: $existing,
                    revisionCreatedCount: 0,
                    idempotentCount: 1,
                    writesCommitted: false,
                    liveFingerprintBefore: $liveFingerprintBefore,
                    liveFingerprintAfter: $liveFingerprintBefore,
                );
            }

            $revisionNo = ((int) PersonalityProfileVariantRevision::query()
                ->where('personality_profile_variant_id', (int) $variant->id)
                ->max('revision_no')) + 1;
            $snapshot = $this->snapshot($package, $packageSha256, $liveFingerprintBefore);
            $revision = PersonalityProfileVariantRevision::query()->create([
                'personality_profile_variant_id' => (int) $variant->id,
                'revision_no' => $revisionNo,
                'snapshot_json' => $snapshot,
                'note' => self::EXPERIMENT_ID.':'.$packageSha256,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);

            $profile->refresh();
            $variant->refresh();
            $seoMeta->refresh();
            $profileSeoMeta?->refresh();
            $liveFingerprintAfter = $this->liveFingerprint($profile, $variant, $seoMeta, $profileSeoMeta);
            if (! hash_equals($liveFingerprintBefore, $liveFingerprintAfter)) {
                throw new RuntimeException('Live public authority changed while creating the inactive draft revision.');
            }

            return $this->receipt(
                status: 'draft_revision_created',
                targetEnvironment: $targetEnvironment,
                packageSha256: $packageSha256,
                revision: $revision,
                revisionCreatedCount: 1,
                idempotentCount: 0,
                writesCommitted: true,
                liveFingerprintBefore: $liveFingerprintBefore,
                liveFingerprintAfter: $liveFingerprintAfter,
            );
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function assertPackage(array $package, string $packageSha256): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $packageSha256)) {
            throw new RuntimeException('Package SHA-256 must be a lowercase 64-character hex string.');
        }

        $expectedScalarValues = [
            'schema_version' => self::SCHEMA_VERSION,
            'experiment_id' => self::EXPERIMENT_ID,
            'framework' => 'MBTI',
            'entity_type' => 'variant',
            'code' => self::TARGET_RUNTIME_TYPE_CODE,
            'locale' => 'zh-CN',
            'slug' => 'intp-a',
            'target.org_id' => 0,
            'target.framework' => 'MBTI',
            'target.locale' => 'zh-CN',
            'target.runtime_type_code' => self::TARGET_RUNTIME_TYPE_CODE,
            'target.route' => self::TARGET_ROUTE,
            'change.field' => self::TARGET_FIELD,
            'change.current' => self::CURRENT_TITLE,
            'change.proposed' => self::PROPOSED_TITLE,
            'seo.title' => self::PROPOSED_TITLE,
            'seo.description' => 'UNCHANGED',
            'canonical' => 'https://fermatmind.com'.self::TARGET_ROUTE,
            'robots' => 'UNCHANGED',
            'evidence.source_repository' => 'fermatmind/fap-web',
            'evidence.source_commit' => self::SOURCE_COMMIT,
            'evidence.m01.sha256' => self::M01_SHA256,
            'evidence.window' => 'current28',
            'evidence.window_start' => '2026-07-13',
            'evidence.window_end' => '2026-08-09',
            'measurement.page_indexing_state' => 'UNKNOWN_PAGE_LEVEL',
            'measurement.post_window_days' => 28,
            'measurement.insufficient_evidence_state' => 'INCONCLUSIVE',
            'authority_baseline.source' => 'production_public_api_readonly',
            'authority_baseline.captured_at' => '2026-08-10',
        ];
        foreach ($expectedScalarValues as $path => $expected) {
            if (data_get($package, $path) !== $expected) {
                throw new RuntimeException('Package contract mismatch at '.$path.'.');
            }
        }

        $change = $package['change'] ?? null;
        if (! is_array($change) || array_keys($change) !== ['field', 'current', 'proposed']) {
            throw new RuntimeException('Package change must contain exactly field, current, and proposed.');
        }

        $expectedWindowFiles = [
            'personality_cannibalization.csv' => self::CANNIBALIZATION_SHA256,
            'personality_gsc_page_query.csv' => self::QUERY_PAGE_SHA256,
            'personality_source_manifest.json' => self::SOURCE_MANIFEST_SHA256,
        ];
        if (data_get($package, 'evidence.window_04_files') !== $expectedWindowFiles) {
            throw new RuntimeException('Package Window 4 evidence files do not match the exact source hashes.');
        }

        $expectedQueries = [
            [
                'query' => 'intp-a',
                'severity' => 'HIGH',
                'intended_owner_impressions' => 51,
                'comparison_page_impressions' => 100,
                'combined_impressions' => 151,
                'combined_clicks' => 1,
            ],
            [
                'query' => 'intp a',
                'severity' => 'MEDIUM',
                'intended_owner_impressions' => 21,
                'comparison_page_impressions' => 70,
                'combined_impressions' => 91,
                'combined_clicks' => 0,
            ],
        ];
        if (data_get($package, 'evidence.queries') !== $expectedQueries) {
            throw new RuntimeException('Package query evidence does not match the exact M01 baseline.');
        }

        $expectedAuthorityBaseline = [
            'profile' => [
                'title' => 'INTP - 逻辑学家',
                'type_name' => '逻辑学家型',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
            ],
            'variant' => [
                'is_published' => true,
            ],
            'seo_meta' => [
                'seo_title' => self::CURRENT_TITLE,
                'seo_description' => self::CURRENT_DESCRIPTION,
                'canonical_url' => 'https://fermatmind.com'.self::TARGET_ROUTE,
                'og_title' => self::CURRENT_TITLE,
                'og_description' => self::CURRENT_DESCRIPTION,
                'og_image_url' => null,
                'twitter_title' => self::CURRENT_TITLE,
                'twitter_description' => self::CURRENT_DESCRIPTION,
                'twitter_image_url' => null,
                'robots' => 'index,follow',
                'jsonld_overrides_json' => [
                    'url' => 'https://fermatmind.com'.self::TARGET_ROUTE,
                    'name' => 'INTP-A 人格特点',
                    'description' => self::CURRENT_DESCRIPTION,
                ],
            ],
        ];
        if (data_get($package, 'authority_baseline.database_contract') !== $expectedAuthorityBaseline) {
            throw new RuntimeException('Package live authority baseline does not match the locked contract.');
        }

        if (preg_match('/^[a-f0-9]{64}$/', (string) data_get($package, 'authority_baseline.public_projection_sha256')) !== 1) {
            throw new RuntimeException('Package complete public projection fingerprint is invalid.');
        }

        $negativeGuarantees = (array) ($package['negative_guarantees'] ?? []);
        $expectedNegativeGuarantees = [
            'production_write',
            'live_seo_meta_write',
            'publication',
            'active_revision_pointer_change',
            'description_change',
            'h1_change',
            'content_change',
            'faq_change',
            'internal_link_change',
            'og_twitter_change',
            'canonical_change',
            'robots_change',
            'indexability_change',
            'sitemap_change',
            'llms_change',
            'search_channel_change',
            'deployment',
        ];
        if (array_keys($negativeGuarantees) !== $expectedNegativeGuarantees) {
            throw new RuntimeException('Package negative guarantee set is incomplete or out of order.');
        }
        foreach ($negativeGuarantees as $name => $value) {
            if ($value !== false) {
                throw new RuntimeException('Negative guarantee must remain false: '.(string) $name.'.');
            }
        }
    }

    /**
     * @return array{PersonalityProfile, PersonalityProfileVariant, PersonalityProfileVariantSeoMeta, PersonalityProfileSeoMeta|null}
     */
    private function resolveAuthority(bool $lock = false): array
    {
        $profileQuery = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')
            ->where('type_code', 'INTP');
        if ($lock) {
            $profileQuery->lockForUpdate();
        }
        $profile = $profileQuery->first();
        if (! $profile instanceof PersonalityProfile) {
            throw new RuntimeException('Target INTP profile authority was not found.');
        }

        $variantQuery = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', self::TARGET_RUNTIME_TYPE_CODE);
        if ($lock) {
            $variantQuery->lockForUpdate();
        }
        $variant = $variantQuery->first();
        if (! $variant instanceof PersonalityProfileVariant) {
            throw new RuntimeException('Target INTP-A variant authority was not found.');
        }

        $seoQuery = PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('personality_profile_variant_id', (int) $variant->id);
        if ($lock) {
            $seoQuery->lockForUpdate();
        }
        $seoMeta = $seoQuery->first();
        if (! $seoMeta instanceof PersonalityProfileVariantSeoMeta) {
            throw new RuntimeException('Target INTP-A SEO meta authority was not found.');
        }

        $profileSeoQuery = PersonalityProfileSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('profile_id', (int) $profile->id);
        if ($lock) {
            $profileSeoQuery->lockForUpdate();
        }
        $profileSeoMeta = $profileSeoQuery->first();
        $profile->setRelation('seoMeta', $profileSeoMeta);
        $variant->setRelation('seoMeta', $seoMeta);

        return [$profile, $variant, $seoMeta, $profileSeoMeta];
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function assertLiveBaseline(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
        array $package,
    ): void {
        $expected = data_get($package, 'authority_baseline.database_contract');
        $actual = [
            'profile' => [
                'title' => (string) $profile->title,
                'type_name' => (string) $profile->type_name,
                'status' => (string) $profile->status,
                'is_public' => (bool) $profile->is_public,
                'is_indexable' => (bool) $profile->is_indexable,
            ],
            'variant' => [
                'is_published' => (bool) $variant->is_published,
            ],
            'seo_meta' => [
                'seo_title' => trim((string) $seoMeta->seo_title),
                'seo_description' => trim((string) $seoMeta->seo_description),
                'canonical_url' => trim((string) $seoMeta->canonical_url),
                'og_title' => $seoMeta->og_title,
                'og_description' => $seoMeta->og_description,
                'og_image_url' => $seoMeta->og_image_url,
                'twitter_title' => $seoMeta->twitter_title,
                'twitter_description' => $seoMeta->twitter_description,
                'twitter_image_url' => $seoMeta->twitter_image_url,
                'robots' => trim((string) $seoMeta->robots),
                'jsonld_overrides_json' => $seoMeta->jsonld_overrides_json,
            ],
        ];

        // On a variant route, variant.type_name overlays profile.type_name. The
        // complete public projection fingerprint below binds the visible value;
        // the shadowed base-profile storage value is not public-route authority.
        unset($expected['profile']['type_name'], $actual['profile']['type_name']);

        $normalizedExpected = $this->canonicalize($expected);
        $normalizedActual = $this->canonicalize($this->normalizeStagingAuthorityUrls($actual, $expected));
        if ($normalizedActual !== $normalizedExpected) {
            $paths = $this->differingPaths($normalizedExpected, $normalizedActual);
            throw new RuntimeException(
                'Target INTP-A live authority baseline drifted at '.implode(', ', $paths).'; refusing the experiment draft.',
            );
        }

        $expectedProjectionFingerprint = (string) data_get($package, 'authority_baseline.public_projection_sha256');
        $actualProjectionFingerprint = $this->publicProjectionFingerprint(
            $profile,
            $variant,
            trim((string) data_get($expected, 'seo_meta.canonical_url')),
        );
        if (! hash_equals($expectedProjectionFingerprint, $actualProjectionFingerprint)) {
            throw new RuntimeException('Target INTP-A complete public authority drifted; refusing the experiment draft.');
        }
    }

    private function findExistingExperimentRevision(
        PersonalityProfileVariant $variant,
        string $packageSha256,
        string $liveFingerprint,
    ): ?PersonalityProfileVariantRevision {
        $exact = null;

        foreach (PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $variant->id)
            ->orderByDesc('revision_no')
            ->get() as $revision) {
            if (! $revision instanceof PersonalityProfileVariantRevision) {
                continue;
            }

            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            if ((string) ($snapshot['experiment_id'] ?? '') !== self::EXPERIMENT_ID) {
                continue;
            }

            if ((string) ($snapshot['package_sha256'] ?? '') !== $packageSha256) {
                throw new RuntimeException('Experiment id already exists with a different package SHA-256.');
            }

            if (! hash_equals($this->snapshotSha256($snapshot), (string) ($snapshot['snapshot_sha256'] ?? ''))) {
                throw new RuntimeException('Existing experiment revision snapshot checksum mismatch.');
            }

            $storedLiveFingerprint = (string) ($snapshot['live_authority_fingerprint_before'] ?? '');
            if (! preg_match('/^[a-f0-9]{64}$/', $storedLiveFingerprint)
                || ! hash_equals($storedLiveFingerprint, $liveFingerprint)) {
                throw new RuntimeException('Live authority drifted since the existing experiment revision was created.');
            }

            $exact = $revision;
            break;
        }

        return $exact;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function snapshot(array $package, string $packageSha256, string $liveFingerprint): array
    {
        $snapshot = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'inactive_draft',
            'experiment_id' => self::EXPERIMENT_ID,
            'package_sha256' => $packageSha256,
            'target' => $package['target'],
            'change' => $package['change'],
            'evidence' => $package['evidence'],
            'measurement' => $package['measurement'],
            'negative_guarantees' => $package['negative_guarantees'],
            'live_authority_fingerprint_before' => $liveFingerprint,
        ];
        $snapshot['snapshot_sha256'] = $this->snapshotSha256($snapshot);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotSha256(array $snapshot): string
    {
        unset($snapshot['snapshot_sha256']);
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode the experiment revision snapshot.');
        }

        return hash('sha256', $encoded);
    }

    private function liveFingerprint(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        PersonalityProfileVariantSeoMeta $seoMeta,
        ?PersonalityProfileSeoMeta $profileSeoMeta,
    ): string {
        $variant->loadMissing(['sections' => static fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
        $profile->loadMissing(['sections' => static fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);

        $payload = [
            'profile' => $profile->only([
                'id', 'org_id', 'scale_code', 'type_code', 'canonical_type_code', 'slug', 'locale',
                'title', 'type_name', 'nickname', 'rarity_text', 'keywords_json', 'subtitle', 'excerpt',
                'hero_kicker', 'hero_quote', 'hero_summary_md', 'hero_summary_html', 'hero_image_url',
                'status', 'is_public', 'is_indexable', 'published_at', 'scheduled_at', 'schema_version',
                'updated_at',
            ]),
            'variant' => $variant->only([
                'id', 'org_id', 'personality_profile_id', 'canonical_type_code', 'variant_code',
                'runtime_type_code', 'type_name', 'nickname', 'rarity_text', 'keywords_json',
                'hero_summary_md', 'hero_summary_html', 'schema_version', 'is_published', 'published_at',
                'updated_at',
            ]),
            'seo_meta' => $seoMeta->only([
                'org_id', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description',
                'og_image_url', 'twitter_title', 'twitter_description', 'twitter_image_url', 'robots',
                'jsonld_overrides_json',
            ]),
            'profile_seo_meta' => $profileSeoMeta?->only([
                'org_id', 'seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description',
                'og_image_url', 'twitter_title', 'twitter_description', 'twitter_image_url', 'robots',
                'jsonld_overrides_json',
            ]),
            'complete_public_projection_sha256' => $this->publicProjectionFingerprint(
                $profile,
                $variant,
                trim((string) $seoMeta->canonical_url),
            ),
            'profile_sections' => $profile->sections->map->only([
                'section_key', 'title', 'render_variant', 'body_md', 'body_html', 'payload_json',
                'sort_order', 'is_enabled',
            ])->values()->all(),
            'variant_sections' => $variant->sections->map->only([
                'section_key', 'render_variant', 'body_md', 'body_html', 'payload_json',
                'sort_order', 'is_enabled',
            ])->values()->all(),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to fingerprint the live public authority.');
        }

        return hash('sha256', $encoded);
    }

    private function publicProjectionFingerprint(
        PersonalityProfile $profile,
        PersonalityProfileVariant $variant,
        string $authorityCanonicalUrl,
    ): string {
        $projection = $this->publicProjectionService->buildForPublicPersonalityRoute(
            $profile,
            $variant,
            'public_variant',
        );
        // PersonalityController applies this same visibility gate before exposing
        // mbti_public_projection_v1. Keep the experiment bound to the public
        // projection while liveFingerprint() separately binds every raw section.
        $projection['sections'] = array_values(array_filter(
            (array) ($projection['sections'] ?? []),
            static fn (mixed $section): bool => is_array($section)
                && (bool) ($section['is_enabled'] ?? false)
                && (string) ($section['render'] ?? '') !== 'premium_teaser',
        ));
        if (trim((string) data_get($projection, 'seo.canonical_url')) === '') {
            throw new RuntimeException('Complete public projection canonical URL is missing.');
        }

        data_set($projection, 'seo.canonical_url', $authorityCanonicalUrl);
        $canonical = $this->canonicalize($projection);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to fingerprint the complete public projection.');
        }

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * Allow only the exact environment-owned staging host representation. The
     * package remains bound to the official public authority URL.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function normalizeStagingAuthorityUrls(array $actual, array $expected): array
    {
        $stagingUrl = 'https://staging.fermatmind.com'.self::TARGET_ROUTE;
        $authorityUrl = trim((string) data_get($expected, 'seo_meta.canonical_url'));

        foreach (['seo_meta.canonical_url', 'seo_meta.jsonld_overrides_json.url'] as $path) {
            if (data_get($actual, $path) === $stagingUrl) {
                data_set($actual, $path, $authorityUrl);
            }
        }

        return $actual;
    }

    /**
     * @return list<string>
     */
    private function differingPaths(mixed $expected, mixed $actual, string $prefix = ''): array
    {
        if (! is_array($expected) || ! is_array($actual)) {
            return $expected === $actual ? [] : [$prefix !== '' ? $prefix : 'authority_baseline'];
        }

        $paths = [];
        $keys = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual)), SORT_REGULAR));
        foreach ($keys as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            if (! array_key_exists($key, $expected) || ! array_key_exists($key, $actual)) {
                $paths[] = $path;

                continue;
            }

            $paths = array_merge($paths, $this->differingPaths($expected[$key], $actual[$key], $path));
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(
        string $status,
        string $targetEnvironment,
        string $packageSha256,
        ?PersonalityProfileVariantRevision $revision,
        int $revisionCreatedCount,
        int $idempotentCount,
        bool $writesCommitted,
        string $liveFingerprintBefore,
        string $liveFingerprintAfter,
    ): array {
        $snapshot = $revision instanceof PersonalityProfileVariantRevision && is_array($revision->snapshot_json)
            ? $revision->snapshot_json
            : [];

        return [
            'schema_version' => 'personality.mbti-seo-title-experiment-receipt.v1',
            'ok' => true,
            'status' => $status,
            'target_environment' => $targetEnvironment,
            'package_sha256' => $packageSha256,
            'target' => [
                'route' => self::TARGET_ROUTE,
                'locale' => 'zh-CN',
                'runtime_type_code' => self::TARGET_RUNTIME_TYPE_CODE,
            ],
            'revision_no' => $revision?->revision_no,
            'revision_snapshot_sha256' => $snapshot['snapshot_sha256'] ?? null,
            'revision_created_count' => $revisionCreatedCount,
            'idempotent_count' => $idempotentCount,
            'writes_committed' => $writesCommitted,
            'live_projection_changes' => hash_equals($liveFingerprintBefore, $liveFingerprintAfter) ? 0 : 1,
            'negative_guarantees' => [
                'production_write' => false,
                'live_seo_meta_write' => false,
                'publication' => false,
                'active_revision_pointer_change' => false,
                'indexability_change' => false,
                'sitemap_llms_change' => false,
                'search_channel_change' => false,
                'deployment' => false,
            ],
            'errors' => [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\ReleaseGate;

use App\Models\Article;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveAuthorityV2DraftImportWriter
{
    public const PR37_MERGE_SHA = 'af99ac41406a2967b9f4778dc9da07b920bfbb7f';

    public const PACKAGE_SHA256 = 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162';

    public const ASSET_COUNT = 231;

    public const APPROVAL_PHRASE = 'AUTHORIZE BIG5 AUTHORITY V2 DRAFT-ONLY PRODUCTION IMPORT FOR PR37_MERGE_SHA=af99ac41406a2967b9f4778dc9da07b920bfbb7f PACKAGE_SHA256=fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162 ASSET_COUNT=231 CREATE=231 UPDATE=0; PUBLIC_RELEASE=0; INDEXABILITY=0; SITEMAP=0; LLMS=0; SEARCH_SUBMISSION=0; ABORT_ON_ANY_MISMATCH';

    /** @var array<string,int> */
    private const SURFACE_COUNTS = [
        'CMS Article' => 109,
        'CMS content_pages' => 4,
        'CMS landing_surfaces/page_blocks' => 2,
        'CMS personality_public_content_assets' => 114,
        'CMS topic_profiles' => 2,
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'articles',
        'content_pages',
        'landing_surfaces',
        'personality_public_content_assets',
        'topic_profiles',
    ];

    /**
     * @return array<string,mixed>
     */
    public function preflight(string $packagePath, string $authorizationPacketPath): array
    {
        return $this->publicPlan($this->buildPlan($packagePath, $authorizationPacketPath, false));
    }

    /**
     * @return array<string,mixed>
     */
    public function write(
        string $packagePath,
        string $authorizationPacketPath,
        int $expectedCreate,
        int $expectedUpdate,
    ): array {
        return DB::transaction(function () use ($packagePath, $authorizationPacketPath, $expectedCreate, $expectedUpdate): array {
            $plan = $this->buildPlan($packagePath, $authorizationPacketPath, true);
            $this->assertExpectedCounts($plan, $expectedCreate, $expectedUpdate);

            /** @var list<array<string,mixed>> $descriptors */
            $descriptors = $plan['descriptors'];
            foreach ($descriptors as $descriptor) {
                if ($descriptor['action'] !== 'create') {
                    throw new RuntimeException('Only exact create-only production imports are authorized.');
                }

                $this->createPrimaryRecord($descriptor);
            }

            $readback = $this->readback($descriptors);
            if (($readback['ok'] ?? false) !== true || (int) ($readback['primary_record_count'] ?? 0) !== self::ASSET_COUNT) {
                throw new RuntimeException('Post-write primary-record readback mismatch; transaction rolled back.');
            }

            return [
                ...$this->publicPlan($plan),
                'status' => 'PASS_DRAFT_ONLY_PRODUCTION_IMPORT',
                'writes_committed' => true,
                'primary_records_created' => self::ASSET_COUNT,
                'primary_records_updated' => 0,
                'public_release_count' => 0,
                'indexability_change_count' => 0,
                'sitemap_change_count' => 0,
                'llms_change_count' => 0,
                'search_submission_count' => 0,
                'cache_invalidation_count' => 0,
                'media_write_count' => 0,
                'readback' => $readback,
            ];
        }, 1);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPlan(string $packagePath, string $authorizationPacketPath, bool $lock): array
    {
        $this->assertRequiredTablesExist();
        [$package, $resolvedPackagePath, $packageFileSha256] = $this->readJson($packagePath, 'draft import package');
        [$authorization, $resolvedAuthorizationPath] = $this->readJson($authorizationPacketPath, 'authorization packet');
        $this->assertPackageContract($package, $authorization, $packageFileSha256);

        /** @var list<array<string,mixed>> $assets */
        $assets = array_values($package['assets']);
        $descriptors = [];
        foreach ($assets as $asset) {
            $descriptor = $this->descriptor($asset);
            $existing = $this->existingPrimaryRecord($descriptor, $lock);
            $descriptors[] = [
                ...$descriptor,
                'action' => $existing instanceof Model ? 'update' : 'create',
                'existing_id' => $existing instanceof Model ? (int) $existing->getKey() : null,
            ];
        }

        $createCount = count(array_filter($descriptors, static fn (array $item): bool => $item['action'] === 'create'));
        $updateCount = count($descriptors) - $createCount;
        $surfaceCounts = [];
        foreach ($descriptors as $descriptor) {
            $surface = (string) $descriptor['authority_surface'];
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;
        }
        ksort($surfaceCounts);

        if ($surfaceCounts !== $this->sortedSurfaceCounts()) {
            throw new RuntimeException('Authority surface counts do not match the approved 231-asset package.');
        }

        return [
            'ok' => true,
            'status' => 'PASS_READ_ONLY_PREFLIGHT',
            'mode' => 'draft_noindex_only',
            'package_path' => $resolvedPackagePath,
            'authorization_packet_path' => $resolvedAuthorizationPath,
            'draft_import_package_sha256' => $packageFileSha256,
            'authority_package_sha256' => self::PACKAGE_SHA256,
            'pr37_merge_sha' => self::PR37_MERGE_SHA,
            'asset_count' => count($descriptors),
            'create_count' => $createCount,
            'update_count' => $updateCount,
            'surface_counts' => $surfaceCounts,
            'writes_committed' => false,
            'public_release_count' => 0,
            'indexability_change_count' => 0,
            'sitemap_change_count' => 0,
            'llms_change_count' => 0,
            'search_submission_count' => 0,
            'cache_invalidation_count' => 0,
            'media_write_count' => 0,
            'descriptors' => $descriptors,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    public function assertExpectedCounts(array $plan, int $expectedCreate, int $expectedUpdate): void
    {
        if ($expectedCreate !== self::ASSET_COUNT || $expectedUpdate !== 0) {
            throw new RuntimeException('Approved expected counts are fixed at 231 creates and 0 updates.');
        }

        if ((int) ($plan['create_count'] ?? -1) !== $expectedCreate || (int) ($plan['update_count'] ?? -1) !== $expectedUpdate) {
            throw new RuntimeException(sprintf(
                'Production preflight count mismatch: expected create=%d update=%d, observed create=%d update=%d.',
                $expectedCreate,
                $expectedUpdate,
                (int) ($plan['create_count'] ?? -1),
                (int) ($plan['update_count'] ?? -1),
            ));
        }
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorization
     */
    private function assertPackageContract(array $package, array $authorization, string $packageFileSha256): void
    {
        $expected = [
            'schema_version' => 'big5-authority-v2-multi-surface-draft-import.v1',
            'mode' => 'draft_noindex_only',
            'pr37_merge_sha' => self::PR37_MERGE_SHA,
            'authority_package_sha256' => self::PACKAGE_SHA256,
            'asset_count' => self::ASSET_COUNT,
            'expected_create_count' => self::ASSET_COUNT,
            'expected_update_count' => 0,
        ];
        foreach ($expected as $field => $value) {
            if (($package[$field] ?? null) !== $value) {
                throw new RuntimeException('Draft import package field mismatch: '.$field.'.');
            }
        }

        if (($authorization['status'] ?? null) !== 'GO_DRAFT_ONLY_PRODUCTION_IMPORT_AUTHORIZED_PENDING_EXACT_PREFLIGHT') {
            throw new RuntimeException('Authorization packet is not approved for draft-only production preflight.');
        }
        if (($authorization['pr37_merge_sha'] ?? null) !== self::PR37_MERGE_SHA
            || ($authorization['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($authorization['draft_import_package_sha256'] ?? null) !== $packageFileSha256
            || ($authorization['exact_approval_phrase'] ?? null) !== self::APPROVAL_PHRASE
            || ($authorization['approval_phrase_currently_executable'] ?? null) !== true) {
            throw new RuntimeException('Authorization packet identity or integrity mismatch.');
        }

        $assets = $package['assets'] ?? null;
        if (! is_array($assets) || count($assets) !== self::ASSET_COUNT) {
            throw new RuntimeException('Draft import package must contain exactly 231 assets.');
        }

        $assetIds = [];
        $routes = [];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                throw new RuntimeException('Draft import asset must be an object at index '.$index.'.');
            }
            foreach (['asset_id', 'route', 'locale', 'page_family', 'source_package', 'authority_surface', 'source_hash', 'draft_payload'] as $field) {
                if (! array_key_exists($field, $asset)) {
                    throw new RuntimeException(sprintf('Draft import asset %d is missing %s.', $index, $field));
                }
            }
            if (($asset['schema_valid'] ?? false) !== true
                || ($asset['source_record_valid'] ?? false) !== true
                || ($asset['duplicate_and_intent_valid'] ?? false) !== true
                || ($asset['private_boundary_valid'] ?? false) !== true
                || ($asset['publish_eligible'] ?? true) !== false
                || ($asset['indexability_eligible'] ?? true) !== false
                || ($asset['sitemap_eligible'] ?? true) !== false
                || ($asset['llms_eligible'] ?? true) !== false
                || ($asset['llms_full_eligible'] ?? true) !== false) {
                throw new RuntimeException('Draft import asset gates are not fail-closed at index '.$index.'.');
            }
            $payload = $asset['draft_payload'];
            if (! is_array($payload) || hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== $asset['source_hash']) {
                throw new RuntimeException('Draft import asset source payload hash mismatch at index '.$index.'.');
            }
            $assetIds[] = (string) $asset['asset_id'];
            $routes[] = (string) $asset['route'];
        }
        if (count(array_unique($assetIds)) !== self::ASSET_COUNT || count(array_unique($routes)) !== self::ASSET_COUNT) {
            throw new RuntimeException('Draft import asset ids and routes must be unique.');
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function descriptor(array $asset): array
    {
        $surface = (string) $asset['authority_surface'];
        $payload = $asset['draft_payload'];
        if (! is_array($payload)) {
            throw new RuntimeException('Draft payload must be an object.');
        }

        return match ($surface) {
            'CMS Article' => $this->articleDescriptor($asset, $payload),
            'CMS content_pages' => $this->contentPageDescriptor($asset, $payload),
            'CMS landing_surfaces/page_blocks' => $this->landingSurfaceDescriptor($asset, $payload),
            'CMS personality_public_content_assets' => $this->personalityAssetDescriptor($asset, $payload),
            'CMS topic_profiles' => $this->topicProfileDescriptor($asset, $payload),
            default => throw new RuntimeException('Unsupported authority surface: '.$surface.'.'),
        };
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload @return array<string,mixed> */
    private function articleDescriptor(array $asset, array $payload): array
    {
        $slug = $this->routeTail((string) $asset['route']);
        $locale = (string) $asset['locale'];

        return $this->baseDescriptor($asset, Article::class, ['org_id' => 0, 'locale' => $locale, 'slug' => $slug], [
            'org_id' => 0,
            'author_name' => $this->nullableString($payload['author'] ?? null),
            'reviewer_name' => null,
            'reading_minutes' => max(1, (int) ceil(str_word_count($this->markdown($payload)) / 220)),
            'slug' => $slug,
            'locale' => $locale,
            'translation_group_id' => $this->translationGroup((string) $asset['route']),
            'source_locale' => $locale,
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => $this->title($asset, $payload),
            'excerpt' => $this->summary($payload),
            'content_md' => $this->markdown($payload),
            'content_html' => null,
            'cover_image_url' => null,
            'related_test_slug' => 'big-five-personality-test-ocean-model',
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => null,
            'scheduled_at' => null,
            'published_revision_id' => null,
        ]);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload @return array<string,mixed> */
    private function contentPageDescriptor(array $asset, array $payload): array
    {
        $route = (string) $asset['route'];
        $locale = (string) $asset['locale'];
        $slug = $this->routeTail($route);
        $pageType = str_contains($route, 'methodology') ? 'methodology' : 'trust';

        return $this->baseDescriptor($asset, ContentPage::class, ['org_id' => 0, 'slug' => $slug, 'locale' => $locale], [
            'org_id' => 0,
            'slug' => $slug,
            'path' => $route,
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => $pageType,
            'title' => $this->title($asset, $payload),
            'summary' => $this->summary($payload),
            'locale' => $locale,
            'translation_group_id' => $this->translationGroup($route),
            'source_locale' => $locale,
            'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
            'is_public' => false,
            'is_indexable' => false,
            'review_state' => 'draft',
            'owner' => $this->nullableString($payload['owner'] ?? null),
            'legal_review_required' => (bool) ($payload['legal_review_required'] ?? true),
            'science_review_required' => (bool) ($payload['science_review_required'] ?? true),
            'headings_json' => is_array($payload['headings_json'] ?? null) ? $payload['headings_json'] : [],
            'content_md' => $this->markdown($payload),
            'content_html' => null,
            'canonical_path' => $route,
            'reviewer' => null,
            'faq_items' => [],
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => true,
            'claim_gate_status' => 'not_reviewed',
            'faq_schema_eligible' => false,
            'status' => ContentPage::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload @return array<string,mixed> */
    private function landingSurfaceDescriptor(array $asset, array $payload): array
    {
        $slug = $this->routeTail((string) $asset['route']);
        $locale = (string) $asset['locale'];
        $surfaceKey = 'test_'.str_replace('-', '_', $slug);

        return $this->baseDescriptor($asset, LandingSurface::class, ['org_id' => 0, 'surface_key' => $surfaceKey, 'locale' => $locale], [
            'org_id' => 0,
            'surface_key' => $surfaceKey,
            'locale' => $locale,
            'title' => $this->title($asset, $payload),
            'description' => $this->summary($payload),
            'schema_version' => 'big5-authority-v2-draft.v1',
            'payload_json' => [
                'authority_route' => (string) $asset['route'],
                'source_package' => (string) $asset['source_package'],
                'source_hash' => (string) $asset['source_hash'],
                'candidate' => $payload,
            ],
            'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
            'scheduled_at' => null,
        ]);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload @return array<string,mixed> */
    private function personalityAssetDescriptor(array $asset, array $payload): array
    {
        $route = (string) $asset['route'];
        $locale = (string) $asset['locale'];
        $family = (string) $asset['page_family'];
        $entityType = match ($family) {
            'model_hub' => PersonalityPublicContentAsset::ENTITY_HUB,
            'domain' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'range' => PersonalityPublicContentAsset::ENTITY_POLARITY,
            'facet_hub' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
            'facet' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
            default => throw new RuntimeException('Unsupported personality page family: '.$family.'.'),
        };
        $entityKey = match ($family) {
            'model_hub' => 'big-five',
            'facet_hub' => 'facets',
            default => $this->routeTail($route),
        };
        $slug = preg_replace('#^/(?:en|zh)/personality/#', '', $route);
        if (! is_string($slug) || $slug === '') {
            throw new RuntimeException('Unable to derive personality asset slug from '.$route.'.');
        }

        return $this->baseDescriptor($asset, PersonalityPublicContentAsset::class, [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'locale' => $locale,
        ], [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $this->title($asset, $payload),
            'summary' => $this->summary($payload),
            'content_sections_json' => is_array($payload['sections'] ?? null) ? $payload['sections'] : [],
            'seo_json' => ['title' => $this->title($asset, $payload), 'description' => $this->summary($payload)],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => $route],
            'hreflang_json' => ['status' => (string) $asset['bilingual_identity']],
            'faq_json' => is_array($payload['faq'] ?? null) ? $payload['faq'] : [],
            'media_json' => [],
            'schema_json' => [],
            'method_boundary_json' => $payload['method_boundary'] ?? $payload['claims'] ?? [],
            'evidence_notes_json' => $payload['visible_sources'] ?? $payload['source_mapping'] ?? [],
            'authority_json' => ['route' => $route, 'asset_id' => (string) $asset['asset_id']],
            'internal_links_json' => $payload['internal_links'] ?? $payload['internal_link_targets'] ?? [],
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
            'source_package' => (string) $asset['source_package'],
            'source_hash' => (string) $asset['source_hash'],
            'published_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload @return array<string,mixed> */
    private function topicProfileDescriptor(array $asset, array $payload): array
    {
        $locale = (string) $asset['locale'];
        $slug = $this->routeTail((string) $asset['route']);

        return $this->baseDescriptor($asset, TopicProfile::class, ['org_id' => 0, 'topic_code' => $slug, 'locale' => $locale], [
            'org_id' => 0,
            'topic_code' => $slug,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $this->title($asset, $payload),
            'subtitle' => $this->nullableString($payload['introduction'] ?? $payload['subtitle'] ?? null),
            'excerpt' => $this->summary($payload),
            'hero_kicker' => 'Big Five Authority V2 draft',
            'hero_quote' => implode(' · ', array_map('strval', is_array($payload['groups'] ?? null) ? $payload['groups'] : [])),
            'cover_image_url' => null,
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'big5-authority-v2-draft.v1',
            'sort_order' => 0,
        ]);
    }

    /** @param array<string,mixed> $asset @param class-string<Model> $model @param array<string,mixed> $identity @param array<string,mixed> $attributes @return array<string,mixed> */
    private function baseDescriptor(array $asset, string $model, array $identity, array $attributes): array
    {
        return [
            'asset_id' => (string) $asset['asset_id'],
            'route' => (string) $asset['route'],
            'authority_surface' => (string) $asset['authority_surface'],
            'model' => $model,
            'identity' => $identity,
            'attributes' => $attributes,
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private function existingPrimaryRecord(array $descriptor, bool $lock): ?Model
    {
        /** @var class-string<Model> $model */
        $model = $descriptor['model'];
        $query = $model::query()->withoutGlobalScopes();
        if ($model === Article::class) {
            $query->withTrashed();
        }
        foreach ($descriptor['identity'] as $field => $value) {
            $query->where($field, $value);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array<string,mixed> $descriptor */
    private function createPrimaryRecord(array $descriptor): Model
    {
        /** @var class-string<Model> $model */
        $model = $descriptor['model'];

        return $model::query()->withoutGlobalScopes()->create($descriptor['attributes']);
    }

    /** @param list<array<string,mixed>> $descriptors @return array<string,mixed> */
    private function readback(array $descriptors): array
    {
        $surfaceCounts = [];
        $issues = [];
        foreach ($descriptors as $descriptor) {
            $record = $this->existingPrimaryRecord($descriptor, true);
            if (! $record instanceof Model) {
                $issues[] = $descriptor['asset_id'].':missing';

                continue;
            }
            $surface = (string) $descriptor['authority_surface'];
            $surfaceCounts[$surface] = ($surfaceCounts[$surface] ?? 0) + 1;
            if (! $this->isFailClosedDraft($record)) {
                $issues[] = $descriptor['asset_id'].':not_fail_closed_draft';
            }
        }
        ksort($surfaceCounts);

        return [
            'ok' => $issues === [] && $surfaceCounts === $this->sortedSurfaceCounts(),
            'primary_record_count' => array_sum($surfaceCounts),
            'surface_counts' => $surfaceCounts,
            'issues' => $issues,
        ];
    }

    private function isFailClosedDraft(Model $record): bool
    {
        if ($record instanceof PersonalityPublicContentAsset) {
            return $record->launch_state === PersonalityPublicContentAsset::LAUNCH_DRAFT
                && $record->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW
                && ! $record->is_public
                && ! $record->index_eligible
                && ! $record->sitemap_eligible
                && ! $record->llms_eligible;
        }

        return (string) $record->status === 'draft'
            && ! (bool) $record->is_public
            && ! (bool) $record->is_indexable;
    }

    private function assertRequiredTablesExist(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required CMS authority table is missing: '.$table.'.');
            }
        }
    }

    /** @return array{array<string,mixed>,string,string}|array{array<string,mixed>,string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with(trim($path), DIRECTORY_SEPARATOR) ? trim($path) : base_path(trim($path));
        if (! File::isFile($resolved)) {
            throw new RuntimeException($label.' not found: '.$resolved.'.');
        }
        $raw = (string) File::get($resolved);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' must be a JSON object.');
        }

        return $label === 'draft import package'
            ? [$decoded, $resolved, hash('sha256', $raw)]
            : [$decoded, $resolved];
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $payload */
    private function title(array $asset, array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? ''));

        return $title !== '' ? $title : (string) $asset['asset_id'];
    }

    /** @param array<string,mixed> $payload */
    private function summary(array $payload): string
    {
        foreach (['summary', 'excerpt', 'introduction', 'primary_question', 'subtitle'] as $field) {
            $value = trim((string) ($payload[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Big Five Authority V2 draft candidate pending manual review.';
    }

    /** @param array<string,mixed> $payload */
    private function markdown(array $payload): string
    {
        $sections = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $parts = [];
        foreach ($sections as $index => $section) {
            if (is_string($section) && trim($section) !== '') {
                $parts[] = trim($section);

                continue;
            }
            if (! is_array($section)) {
                continue;
            }
            $heading = trim((string) ($section['heading'] ?? $section['title'] ?? $section['key'] ?? 'Section '.($index + 1)));
            $body = trim((string) ($section['body_md'] ?? $section['body'] ?? $section['content'] ?? ''));
            if ($body === '') {
                $body = json_encode($section, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $parts[] = '## '.$heading."\n\n".$body;
        }
        if ($parts === []) {
            $parts[] = '## Draft candidate'."\n\n".$this->summary($payload);
        }

        return implode("\n\n", $parts);
    }

    private function routeTail(string $route): string
    {
        $tail = basename(parse_url($route, PHP_URL_PATH) ?: $route);
        if ($tail === '' || $tail === '.' || $tail === '/') {
            throw new RuntimeException('Unable to derive route identity from '.$route.'.');
        }

        return strtolower($tail);
    }

    private function translationGroup(string $route): string
    {
        $withoutLocale = preg_replace('#^/(?:en|zh)/#', '/', $route) ?: $route;

        return 'big5-v2-'.substr(hash('sha256', $withoutLocale), 0, 40);
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /** @return array<string,int> */
    private function sortedSurfaceCounts(): array
    {
        $counts = self::SURFACE_COUNTS;
        ksort($counts);

        return $counts;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['descriptors']);

        return $plan;
    }
}

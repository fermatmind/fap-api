<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\ReleaseGate;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveAuthorityV2CollisionSafeDraftRevisionWriter
{
    public const PRIMARY_CREATE_COUNT = 106;

    public const EXISTING_REVISION_COUNT = 125;

    public const REVISION_CREATE_COUNT = 229;

    public const NEW_WORKING_REVISION_COUNT = 104;

    public const EXISTING_POINTER_UPDATE_COUNT = 125;

    public const COLLISION_CONTRACT_SHA256 = 'fffcd07c97a7adbefc9d63c03b6523233f4b9f3c6a0c5733249da591254f3b49';

    /** @var array<string,array<string,int>> */
    private const SURFACE_ACTIONS = [
        'CMS Article' => ['primary_create' => 100, 'existing_revision' => 9, 'revision_create' => 109],
        'CMS content_pages' => ['primary_create' => 4, 'existing_revision' => 0, 'revision_create' => 4],
        'CMS landing_surfaces/page_blocks' => ['primary_create' => 2, 'existing_revision' => 0, 'revision_create' => 0],
        'CMS personality_public_content_assets' => ['primary_create' => 0, 'existing_revision' => 114, 'revision_create' => 114],
        'CMS topic_profiles' => ['primary_create' => 0, 'existing_revision' => 2, 'revision_create' => 2],
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'articles',
        'article_translation_revisions',
        'content_pages',
        'cms_translation_revisions',
        'landing_surfaces',
        'personality_public_content_assets',
        'personality_public_content_asset_revisions',
        'topic_profiles',
        'topic_profile_revisions',
    ];

    public function __construct(
        private readonly BigFiveAuthorityV2DraftImportWriter $packageWriter,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(
        string $packagePath,
        string $legacyAuthorizationPacketPath,
        string $collisionContractPath,
        string $authorizationPacketPath,
    ): array {
        return $this->publicPlan($this->buildPlan(
            $packagePath,
            $legacyAuthorizationPacketPath,
            $collisionContractPath,
            $authorizationPacketPath,
            false,
        ));
    }

    /** @return array<string,mixed> */
    public function write(
        string $packagePath,
        string $legacyAuthorizationPacketPath,
        string $collisionContractPath,
        string $authorizationPacketPath,
        int $expectedPrimaryCreate,
        int $expectedExistingRevision,
        int $expectedRevisionCreate,
        string $expectedPreflightFingerprint,
    ): array {
        return DB::transaction(function () use (
            $packagePath,
            $legacyAuthorizationPacketPath,
            $collisionContractPath,
            $authorizationPacketPath,
            $expectedPrimaryCreate,
            $expectedExistingRevision,
            $expectedRevisionCreate,
            $expectedPreflightFingerprint,
        ): array {
            $plan = $this->buildPlan(
                $packagePath,
                $legacyAuthorizationPacketPath,
                $collisionContractPath,
                $authorizationPacketPath,
                true,
            );
            $this->assertExpectedCounts($plan, $expectedPrimaryCreate, $expectedExistingRevision, $expectedRevisionCreate);
            if (! hash_equals((string) $plan['preflight_fingerprint'], $expectedPreflightFingerprint)) {
                throw new RuntimeException('Production preflight fingerprint changed before write; transaction aborted.');
            }

            $existingRuntimeFingerprint = (string) $plan['existing_public_runtime_fingerprint'];
            $written = [];
            foreach ($plan['descriptors'] as $descriptor) {
                $record = $descriptor['action'] === 'create_primary_draft'
                    ? $this->createPrimaryRecord($descriptor)
                    : $this->existingRecord($descriptor, true);

                if (! $record instanceof Model) {
                    throw new RuntimeException('Primary record disappeared during collision-safe import: '.$descriptor['asset_id'].'.');
                }

                $revisionId = $this->createWorkingRevision($descriptor, $record);
                $written[] = [
                    'asset_id' => (string) $descriptor['asset_id'],
                    'action' => (string) $descriptor['action'],
                    'primary_id' => (int) $record->getKey(),
                    'revision_id' => $revisionId,
                ];
            }

            $afterFingerprint = $this->existingPublicRuntimeFingerprint($plan['descriptors'], true);
            if (! hash_equals($existingRuntimeFingerprint, $afterFingerprint)) {
                throw new RuntimeException('Existing published public-runtime fingerprint changed; transaction rolled back.');
            }

            $readback = $this->readback($plan['descriptors'], $written, $existingRuntimeFingerprint);
            if (($readback['ok'] ?? false) !== true) {
                throw new RuntimeException('Collision-safe post-write readback failed; transaction rolled back.');
            }

            return [
                ...$this->publicPlan($plan),
                'status' => 'PASS_COLLISION_SAFE_DRAFT_REVISION_IMPORT',
                'writes_committed' => true,
                'primary_records_created' => self::PRIMARY_CREATE_COUNT,
                'existing_primary_public_content_overwrites' => 0,
                'working_or_draft_revisions_created' => self::REVISION_CREATE_COUNT,
                'existing_working_pointers_updated' => self::EXISTING_POINTER_UPDATE_COUNT,
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

    /** @param array<string,mixed> $plan */
    public function assertExpectedCounts(
        array $plan,
        int $expectedPrimaryCreate,
        int $expectedExistingRevision,
        int $expectedRevisionCreate,
    ): void {
        if ($expectedPrimaryCreate !== self::PRIMARY_CREATE_COUNT
            || $expectedExistingRevision !== self::EXISTING_REVISION_COUNT
            || $expectedRevisionCreate !== self::REVISION_CREATE_COUNT) {
            throw new RuntimeException('Authorized collision-safe counts are fixed at primary_create=106, existing_revision=125, revision_create=229.');
        }

        foreach ([
            'primary_create_count' => $expectedPrimaryCreate,
            'existing_revision_count' => $expectedExistingRevision,
            'revision_create_count' => $expectedRevisionCreate,
        ] as $field => $expected) {
            if ((int) ($plan[$field] ?? -1) !== $expected) {
                throw new RuntimeException(sprintf(
                    'Collision-safe preflight count mismatch for %s: expected=%d observed=%d.',
                    $field,
                    $expected,
                    (int) ($plan[$field] ?? -1),
                ));
            }
        }
    }

    public function approvalPhrase(string $deploySha, string $preflightFingerprint): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1) {
            throw new RuntimeException('Deploy SHA must be an exact lowercase 40-character Git SHA.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $preflightFingerprint) !== 1) {
            throw new RuntimeException('Preflight fingerprint must be an exact lowercase SHA-256.');
        }

        return sprintf(
            'AUTHORIZE BIG5 AUTHORITY V2 COLLISION-SAFE DRAFT REVISION IMPORT FOR DEPLOY_SHA=%s PR37_MERGE_SHA=%s PACKAGE_SHA256=%s COLLISION_CONTRACT_SHA256=%s PREFLIGHT_FINGERPRINT=%s ASSET_COUNT=231 PRIMARY_CREATE=106 EXISTING_REVISION=125 REVISION_CREATE=229 PUBLIC_CONTENT_OVERWRITE=0; PUBLIC_RELEASE=0; INDEXABILITY=0; SITEMAP=0; LLMS=0; MEDIA=0; CACHE=0; SEARCH_SUBMISSION=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            self::COLLISION_CONTRACT_SHA256,
            $preflightFingerprint,
        );
    }

    /** @return array<string,mixed> */
    private function buildPlan(
        string $packagePath,
        string $legacyAuthorizationPacketPath,
        string $collisionContractPath,
        string $authorizationPacketPath,
        bool $lock,
    ): array {
        $this->assertSchema();
        [$contract, $resolvedContractPath, $contractSha] = $this->readJson($collisionContractPath, 'collision-safe preflight contract');
        [$authorization, $resolvedAuthorizationPath] = $this->readJson($authorizationPacketPath, 'collision-safe authorization packet');
        $this->assertCollisionContract($contract, $authorization, $contractSha);

        $legacyPlan = $this->packageWriter->validatedPlan($packagePath, $legacyAuthorizationPacketPath, $lock);
        $descriptors = [];
        $surfaceActions = [];
        foreach ($legacyPlan['descriptors'] as $descriptor) {
            $action = $descriptor['existing_id'] === null ? 'create_primary_draft' : 'create_isolated_working_revision';
            $surface = (string) $descriptor['authority_surface'];
            $surfaceActions[$surface] ??= ['primary_create' => 0, 'existing_revision' => 0, 'revision_create' => 0];
            $surfaceActions[$surface][$action === 'create_primary_draft' ? 'primary_create' : 'existing_revision']++;
            if ($this->revisionManaged($descriptor)) {
                $surfaceActions[$surface]['revision_create']++;
            }

            $planned = [...$descriptor, 'action' => $action];
            if ($action === 'create_isolated_working_revision') {
                $record = $this->existingRecord($planned, $lock);
                if (! $record instanceof Model) {
                    throw new RuntimeException('Existing identity disappeared during preflight: '.$descriptor['asset_id'].'.');
                }
                $this->assertPublishedRecordCanReceiveIsolatedDraft($record, $planned);
            }
            $this->assertNoImportedRevisionAlreadyExists($planned);
            $descriptors[] = $planned;
        }
        ksort($surfaceActions);
        if ($surfaceActions !== $this->sortedSurfaceActions()) {
            throw new RuntimeException('Collision-safe surface action counts do not match the approved production identity diagnosis.');
        }

        $existingFingerprint = $this->existingPublicRuntimeFingerprint($descriptors, $lock);
        $preflightFingerprint = $this->fingerprint([
            'collision_contract_sha256' => self::COLLISION_CONTRACT_SHA256,
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'draft_import_package_sha256' => (string) $legacyPlan['draft_import_package_sha256'],
            'existing_public_runtime_fingerprint' => $existingFingerprint,
            'actions' => array_map(static fn (array $descriptor): array => [
                'asset_id' => (string) $descriptor['asset_id'],
                'authority_surface' => (string) $descriptor['authority_surface'],
                'action' => (string) $descriptor['action'],
                'existing_id' => $descriptor['existing_id'],
                'identity' => $descriptor['identity'],
            ], $descriptors),
        ]);

        return [
            'ok' => true,
            'status' => 'PASS_COLLISION_SAFE_READ_ONLY_PREFLIGHT',
            'mode' => 'draft_revision_no_public_runtime_mutation',
            'package_path' => (string) $legacyPlan['package_path'],
            'legacy_authorization_packet_path' => (string) $legacyPlan['authorization_packet_path'],
            'collision_contract_path' => $resolvedContractPath,
            'authorization_packet_path' => $resolvedAuthorizationPath,
            'pr37_merge_sha' => BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'draft_import_package_sha256' => (string) $legacyPlan['draft_import_package_sha256'],
            'collision_contract_sha256' => self::COLLISION_CONTRACT_SHA256,
            'asset_count' => 231,
            'primary_create_count' => self::PRIMARY_CREATE_COUNT,
            'existing_revision_count' => self::EXISTING_REVISION_COUNT,
            'revision_create_count' => self::REVISION_CREATE_COUNT,
            'new_working_revision_count' => self::NEW_WORKING_REVISION_COUNT,
            'existing_pointer_update_count' => self::EXISTING_POINTER_UPDATE_COUNT,
            'existing_primary_public_content_overwrite_count' => 0,
            'surface_actions' => $surfaceActions,
            'existing_public_runtime_fingerprint' => $existingFingerprint,
            'preflight_fingerprint' => $preflightFingerprint,
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

    /** @param array<string,mixed> $descriptor */
    private function createPrimaryRecord(array $descriptor): Model
    {
        $model = $descriptor['model'];
        /** @var Model $record */
        $record = $model::query()->withoutGlobalScopes()->create($descriptor['attributes']);

        return $record;
    }

    /** @param array<string,mixed> $descriptor */
    private function createWorkingRevision(array $descriptor, Model $record): ?int
    {
        return match (true) {
            $record instanceof Article => $this->createArticleRevision($record, $descriptor),
            $record instanceof ContentPage => $this->createContentPageRevision($record, $descriptor),
            $record instanceof PersonalityPublicContentAsset => $this->createPersonalityRevision($record, $descriptor),
            $record instanceof TopicProfile => $this->createTopicRevision($record, $descriptor),
            $record instanceof LandingSurface => null,
            default => throw new RuntimeException('Unsupported revision target model.'),
        };
    }

    /** @param array<string,mixed> $descriptor */
    private function createArticleRevision(Article $article, array $descriptor): int
    {
        $currentWorkingId = $article->working_revision_id ? (int) $article->working_revision_id : null;
        $next = ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->max('revision_number')) + 1;
        $attributes = $descriptor['attributes'];
        $bodyHash = Article::sourceVersionHashFromPayload([
            'locale' => $article->locale,
            'title' => $attributes['title'],
            'excerpt' => $attributes['excerpt'],
            'content_md' => $attributes['content_md'],
            'content_html' => $attributes['content_html'],
            'cover_image_alt' => $article->cover_image_alt,
            'related_test_slug' => $attributes['related_test_slug'],
            'voice' => $article->voice,
            'voice_order' => $article->voice_order,
        ]);

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $attributes['translation_group_id'],
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->locale,
            'revision_number' => $next,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'source_version_hash' => $bodyHash,
            'translated_from_version_hash' => $bodyHash,
            'supersedes_revision_id' => $currentWorkingId,
            'authority_asset_key' => (string) $descriptor['asset_id'],
            'authority_source_package' => $this->sourcePackage($descriptor),
            'authority_source_hash' => $this->sourceHash($descriptor),
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'authority_metadata_json' => [
                'route' => (string) $descriptor['route'],
                'authority_surface' => (string) $descriptor['authority_surface'],
                'draft_attributes' => $attributes,
                'public_runtime_mutation_allowed' => false,
            ],
            'title' => (string) $attributes['title'],
            'excerpt' => $attributes['excerpt'],
            'content_md' => (string) $attributes['content_md'],
            'seo_title' => (string) $attributes['title'],
            'seo_description' => $attributes['excerpt'],
        ]);
        $this->setWorkingPointer($article, (int) $revision->id, $currentWorkingId);

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function createContentPageRevision(ContentPage $page, array $descriptor): int
    {
        $currentWorkingId = $page->working_revision_id ? (int) $page->working_revision_id : null;
        $next = ((int) CmsTranslationRevision::query()->withoutGlobalScopes()
            ->where('content_type', 'content_page')
            ->where('content_id', (int) $page->id)
            ->max('revision_number')) + 1;
        $payload = [
            ...$descriptor['attributes'],
            '_big_five_authority_v2_import' => [
                'asset_id' => (string) $descriptor['asset_id'],
                'route' => (string) $descriptor['route'],
                'source_package' => $this->sourcePackage($descriptor),
                'source_hash' => $this->sourceHash($descriptor),
                'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
                'public_runtime_mutation_allowed' => false,
            ],
        ];

        $revision = CmsTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $page->org_id,
            'content_type' => 'content_page',
            'content_id' => (int) $page->id,
            'source_content_id' => null,
            'translation_group_id' => (string) $page->translation_group_id,
            'locale' => (string) $page->locale,
            'source_locale' => (string) $page->locale,
            'revision_number' => $next,
            'revision_status' => CmsTranslationRevision::STATUS_HUMAN_REVIEW,
            'source_version_hash' => (string) $page->source_version_hash,
            'translated_from_version_hash' => (string) $page->source_version_hash,
            'payload_json' => $payload,
            'supersedes_revision_id' => $currentWorkingId,
            'authority_asset_key' => (string) $descriptor['asset_id'],
            'authority_source_package' => $this->sourcePackage($descriptor),
            'authority_source_hash' => $this->sourceHash($descriptor),
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
        ]);
        $this->setWorkingPointer($page, (int) $revision->id, $currentWorkingId);

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function createPersonalityRevision(PersonalityPublicContentAsset $asset, array $descriptor): int
    {
        $currentWorkingId = $asset->working_revision_id ? (int) $asset->working_revision_id : null;
        $next = ((int) PersonalityPublicContentAssetRevision::query()
            ->where('asset_id', (int) $asset->id)
            ->max('revision_no')) + 1;
        $revision = PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => (int) $asset->id,
            'revision_no' => $next,
            'authority_asset_key' => (string) $descriptor['asset_id'],
            'source_package' => $this->sourcePackage($descriptor),
            'source_hash' => $this->sourceHash($descriptor),
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'workflow_state' => PersonalityPublicContentAssetRevision::STATE_DRAFT,
            'snapshot_json' => $descriptor['attributes'],
            'public_runtime_fingerprint_before' => $this->recordPublicRuntimeFingerprint($asset),
        ]);
        $this->setWorkingPointer($asset, (int) $revision->id, $currentWorkingId);

        return (int) $revision->id;
    }

    /** @param array<string,mixed> $descriptor */
    private function createTopicRevision(TopicProfile $topic, array $descriptor): int
    {
        $currentWorkingId = $topic->working_revision_id ? (int) $topic->working_revision_id : null;
        $next = ((int) TopicProfileRevision::query()
            ->where('profile_id', (int) $topic->id)
            ->max('revision_no')) + 1;
        $revision = TopicProfileRevision::query()->create([
            'profile_id' => (int) $topic->id,
            'revision_no' => $next,
            'authority_asset_key' => (string) $descriptor['asset_id'],
            'source_package' => $this->sourcePackage($descriptor),
            'source_hash' => $this->sourceHash($descriptor),
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'workflow_state' => 'draft',
            'snapshot_json' => ['profile' => $descriptor['attributes']],
            'public_runtime_fingerprint_before' => $this->recordPublicRuntimeFingerprint($topic),
            'note' => 'Big Five Authority V2 isolated draft import; public runtime unchanged.',
            'created_at' => now(),
        ]);
        $this->setWorkingPointer($topic, (int) $revision->id, $currentWorkingId);

        return (int) $revision->id;
    }

    private function setWorkingPointer(Model $record, int $revisionId, ?int $expectedCurrent): void
    {
        $query = DB::table($record->getTable())->where($record->getKeyName(), $record->getKey());
        $expectedCurrent === null
            ? $query->whereNull('working_revision_id')
            : $query->where('working_revision_id', $expectedCurrent);
        if ($query->update(['working_revision_id' => $revisionId]) !== 1) {
            throw new RuntimeException('Working revision pointer changed concurrently; transaction aborted.');
        }
        $record->setAttribute('working_revision_id', $revisionId);
    }

    /** @param array<string,mixed> $descriptor */
    private function assertPublishedRecordCanReceiveIsolatedDraft(Model $record, array $descriptor): void
    {
        $published = match (true) {
            $record instanceof Article => (string) $record->status === 'published' && (bool) $record->is_public,
            $record instanceof PersonalityPublicContentAsset => (string) $record->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED && (bool) $record->is_public,
            $record instanceof TopicProfile => (string) $record->status === TopicProfile::STATUS_PUBLISHED && (bool) $record->is_public,
            default => false,
        };
        if (! $published) {
            throw new RuntimeException('Existing identity is not the expected published/public authority record: '.$descriptor['asset_id'].'.');
        }

        if ($record instanceof Article) {
            $working = $record->working_revision_id ? (int) $record->working_revision_id : null;
            $publishedRevision = $record->published_revision_id ? (int) $record->published_revision_id : null;
            if ($publishedRevision === null || $working !== $publishedRevision) {
                throw new RuntimeException('Existing Article has an isolated working draft or missing published revision: '.$descriptor['asset_id'].'.');
            }
        } elseif ($record->working_revision_id !== null) {
            throw new RuntimeException('Existing identity already has a working revision: '.$descriptor['asset_id'].'.');
        }
    }

    /** @param array<string,mixed> $descriptor */
    private function assertNoImportedRevisionAlreadyExists(array $descriptor): void
    {
        $assetKey = (string) $descriptor['asset_id'];
        $exists = match ($descriptor['model']) {
            Article::class => ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)
                ->where('authority_asset_key', $assetKey)->exists(),
            ContentPage::class => CmsTranslationRevision::query()->withoutGlobalScopes()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)
                ->where('authority_asset_key', $assetKey)->exists(),
            PersonalityPublicContentAsset::class => PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)
                ->where('authority_asset_key', $assetKey)->exists(),
            TopicProfile::class => TopicProfileRevision::query()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)
                ->where('authority_asset_key', $assetKey)->exists(),
            LandingSurface::class => false,
            default => true,
        };
        if ($exists) {
            throw new RuntimeException('Authority draft revision already exists for '.$assetKey.'; retry requires a new exact package.');
        }
    }

    /** @param array<string,mixed> $descriptor */
    private function existingRecord(array $descriptor, bool $lock): ?Model
    {
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

    /** @param list<array<string,mixed>> $descriptors */
    private function existingPublicRuntimeFingerprint(array $descriptors, bool $lock): string
    {
        $rows = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor['action'] !== 'create_isolated_working_revision') {
                continue;
            }
            $record = $this->existingRecord($descriptor, $lock);
            if (! $record instanceof Model) {
                throw new RuntimeException('Existing public record missing while fingerprinting.');
            }
            $rows[] = [
                'asset_id' => (string) $descriptor['asset_id'],
                'model' => (string) $descriptor['model'],
                'primary_id' => (int) $record->getKey(),
                'runtime_attributes' => $this->publicRuntimeAttributes($record),
            ];
        }

        return $this->fingerprint($rows);
    }

    private function recordPublicRuntimeFingerprint(Model $record): string
    {
        return $this->fingerprint($this->publicRuntimeAttributes($record));
    }

    /** @return array<string,mixed> */
    private function publicRuntimeAttributes(Model $record): array
    {
        $attributes = $record->getAttributes();
        unset($attributes['working_revision_id']);
        ksort($attributes);

        return $attributes;
    }

    /** @param list<array<string,mixed>> $descriptors @param list<array<string,mixed>> $written @return array<string,mixed> */
    private function readback(array $descriptors, array $written, string $existingFingerprint): array
    {
        $issues = [];
        $revisions = [
            'article_translation_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count(),
            'cms_translation_revisions' => CmsTranslationRevision::query()->withoutGlobalScopes()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count(),
            'personality_public_content_asset_revisions' => PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count(),
            'topic_profile_revisions' => TopicProfileRevision::query()
                ->where('authority_package_sha256', BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256)->count(),
        ];
        if ($revisions !== [
            'article_translation_revisions' => 109,
            'cms_translation_revisions' => 4,
            'personality_public_content_asset_revisions' => 114,
            'topic_profile_revisions' => 2,
        ]) {
            $issues[] = 'revision_surface_counts_mismatch';
        }

        foreach ($written as $row) {
            $descriptor = collect($descriptors)->firstWhere('asset_id', $row['asset_id']);
            if (! is_array($descriptor)) {
                $issues[] = $row['asset_id'].':descriptor_missing';

                continue;
            }
            $record = $this->existingRecord($descriptor, true);
            if (! $record instanceof Model) {
                $issues[] = $row['asset_id'].':primary_missing';

                continue;
            }
            if ($row['revision_id'] !== null && (int) $record->working_revision_id !== (int) $row['revision_id']) {
                $issues[] = $row['asset_id'].':working_pointer_mismatch';
            }
            if ($descriptor['action'] === 'create_primary_draft' && ! $this->isFailClosedDraft($record)) {
                $issues[] = $row['asset_id'].':new_primary_not_fail_closed';
            }
        }

        $after = $this->existingPublicRuntimeFingerprint($descriptors, true);
        if (! hash_equals($existingFingerprint, $after)) {
            $issues[] = 'existing_public_runtime_fingerprint_mismatch';
        }

        return [
            'ok' => $issues === [] && array_sum($revisions) === self::REVISION_CREATE_COUNT,
            'primary_record_count' => count($written),
            'primary_records_created' => self::PRIMARY_CREATE_COUNT,
            'existing_primary_records_preserved' => self::EXISTING_REVISION_COUNT,
            'revision_counts' => $revisions,
            'revision_count' => array_sum($revisions),
            'existing_public_runtime_fingerprint_before' => $existingFingerprint,
            'existing_public_runtime_fingerprint_after' => $after,
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

    /** @param array<string,mixed> $descriptor */
    private function revisionManaged(array $descriptor): bool
    {
        return $descriptor['model'] !== LandingSurface::class;
    }

    /** @param array<string,mixed> $descriptor */
    private function sourcePackage(array $descriptor): string
    {
        return (string) ($descriptor['source_package']
            ?? $descriptor['attributes']['source_package']
            ?? data_get($descriptor['attributes'], 'payload_json.source_package')
            ?? 'big-five-authority-v2');
    }

    /** @param array<string,mixed> $descriptor */
    private function sourceHash(array $descriptor): string
    {
        $hash = $descriptor['source_hash']
            ?? $descriptor['attributes']['source_hash']
            ?? data_get($descriptor['attributes'], 'payload_json.source_hash');
        if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) {
            return $hash;
        }

        return $this->fingerprint($descriptor['attributes']);
    }

    private function assertSchema(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Required collision-safe draft revision table is missing: '.$table.'.');
            }
        }
        foreach ([
            'articles' => ['working_revision_id', 'published_revision_id'],
            'article_translation_revisions' => ['authority_asset_key', 'authority_package_sha256'],
            'content_pages' => ['working_revision_id', 'published_revision_id'],
            'cms_translation_revisions' => ['authority_asset_key', 'authority_package_sha256'],
            'personality_public_content_assets' => ['working_revision_id', 'published_revision_id'],
            'topic_profiles' => ['working_revision_id', 'published_revision_id'],
            'topic_profile_revisions' => ['authority_asset_key', 'authority_package_sha256', 'workflow_state'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException('Required collision-safe draft revision column is missing: '.$table.'.'.$column.'.');
                }
            }
        }
    }

    /** @param array<string,mixed> $contract @param array<string,mixed> $authorization */
    private function assertCollisionContract(array $contract, array $authorization, string $contractSha): void
    {
        if (! hash_equals(self::COLLISION_CONTRACT_SHA256, $contractSha)) {
            throw new RuntimeException('Collision-safe preflight contract SHA-256 mismatch.');
        }
        foreach ([
            'schema_version' => 'big5-authority-v2-collision-safe-preflight-contract.v1',
            'pr37_merge_sha' => BigFiveAuthorityV2DraftImportWriter::PR37_MERGE_SHA,
            'authority_package_sha256' => BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256,
            'asset_count' => 231,
            'primary_create_count' => self::PRIMARY_CREATE_COUNT,
            'existing_revision_count' => self::EXISTING_REVISION_COUNT,
            'revision_create_count' => self::REVISION_CREATE_COUNT,
            'existing_primary_public_content_overwrite_count' => 0,
        ] as $field => $expected) {
            if (($contract[$field] ?? null) !== $expected) {
                throw new RuntimeException('Collision-safe preflight contract field mismatch: '.$field.'.');
            }
        }
        if (($contract['surface_actions'] ?? null) !== self::SURFACE_ACTIONS) {
            throw new RuntimeException('Collision-safe preflight surface action contract mismatch.');
        }
        if (($authorization['schema_version'] ?? null) !== 'big5-authority-v2-collision-safe-production-authorization.v1'
            || ($authorization['status'] ?? null) !== 'HOLD_PENDING_WRITER_DEPLOY_MIGRATION_AND_READ_ONLY_PRODUCTION_PREFLIGHT'
            || ($authorization['collision_safe_preflight_contract_sha256'] ?? null) !== self::COLLISION_CONTRACT_SHA256
            || ($authorization['authority_package_sha256'] ?? null) !== BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256
            || ($authorization['asset_count'] ?? null) !== 231
            || ($authorization['production_write_currently_authorized'] ?? true) !== false) {
            throw new RuntimeException('Collision-safe authorization packet identity or hold status mismatch.');
        }
    }

    /** @return array{0:array<string,mixed>,1:string,2:string}|array{0:array<string,mixed>,1:string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException(ucfirst($label).' not found.');
        }
        $raw = File::get($resolved);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException(ucfirst($label).' must be a JSON object.');
        }

        return [$decoded, $resolved, hash('sha256', $raw)];
    }

    /** @param array<string,mixed>|list<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    /** @return array<string,array<string,int>> */
    private function sortedSurfaceActions(): array
    {
        $expected = self::SURFACE_ACTIONS;
        ksort($expected);

        return $expected;
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['descriptors']);

        return $plan;
    }
}

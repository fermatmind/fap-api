<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Models\ContentPackVersion;
use App\Services\Content\ContentControlPlaneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentControlPlaneServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $tmpRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpRoots as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    public function test_contract_exposes_control_plane_metadata_without_replacing_runtime_truth(): void
    {
        $version = $this->seedVersionFromRealMbtiPack([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_03',
            'dir_version_alias' => 'MBTI-CN-v0.3-control-plane',
        ]);

        /** @var ContentControlPlaneService $service */
        $service = app(ContentControlPlaneService::class);
        $contract = $service->forVersion($version)['content_control_plane_v1'];

        $this->assertSame('backend_filament_ops', $contract['authoring_scope']);
        $this->assertSame('content_pack_authoring_bundle', $contract['content_object_type']);
        $this->assertArrayHasKey('draft_state', $contract);
        $this->assertArrayHasKey('revision_no', $contract);
        $this->assertArrayHasKey('review_state', $contract);
        $this->assertArrayHasKey('preview_target', $contract);
        $this->assertArrayHasKey('compile_status', $contract);
        $this->assertArrayHasKey('governance_status', $contract);
        $this->assertArrayHasKey('release_candidate_status', $contract);
        $this->assertArrayHasKey('publish_target', $contract);
        $this->assertArrayHasKey('rollback_target', $contract);
        $this->assertArrayHasKey('locale_scope', $contract);
        $this->assertArrayHasKey('experiment_scope', $contract);
        $this->assertArrayHasKey('content_inventory_v1', $contract);
        $this->assertArrayHasKey('fragment_object_groups_v1', $contract);
        $this->assertArrayHasKey('runtime_artifact_ref', $contract);
        $this->assertArrayHasKey('content_objects_v1', $contract);
        $this->assertSame('compiled', $contract['compile_status']);
        $this->assertSame('passing', $contract['governance_status']);
        $this->assertNull($contract['runtime_artifact_ref']);
        $this->assertNotEmpty($contract['content_object_inventory']);

        $inventory = $contract['content_inventory_v1'];
        $this->assertSame(1, $inventory['inventory_contract_version']);
        $this->assertSame('mbti_content_inventory.v1', $inventory['governance_profile']);
        $this->assertSame('mbti_content_inventory_v1.cn-mainland.zh-CN.2026-03-ce6', $inventory['inventory_fingerprint']);
        $this->assertSame(14, $inventory['fragment_family_count']);
        $this->assertSame(8, $inventory['fragment_object_group_count']);
        $this->assertContains('tone_fragment', $inventory['fragment_object_group_keys']);
        $this->assertContains('traits.why_this_type', $inventory['section_family_keys']);
        $this->assertContains('cta.surface', $inventory['section_family_keys']);
        $this->assertArrayHasKey('selection_tag_keys', $inventory);
        $this->assertCount(8, $contract['fragment_object_groups_v1']);
        $this->assertContains('scene_fragment', array_column($contract['fragment_object_groups_v1'], 'object_group_key'));

        $objects = collect($contract['content_objects_v1']);
        $this->assertNotEmpty($objects);
        $this->assertEqualsCanonicalizing([
            'narrative_fragment',
            'calibration_copy',
            'cta_overlay',
            'faq_explainability_copy',
            'scene_fragment',
            'action_fragment',
            'stress_fragment',
            'recovery_fragment',
            'watchout_fragment',
            'tone_fragment',
            'locale_variant_draft',
            'experiment_overlay',
            'release_candidate_metadata',
        ], $objects->pluck('content_object_type')->all());

        $requiredKeys = [
            'content_object_v1',
            'content_object_id',
            'content_object_type',
            'fragment_family',
            'object_group_key',
            'authoring_scope',
            'draft_state',
            'revision_no',
            'review_state',
            'preview_target',
            'compile_status',
            'governance_status',
            'release_candidate_status',
            'publish_target',
            'rollback_target',
            'locale_scope',
            'experiment_scope',
            'runtime_binding',
            'runtime_artifact_ref',
            'source_pack_version_id',
            'source_release_id',
            'governance_profile',
            'source_refs',
        ];

        foreach ($objects as $object) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $object);
            }
        }

        $this->assertTrue($objects->every(fn (array $object): bool => $object['runtime_artifact_ref'] === null));

        $objectsByType = $objects->keyBy('content_object_type');
        $this->assertSame('tone_fragment', $objectsByType['tone_fragment']['content_object_type']);
        $this->assertSame('tone_fragment', $objectsByType['tone_fragment']['fragment_family']);
        $this->assertSame('runtime_bindable', $objectsByType['tone_fragment']['runtime_binding']);
        $this->assertContains('report_dynamic_sections.json', $objectsByType['tone_fragment']['source_refs']);
        $this->assertSame('locale_variant_draft_ready', $objectsByType['locale_variant_draft']['draft_state']);
        $this->assertSame('locale_review_ready', $objectsByType['locale_variant_draft']['review_state']);
        $this->assertSame('draft_only', $objectsByType['locale_variant_draft']['release_candidate_status']);
        $this->assertNull($objectsByType['locale_variant_draft']['publish_target']);
        $this->assertNull($objectsByType['locale_variant_draft']['runtime_artifact_ref']);

        $this->assertSame('release_metadata_ready', $objectsByType['release_candidate_metadata']['draft_state']);
        $this->assertSame('release_review_ready', $objectsByType['release_candidate_metadata']['review_state']);
        $this->assertSame('ready_for_release_review', $objectsByType['release_candidate_metadata']['release_candidate_status']);
        $this->assertNull($objectsByType['release_candidate_metadata']['publish_target']);
        $this->assertNull($objectsByType['release_candidate_metadata']['runtime_artifact_ref']);
    }

    public function test_draft_requires_publish_before_runtime_artifact_ref_exists(): void
    {
        $version = $this->seedVersionFromRealMbtiPack([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_04',
            'dir_version_alias' => 'MBTI-CN-v0.3-control-plane-draft',
        ]);

        /** @var ContentControlPlaneService $service */
        $service = app(ContentControlPlaneService::class);
        $draftContract = $service->forVersion($version)['content_control_plane_v1'];

        $this->assertSame('draft_ready', $draftContract['draft_state']);
        $this->assertNull($draftContract['runtime_artifact_ref']);
        $this->assertTrue(collect($draftContract['content_objects_v1'])->every(
            fn (array $object): bool => $object['runtime_artifact_ref'] === null
        ));
        $this->assertSame(14, $draftContract['content_inventory_v1']['fragment_family_count']);
        $this->assertSame(8, $draftContract['content_inventory_v1']['fragment_object_group_count']);

        $this->insertSuccessfulPublishRelease(
            (string) $version->id,
            (string) $version->pack_id,
            (string) $version->content_package_version,
            (string) $version->region,
            (string) $version->locale,
            (string) $version->dir_version_alias,
            'app/private/content_releases/'.$version->id.'/source_pack'
        );

        $publishedContract = $service->forVersion($version->fresh())['content_control_plane_v1'];

        $this->assertSame('published', $publishedContract['draft_state']);
        $this->assertIsArray($publishedContract['runtime_artifact_ref']);
        $this->assertSame((string) $version->content_package_version, $publishedContract['runtime_artifact_ref']['pack_version']);
        $this->assertSame('mbti_content_inventory.v1', $publishedContract['content_inventory_v1']['governance_profile']);

        $publishedObjects = collect($publishedContract['content_objects_v1'])->keyBy('content_object_type');
        $this->assertIsArray($publishedObjects['narrative_fragment']['runtime_artifact_ref']);
        $this->assertIsArray($publishedObjects['tone_fragment']['runtime_artifact_ref']);
        $this->assertSame((string) $version->id, $publishedObjects['narrative_fragment']['source_pack_version_id']);
        $this->assertNotNull($publishedObjects['narrative_fragment']['source_release_id']);
        $this->assertNull($publishedObjects['locale_variant_draft']['runtime_artifact_ref']);
        $this->assertNull($publishedObjects['locale_variant_draft']['source_release_id']);
        $this->assertSame('draft_only', $publishedObjects['locale_variant_draft']['release_candidate_status']);
        $this->assertNull($publishedObjects['release_candidate_metadata']['runtime_artifact_ref']);
        $this->assertNull($publishedObjects['release_candidate_metadata']['source_release_id']);
        $this->assertSame('published_release_metadata', $publishedObjects['release_candidate_metadata']['release_candidate_status']);
    }

    public function test_published_version_exposes_previous_release_as_rollback_target(): void
    {
        $previous = $this->seedVersionFromRealMbtiPack([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_05',
            'dir_version_alias' => 'MBTI-CN-v0.3-runtime',
        ]);
        $current = $this->seedVersionFromRealMbtiPack([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'content_2026_06',
            'dir_version_alias' => 'MBTI-CN-v0.3-runtime',
        ]);

        $previousReleaseId = $this->insertSuccessfulPublishRelease(
            (string) $previous->id,
            (string) $previous->pack_id,
            (string) $previous->content_package_version,
            (string) $previous->region,
            (string) $previous->locale,
            (string) $previous->dir_version_alias,
            'app/private/content_releases/'.$previous->id.'/source_pack',
            now()->subHour()
        );
        $this->insertSuccessfulPublishRelease(
            (string) $current->id,
            (string) $current->pack_id,
            (string) $current->content_package_version,
            (string) $current->region,
            (string) $current->locale,
            (string) $current->dir_version_alias,
            'app/private/content_releases/'.$current->id.'/source_pack',
            now()
        );

        /** @var ContentControlPlaneService $service */
        $service = app(ContentControlPlaneService::class);
        $contract = $service->forVersion($current)['content_control_plane_v1'];

        $this->assertSame('published', $contract['release_candidate_status']);
        $this->assertIsArray($contract['rollback_target']);
        $this->assertSame($previousReleaseId, $contract['rollback_target']['release_id']);
        $this->assertSame(14, $contract['content_inventory_v1']['fragment_family_count']);
        $this->assertSame(8, $contract['content_inventory_v1']['fragment_object_group_count']);

        $publishedObject = collect($contract['content_objects_v1'])
            ->first(fn (array $object): bool => is_array($object['runtime_artifact_ref'] ?? null));

        $this->assertIsArray($publishedObject);
        $this->assertIsArray($publishedObject['rollback_target']);
        $this->assertSame($previousReleaseId, $publishedObject['rollback_target']['release_id']);
    }

    /**
     * @param  array<string,string>  $overrides
     */
    private function seedVersionFromRealMbtiPack(array $overrides = []): ContentPackVersion
    {
        $sourceDir = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3');
        $versionId = (string) Str::uuid();
        $relativePath = 'content_control_plane_tests/'.$versionId.'/source_pack';
        $targetDir = storage_path('app/private/'.$relativePath);

        File::ensureDirectoryExists(dirname($targetDir));
        File::copyDirectory($sourceDir, $targetDir);
        $this->tmpRoots[] = dirname(dirname($targetDir));

        $manifest = json_decode((string) file_get_contents($sourceDir.'/manifest.json'), true);
        $this->assertIsArray($manifest);

        return ContentPackVersion::query()->create([
            'id' => $versionId,
            'region' => $overrides['region'] ?? 'CN_MAINLAND',
            'locale' => $overrides['locale'] ?? 'zh-CN',
            'pack_id' => $overrides['pack_id'] ?? (string) ($manifest['pack_id'] ?? 'MBTI.cn-mainland.zh-CN.v0.3'),
            'content_package_version' => $overrides['content_package_version'] ?? 'content_2026_03',
            'dir_version_alias' => $overrides['dir_version_alias'] ?? 'MBTI-CN-v0.3-control-plane',
            'source_type' => 'upload',
            'source_ref' => 'private://content_control_plane_tests/'.$versionId.'/pack.zip',
            'sha256' => str_repeat('a', 64),
            'manifest_json' => $manifest,
            'extracted_rel_path' => $relativePath,
            'created_by' => 'ops_admin',
        ]);
    }

    private function insertSuccessfulPublishRelease(
        string $versionId,
        string $packId,
        string $packVersion,
        string $region,
        string $locale,
        string $dirAlias,
        string $storagePath,
        ?\Illuminate\Support\Carbon $createdAt = null
    ): string {
        $releaseId = (string) Str::uuid();
        $createdAt ??= now();

        DB::table('content_pack_releases')->insert([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => $region,
            'locale' => $locale,
            'dir_alias' => $dirAlias,
            'from_version_id' => null,
            'to_version_id' => $versionId,
            'from_pack_id' => null,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => 'published',
            'created_by' => 'ops_admin',
            'probe_ok' => true,
            'probe_json' => json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'probe_run_at' => $createdAt,
            'manifest_hash' => 'manifest_'.$versionId,
            'compiled_hash' => 'compiled_'.$versionId,
            'content_hash' => 'content_'.$versionId,
            'norms_version' => '2026Q1',
            'git_sha' => 'git_'.$versionId,
            'pack_version' => $packVersion,
            'manifest_json' => json_encode(['pack_id' => $packId, 'content_package_version' => $packVersion], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => 'git_'.$versionId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $releaseId;
    }
}

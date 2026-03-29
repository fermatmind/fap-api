<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentPackVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class ContentControlPlaneService
{
    public function __construct(
        private readonly MbtiContentGovernanceService $mbtiGovernance,
    ) {}

    /**
     * @return array{content_control_plane_v1:array<string,mixed>}
     */
    public function forVersion(ContentPackVersion|array $version): array
    {
        $versionData = $this->normalizeVersion($version);
        $pack = $this->buildPackContext($versionData);
        $governance = $this->inspectGovernance($pack);
        $compile = $this->inspectCompile($pack['base_dir']);
        $release = $this->inspectRelease($versionData);

        $draftState = $this->resolveDraftState($compile['status'], $governance['status'], $release);
        $reviewState = $this->resolveReviewState($compile['status'], $governance['status'], $release);
        $localeScope = $this->resolveLocaleScope($versionData);
        $publishTarget = $this->resolvePublishTarget($versionData);
        $previewTarget = $this->resolvePreviewTarget($versionData);
        $releaseCandidateStatus = $this->resolveReleaseCandidateStatus($release, $compile['status'], $governance['status']);
        $contentObjects = $this->buildContentObjects(
            $versionData,
            $governance['content_object_inventory'],
            $draftState,
            $reviewState,
            $compile['status'],
            $governance['status'],
            $releaseCandidateStatus,
            $previewTarget,
            $publishTarget,
            $localeScope,
            $governance['experiment_scope'],
            $release['runtime_artifact_ref'],
            $release['rollback_target'],
        );

        return [
            'content_control_plane_v1' => [
                'authoring_scope' => 'backend_filament_ops',
                'content_object_type' => 'content_pack_authoring_bundle',
                'draft_state' => $draftState,
                'revision_no' => $this->resolveRevisionNo($versionData),
                'review_state' => $reviewState,
                'preview_target' => $previewTarget,
                'compile_status' => $compile['status'],
                'governance_status' => $governance['status'],
                'release_candidate_status' => $releaseCandidateStatus,
                'publish_target' => $publishTarget,
                'rollback_target' => $release['rollback_target'],
                'locale_scope' => $localeScope,
                'experiment_scope' => $governance['experiment_scope'],
                'content_inventory_v1' => $governance['content_inventory_v1'],
                'fragment_object_groups_v1' => $governance['fragment_object_groups_v1'],
                'runtime_artifact_ref' => $release['runtime_artifact_ref'],
                'cultural_context' => $governance['cultural_context'],
                'content_object_inventory' => $this->summarizeContentObjects($contentObjects),
                'content_objects_v1' => $contentObjects,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeVersion(ContentPackVersion|array $version): array
    {
        if ($version instanceof ContentPackVersion) {
            return [
                'id' => (string) $version->getKey(),
                'region' => (string) $version->region,
                'locale' => (string) $version->locale,
                'pack_id' => (string) $version->pack_id,
                'content_package_version' => (string) $version->content_package_version,
                'dir_version_alias' => (string) $version->dir_version_alias,
                'extracted_rel_path' => (string) ($version->extracted_rel_path ?? ''),
                'manifest_json' => is_array($version->manifest_json) ? $version->manifest_json : [],
                'created_at' => $version->created_at,
            ];
        }

        $payload = (array) $version;
        $manifest = $payload['manifest_json'] ?? [];
        if (is_string($manifest) && trim($manifest) !== '') {
            $decoded = json_decode($manifest, true);
            $manifest = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'region' => (string) ($payload['region'] ?? ''),
            'locale' => (string) ($payload['locale'] ?? ''),
            'pack_id' => (string) ($payload['pack_id'] ?? ''),
            'content_package_version' => (string) ($payload['content_package_version'] ?? ''),
            'dir_version_alias' => (string) ($payload['dir_version_alias'] ?? ''),
            'extracted_rel_path' => (string) ($payload['extracted_rel_path'] ?? ''),
            'manifest_json' => is_array($manifest) ? $manifest : [],
            'created_at' => $payload['created_at'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $versionData
     * @return array<string,mixed>
     */
    private function buildPackContext(array $versionData): array
    {
        $manifest = is_array($versionData['manifest_json'] ?? null) ? $versionData['manifest_json'] : [];

        return [
            'pack_id' => (string) ($versionData['pack_id'] ?? ($manifest['pack_id'] ?? '')),
            'version' => (string) ($versionData['content_package_version'] ?? ($manifest['content_package_version'] ?? '')),
            'base_dir' => $this->absoluteFromPrivate((string) ($versionData['extracted_rel_path'] ?? '')),
            'manifest' => $manifest,
        ];
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array{
     *   status:string,
     *   cultural_context:?string,
     *   experiment_scope:array<string,mixed>,
     *   content_inventory_v1:array<string,mixed>,
     *   fragment_object_groups_v1:list<array<string,mixed>>,
     *   content_object_inventory:list<array<string,mixed>>
     * }
     */
    private function inspectGovernance(array $pack): array
    {
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $baseDir = trim((string) ($pack['base_dir'] ?? ''));

        if ($baseDir === '' || ! File::isDirectory($baseDir) || ! $this->mbtiGovernance->appliesTo($pack)) {
            return [
                'status' => 'not_applicable',
                'cultural_context' => null,
                'experiment_scope' => [
                    'stable_files' => 0,
                    'experiment_files' => 0,
                    'commercial_overlay_files' => 0,
                    'experiment_keys' => [],
                    'overlay_targets' => [],
                ],
                'content_inventory_v1' => $this->defaultInventorySummary(false),
                'fragment_object_groups_v1' => [],
                'content_object_inventory' => $this->defaultObjectInventory(
                    trim((string) ($manifest['region'] ?? '')) !== '' && trim((string) ($manifest['locale'] ?? '')) !== '',
                    false,
                    false,
                    false,
                    true,
                ),
            ];
        }

        $errors = $this->mbtiGovernance->lintPack($pack);
        $document = $this->mbtiGovernance->loadDocument($baseDir);
        $inventoryDocument = $this->mbtiGovernance->loadInventoryDocument($baseDir);
        $filePolicies = is_array($document['file_policies'] ?? null) ? $document['file_policies'] : [];

        $stableFiles = 0;
        $experimentFiles = 0;
        $commercialOverlayFiles = 0;
        $experimentKeys = [];
        $overlayTargets = [];
        $narrativeFiles = 0;

        foreach ($filePolicies as $fileName => $policy) {
            if (! is_array($policy)) {
                continue;
            }

            $tier = trim((string) ($policy['content_tier'] ?? ''));
            $layers = array_values(array_filter(array_map('strval', (array) ($policy['layers'] ?? []))));

            if ($tier === 'stable') {
                $stableFiles++;
            } elseif ($tier === 'experiment') {
                $experimentFiles++;
                $experimentKey = trim((string) ($policy['experiment_key'] ?? ''));
                if ($experimentKey !== '') {
                    $experimentKeys[] = $experimentKey;
                }
            } elseif ($tier === 'commercial_overlay') {
                $commercialOverlayFiles++;
                foreach ((array) ($policy['targets'] ?? []) as $target) {
                    $target = trim((string) $target);
                    if ($target !== '') {
                        $overlayTargets[] = $target;
                    }
                }
            }

            if (array_intersect($layers, ['scene', 'explainability', 'action']) !== []) {
                $narrativeFiles++;
            }
        }

        $experimentKeys = array_values(array_unique($experimentKeys));
        $overlayTargets = array_values(array_unique($overlayTargets));
        $fragmentObjectGroups = array_values(array_filter((array) ($inventoryDocument['fragment_object_groups'] ?? []), 'is_array'));
        $contentObjectInventory = $this->mergeObjectizedFragmentInventory(
            $this->defaultObjectInventory(
                trim((string) ($document['cultural_context'] ?? '')) !== '',
                $narrativeFiles > 0,
                $experimentFiles > 0,
                $commercialOverlayFiles > 0,
                true,
            ),
            $fragmentObjectGroups
        );

        return [
            'status' => $errors === [] ? 'passing' : 'failing',
            'cultural_context' => trim((string) ($document['cultural_context'] ?? '')) ?: null,
            'experiment_scope' => [
                'stable_files' => $stableFiles,
                'experiment_files' => $experimentFiles,
                'commercial_overlay_files' => $commercialOverlayFiles,
                'experiment_keys' => $experimentKeys,
                'overlay_targets' => $overlayTargets,
            ],
            'content_inventory_v1' => is_array($inventoryDocument)
                ? $this->mbtiGovernance->summarizeInventory($inventoryDocument)
                : $this->defaultInventorySummary(true),
            'fragment_object_groups_v1' => $fragmentObjectGroups,
            'content_object_inventory' => $contentObjectInventory,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultInventorySummary(bool $enabled): array
    {
        return [
            'inventory_status' => $enabled ? 'missing' : 'not_applicable',
            'inventory_contract_version' => 0,
            'inventory_fingerprint' => null,
            'governance_profile' => null,
            'fragment_family_count' => 0,
            'fragment_family_keys' => [],
            'fragment_object_group_count' => 0,
            'fragment_object_group_keys' => [],
            'selection_tag_count' => 0,
            'selection_tag_keys' => [],
            'section_family_count' => 0,
            'section_family_keys' => [],
        ];
    }

    /**
     * @return array{status:string,compiled_dir:?string,compiled_manifest:?string}
     */
    private function inspectCompile(string $baseDir): array
    {
        $baseDir = trim($baseDir);
        if ($baseDir === '' || ! File::isDirectory($baseDir)) {
            return [
                'status' => 'source_missing',
                'compiled_dir' => null,
                'compiled_manifest' => null,
            ];
        }

        $compiledDir = $baseDir.DIRECTORY_SEPARATOR.'compiled';
        $compiledManifest = $compiledDir.DIRECTORY_SEPARATOR.'manifest.json';

        if (File::exists($compiledManifest)) {
            return [
                'status' => 'compiled',
                'compiled_dir' => $compiledDir,
                'compiled_manifest' => $compiledManifest,
            ];
        }

        return [
            'status' => 'pending_compile',
            'compiled_dir' => File::isDirectory($compiledDir) ? $compiledDir : null,
            'compiled_manifest' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $versionData
     * @return array{latest_publish:?object,current_runtime:?object,rollback_target:?array<string,mixed>,runtime_artifact_ref:?array<string,mixed>}
     */
    private function inspectRelease(array $versionData): array
    {
        $versionId = trim((string) ($versionData['id'] ?? ''));
        $region = trim((string) ($versionData['region'] ?? ''));
        $locale = trim((string) ($versionData['locale'] ?? ''));
        $dirAlias = trim((string) ($versionData['dir_version_alias'] ?? ''));
        $packId = trim((string) ($versionData['pack_id'] ?? ''));

        if ($versionId === '') {
            return [
                'latest_publish' => null,
                'current_runtime' => null,
                'rollback_target' => null,
                'runtime_artifact_ref' => null,
            ];
        }

        $latestPublish = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('to_version_id', $versionId)
            ->orderByDesc('created_at')
            ->first();

        $currentRuntime = $latestPublish && (string) ($latestPublish->status ?? '') === 'success'
            ? $latestPublish
            : null;

        $rollbackRelease = null;
        if ($currentRuntime !== null) {
            $rollbackRelease = DB::table('content_pack_releases')
                ->where('action', 'publish')
                ->where('status', 'success')
                ->where('region', $region)
                ->where('locale', $locale)
                ->when($dirAlias !== '', fn ($query) => $query->where('dir_alias', $dirAlias))
                ->where('id', '!=', (string) ($currentRuntime->id ?? ''))
                ->orderByDesc('created_at')
                ->first();
        }

        return [
            'latest_publish' => $latestPublish,
            'current_runtime' => $currentRuntime,
            'rollback_target' => $rollbackRelease ? $this->artifactRefFromRelease($rollbackRelease) : null,
            'runtime_artifact_ref' => $currentRuntime ? $this->artifactRefFromRelease($currentRuntime) : null,
        ];
    }

    private function resolveDraftState(string $compileStatus, string $governanceStatus, array $release): string
    {
        if ($release['current_runtime'] !== null) {
            return 'published';
        }

        if ($governanceStatus === 'failing') {
            return 'draft_needs_governance_fix';
        }

        if ($compileStatus !== 'compiled') {
            return 'draft_needs_compile';
        }

        return 'draft_ready';
    }

    private function resolveReviewState(string $compileStatus, string $governanceStatus, array $release): string
    {
        if ($release['current_runtime'] !== null) {
            return 'released';
        }

        if ($governanceStatus === 'failing') {
            return 'review_blocked_by_governance';
        }

        if ($compileStatus !== 'compiled') {
            return 'review_blocked_by_compile';
        }

        return 'ready_for_release_review';
    }

    private function resolveReleaseCandidateStatus(array $release, string $compileStatus, string $governanceStatus): string
    {
        if ($release['current_runtime'] !== null) {
            return 'published';
        }

        $latestPublish = $release['latest_publish'];
        if ($latestPublish && (string) ($latestPublish->status ?? '') === 'failed') {
            return 'publish_failed';
        }

        if ($compileStatus === 'compiled' && $governanceStatus !== 'failing') {
            return 'ready';
        }

        return 'not_ready';
    }

    /**
     * @param  array<string,mixed>  $versionData
     */
    private function resolveRevisionNo(array $versionData): int
    {
        $packId = trim((string) ($versionData['pack_id'] ?? ''));
        $region = trim((string) ($versionData['region'] ?? ''));
        $locale = trim((string) ($versionData['locale'] ?? ''));
        $versionId = trim((string) ($versionData['id'] ?? ''));

        if ($packId === '' || $region === '' || $locale === '' || $versionId === '') {
            return 1;
        }

        $createdAt = $versionData['created_at'] ?? null;

        $query = DB::table('content_pack_versions')
            ->where('pack_id', $packId)
            ->where('region', $region)
            ->where('locale', $locale);

        if ($createdAt !== null) {
            $query->where('created_at', '<=', $createdAt);
        }

        return max(1, (int) $query->count());
    }

    /**
     * @param  array<string,mixed>  $versionData
     */
    private function resolvePreviewTarget(array $versionData): string
    {
        $versionId = trim((string) ($versionData['id'] ?? ''));
        if ($versionId === '') {
            return 'ops://content-pack-versions/pending';
        }

        return 'ops://content-pack-versions/'.$versionId;
    }

    /**
     * @param  array<string,mixed>  $versionData
     */
    private function resolvePublishTarget(array $versionData): string
    {
        $region = trim((string) ($versionData['region'] ?? ''));
        $locale = trim((string) ($versionData['locale'] ?? ''));
        $dirAlias = trim((string) ($versionData['dir_version_alias'] ?? ''));

        if ($region === '' || $locale === '' || $dirAlias === '') {
            return 'default/pending/pending/pending';
        }

        return 'default/'.$region.'/'.$locale.'/'.$dirAlias;
    }

    /**
     * @param  array<string,mixed>  $versionData
     */
    private function resolveLocaleScope(array $versionData): string
    {
        $region = trim((string) ($versionData['region'] ?? ''));
        $locale = trim((string) ($versionData['locale'] ?? ''));

        return trim($region.'.'.$locale, '.');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultObjectInventory(
        bool $hasLocaleVariant,
        bool $hasNarrative,
        bool $hasExperimentOverlay,
        bool $hasCtaOverlay,
        bool $hasReleaseCandidate
    ): array {
        return [
            [
                'type' => 'narrative_fragment',
                'enabled' => $hasNarrative,
                'governance_profile' => 'growth_content.narrative_fragment.v1',
                'layer_scope' => ['scene', 'explainability', 'action'],
            ],
            [
                'type' => 'calibration_copy',
                'enabled' => $hasLocaleVariant,
                'governance_profile' => 'growth_content.calibration_copy.v1',
                'layer_scope' => ['boundary', 'explainability'],
            ],
            [
                'type' => 'cta_overlay',
                'enabled' => $hasCtaOverlay,
                'governance_profile' => 'growth_content.cta_overlay.v1',
                'layer_scope' => ['action'],
            ],
            [
                'type' => 'faq_explainability_copy',
                'enabled' => $hasNarrative,
                'governance_profile' => 'growth_content.faq_explainability_copy.v1',
                'layer_scope' => ['explainability'],
            ],
            [
                'type' => 'scene_fragment',
                'enabled' => $hasNarrative,
                'governance_profile' => 'growth_content.scene_fragment.v1',
                'layer_scope' => ['scene'],
            ],
            [
                'type' => 'action_fragment',
                'enabled' => $hasNarrative,
                'governance_profile' => 'growth_content.action_fragment.v1',
                'layer_scope' => ['action'],
            ],
            [
                'type' => 'locale_variant_draft',
                'enabled' => $hasLocaleVariant,
                'governance_profile' => 'growth_content.locale_variant_draft.v1',
                'layer_scope' => ['skeleton', 'boundary', 'scene', 'explainability', 'action'],
            ],
            [
                'type' => 'experiment_overlay',
                'enabled' => $hasExperimentOverlay,
                'governance_profile' => 'growth_content.experiment_overlay.v1',
                'layer_scope' => ['scene', 'action', 'explainability'],
            ],
            [
                'type' => 'release_candidate_metadata',
                'enabled' => $hasReleaseCandidate,
                'governance_profile' => 'growth_content.release_candidate_metadata.v1',
                'layer_scope' => [],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $baseInventory
     * @param  list<array<string,mixed>>  $fragmentObjectGroups
     * @return list<array<string,mixed>>
     */
    private function mergeObjectizedFragmentInventory(array $baseInventory, array $fragmentObjectGroups): array
    {
        $merged = [];

        foreach ($baseInventory as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            if ($type !== '') {
                $merged[$type] = $item;
            }
        }

        foreach ($fragmentObjectGroups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $type = trim((string) ($group['content_object_type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $existing = is_array($merged[$type] ?? null) ? $merged[$type] : [
                'type' => $type,
                'enabled' => true,
                'layer_scope' => [],
            ];

            $merged[$type] = array_merge($existing, [
                'type' => $type,
                'enabled' => true,
                'fragment_family' => trim((string) ($group['fragment_family'] ?? '')),
                'object_group_key' => trim((string) ($group['object_group_key'] ?? $type)),
                'authoring_scope' => trim((string) ($group['authoring_scope'] ?? 'backend_filament_ops')),
                'review_state_profile' => trim((string) ($group['review_state_profile'] ?? 'fragment_review')),
                'preview_target_key' => trim((string) ($group['preview_target_key'] ?? $type)),
                'release_candidate_policy' => trim((string) ($group['release_candidate_policy'] ?? 'runtime_bindable_family')),
                'publish_target_policy' => trim((string) ($group['publish_target_policy'] ?? 'compiled_artifact_only')),
                'rollback_target_policy' => trim((string) ($group['rollback_target_policy'] ?? 'release_lineage')),
                'runtime_binding' => trim((string) ($group['runtime_binding'] ?? 'runtime_bindable')),
                'locale_scope' => trim((string) ($group['locale_scope'] ?? '')),
                'experiment_scope_key' => trim((string) ($group['experiment_scope'] ?? 'stable')),
                'source_refs' => array_values(array_filter(array_map('strval', (array) ($group['source_refs'] ?? [])))),
                'governance_profile' => trim((string) ($group['governance_profile'] ?? ($existing['governance_profile'] ?? 'growth_content.default.v1'))),
            ]);
        }

        return array_values($merged);
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     * @param  array<string,mixed>  $experimentScope
     * @param  array<string,mixed>|null  $runtimeArtifactRef
     * @param  array<string,mixed>|null  $rollbackTarget
     * @return list<array<string,mixed>>
     */
    private function buildContentObjects(
        array $versionData,
        array $inventory,
        string $draftState,
        string $reviewState,
        string $compileStatus,
        string $governanceStatus,
        string $releaseCandidateStatus,
        string $previewTarget,
        string $publishTarget,
        string $localeScope,
        array $experimentScope,
        ?array $runtimeArtifactRef,
        ?array $rollbackTarget,
    ): array {
        $versionId = trim((string) ($versionData['id'] ?? ''));
        $packId = trim((string) ($versionData['pack_id'] ?? ''));
        $objects = [];

        foreach ($inventory as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $enabled = (bool) ($item['enabled'] ?? false);
            $runtimeBindable = $enabled && $this->isRuntimeBindableObject($type, $item);
            $objectId = implode(':', array_filter([$packId, $versionId, $type], static fn (string $value): bool => $value !== ''));
            $objectGroupKey = trim((string) ($item['object_group_key'] ?? ''));
            $previewTargetKey = trim((string) ($item['preview_target_key'] ?? ''));
            $previewQueryValue = $objectGroupKey !== '' ? $objectGroupKey : ($previewTargetKey !== '' ? $previewTargetKey : $type);
            $objectPreviewTarget = $enabled && $previewTarget !== ''
                ? $previewTarget.'?object='.rawurlencode($previewQueryValue)
                : null;
            $objectPublishTarget = $runtimeBindable && $publishTarget !== 'default/pending/pending/pending'
                ? $publishTarget.'#object='.rawurlencode($previewQueryValue)
                : null;
            $objectDraftState = $this->resolveObjectDraftState($type, $enabled, $draftState, $compileStatus, $governanceStatus);
            $objectReviewState = $this->resolveObjectReviewState($type, $enabled, $reviewState, $compileStatus, $governanceStatus);
            $objectReleaseCandidateStatus = $this->resolveObjectReleaseCandidateStatus($type, $enabled, $releaseCandidateStatus, $compileStatus, $governanceStatus);

            $objects[] = [
                'content_object_v1' => 'content_object.v1',
                'content_object_id' => $objectId,
                'content_object_type' => $type,
                'fragment_family' => trim((string) ($item['fragment_family'] ?? '')) ?: null,
                'object_group_key' => $objectGroupKey !== '' ? $objectGroupKey : null,
                'authoring_scope' => trim((string) ($item['authoring_scope'] ?? '')) !== ''
                    ? trim((string) ($item['authoring_scope'] ?? ''))
                    : 'backend_filament_ops',
                'draft_state' => $objectDraftState,
                'revision_no' => $this->resolveRevisionNo($versionData),
                'review_state' => $objectReviewState,
                'preview_target' => $objectPreviewTarget,
                'compile_status' => $enabled ? $compileStatus : 'not_in_scope',
                'governance_status' => $enabled ? $governanceStatus : 'not_in_scope',
                'release_candidate_status' => $objectReleaseCandidateStatus,
                'publish_target' => $objectPublishTarget,
                'rollback_target' => $runtimeBindable ? $this->decorateArtifactRef($rollbackTarget, $type, $objectId) : null,
                'locale_scope' => $localeScope,
                'experiment_scope' => $this->buildObjectExperimentScope($type, $experimentScope, $enabled),
                'runtime_artifact_ref' => $runtimeBindable ? $this->decorateArtifactRef($runtimeArtifactRef, $type, $objectId) : null,
                'source_pack_version_id' => $versionId !== '' ? $versionId : null,
                'source_release_id' => $runtimeBindable && is_array($runtimeArtifactRef)
                    ? trim((string) ($runtimeArtifactRef['release_id'] ?? '')) ?: null
                    : null,
                'source_refs' => array_values(array_filter(array_map('strval', (array) ($item['source_refs'] ?? [])))),
                'runtime_binding' => trim((string) ($item['runtime_binding'] ?? ($runtimeBindable ? 'runtime_bindable' : 'metadata_only'))),
                'governance_profile' => trim((string) ($item['governance_profile'] ?? '')) ?: 'growth_content.default.v1',
            ];
        }

        return $objects;
    }

    /**
     * @param  list<array<string,mixed>>  $contentObjects
     * @return list<array<string,mixed>>
     */
    private function summarizeContentObjects(array $contentObjects): array
    {
        return array_map(static function (array $item): array {
            return [
                'type' => (string) ($item['content_object_type'] ?? 'unknown'),
                'enabled' => ! in_array((string) ($item['draft_state'] ?? 'not_in_scope'), ['not_in_scope'], true),
                'fragment_family' => $item['fragment_family'] ?? null,
                'draft_state' => (string) ($item['draft_state'] ?? 'unknown'),
                'review_state' => (string) ($item['review_state'] ?? 'unknown'),
                'release_candidate_status' => (string) ($item['release_candidate_status'] ?? 'unknown'),
                'runtime_bound' => is_array($item['runtime_artifact_ref'] ?? null),
            ];
        }, $contentObjects);
    }

    /**
     * @param  array<string,mixed>|null  $artifact
     * @return array<string,mixed>|null
     */
    private function decorateArtifactRef(?array $artifact, string $type, string $objectId): ?array
    {
        if (! is_array($artifact) || $artifact === []) {
            return null;
        }

        return array_merge($artifact, [
            'content_object_type' => $type,
            'content_object_id' => $objectId,
        ]);
    }

    /**
     * @param  array<string,mixed>  $experimentScope
     * @return array<string,mixed>
     */
    private function buildObjectExperimentScope(string $type, array $experimentScope, bool $enabled): array
    {
        if (! $enabled) {
            return [
                'stable_files' => 0,
                'experiment_files' => 0,
                'commercial_overlay_files' => 0,
                'experiment_keys' => [],
                'overlay_targets' => [],
            ];
        }

        if (in_array($type, ['experiment_overlay', 'cta_overlay', 'release_candidate_metadata'], true)) {
            return $experimentScope;
        }

        return [
            'stable_files' => (int) ($experimentScope['stable_files'] ?? 0),
            'experiment_files' => 0,
            'commercial_overlay_files' => 0,
            'experiment_keys' => [],
            'overlay_targets' => [],
        ];
    }

    private function isRuntimeBindableObject(string $type, array $item = []): bool
    {
        $runtimeBinding = trim((string) ($item['runtime_binding'] ?? ''));
        if ($runtimeBinding !== '') {
            return $runtimeBinding === 'runtime_bindable';
        }

        return in_array($type, [
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
            'experiment_overlay',
        ], true);
    }

    private function resolveObjectDraftState(
        string $type,
        bool $enabled,
        string $draftState,
        string $compileStatus,
        string $governanceStatus
    ): string {
        if (! $enabled) {
            return 'not_in_scope';
        }

        return match ($type) {
            'locale_variant_draft' => $compileStatus === 'compiled' && $governanceStatus === 'passing'
                ? 'locale_variant_draft_ready'
                : 'locale_variant_draft_pending',
            'release_candidate_metadata' => $compileStatus === 'compiled' && $governanceStatus === 'passing'
                ? 'release_metadata_ready'
                : 'release_metadata_pending',
            default => $draftState,
        };
    }

    private function resolveObjectReviewState(
        string $type,
        bool $enabled,
        string $reviewState,
        string $compileStatus,
        string $governanceStatus
    ): string {
        if (! $enabled) {
            return 'not_in_scope';
        }

        return match ($type) {
            'locale_variant_draft' => $compileStatus === 'compiled' && $governanceStatus === 'passing'
                ? 'locale_review_ready'
                : 'locale_review_pending',
            'release_candidate_metadata' => $compileStatus === 'compiled' && $governanceStatus === 'passing'
                ? 'release_review_ready'
                : 'release_review_pending',
            default => $reviewState,
        };
    }

    private function resolveObjectReleaseCandidateStatus(
        string $type,
        bool $enabled,
        string $releaseCandidateStatus,
        string $compileStatus,
        string $governanceStatus
    ): string {
        if (! $enabled) {
            return 'not_in_scope';
        }

        return match ($type) {
            'locale_variant_draft' => 'draft_only',
            'release_candidate_metadata' => $compileStatus === 'compiled' && $governanceStatus === 'passing'
                ? ($releaseCandidateStatus === 'published' ? 'published_release_metadata' : 'ready_for_release_review')
                : 'not_ready',
            default => $releaseCandidateStatus,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function artifactRefFromRelease(object $release): array
    {
        return [
            'release_id' => (string) ($release->id ?? ''),
            'dir_alias' => (string) ($release->dir_alias ?? ''),
            'storage_path' => (string) ($release->storage_path ?? ''),
            'manifest_hash' => (string) ($release->manifest_hash ?? ''),
            'compiled_hash' => (string) ($release->compiled_hash ?? ''),
            'content_hash' => (string) ($release->content_hash ?? ''),
            'pack_version' => (string) ($release->pack_version ?? ''),
        ];
    }

    private function absoluteFromPrivate(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            return '';
        }

        return rtrim(storage_path('app/private'), '/\\').DIRECTORY_SEPARATOR.ltrim($relPath, '/\\');
    }
}

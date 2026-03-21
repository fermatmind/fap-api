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
    ) {
    }

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

        return [
            'content_control_plane_v1' => [
                'authoring_scope' => 'backend_filament_ops',
                'content_object_type' => 'content_pack_authoring_bundle',
                'draft_state' => $draftState,
                'revision_no' => $this->resolveRevisionNo($versionData),
                'review_state' => $reviewState,
                'preview_target' => $this->resolvePreviewTarget($versionData),
                'compile_status' => $compile['status'],
                'governance_status' => $governance['status'],
                'release_candidate_status' => $this->resolveReleaseCandidateStatus($release, $compile['status'], $governance['status']),
                'publish_target' => $this->resolvePublishTarget($versionData),
                'rollback_target' => $release['rollback_target'],
                'locale_scope' => $this->resolveLocaleScope($versionData),
                'experiment_scope' => $governance['experiment_scope'],
                'runtime_artifact_ref' => $release['runtime_artifact_ref'],
                'cultural_context' => $governance['cultural_context'],
                'content_object_inventory' => $governance['content_object_inventory'],
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
            'content_object_inventory' => $this->defaultObjectInventory(
                trim((string) ($document['cultural_context'] ?? '')) !== '',
                $narrativeFiles > 0,
                $experimentFiles > 0,
                $commercialOverlayFiles > 0,
                true,
            ),
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
            ],
            [
                'type' => 'calibration_copy',
                'enabled' => $hasLocaleVariant,
            ],
            [
                'type' => 'cta_overlay',
                'enabled' => $hasCtaOverlay,
            ],
            [
                'type' => 'experiment_overlay',
                'enabled' => $hasExperimentOverlay,
            ],
            [
                'type' => 'locale_variant_draft',
                'enabled' => $hasLocaleVariant,
            ],
            [
                'type' => 'release_candidate_metadata',
                'enabled' => $hasReleaseCandidate,
            ],
        ];
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

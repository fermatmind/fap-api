<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\ContentPackRelease;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Enneagram\EnneagramTechnicalNoteService;
use App\Services\Enneagram\Registry\RegistryValidator;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class EnneagramRegistryOpsService
{
    private const PACK_ID = 'ENNEAGRAM';

    private const PACK_VERSION = 'v2';

    private const STORAGE_PATH = 'repo://content_packs/ENNEAGRAM/v2/registry';

    /**
     * @var list<string>
     */
    private const FUTURE_WORKPLACE_MODULES = [
        'manager_guide',
        'team_conflict_profile',
        'collaboration_pair_guide',
        'leadership_trigger_points',
        'communication_manual_for_others',
    ];

    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
        private readonly RegistryValidator $registryValidator,
        private readonly EnneagramTechnicalNoteService $technicalNoteService,
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
        private readonly ContentReleaseSnapshotCatalogService $snapshotCatalogService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preview(): array
    {
        $pack = $this->packLoader->loadRegistryPack();
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $registries = is_array($pack['registries'] ?? null) ? $pack['registries'] : [];
        $registryReleaseHash = trim((string) ($pack['release_hash'] ?? ''));
        $validationErrors = $this->registryValidator->validate($pack);
        $technicalNoteContract = $this->technicalNoteService->contract();
        $technicalNote = is_array($technicalNoteContract['technical_note_v1'] ?? null)
            ? $technicalNoteContract['technical_note_v1']
            : [];

        $contentMaturitySummary = $this->distributionSummary($registries, 'content_maturity');
        $evidenceLevelSummary = $this->distributionSummary($registries, 'evidence_level');
        $theoryHintSafety = $this->theoryHintSafety((array) ($pack['theory_hint_registry'] ?? []));
        $sampleReports = $this->sampleReportPreview((array) ($pack['sample_report_registry'] ?? []));
        $observationPreview = $this->observationPreview((array) ($pack['observation_registry'] ?? []));
        $groupPreview = $this->groupPreview((array) ($pack['group_registry'] ?? []));
        $pairPreview = $this->pairPreview((array) ($pack['pair_registry'] ?? []));
        $typePreview = $this->typePreview((array) ($pack['type_registry'] ?? []));
        $methodPreview = $this->methodPreview((array) ($pack['method_registry'] ?? []));
        $technicalNotePreview = $this->technicalNotePreview($technicalNote);
        $workplacePlaceholder = $this->workplacePlaceholder($manifest, $registries);
        $releaseState = $this->releaseState();

        return [
            'scale_code' => self::PACK_ID,
            'registry_version' => trim((string) ($manifest['registry_version'] ?? '')),
            'release_id' => trim((string) ($manifest['release_id'] ?? '')),
            'registry_release_hash' => $registryReleaseHash,
            'registry_root' => (string) ($pack['root'] ?? ''),
            'required_registries' => array_values((array) ($manifest['registries'] ?? [])),
            'validation' => [
                'status' => $validationErrors === [] ? 'passed' : 'failed',
                'errors' => $validationErrors,
                'can_publish' => $validationErrors === [],
            ],
            'registry_files' => $this->registryFileSummary($manifest, $registries),
            'content_maturity_summary' => $contentMaturitySummary,
            'evidence_level_summary' => $evidenceLevelSummary,
            'coverage' => [
                'type_count' => (int) ($typePreview['type_count'] ?? 0),
                'p0_pair_coverage_count' => (int) ($pairPreview['p0_pair_coverage_count'] ?? 0),
                'group_count' => (int) ($groupPreview['group_count'] ?? 0),
                'observation_day_coverage' => (int) ($observationPreview['day_count'] ?? 0),
                'sample_report_count' => (int) ($sampleReports['sample_count'] ?? 0),
                'technical_note_sections_count' => (int) ($technicalNotePreview['section_count'] ?? 0),
                'method_boundary_count' => (int) ($methodPreview['boundary_count'] ?? 0),
            ],
            'type_preview' => $typePreview,
            'pair_preview' => $pairPreview,
            'group_preview' => $groupPreview,
            'observation_preview' => $observationPreview,
            'method_preview' => $methodPreview,
            'sample_report_preview' => $sampleReports,
            'technical_note_preview' => $technicalNotePreview,
            'theory_hint_safety' => $theoryHintSafety,
            'workplace_placeholder' => $workplacePlaceholder,
            'release_state' => $releaseState,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function publish(string $publishedBy = 'ops'): array
    {
        $preview = $this->preview();
        $errors = array_values((array) data_get($preview, 'validation.errors', []));
        if ($errors !== []) {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_VALIDATION_FAILED');
        }

        $releaseId = (string) Str::uuid();
        $manifestHash = trim((string) ($preview['registry_release_hash'] ?? ''));
        if ($manifestHash === '') {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_RELEASE_HASH_MISSING');
        }

        $activationBeforeReleaseId = $this->activeReleaseId();
        $now = now();
        $release = ContentPackRelease::query()->create([
            'id' => $releaseId,
            'action' => 'enneagram_registry_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => self::PACK_VERSION,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => self::PACK_ID,
            'status' => 'success',
            'message' => 'Enneagram registry release published via ops preview',
            'created_by' => trim($publishedBy) !== '' ? $publishedBy : 'ops',
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $manifestHash,
            'content_hash' => $manifestHash,
            'pack_version' => self::PACK_VERSION,
            'manifest_json' => $this->manifestPayload($preview),
            'storage_path' => self::STORAGE_PATH,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->manifestCatalogService->upsertManifest([
            'content_pack_release_id' => (string) $release->getKey(),
            'manifest_hash' => $manifestHash,
            'schema_version' => 'enneagram.registry_release_manifest.v1',
            'storage_disk' => 'repo',
            'storage_path' => self::STORAGE_PATH,
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'compiled_hash' => $manifestHash,
            'content_hash' => $manifestHash,
            'payload_json' => $this->manifestPayload($preview),
        ]);

        $this->activateReleaseRow((string) $release->getKey());

        $this->snapshotCatalogService->recordSnapshot([
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'from_content_pack_release_id' => $activationBeforeReleaseId,
            'to_content_pack_release_id' => (string) $release->getKey(),
            'activation_before_release_id' => $activationBeforeReleaseId,
            'activation_after_release_id' => (string) $release->getKey(),
            'reason' => 'enneagram_registry_publish',
            'created_by' => trim($publishedBy) !== '' ? $publishedBy : 'ops',
            'meta_json' => $this->snapshotMeta($preview, 'publish'),
        ]);

        return $this->preview();
    }

    /**
     * @return array<string,mixed>
     */
    public function activate(string $releaseId, string $activatedBy = 'ops'): array
    {
        $target = $this->findReleaseOrFail($releaseId);
        $activationBeforeReleaseId = $this->activeReleaseId();

        $this->activateReleaseRow((string) $target->id);

        $this->snapshotCatalogService->recordSnapshot([
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'from_content_pack_release_id' => $activationBeforeReleaseId,
            'to_content_pack_release_id' => (string) $target->id,
            'activation_before_release_id' => $activationBeforeReleaseId,
            'activation_after_release_id' => (string) $target->id,
            'reason' => 'enneagram_registry_activate',
            'created_by' => trim($activatedBy) !== '' ? $activatedBy : 'ops',
            'meta_json' => [
                'release_id' => (string) $target->id,
                'manifest_hash' => (string) ($target->manifest_hash ?? ''),
                'action' => 'activate',
            ],
        ]);

        return $this->preview();
    }

    /**
     * @return array<string,mixed>
     */
    public function rollback(string $releaseId, string $rolledBackBy = 'ops'): array
    {
        $target = $this->findReleaseOrFail($releaseId);
        $activationBeforeReleaseId = $this->activeReleaseId();

        $this->activateReleaseRow((string) $target->id);

        ContentPackRelease::query()->create([
            'id' => (string) Str::uuid(),
            'action' => 'enneagram_registry_rollback',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => self::PACK_VERSION,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => self::PACK_ID,
            'to_pack_id' => self::PACK_ID,
            'status' => 'success',
            'message' => 'Rollback to release '.$target->id,
            'created_by' => trim($rolledBackBy) !== '' ? $rolledBackBy : 'ops',
            'manifest_hash' => (string) ($target->manifest_hash ?? ''),
            'compiled_hash' => (string) ($target->compiled_hash ?? ''),
            'content_hash' => (string) ($target->content_hash ?? ''),
            'pack_version' => self::PACK_VERSION,
            'manifest_json' => is_array($target->manifest_json) ? $target->manifest_json : [],
            'storage_path' => (string) ($target->storage_path ?? self::STORAGE_PATH),
        ]);

        $this->snapshotCatalogService->recordSnapshot([
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'from_content_pack_release_id' => $activationBeforeReleaseId,
            'to_content_pack_release_id' => (string) $target->id,
            'activation_before_release_id' => $activationBeforeReleaseId,
            'activation_after_release_id' => (string) $target->id,
            'reason' => 'enneagram_registry_rollback',
            'created_by' => trim($rolledBackBy) !== '' ? $rolledBackBy : 'ops',
            'meta_json' => [
                'release_id' => (string) $target->id,
                'manifest_hash' => (string) ($target->manifest_hash ?? ''),
                'action' => 'rollback',
            ],
        ]);

        return $this->preview();
    }

    /**
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    private function manifestPayload(array $preview): array
    {
        return [
            'scale_code' => self::PACK_ID,
            'registry_version' => $preview['registry_version'] ?? null,
            'release_id' => $preview['release_id'] ?? null,
            'registry_release_hash' => $preview['registry_release_hash'] ?? null,
            'content_maturity_summary' => $preview['content_maturity_summary'] ?? [],
            'evidence_level_summary' => $preview['evidence_level_summary'] ?? [],
            'technical_note_version' => data_get($preview, 'technical_note_preview.technical_note_version'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $preview
     * @return array<string,mixed>
     */
    private function snapshotMeta(array $preview, string $action): array
    {
        return [
            'action' => $action,
            'release_id' => $preview['release_id'] ?? null,
            'registry_release_hash' => $preview['registry_release_hash'] ?? null,
            'content_maturity_summary' => $preview['content_maturity_summary'] ?? [],
            'technical_note_version' => data_get($preview, 'technical_note_preview.technical_note_version'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $registries
     * @return array<string,mixed>
     */
    private function workplacePlaceholder(array $manifest, array $registries): array
    {
        $activeContextModes = [];
        foreach ($registries as $registry) {
            if (! is_array($registry)) {
                continue;
            }
            $mode = trim((string) ($registry['context_mode'] ?? ''));
            if ($mode !== '') {
                $activeContextModes[$mode] = true;
            }
        }

        $supportedModes = array_values(array_map('strval', (array) ($manifest['supported_context_modes'] ?? [])));
        $inactiveModes = array_values(array_filter(
            $supportedModes,
            static fn (string $mode): bool => in_array($mode, ['workplace', 'team'], true)
        ));

        return [
            'supported_context_modes' => $supportedModes,
            'active_context_modes' => array_keys($activeContextModes),
            'workplace_active' => in_array('workplace', array_keys($activeContextModes), true),
            'team_active' => in_array('team', array_keys($activeContextModes), true),
            'inactive_modes' => $inactiveModes,
            'product_enabled' => false,
            'dashboard_enabled' => false,
            'future_modules' => self::FUTURE_WORKPLACE_MODULES,
            'summary' => 'workplace / team 仅作为未来模式占位，当前没有启用 B2B dashboard 或 team runtime surface。',
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function technicalNotePreview(array $registry): array
    {
        $sections = array_values(array_filter((array) ($registry['sections'] ?? []), static fn ($entry): bool => is_array($entry)));
        $disclaimers = array_values(array_filter((array) ($registry['disclaimers'] ?? [])));
        $dataStatusSummary = is_array($registry['data_status_summary'] ?? null) ? $registry['data_status_summary'] : [];

        $disclaimerKeys = [];
        foreach ($disclaimers as $entry) {
            $key = trim((string) data_get($entry, 'key'));
            if ($key !== '') {
                $disclaimerKeys[$key] = true;
            }
        }

        return [
            'technical_note_version' => trim((string) ($registry['technical_note_version'] ?? '')),
            'section_count' => count($sections),
            'sections' => array_map(
                static fn (array $entry): array => [
                    'section_key' => trim((string) ($entry['section_key'] ?? '')),
                    'title' => trim((string) ($entry['title'] ?? '')),
                    'data_status' => trim((string) ($entry['data_status'] ?? '')),
                ],
                $sections
            ),
            'data_status_summary' => $dataStatusSummary,
            'disclaimer_count' => count($disclaimers),
            'disclaimers_present' => $disclaimers !== [],
            'unsupported_claims_guard' => [
                'no_clinical_claim' => isset($disclaimerKeys['not_clinical']),
                'no_hiring_screening_claim' => isset($disclaimerKeys['not_hiring_screening']),
                'no_hard_theory_judgement' => isset($disclaimerKeys['no_hard_theory_judgement']),
                'no_cross_form_numeric_compare' => isset($disclaimerKeys['no_cross_form_numeric_compare']),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function sampleReportPreview(array $registry): array
    {
        $entries = is_array($registry['entries'] ?? null) ? $registry['entries'] : [];
        $samples = [];
        foreach ($entries as $sampleKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $samples[] = [
                'sample_key' => trim((string) ($entry['sample_key'] ?? $sampleKey)),
                'sample_type' => trim((string) ($entry['sample_type'] ?? '')),
                'interpretation_scope' => trim((string) ($entry['interpretation_scope'] ?? '')),
                'form_code' => trim((string) ($entry['form_code'] ?? '')),
                'content_maturity' => trim((string) ($entry['content_maturity'] ?? '')),
            ];
        }

        return [
            'sample_count' => count($samples),
            'samples' => $samples,
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function observationPreview(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), static fn ($entry): bool => is_array($entry)));

        return [
            'day_count' => count($entries),
            'days' => array_map(
                static fn (array $entry): array => [
                    'day' => (int) ($entry['day'] ?? 0),
                    'phase' => trim((string) ($entry['phase'] ?? '')),
                    'title' => trim((string) ($entry['title'] ?? '')),
                ],
                $entries
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function groupPreview(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), static fn ($entry): bool => is_array($entry)));

        return [
            'group_count' => count($entries),
            'groups' => array_map(
                static fn (array $entry): array => [
                    'group_key' => trim((string) ($entry['group_key'] ?? '')),
                    'title' => trim((string) ($entry['title'] ?? '')),
                    'content_maturity' => trim((string) ($entry['content_maturity'] ?? '')),
                ],
                $entries
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function pairPreview(array $registry): array
    {
        $entries = is_array($registry['entries'] ?? null) ? $registry['entries'] : [];
        $p0Coverage = 0;
        $pairs = [];
        foreach ($entries as $pairKey => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $contentMaturity = trim((string) ($entry['content_maturity'] ?? ''));
            if ($contentMaturity === 'p0_ready') {
                $p0Coverage++;
            }

            $pairs[] = [
                'pair_key' => trim((string) ($entry['pair_key'] ?? $pairKey)),
                'content_maturity' => $contentMaturity,
                'evidence_level' => trim((string) ($entry['evidence_level'] ?? '')),
            ];
        }

        return [
            'pair_count' => count($pairs),
            'p0_pair_coverage_count' => $p0Coverage,
            'pairs' => $pairs,
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function typePreview(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), static fn ($entry): bool => is_array($entry)));

        return [
            'type_count' => count($entries),
            'types' => array_map(
                static fn (array $entry): array => [
                    'type_id' => trim((string) ($entry['type_id'] ?? '')),
                    'hero_summary' => trim((string) ($entry['hero_summary'] ?? '')),
                    'content_maturity' => trim((string) ($entry['content_maturity'] ?? '')),
                ],
                $entries
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function methodPreview(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), static fn ($entry): bool => is_array($entry)));

        return [
            'boundary_count' => count($entries),
            'boundaries' => array_map(
                static fn (array $entry): array => [
                    'method_key' => trim((string) ($entry['method_key'] ?? '')),
                    'label' => trim((string) ($entry['label'] ?? '')),
                    'evidence_level' => trim((string) ($entry['evidence_level'] ?? '')),
                ],
                $entries
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $registry
     * @return array<string,mixed>
     */
    private function theoryHintSafety(array $registry): array
    {
        $entries = array_values(array_filter((array) ($registry['entries'] ?? []), static fn ($entry): bool => is_array($entry)));
        $violations = [];
        foreach ($entries as $entry) {
            if (($entry['hard_judgement_allowed'] ?? false) === true) {
                $violations[] = trim((string) ($entry['theory_key'] ?? ''));
            }
        }

        return [
            'entry_count' => count($entries),
            'all_non_hard_judgement' => $violations === [],
            'hard_judgement_violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $registries
     * @return list<array<string,mixed>>
     */
    private function registryFileSummary(array $manifest, array $registries): array
    {
        $rows = [];
        foreach ((array) ($manifest['registries'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $registryKey = trim((string) ($entry['registry_key'] ?? ''));
            $registry = is_array($registries[$registryKey] ?? null) ? $registries[$registryKey] : [];
            $rows[] = [
                'registry_key' => $registryKey,
                'file' => trim((string) ($entry['file'] ?? '')),
                'content_maturity' => trim((string) ($registry['content_maturity'] ?? '')),
                'evidence_level' => trim((string) ($registry['evidence_level'] ?? '')),
                'preview_enabled' => (bool) ($registry['preview_enabled'] ?? false),
                'context_mode' => trim((string) ($registry['context_mode'] ?? '')),
                'entry_count' => $this->entryCount($registry),
            ];
        }

        return $rows;
    }

    private function entryCount(array $registry): int
    {
        $entries = $registry['entries'] ?? null;
        if (is_array($entries)) {
            return count($entries);
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $registries
     * @return array<string,int>
     */
    private function distributionSummary(array $registries, string $field): array
    {
        $summary = [];
        foreach ($registries as $registry) {
            $this->accumulateFieldDistribution($registry, $field, $summary);
        }

        ksort($summary);

        return $summary;
    }

    /**
     * @param  array<string,int>  $summary
     */
    private function accumulateFieldDistribution(mixed $value, string $field, array &$summary): void
    {
        if (! is_array($value)) {
            return;
        }

        $candidate = trim((string) ($value[$field] ?? ''));
        if ($candidate !== '') {
            $summary[$candidate] = ($summary[$candidate] ?? 0) + 1;
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $this->accumulateFieldDistribution($nested, $field, $summary);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function releaseState(): array
    {
        if (! Schema::hasTable('content_pack_releases')) {
            return [
                'active_release' => null,
                'last_published_release' => null,
                'history' => [],
            ];
        }

        $activeReleaseId = $this->activeReleaseId();
        $history = ContentPackRelease::query()
            ->where('to_pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->whereIn('action', ['enneagram_registry_publish', 'enneagram_registry_rollback'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ContentPackRelease $release) use ($activeReleaseId): array {
                return [
                    'release_id' => (string) $release->id,
                    'action' => (string) ($release->action ?? ''),
                    'manifest_hash' => (string) ($release->manifest_hash ?? ''),
                    'created_by' => (string) ($release->created_by ?? ''),
                    'created_at' => optional($release->created_at)?->toIso8601String(),
                    'is_active' => $activeReleaseId !== null && $activeReleaseId === (string) $release->id,
                ];
            })
            ->values()
            ->all();

        $lastPublished = ContentPackRelease::query()
            ->where('to_pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->where('action', 'enneagram_registry_publish')
            ->orderByDesc('created_at')
            ->first();

        return [
            'active_release' => $activeReleaseId !== null ? $this->releaseSummary($activeReleaseId) : null,
            'last_published_release' => $lastPublished instanceof ContentPackRelease ? $this->releaseSummary((string) $lastPublished->id) : null,
            'history' => $history,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function releaseSummary(string $releaseId): ?array
    {
        $release = ContentPackRelease::query()->find($releaseId);
        if (! $release instanceof ContentPackRelease) {
            return null;
        }

        return [
            'release_id' => (string) $release->id,
            'action' => (string) ($release->action ?? ''),
            'manifest_hash' => (string) ($release->manifest_hash ?? ''),
            'created_by' => (string) ($release->created_by ?? ''),
            'created_at' => optional($release->created_at)?->toIso8601String(),
        ];
    }

    private function activeReleaseId(): ?string
    {
        if (! Schema::hasTable('content_pack_activations')) {
            return null;
        }

        $releaseId = DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->value('release_id');

        $normalized = trim((string) $releaseId);

        return $normalized !== '' ? $normalized : null;
    }

    private function activateReleaseRow(string $releaseId): void
    {
        if (! Schema::hasTable('content_pack_activations')) {
            throw new RuntimeException('CONTENT_PACK_ACTIVATIONS_TABLE_MISSING');
        }

        $now = now();
        DB::table('content_pack_activations')->updateOrInsert(
            [
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
            ],
            [
                'release_id' => $releaseId,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function findReleaseOrFail(string $releaseId): ContentPackRelease
    {
        $release = ContentPackRelease::query()
            ->where('id', trim($releaseId))
            ->where('to_pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->where('action', 'enneagram_registry_publish')
            ->where('status', 'success')
            ->first();

        if (! $release instanceof ContentPackRelease) {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_RELEASE_NOT_FOUND');
        }

        $releaseHash = trim((string) ($release->manifest_hash ?? ''));
        $compiledHash = trim((string) ($release->compiled_hash ?? ''));
        $contentHash = trim((string) ($release->content_hash ?? ''));
        $storagePath = trim((string) ($release->storage_path ?? ''));
        $manifest = is_array($release->manifest_json) ? $release->manifest_json : [];
        $manifestScaleCode = trim((string) ($manifest['scale_code'] ?? ''));
        $manifestReleaseHash = trim((string) ($manifest['registry_release_hash'] ?? ''));

        if (
            $releaseHash === ''
            || $compiledHash === ''
            || ! hash_equals($releaseHash, $compiledHash)
            || ($contentHash !== '' && ! hash_equals($releaseHash, $contentHash))
            || $storagePath !== self::STORAGE_PATH
            || $manifestScaleCode !== self::PACK_ID
            || $manifestReleaseHash === ''
            || ! hash_equals($releaseHash, $manifestReleaseHash)
        ) {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_RELEASE_INVALID');
        }

        return $release;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Support\Facades\DB;

final class Mbti64CmsRevisionDraftWriter
{
    private const VARIANT_SNAPSHOT_KEY = 'mbti64_variant_content_package_v2_1';

    private const COMPARISON_SNAPSHOT_KEY = 'mbti64_comparison_draft_v2_1';

    private const STRUCTURED_METADATA_FIELDS = [
        'primary_query',
        'secondary_queries',
        'excluded_queries',
        'target_intent',
        'target_test_route',
        'method_boundary',
        'trademark_boundary',
        'information_gain',
        'claim_risk_notes',
        'qa_flags_for_codex',
        'route_safety',
        'v2_optimization',
        'above_the_fold_module',
        'serp_ctr_package_v2',
        'status',
    ];

    public function __construct(private readonly Mbti64BackendImportContractPlanner $planner)
    {
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $package, string $sourceSha256, array $options = []): array
    {
        return $this->buildSummary($package, $sourceSha256, false, $options);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function write(array $package, string $sourceSha256, array $options = []): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $sourceSha256, true, $options));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, string $sourceSha256, bool $write, array $options): array
    {
        $contract = $this->planner->plan($package);
        if (($contract['ok'] ?? false) !== true) {
            return array_merge($this->baseSummary($package, $sourceSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'contract' => $contract,
                'errors' => $contract['errors'] ?? [],
                'warnings' => $contract['warnings'] ?? [],
            ]);
        }

        $preparedRows = [];
        $errors = [];
        foreach ((array) ($contract['rows'] ?? []) as $plannedRow) {
            if (! is_array($plannedRow)) {
                continue;
            }

            $row = $this->packageRow($package, (int) ($plannedRow['position'] ?? 0));
            $preparedRows[] = $this->prepareRow($plannedRow, $row, $package, $sourceSha256, $write, $errors);
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $sourceSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'contract' => $contract,
                'rows' => $preparedRows,
                'errors' => $errors,
                'warnings' => $contract['warnings'] ?? [],
            ]);
        }

        $created = 0;
        $skippedExisting = 0;
        if ($write) {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'skipped_existing';
                    $skippedExisting++;

                    continue;
                }

                $revision = $this->createRevision($preparedRow);
                $preparedRow['action'] = 'created';
                $preparedRow['created_revision_id'] = (int) $revision->id;
                $preparedRow['created_revision_no'] = (int) $revision->revision_no;
                $created++;
            }
            unset($preparedRow);
        } else {
            foreach ($preparedRows as &$preparedRow) {
                if (($preparedRow['existing_revision_id'] ?? null) !== null) {
                    $preparedRow['action'] = 'would_skip_existing';
                    $skippedExisting++;

                    continue;
                }

                $preparedRow['action'] = 'would_create';
            }
            unset($preparedRow);
        }

        return array_merge($this->baseSummary($package, $sourceSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'contract' => $contract,
            'row_count' => count($preparedRows),
            'variant_row_count' => count(array_filter(
                $preparedRows,
                static fn (array $row): bool => ($row['page_type'] ?? null) === 'variant'
            )),
            'comparison_row_count' => count(array_filter(
                $preparedRows,
                static fn (array $row): bool => ($row['page_type'] ?? null) === 'comparison'
            )),
            'created_revision_count' => $created,
            'skipped_existing_count' => $skippedExisting,
            'would_create_revision_count' => $write ? 0 : count($preparedRows) - $skippedExisting,
            'writes_committed' => $write && $created > 0,
            'rows' => $preparedRows,
            'errors' => [],
            'warnings' => $contract['warnings'] ?? [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, string $sourceSha256, bool $write): array
    {
        return [
            'artifact' => 'MBTI64-CMS-REVISION-DRAFT-01',
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'draft_only' => true,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'writes_committed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function packageRow(array $package, int $position): array
    {
        $rows = is_array($package['rows'] ?? null) ? array_values((array) $package['rows']) : [];
        $row = $rows[$position - 1] ?? [];

        return is_array($row) ? $row : [];
    }

    /**
     * @param  array<string,mixed>  $plannedRow
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $package
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function prepareRow(
        array $plannedRow,
        array $row,
        array $package,
        string $sourceSha256,
        bool $write,
        array &$errors,
    ): array {
        $pageType = (string) ($plannedRow['page_type'] ?? '');
        $snapshotKey = $pageType === 'comparison' ? self::COMPARISON_SNAPSHOT_KEY : self::VARIANT_SNAPSHOT_KEY;
        $target = $this->targetRecord($plannedRow);
        $targetId = $target['id'] ?? null;
        $targetField = $pageType === 'comparison' ? 'profile_id' : 'personality_profile_variant_id';

        if (! is_int($targetId)) {
            $errors[] = [
                'field' => 'rows.'.((string) ((int) ($plannedRow['position'] ?? 0) - 1)).'.url',
                'code' => 'target_not_found',
                'message' => 'CMS target record was not found for '.$pageType.' row '.((string) ($plannedRow['url'] ?? '')),
            ];
        }

        $existingRevision = is_int($targetId)
            ? $this->existingRevision($pageType, $targetField, $targetId, $snapshotKey, $sourceSha256)
            : null;
        $nextRevisionNo = is_int($targetId)
            ? $this->nextRevisionNo($pageType, $targetField, $targetId)
            : null;

        return [
            'position' => (int) ($plannedRow['position'] ?? 0),
            'url' => (string) ($plannedRow['url'] ?? ''),
            'locale' => (string) ($plannedRow['locale'] ?? ''),
            'page_type' => $pageType,
            'identity' => $plannedRow['identity'] ?? [],
            'target_table' => (string) (($plannedRow['target']['target_table'] ?? '')),
            'target_id' => $targetId,
            'snapshot_key' => $snapshotKey,
            'source_sha256' => $sourceSha256,
            'existing_revision_id' => $existingRevision?->id !== null ? (int) $existingRevision->id : null,
            'existing_revision_no' => $existingRevision?->revision_no !== null ? (int) $existingRevision->revision_no : null,
            'next_revision_no' => $nextRevisionNo,
            'write_mode' => $write ? 'write_draft_revision' : 'dry_run',
            'action' => 'pending',
            'snapshot_preview' => $this->snapshotPayload($snapshotKey, $row, $package, $plannedRow, $sourceSha256),
        ];
    }

    /**
     * @param  array<string,mixed>  $plannedRow
     * @return array{id?:int}
     */
    private function targetRecord(array $plannedRow): array
    {
        $identity = is_array($plannedRow['identity'] ?? null) ? $plannedRow['identity'] : [];
        $locale = (string) ($plannedRow['locale'] ?? '');

        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', $locale)
            ->where('canonical_type_code', (string) ($identity['canonical_type_code'] ?? ''))
            ->first();

        if (! $profile instanceof PersonalityProfile) {
            return [];
        }

        if (($plannedRow['page_type'] ?? null) === 'comparison') {
            return ['id' => (int) $profile->id];
        }

        $variant = PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', (string) ($identity['runtime_type_code'] ?? ''))
            ->first();

        return $variant instanceof PersonalityProfileVariant ? ['id' => (int) $variant->id] : [];
    }

    private function existingRevision(
        string $pageType,
        string $targetField,
        int $targetId,
        string $snapshotKey,
        string $sourceSha256,
    ): PersonalityProfileRevision|PersonalityProfileVariantRevision|null {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $storedSha = (string) ($snapshot[$snapshotKey]['source']['source_sha256'] ?? '');
            if ($storedSha === $sourceSha256) {
                return $revision;
            }
        }

        return null;
    }

    private function nextRevisionNo(string $pageType, string $targetField, int $targetId): int
    {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        return ((int) $query->max('revision_no')) + 1;
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     */
    private function createRevision(array $preparedRow): PersonalityProfileRevision|PersonalityProfileVariantRevision
    {
        $pageType = (string) ($preparedRow['page_type'] ?? '');
        $targetId = (int) ($preparedRow['target_id'] ?? 0);
        $revisionNo = (int) ($preparedRow['next_revision_no'] ?? 0);
        $snapshot = is_array($preparedRow['snapshot_preview'] ?? null) ? $preparedRow['snapshot_preview'] : [];
        $note = $pageType === 'comparison'
            ? 'mbti64 pilot-v2.1 comparison draft overlay: '.((string) ($preparedRow['url'] ?? ''))
            : 'mbti64 pilot-v2.1 variant draft: '.((string) ($preparedRow['url'] ?? ''));

        if ($pageType === 'comparison') {
            return PersonalityProfileRevision::query()->create([
                'profile_id' => $targetId,
                'revision_no' => $revisionNo,
                'snapshot_json' => $snapshot,
                'note' => $note,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);
        }

        return PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => $targetId,
            'revision_no' => $revisionNo,
            'snapshot_json' => $snapshot,
            'note' => $note,
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $plannedRow
     * @return array<string,mixed>
     */
    private function snapshotPayload(
        string $snapshotKey,
        array $row,
        array $package,
        array $plannedRow,
        string $sourceSha256,
    ): array {
        return [
            $snapshotKey => [
                'source' => [
                    'artifact' => (string) ($package['artifact'] ?? ''),
                    'version' => (string) ($package['version'] ?? ''),
                    'status' => (string) ($package['status'] ?? ''),
                    'source_sha256' => $sourceSha256,
                ],
                'identity' => $plannedRow['identity'] ?? [],
                'first_class_draft_fields' => [
                    'url' => (string) ($row['url'] ?? ''),
                    'locale' => (string) ($row['locale'] ?? ''),
                    'page_type' => (string) ($row['page_type'] ?? ''),
                    'canonical_target' => (string) ($row['canonical_target'] ?? ''),
                    'seo' => is_array($row['seo'] ?? null) ? $row['seo'] : [],
                    'content' => is_array($row['content'] ?? null) ? $row['content'] : [],
                    'faq' => is_array($row['faq'] ?? null) ? array_values((array) $row['faq']) : [],
                    'internal_links' => is_array($row['internal_links'] ?? null) ? array_values((array) $row['internal_links']) : [],
                ],
                'structured_metadata' => $this->structuredMetadata($row),
                'safety_holds' => [
                    'draft_only' => true,
                    'publish_attempted' => false,
                    'index_attempted' => false,
                    'sitemap_llms_release_attempted' => false,
                    'search_release_attempted' => false,
                    'runtime_content_updated' => false,
                ],
                'raw_row' => $row,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function structuredMetadata(array $row): array
    {
        $metadata = [];
        foreach (self::STRUCTURED_METADATA_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                $metadata[$field] = $row[$field];
            }
        }

        return $metadata;
    }
}

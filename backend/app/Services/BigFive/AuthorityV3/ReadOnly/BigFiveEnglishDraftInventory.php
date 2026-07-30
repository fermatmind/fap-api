<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\ReadOnly;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveEnglishDraftInventory
{
    public const SCHEMA_VERSION = 'en-parity-w2-big-five-draft-inventory.v1';

    /** @var list<string> */
    public const DISPOSITIONS = [
        'verify_only_no_action',
        'duplicate_of_published',
        'stale_working_revision',
        'valid_unpublished_candidate',
        'schema_repair_required',
        'editorial_repair_required',
        'translation_identity_mismatch',
        'orphan_revision',
        'prohibited_content',
        'blocked_authority_unknown',
    ];

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $before = $this->databaseFingerprint();
        $entries = collect(BigFiveCanonicalRouteCatalog::canonicalEntries('en'));
        $expected = $entries->reject(fn (array $entry): bool => in_array($entry['entity_type'], [
            PersonalityPublicContentAsset::ENTITY_HUB,
            PersonalityPublicContentAsset::ENTITY_FACET_HUB,
        ], true))->values();

        $assets = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', 'en')
            ->orderBy('id')
            ->get();
        $canonical = $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => $entries->contains(
            fn (array $entry): bool => $entry['entity_type'] === $asset->entity_type
                && $entry['entity_key'] === $asset->entity_key
                && $entry['path'] === (string) data_get($asset->canonical_json, 'path'),
        ))->values();
        $aliases = $assets->reject(fn (PersonalityPublicContentAsset $asset): bool => $canonical->contains('id', $asset->id));

        $rows = $expected->map(function (array $entry) use ($canonical): array {
            /** @var PersonalityPublicContentAsset|null $asset */
            $asset = $canonical->first(fn (PersonalityPublicContentAsset $candidate): bool => (
                $candidate->entity_type === $entry['entity_type']
                && $candidate->entity_key === $entry['entity_key']
            ));

            return $this->row($entry, $asset);
        })->all();
        $revisions = PersonalityPublicContentAssetRevision::query()
            ->whereIn('asset_id', $canonical->pluck('id')->all())
            ->orderBy('id')
            ->get();
        $referencedRevisionIds = collect($rows)
            ->flatMap(fn (array $row): array => [$row['working_revision_id'], $row['published_revision_id']])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();
        $historicalByAsset = $revisions
            ->reject(fn (PersonalityPublicContentAssetRevision $revision): bool => in_array(
                (int) $revision->id,
                $referencedRevisionIds,
                true,
            ))
            ->groupBy('asset_id');
        $rows = array_map(function (array $row) use ($historicalByAsset): array {
            $historical = $row['backend_resource_id'] === null
                ? collect()
                : $historicalByAsset->get((int) $row['backend_resource_id'], collect());
            $registered = $historical
                ->filter(fn (PersonalityPublicContentAssetRevision $revision): bool => $this->isRegisteredHistoricalSlotRevision($revision))
                ->sortBy('revision_no')
                ->values();
            if ($registered->count() !== 1) {
                return $row + [
                    'historical_draft_revision_id' => null,
                    'historical_draft_revision_status' => null,
                    'historical_draft_fingerprint_sha256' => null,
                    'historical_draft_created_at' => null,
                    'historical_draft_updated_at' => null,
                    'historical_draft_pointer_active' => false,
                    'historical_source_package' => null,
                    'historical_source_hash' => null,
                    'historical_authority_package_sha256' => null,
                ];
            }
            /** @var PersonalityPublicContentAssetRevision $revision */
            $revision = $registered->first();
            $historicalFingerprint = $this->fingerprint($revision->snapshot_json);
            $mayClassifyHistoricalLineage = in_array($row['recommended_disposition'], [
                'duplicate_of_published',
                'verify_only_no_action',
                'stale_working_revision',
            ], true);

            return array_replace($row, [
                'historical_draft_revision_id' => (int) $revision->id,
                'historical_draft_revision_status' => (string) $revision->workflow_state,
                'historical_draft_fingerprint_sha256' => $historicalFingerprint,
                'historical_draft_created_at' => $revision->created_at?->toAtomString(),
                'historical_draft_updated_at' => $revision->updated_at?->toAtomString(),
                'historical_draft_pointer_active' => false,
                'historical_draft_equals_current_published' => $row['published_revision_fingerprint_sha256'] !== null
                    && hash_equals($row['published_revision_fingerprint_sha256'], $historicalFingerprint),
                'historical_source_package' => (string) $revision->source_package,
                'historical_source_hash' => (string) $revision->source_hash,
                'historical_authority_package_sha256' => (string) $revision->authority_package_sha256,
                'recommended_disposition' => $mayClassifyHistoricalLineage
                    ? 'stale_working_revision'
                    : $row['recommended_disposition'],
                'blocker' => $mayClassifyHistoricalLineage ? null : $row['blocker'],
            ]);
        }, $rows);

        $after = $this->databaseFingerprint();
        if (! hash_equals($before, $after)) {
            throw new RuntimeException('Read-only Big Five English draft inventory changed the database.');
        }

        $dispositions = array_count_values(array_column($rows, 'recommended_disposition'));
        ksort($dispositions);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'PASS_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE',
            'mode' => 'database_read_only_zero_write',
            'authority' => 'personality_public_content_assets_and_immutable_revisions',
            'locale' => 'en',
            'cohort_definition' => '50 registered historical slot identities from the 52-page EN52 canonical catalog, excluding model hub and facet hub',
            'counts' => [
                'expected_canonical_assets' => 52,
                'observed_canonical_assets' => $canonical->count(),
                'historical_slots' => $expected->count(),
                'observed_slot_assets' => collect($rows)->whereNotNull('backend_resource_id')->count(),
                'historical_revision_rows' => collect($rows)->whereNotNull('historical_draft_revision_id')->count(),
                'independent_working_revisions' => collect($rows)->where('working_pointer_active', true)
                    ->where('draft_equals_published', false)->count(),
                'published_revisions' => collect($rows)->whereNotNull('published_revision_id')->count(),
                'public_projections' => collect($rows)->where('published_projection_exists', true)->count(),
                'redirect_only_alias_rows' => $aliases->count(),
            ],
            'disposition_totals' => $dispositions,
            'database_snapshot_before_sha256' => $before,
            'database_snapshot_after_sha256' => $after,
            'database_snapshot_unchanged' => true,
            'writes_committed' => false,
            'rows' => $rows,
        ];
    }

    /** @param array{entity_type:string,entity_key:string,path:string} $entry
     * @return array<string,mixed>
     */
    private function row(array $entry, ?PersonalityPublicContentAsset $asset): array
    {
        $working = $asset?->working_revision_id
            ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->working_revision_id)
            : null;
        $published = $asset?->published_revision_id
            ? PersonalityPublicContentAssetRevision::query()->find((int) $asset->published_revision_id)
            : null;
        $workingSnapshot = $working?->snapshot_json;
        $publishedSnapshot = $published?->snapshot_json;
        $workingFingerprint = $working ? $this->fingerprint($workingSnapshot) : null;
        $publishedFingerprint = $published ? $this->fingerprint($publishedSnapshot) : null;
        $workingActive = $asset !== null && $working !== null
            && (int) $working->asset_id === (int) $asset->id;
        $publishedBound = $asset !== null && $published !== null
            && (int) $published->asset_id === (int) $asset->id;
        $pointerEqual = $working !== null && $published !== null
            && (int) $working->id === (int) $published->id;
        $contentEqual = $workingFingerprint !== null && $publishedFingerprint !== null
            && hash_equals($workingFingerprint, $publishedFingerprint);
        $newer = $working !== null && $published !== null
            && (int) $working->revision_no > (int) $published->revision_no;
        $payload = json_encode($workingSnapshot ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $cjk = preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $payload) === 1;
        $private = $this->containsProhibitedPrivateField($workingSnapshot);
        $schemaComplete = $working !== null
            && filled(data_get($workingSnapshot, 'title'))
            && filled(data_get($workingSnapshot, 'summary'))
            && is_array(data_get($workingSnapshot, 'content_sections_json'))
            && is_array(data_get($workingSnapshot, 'faq_json'));
        $textOnly = ! $this->containsMediaReference($workingSnapshot);
        $projection = $asset !== null && (bool) $asset->is_public
            && in_array($asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            ], true)
            && ($asset->published_at === null || ! $asset->published_at->isFuture());

        $disposition = match (true) {
            $asset === null || ! $workingActive || ($asset->published_revision_id && ! $publishedBound) => 'blocked_authority_unknown',
            $cjk || $private || ! $textOnly => 'prohibited_content',
            ! $schemaComplete => 'schema_repair_required',
            $contentEqual => 'duplicate_of_published',
            $published !== null && ! $newer => 'stale_working_revision',
            $working !== null && $published === null => 'valid_unpublished_candidate',
            default => 'verify_only_no_action',
        };

        return [
            'backend_resource_id' => $asset?->id,
            'logical_identity' => $entry['entity_type'].':'.$entry['entity_key'],
            'entity_type' => $entry['entity_type'],
            'locale' => 'en',
            'translation_group_id' => data_get($asset?->authority_json, 'translation_group_id'),
            'working_revision_id' => $working?->id,
            'working_revision_status' => $working?->workflow_state,
            'working_revision_fingerprint_sha256' => $workingFingerprint,
            'published_revision_id' => $published?->id,
            'published_revision_fingerprint_sha256' => $publishedFingerprint,
            'draft_created_at' => $working?->created_at?->toAtomString(),
            'draft_updated_at' => $working?->updated_at?->toAtomString(),
            'published_projection_exists' => $projection,
            'working_pointer_active' => $workingActive,
            'public_page_accessible' => $projection && filled($entry['path']),
            'draft_equals_published' => $pointerEqual,
            'draft_content_equals_published' => $contentEqual,
            'draft_newer_than_published' => $newer,
            'schema_complete' => $schemaComplete,
            'title_complete' => filled(data_get($workingSnapshot, 'title')),
            'summary_complete' => filled(data_get($workingSnapshot, 'summary')),
            'sections_complete' => is_array(data_get($workingSnapshot, 'content_sections_json')),
            'faq_complete' => is_array(data_get($workingSnapshot, 'faq_json')),
            'text_only_compliant' => $textOnly,
            'claim_boundary_compliant' => ! $private,
            'duplicate_template_risk' => $contentEqual ? 'published_equivalent' : 'not_established',
            'chinese_leakage' => $cjk,
            'private_result_leakage' => $private,
            'recommended_disposition' => $disposition,
            'source_evidence' => [
                'asset_table' => 'personality_public_content_assets',
                'revision_table' => 'personality_public_content_asset_revisions',
                'canonical_path' => $entry['path'],
            ],
            'blocker' => $disposition === 'blocked_authority_unknown' ? 'current_revision_authority_incomplete' : null,
        ];
    }

    private function databaseFingerprint(): string
    {
        foreach (['personality_public_content_assets', 'personality_public_content_asset_revisions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required authority table {$table} is missing.");
            }
        }

        return $this->fingerprint([
            'assets' => DB::table('personality_public_content_assets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'revisions' => DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ]);
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function containsProhibitedPrivateField(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(
                '/^(?:attempt(?:_id)?|report_token|order(?:_id)?|payment(?:_id)?|private_url|recovery_token|score_vector|raw_score|percentile)$/i',
                $key,
            ) === 1) {
                return true;
            }
            if ($this->containsProhibitedPrivateField($item)) {
                return true;
            }
        }

        return false;
    }

    private function containsMediaReference(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/!\[[^\]]*\]\([^)]+\)|<img\b/i', $value) === 1;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match('/(?:^|_)(?:hero|inline|og|media|image)(?:_|$)/i', $key) === 1
                && ! in_array($item, [null, false, '', []], true)) {
                return true;
            }
            if ($this->containsMediaReference($item)) {
                return true;
            }
        }

        return false;
    }

    private function isRegisteredHistoricalSlotRevision(PersonalityPublicContentAssetRevision $revision): bool
    {
        return preg_match(
            '/^big5-authority-v2-(?:domains|range-(?:agreeableness|conscientiousness|extraversion|neuroticism|openness)|facets-(?:agreeableness|conscientiousness|extraversion|neuroticism|openness))-/',
            (string) $revision->source_package,
        ) === 1;
    }
}

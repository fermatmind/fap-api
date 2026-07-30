<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\ReadOnly;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveEnglishDraftInventory
{
    public const SCHEMA_VERSION = 'en-parity-w2-big-five-runtime-draft-inventory.v1';

    private const HISTORICAL_AUTHORITY_PACKAGE_SHA256 = 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162';

    /** @var array<string,string> */
    private const HISTORICAL_PACKAGE_BY_DOMAIN = [
        'agreeableness' => 'big5-authority-v2-range-agreeableness-13',
        'conscientiousness' => 'big5-authority-v2-range-conscientiousness-11',
        'extraversion' => 'big5-authority-v2-range-extraversion-12',
        'neuroticism' => 'big5-authority-v2-range-neuroticism-14',
        'openness' => 'big5-authority-v2-range-openness-10',
    ];

    /** @var array<string,string> */
    private const FACET_DOMAIN = [
        'achievement-striving' => 'conscientiousness',
        'actions' => 'openness',
        'activity' => 'extraversion',
        'aesthetics' => 'openness',
        'altruism' => 'agreeableness',
        'anger' => 'neuroticism',
        'anxiety' => 'neuroticism',
        'assertiveness' => 'extraversion',
        'competence' => 'conscientiousness',
        'compliance' => 'agreeableness',
        'deliberation' => 'conscientiousness',
        'depression' => 'neuroticism',
        'dutifulness' => 'conscientiousness',
        'excitement-seeking' => 'extraversion',
        'feelings' => 'openness',
        'gregariousness' => 'extraversion',
        'ideas' => 'openness',
        'imagination' => 'openness',
        'impulsiveness' => 'neuroticism',
        'modesty' => 'agreeableness',
        'order' => 'conscientiousness',
        'positive-emotions' => 'extraversion',
        'self-consciousness' => 'neuroticism',
        'self-discipline' => 'conscientiousness',
        'straightforwardness' => 'agreeableness',
        'tender-mindedness' => 'agreeableness',
        'trust' => 'agreeableness',
        'values' => 'openness',
        'vulnerability' => 'neuroticism',
        'warmth' => 'extraversion',
    ];

    /** @var array<string,string> */
    private const FACET_EN52_AUTHORITY_KEY = [
        'achievement-striving' => 'C4-achievement-striving',
        'actions' => 'O4-actions',
        'activity' => 'E4-activity',
        'aesthetics' => 'O2-aesthetics',
        'altruism' => 'A3-altruism',
        'anger' => 'N2-anger',
        'anxiety' => 'N1-anxiety',
        'assertiveness' => 'E3-assertiveness',
        'competence' => 'C1-competence',
        'compliance' => 'A4-compliance',
        'deliberation' => 'C6-deliberation',
        'depression' => 'N3-depression',
        'dutifulness' => 'C3-dutifulness',
        'excitement-seeking' => 'E5-excitement-seeking',
        'feelings' => 'O3-feelings',
        'gregariousness' => 'E2-gregariousness',
        'ideas' => 'O5-ideas',
        'imagination' => 'O1-imagination',
        'impulsiveness' => 'N5-impulsiveness',
        'modesty' => 'A5-modesty',
        'order' => 'C2-order',
        'positive-emotions' => 'E6-positive-emotions',
        'self-consciousness' => 'N4-self-consciousness',
        'self-discipline' => 'C5-self-discipline',
        'straightforwardness' => 'A2-straightforwardness',
        'tender-mindedness' => 'A6-tender-mindedness',
        'trust' => 'A1-trust',
        'values' => 'O6-values',
        'vulnerability' => 'N6-vulnerability',
        'warmth' => 'E1-warmth',
    ];

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
        $hubEntries = $entries->filter(fn (array $entry): bool => in_array($entry['entity_type'], [
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
        $redirectOnlyAliases = BigFiveCanonicalRouteCatalog::redirectOnlyAliasTargets('en');
        $aliases = $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (
            isset($redirectOnlyAliases[$asset->entity_key])
            && $asset->entity_type === PersonalityPublicContentAsset::ENTITY_POLARITY
            && (string) $asset->slug === 'big-five/'.$asset->entity_key
            && (string) data_get($asset->canonical_json, 'path') === '/en/personality/big-five/'.$asset->entity_key
        ))->values();
        $unknownAuthorityRows = $assets->reject(fn (PersonalityPublicContentAsset $asset): bool => (
            $canonical->contains('id', $asset->id) || $aliases->contains('id', $asset->id)
        ))->values();

        $rows = $expected->map(function (array $entry) use ($canonical): array {
            /** @var PersonalityPublicContentAsset|null $asset */
            $asset = $canonical->first(fn (PersonalityPublicContentAsset $candidate): bool => (
                $candidate->entity_type === $entry['entity_type']
                && $candidate->entity_key === $entry['entity_key']
            ));

            return $this->row($entry, $asset);
        })->all();
        $hubRows = $hubEntries->map(function (array $entry) use ($canonical): array {
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
        $historicalByAsset = $revisions->groupBy('asset_id');
        $rows = array_map(function (array $row) use ($historicalByAsset): array {
            $historical = $row['backend_resource_id'] === null
                ? collect()
                : $historicalByAsset->get((int) $row['backend_resource_id'], collect());
            $registered = $historical
                ->filter(fn (PersonalityPublicContentAssetRevision $revision): bool => (
                    $this->isRegisteredHistoricalSlotRevision($revision, $row)
                ))
                ->sortBy('revision_no')
                ->values();
            if ($registered->count() !== 1) {
                $historicalBlocker = $registered->isEmpty()
                    ? 'registered_historical_slot_revision_missing'
                    : 'registered_historical_slot_revision_ambiguous';
                $mayBlockHistoricalLineage = in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'verify_only_no_action',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                ], true);

                return array_replace($row, [
                    'historical_draft_revision_id' => null,
                    'historical_draft_revision_status' => null,
                    'historical_draft_fingerprint_sha256' => null,
                    'historical_draft_created_at' => null,
                    'historical_draft_updated_at' => null,
                    'historical_draft_pointer_active' => false,
                    'historical_working_pointer_active' => false,
                    'historical_published_pointer_active' => false,
                    'historical_slot_resolution' => $registered->isEmpty() ? 'missing' : 'ambiguous',
                    'historical_source_package' => null,
                    'historical_source_hash' => null,
                    'historical_authority_package_sha256' => null,
                    'historical_private_result_leakage' => null,
                    'historical_media_reference' => null,
                    'historical_chinese_leakage' => null,
                    'recommended_disposition' => $mayBlockHistoricalLineage
                        ? 'blocked_authority_unknown'
                        : $row['recommended_disposition'],
                    'blocker' => $row['blocker'] ?? $historicalBlocker,
                ]);
            }
            /** @var PersonalityPublicContentAssetRevision $revision */
            $revision = $registered->first();
            $historicalFingerprint = $this->fingerprint($revision->snapshot_json);
            $historicalPayload = json_encode(
                $revision->snapshot_json ?? [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
            $historicalPrivate = $this->containsProhibitedPrivateField($revision->snapshot_json);
            $historicalMedia = $this->containsMediaReference($revision->snapshot_json);
            $historicalCjk = preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $historicalPayload) === 1;
            $historicalProhibited = $historicalPrivate || $historicalMedia || $historicalCjk;
            $historicalWorkingPointerActive = (int) $revision->id === (int) ($row['working_revision_id'] ?? 0);
            $historicalPublishedPointerActive = (int) $revision->id === (int) ($row['published_revision_id'] ?? 0);
            $mayClassifyHistoricalLineage = in_array($row['recommended_disposition'], [
                'duplicate_of_published',
                'verify_only_no_action',
                'stale_working_revision',
            ], true);
            $historicalMayBlock = $mayClassifyHistoricalLineage
                || $row['recommended_disposition'] === 'valid_unpublished_candidate';

            return array_replace($row, [
                'historical_draft_revision_id' => (int) $revision->id,
                'historical_draft_revision_status' => (string) $revision->workflow_state,
                'historical_draft_fingerprint_sha256' => $historicalFingerprint,
                'historical_draft_created_at' => $revision->created_at?->toAtomString(),
                'historical_draft_updated_at' => $revision->updated_at?->toAtomString(),
                'historical_draft_pointer_active' => $historicalWorkingPointerActive
                    || $historicalPublishedPointerActive,
                'historical_working_pointer_active' => $historicalWorkingPointerActive,
                'historical_published_pointer_active' => $historicalPublishedPointerActive,
                'historical_slot_resolution' => 'resolved',
                'historical_draft_equals_current_published' => $row['published_revision_fingerprint_sha256'] !== null
                    && hash_equals($row['published_revision_fingerprint_sha256'], $historicalFingerprint),
                'historical_source_package' => (string) $revision->source_package,
                'historical_source_hash' => (string) $revision->source_hash,
                'historical_authority_package_sha256' => (string) $revision->authority_package_sha256,
                'historical_private_result_leakage' => $historicalPrivate,
                'historical_media_reference' => $historicalMedia,
                'historical_chinese_leakage' => $historicalCjk,
                'recommended_disposition' => match (true) {
                    $historicalProhibited && $historicalMayBlock => 'prohibited_content',
                    $mayClassifyHistoricalLineage => 'stale_working_revision',
                    default => $row['recommended_disposition'],
                },
                'blocker' => match (true) {
                    $historicalProhibited && $historicalMayBlock => 'historical_draft_prohibited_content',
                    $mayClassifyHistoricalLineage => null,
                    default => $row['blocker'],
                },
            ]);
        }, $rows);

        $after = $this->databaseFingerprint();
        if (! hash_equals($before, $after)) {
            throw new RuntimeException('Read-only Big Five English draft inventory changed the database.');
        }

        $dispositions = array_count_values(array_column($rows, 'recommended_disposition'));
        ksort($dispositions);

        $blockingRows = collect($rows)->filter(fn (array $row): bool => (
            ! in_array($row['recommended_disposition'], [
                'verify_only_no_action',
                'duplicate_of_published',
                'stale_working_revision',
                'valid_unpublished_candidate',
            ], true)
        ))->count();
        $hubAuthorityComplete = count($hubRows) === 2
            && collect($hubRows)->every(fn (array $row): bool => (
                $row['backend_resource_id'] !== null
                && $row['published_en52_lineage_locked'] === true
                && $row['published_en52_projection_locked'] === true
                && $row['published_projection_exists'] === true
                && $row['draft_equals_published'] === true
                && $row['schema_complete'] === true
                && $row['text_only_compliant'] === true
                && $row['claim_boundary_compliant'] === true
                && $row['chinese_leakage'] === false
                && in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                    'verify_only_no_action',
                ], true)
            ));
        $slotAuthorityComplete = count($rows) === 50
            && collect($rows)->every(fn (array $row): bool => (
                $row['backend_resource_id'] !== null
                && $row['published_en52_lineage_locked'] === true
                && $row['published_en52_projection_locked'] === true
                && $row['published_projection_exists'] === true
                && $row['schema_complete'] === true
                && $row['text_only_compliant'] === true
                && $row['claim_boundary_compliant'] === true
                && $row['chinese_leakage'] === false
                && in_array($row['recommended_disposition'], [
                    'duplicate_of_published',
                    'stale_working_revision',
                    'valid_unpublished_candidate',
                    'verify_only_no_action',
                ], true)
            ));
        $canonicalCohortComplete = $canonical->count() === BigFiveEn52PackageCompiler::ASSET_COUNT
            && $hubAuthorityComplete
            && $slotAuthorityComplete;
        $ok = $blockingRows === 0
            && $unknownAuthorityRows->isEmpty()
            && $canonicalCohortComplete;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok
                ? 'PASS_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE'
                : 'BLOCKED_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE',
            'mode' => 'database_read_only_zero_write',
            'authority' => 'personality_public_content_assets_and_immutable_revisions',
            'locale' => 'en',
            'cohort_definition' => '50 registered historical slot identities from the 52-page EN52 canonical catalog, excluding model hub and facet hub',
            'canonical_cohort_complete' => $canonicalCohortComplete,
            'excluded_hub_authority_complete' => $hubAuthorityComplete,
            'historical_slot_authority_complete' => $slotAuthorityComplete,
            'counts' => [
                'expected_canonical_assets' => BigFiveEn52PackageCompiler::ASSET_COUNT,
                'observed_canonical_assets' => $canonical->count(),
                'expected_excluded_hub_assets' => 2,
                'validated_excluded_hub_assets' => collect($hubRows)->filter(fn (array $row): bool => (
                    $row['published_en52_lineage_locked'] === true
                    && $row['published_en52_projection_locked'] === true
                    && $row['published_projection_exists'] === true
                    && $row['draft_equals_published'] === true
                    && $row['schema_complete'] === true
                    && $row['text_only_compliant'] === true
                    && $row['claim_boundary_compliant'] === true
                    && $row['chinese_leakage'] === false
                ))->count(),
                'historical_slots' => $expected->count(),
                'observed_slot_assets' => collect($rows)->whereNotNull('backend_resource_id')->count(),
                'historical_revision_rows' => collect($rows)->whereNotNull('historical_draft_revision_id')->count(),
                'independent_working_revisions' => collect($rows)->where('working_pointer_active', true)
                    ->where('draft_equals_published', false)->count(),
                'published_revisions' => collect($rows)->whereNotNull('published_revision_id')->count(),
                'public_projections' => collect($rows)->where('published_projection_exists', true)->count(),
                'redirect_only_alias_rows' => $aliases->count(),
                'unknown_authority_rows' => $unknownAuthorityRows->count(),
                'blocking_rows' => $blockingRows,
            ],
            'disposition_totals' => $dispositions,
            'database_snapshot_before_sha256' => $before,
            'database_snapshot_after_sha256' => $after,
            'database_snapshot_unchanged' => true,
            'writes_committed' => false,
            'excluded_hub_rows' => $hubRows,
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
        $workingContent = is_array(data_get($workingSnapshot, 'attributes'))
            ? data_get($workingSnapshot, 'attributes')
            : $workingSnapshot;
        $publishedAttributes = is_array(data_get($publishedSnapshot, 'attributes'))
            ? data_get($publishedSnapshot, 'attributes')
            : null;
        $workingFingerprint = $working ? $this->fingerprint($workingContent) : null;
        $publishedFingerprint = $published ? $this->fingerprint(
            $publishedAttributes ?? $publishedSnapshot,
        ) : null;
        $workingActive = $asset !== null && $working !== null
            && (int) $working->asset_id === (int) $asset->id;
        $publishedBound = $asset !== null && $published !== null
            && (int) $published->asset_id === (int) $asset->id;
        $publishedEn52Locked = $publishedBound
            && (string) $published->source_package === BigFiveEn52PackageCompiler::RELEASE_ID
            && (string) $published->authority_package_sha256 === BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            && (string) $published->workflow_state === BigFiveEn52Publisher::WORKFLOW_STATE
            && (string) $published->authority_asset_key === $this->en52AuthorityAssetKey($entry)
            && data_get($publishedSnapshot, 'schema_version') === BigFiveEn52PackageCompiler::SCHEMA_VERSION
            && data_get($publishedSnapshot, 'release_id') === BigFiveEn52PackageCompiler::RELEASE_ID
            && data_get($publishedSnapshot, 'authority_asset_key') === $this->en52AuthorityAssetKey($entry)
            && data_get($publishedSnapshot, 'source_content_sha256') === BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256
            && data_get($publishedSnapshot, 'package_file_sha256') === BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            && is_array($publishedAttributes)
            && hash_equals((string) $published->source_hash, (string) ($publishedAttributes['source_hash'] ?? ''));
        $publishedProjectionLocked = $publishedEn52Locked
            && $asset !== null
            && $this->runtimeProjectionMatches($asset, $publishedAttributes);
        $pointerEqual = $working !== null && $published !== null
            && (int) $working->id === (int) $published->id;
        $contentEqual = $workingFingerprint !== null && $publishedFingerprint !== null
            && hash_equals($workingFingerprint, $publishedFingerprint);
        $newer = $working !== null && $published !== null
            && (int) $working->revision_no > (int) $published->revision_no;
        $payload = json_encode($workingContent ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $cjk = preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $payload) === 1;
        $private = $this->containsProhibitedPrivateField($workingContent);
        $schemaComplete = $working !== null
            && filled(data_get($workingContent, 'title'))
            && filled(data_get($workingContent, 'summary'))
            && is_array(data_get($workingContent, 'content_sections_json'))
            && is_array(data_get($workingContent, 'faq_json'));
        $textOnly = ! $this->containsMediaReference($workingContent);
        $projection = $publishedProjectionLocked && $asset !== null && (bool) $asset->is_public
            && in_array($asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
                PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            ], true)
            && ($asset->published_at === null || ! $asset->published_at->isFuture());

        $disposition = match (true) {
            $asset === null || ! $workingActive || ($asset->published_revision_id && ! $publishedBound) => 'blocked_authority_unknown',
            $cjk || $private || ! $textOnly => 'prohibited_content',
            ! $schemaComplete => 'schema_repair_required',
            $published !== null && ! $publishedEn52Locked => 'blocked_authority_unknown',
            $published !== null && ! $publishedProjectionLocked => 'blocked_authority_unknown',
            $contentEqual => 'duplicate_of_published',
            $published !== null && ! $newer => 'stale_working_revision',
            $working !== null && $published === null => 'valid_unpublished_candidate',
            $working !== null && $published !== null && ! $pointerEqual && $newer => 'valid_unpublished_candidate',
            default => 'verify_only_no_action',
        };

        return [
            'backend_resource_id' => $asset?->id,
            'logical_identity' => $entry['entity_type'].':'.$entry['entity_key'],
            'entity_type' => $entry['entity_type'],
            'entity_key' => $entry['entity_key'],
            'locale' => 'en',
            'translation_group_id' => data_get($asset?->authority_json, 'translation_group_id'),
            'working_revision_id' => $working?->id,
            'working_revision_status' => $working?->workflow_state,
            'working_revision_fingerprint_sha256' => $workingFingerprint,
            'published_revision_id' => $published?->id,
            'published_revision_fingerprint_sha256' => $publishedFingerprint,
            'published_en52_lineage_locked' => $publishedEn52Locked,
            'published_en52_projection_locked' => $publishedProjectionLocked,
            'draft_created_at' => $working?->created_at?->toAtomString(),
            'draft_updated_at' => $working?->updated_at?->toAtomString(),
            'published_projection_exists' => $projection,
            'working_pointer_active' => $workingActive,
            'public_page_accessible' => $projection && filled($entry['path']),
            'draft_equals_published' => $pointerEqual,
            'draft_content_equals_published' => $contentEqual,
            'draft_newer_than_published' => $newer,
            'schema_complete' => $schemaComplete,
            'title_complete' => filled(data_get($workingContent, 'title')),
            'summary_complete' => filled(data_get($workingContent, 'summary')),
            'sections_complete' => is_array(data_get($workingContent, 'content_sections_json')),
            'faq_complete' => is_array(data_get($workingContent, 'faq_json')),
            'text_only_compliant' => $textOnly,
            'claim_boundary_compliant' => ! $private,
            'duplicate_template_risk' => $contentEqual ? 'published_equivalent' : 'not_established',
            'chinese_leakage' => $cjk,
            'private_result_leakage' => $private,
            'recommended_disposition' => $disposition,
            'current_revision_disposition' => $disposition,
            'source_evidence' => [
                'asset_table' => 'personality_public_content_assets',
                'revision_table' => 'personality_public_content_asset_revisions',
                'canonical_path' => $entry['path'],
            ],
            'blocker' => $disposition === 'blocked_authority_unknown'
                ? ($published !== null && ! $publishedEn52Locked
                    ? 'current_published_revision_not_locked_en52_authority'
                    : ($published !== null && ! $publishedProjectionLocked
                        ? 'live_asset_not_locked_en52_projection'
                        : 'current_revision_authority_incomplete'))
                : null,
        ];
    }

    /** @param array<string,mixed> $expected */
    private function runtimeProjectionMatches(
        PersonalityPublicContentAsset $asset,
        array $expected,
    ): bool {
        foreach ($expected as $key => $value) {
            if ($this->stableJson($this->comparable($asset->getAttribute($key)))
                !== $this->stableJson($this->comparable($value))) {
                return false;
            }
        }

        return true;
    }

    private function comparable(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function stableJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item);
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
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
            if (is_string($key)) {
                $normalizedKey = strtolower($key);
                if (in_array($normalizedKey, BigFiveResultPageV2SelectorAssetContract::FORBIDDEN_PUBLIC_FIELDS, true)
                    || in_array($normalizedKey, BigFiveResultPageV2Contract::FORBIDDEN_PUBLIC_FIELDS, true)
                    || in_array($normalizedKey, BigFiveResultPageV2Contract::SHARE_FORBIDDEN_SCORE_FIELDS, true)
                    || $normalizedKey === 'private_path'
                    || preg_match(
                        '/^(?:answers|attempt(?:_id|_uuid)?|draft_snapshot|generated_authority_package|'
                            .'order(?:_id)?|payment(?:_id)?|private_url|recovery_token|report_(?:token|url)|'
                            .'review_snapshot|selector_trace|snapshot_json|user_id|working_revision_payload)$/',
                        $normalizedKey,
                    ) === 1) {
                    return true;
                }
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
            return preg_match('/!\[[^\]]*\]\([^)]+\)|<(?:img|picture|source)\b/i', $value) === 1;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match('/(?:^|_)(?:hero|inline|og|media|images?)(?:_|$)/i', $key) === 1
                && ! in_array($item, [null, false, '', []], true)) {
                return true;
            }
            if ($this->containsMediaReference($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $entry */
    private function en52AuthorityAssetKey(array $entry): string
    {
        if ($entry['entity_type'] === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL) {
            return self::FACET_EN52_AUTHORITY_KEY[(string) $entry['entity_key']] ?? '';
        }
        if ($entry['entity_type'] === PersonalityPublicContentAsset::ENTITY_HUB) {
            return $entry['entity_key'] === 'big-five' ? 'big-five-hub' : '';
        }

        return (string) $entry['entity_key'];
    }

    /** @param array<string,mixed> $row */
    private function isRegisteredHistoricalSlotRevision(
        PersonalityPublicContentAssetRevision $revision,
        array $row,
    ): bool {
        $identity = $this->historicalSlotIdentity(
            (string) $row['entity_type'],
            (string) $row['entity_key'],
        );

        return $identity !== null
            && (string) $revision->source_package === $identity['source_package']
            && (string) $revision->authority_asset_key === $identity['authority_asset_key']
            && (string) $revision->authority_package_sha256 === self::HISTORICAL_AUTHORITY_PACKAGE_SHA256;
    }

    /** @return array{source_package:string,authority_asset_key:string}|null */
    private function historicalSlotIdentity(string $entityType, string $entityKey): ?array
    {
        if ($entityType === PersonalityPublicContentAsset::ENTITY_DOMAIN
            && in_array($entityKey, BigFiveCanonicalRouteCatalog::DOMAINS, true)) {
            return [
                'source_package' => 'big5-authority-v2-domains-08',
                'authority_asset_key' => 'domain:'.$entityKey,
            ];
        }
        if ($entityType === PersonalityPublicContentAsset::ENTITY_POLARITY) {
            [$domain, $range] = array_pad(explode('-', $entityKey, 2), 2, null);
            if (isset(self::HISTORICAL_PACKAGE_BY_DOMAIN[$domain])
                && in_array($range, ['high', 'mid', 'low'], true)) {
                return [
                    'source_package' => self::HISTORICAL_PACKAGE_BY_DOMAIN[$domain],
                    'authority_asset_key' => "range:{$domain}:{$range}",
                ];
            }
        }
        if ($entityType === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL) {
            $domain = self::FACET_DOMAIN[$entityKey] ?? null;
            $batch = [
                'openness' => 15,
                'conscientiousness' => 16,
                'extraversion' => 17,
                'agreeableness' => 18,
                'neuroticism' => 19,
            ][$domain] ?? null;
            if ($domain !== null && $batch !== null) {
                return [
                    'source_package' => "big5-authority-v2-facets-{$domain}-{$batch}",
                    'authority_asset_key' => "facet:{$domain}:{$entityKey}",
                ];
            }
        }

        return null;
    }
}

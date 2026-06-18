<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class Mbti64CmsRevisionPromotionService
{
    private const VARIANT_SNAPSHOT_KEY = 'mbti64_variant_content_package_v2_1';

    private const COMPARISON_SNAPSHOT_KEY = 'mbti64_comparison_draft_v2_1';

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
    public function promote(array $package, string $sourceSha256, array $options = []): array
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
            $preparedRows[] = $this->prepareRow($plannedRow, $row, $sourceSha256, $write, $errors);
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

        $promoted = 0;
        $skippedExisting = 0;
        foreach ($preparedRows as &$preparedRow) {
            if (($preparedRow['live_matches_revision'] ?? false) === true) {
                $preparedRow['action'] = $write ? 'skipped_existing' : 'would_skip_existing';
                $skippedExisting++;

                continue;
            }

            if ($write) {
                $this->applyPromotion($preparedRow);
                $preparedRow['action'] = 'promoted';
                $promoted++;

                continue;
            }

            $preparedRow['action'] = 'would_promote';
        }
        unset($preparedRow);

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
            'promoted_count' => $promoted,
            'skipped_existing_count' => $skippedExisting,
            'would_promote_count' => $write ? 0 : count($preparedRows) - $skippedExisting,
            'writes_committed' => $write && $promoted > 0,
            'rows' => $preparedRows,
            'errors' => [],
            'warnings' => $contract['warnings'] ?? [],
            'options' => $options,
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, string $sourceSha256, bool $write): array
    {
        return [
            'artifact' => 'MBTI64-BACKEND-PROMOTION-CONTRACT-01',
            'source_version' => (string) ($package['version'] ?? ''),
            'source_status' => (string) ($package['status'] ?? ''),
            'source_sha256' => $sourceSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'content_promotion_attempted' => $write,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'queue_enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'writes_committed' => false,
            'side_effect_boundary' => [
                'live_cms_content_fields_may_change' => $write,
                'profile_publish_state_changes_allowed' => false,
                'variant_publish_state_changes_allowed' => false,
                'sitemap_llms_changes_allowed' => false,
                'search_queue_changes_allowed' => false,
                'search_submit_allowed' => false,
            ],
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
     * @param  list<array<string,string>>  $errors
     * @return array<string,mixed>
     */
    private function prepareRow(
        array $plannedRow,
        array $row,
        string $sourceSha256,
        bool $write,
        array &$errors,
    ): array {
        $pageType = (string) ($plannedRow['page_type'] ?? '');
        $snapshotKey = $pageType === 'comparison' ? self::COMPARISON_SNAPSHOT_KEY : self::VARIANT_SNAPSHOT_KEY;
        $target = $this->targetRecord($plannedRow);
        $targetId = $target['id'] ?? null;
        $targetField = $pageType === 'comparison' ? 'profile_id' : 'personality_profile_variant_id';
        $revision = null;
        $snapshot = [];
        $promotionPayload = [];
        $latestRevisionNo = null;

        if (! is_int($targetId)) {
            $errors[] = [
                'field' => 'rows.'.((string) ((int) ($plannedRow['position'] ?? 0) - 1)).'.url',
                'code' => 'target_not_found',
                'message' => 'CMS target record was not found for '.$pageType.' row '.((string) ($plannedRow['url'] ?? '')),
            ];
        } else {
            $revision = $this->matchingRevision($pageType, $targetField, $targetId, $snapshotKey, $sourceSha256);
            $latestRevisionNo = $this->latestRevisionNo($pageType, $targetField, $targetId);

            if (! $revision instanceof Model) {
                $errors[] = [
                    'field' => 'rows.'.((string) ((int) ($plannedRow['position'] ?? 0) - 1)).'.revision',
                    'code' => 'revision_not_found_for_source_sha256',
                    'message' => 'No matching draft revision was found for source hash '.$sourceSha256.'.',
                ];
            } else {
                $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
                if ((int) $revision->revision_no !== (int) $latestRevisionNo) {
                    $errors[] = [
                        'field' => 'rows.'.((string) ((int) ($plannedRow['position'] ?? 0) - 1)).'.revision',
                        'code' => 'revision_not_latest_for_target',
                        'message' => 'Matching revision is not the latest revision for its target.',
                    ];
                }

                if (! $this->safetyHoldsArePromotable($snapshot, $snapshotKey, $sourceSha256)) {
                    $errors[] = [
                        'field' => 'rows.'.((string) ((int) ($plannedRow['position'] ?? 0) - 1)).'.revision',
                        'code' => 'revision_safety_holds_not_promotable',
                        'message' => 'Draft revision safety holds are not eligible for controlled promotion.',
                    ];
                }

                $promotionPayload = $this->promotionPayload($pageType, $snapshotKey, $snapshot, $row, $plannedRow);
            }
        }

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
            'revision_id' => $revision instanceof Model ? (int) $revision->getKey() : null,
            'revision_no' => $revision instanceof Model ? (int) $revision->getAttribute('revision_no') : null,
            'latest_revision_no' => $latestRevisionNo,
            'write_mode' => $write ? 'write_live_cms_content' : 'dry_run',
            'live_matches_revision' => is_int($targetId) && $promotionPayload !== []
                ? $this->liveMatchesPromotion($pageType, $targetId, $promotionPayload)
                : false,
            'action' => 'pending',
            'promotion_preview' => $promotionPayload,
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

    private function matchingRevision(
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

    private function latestRevisionNo(string $pageType, string $targetField, int $targetId): int
    {
        $query = $pageType === 'comparison'
            ? PersonalityProfileRevision::query()->where($targetField, $targetId)
            : PersonalityProfileVariantRevision::query()->where($targetField, $targetId);

        return (int) $query->max('revision_no');
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function safetyHoldsArePromotable(array $snapshot, string $snapshotKey, string $sourceSha256): bool
    {
        $node = is_array($snapshot[$snapshotKey] ?? null) ? $snapshot[$snapshotKey] : [];
        $holds = is_array($node['safety_holds'] ?? null) ? $node['safety_holds'] : [];

        return (string) ($node['source']['source_sha256'] ?? '') === $sourceSha256
            && ($holds['draft_only'] ?? null) === true
            && ($holds['publish_attempted'] ?? null) === false
            && ($holds['index_attempted'] ?? null) === false
            && ($holds['sitemap_llms_release_attempted'] ?? null) === false
            && ($holds['search_release_attempted'] ?? null) === false
            && ($holds['runtime_content_updated'] ?? null) === false;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $plannedRow
     * @return array<string,mixed>
     */
    private function promotionPayload(string $pageType, string $snapshotKey, array $snapshot, array $row, array $plannedRow): array
    {
        $node = is_array($snapshot[$snapshotKey] ?? null) ? $snapshot[$snapshotKey] : [];
        $fields = is_array($node['first_class_draft_fields'] ?? null) ? $node['first_class_draft_fields'] : [];
        $metadata = is_array($node['structured_metadata'] ?? null) ? $node['structured_metadata'] : [];
        $seo = is_array($fields['seo'] ?? null) ? $fields['seo'] : (is_array($row['seo'] ?? null) ? $row['seo'] : []);
        $content = is_array($fields['content'] ?? null) ? $fields['content'] : (is_array($row['content'] ?? null) ? $row['content'] : []);
        $faq = is_array($fields['faq'] ?? null) ? array_values((array) $fields['faq']) : (is_array($row['faq'] ?? null) ? array_values((array) $row['faq']) : []);
        $links = is_array($fields['internal_links'] ?? null)
            ? array_values((array) $fields['internal_links'])
            : (is_array($row['internal_links'] ?? null) ? array_values((array) $row['internal_links']) : []);
        $canonical = (string) ($fields['canonical_target'] ?? ($row['canonical_target'] ?? ($plannedRow['url'] ?? '')));

        return [
            'page_type' => $pageType,
            'seo' => [
                'seo_title' => $this->nullableString($seo['seo_title'] ?? null),
                'seo_description' => $this->nullableString($seo['seo_description'] ?? null),
                'canonical_url' => $canonical,
                'og_title' => $this->nullableString($seo['og_title'] ?? ($seo['seo_title'] ?? null)),
                'og_description' => $this->nullableString($seo['og_description'] ?? ($seo['seo_description'] ?? null)),
                'twitter_title' => $this->nullableString($seo['twitter_title'] ?? ($seo['seo_title'] ?? null)),
                'twitter_description' => $this->nullableString($seo['twitter_description'] ?? ($seo['seo_description'] ?? null)),
                'robots' => 'index,follow',
                'jsonld_overrides_json' => [
                    'name' => $this->nullableString($seo['h1'] ?? ($seo['seo_title'] ?? null)),
                    'description' => $this->nullableString($seo['seo_description'] ?? null),
                    'url' => $canonical,
                ],
            ],
            'sections' => $this->sectionPayloads($content, $faq, $links, $seo, $metadata, $row, $plannedRow, $snapshotKey),
            'comparison_section' => $this->comparisonSectionPayload($content, $faq, $links, $seo, $metadata, $row, $plannedRow, $snapshotKey),
        ];
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  list<mixed>  $faq
     * @param  list<mixed>  $links
     * @param  array<string,mixed>  $seo
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $plannedRow
     * @return list<array<string,mixed>>
     */
    private function sectionPayloads(
        array $content,
        array $faq,
        array $links,
        array $seo,
        array $metadata,
        array $row,
        array $plannedRow,
        string $snapshotKey,
    ): array {
        $sections = [];
        $sort = 100;
        foreach ($content as $key => $value) {
            $sectionKey = $this->safeSectionKey((string) $key);
            $payload = is_array($value) ? $value : ['body' => $value];
            $body = $this->bodyFromContent($value);

            $sections[] = [
                'section_key' => $sectionKey,
                'render_variant' => 'rich_text',
                'body_md' => $body,
                'body_html' => null,
                'payload_json' => [
                    'title' => $this->nullableString($payload['h2'] ?? ($payload['title'] ?? null)),
                    'body' => $body,
                    'source' => 'mbti64_v2_1_revision_promotion',
                    'raw' => $payload,
                ],
                'sort_order' => $sort,
                'is_enabled' => true,
            ];
            $sort += 10;
        }

        if ($faq !== []) {
            $sections[] = [
                'section_key' => 'faq',
                'render_variant' => 'faq',
                'body_md' => null,
                'body_html' => null,
                'payload_json' => [
                    'items' => $faq,
                    'source' => 'mbti64_v2_1_revision_promotion',
                ],
                'sort_order' => 900,
                'is_enabled' => true,
            ];
        }

        if ($links !== []) {
            $sections[] = [
                'section_key' => 'related_content',
                'render_variant' => 'links',
                'body_md' => null,
                'body_html' => null,
                'payload_json' => [
                    'links' => $links,
                    'source' => 'mbti64_v2_1_revision_promotion',
                ],
                'sort_order' => 910,
                'is_enabled' => true,
            ];
        }

        $sections[] = [
            'section_key' => 'mbti64_promotion_metadata',
            'render_variant' => 'callout',
            'body_md' => $this->nullableString($seo['quick_answer_summary'] ?? null),
            'body_html' => null,
            'payload_json' => [
                'snapshot_key' => $snapshotKey,
                'url' => (string) ($plannedRow['url'] ?? ''),
                'identity' => $plannedRow['identity'] ?? [],
                'structured_metadata' => $metadata,
                'raw_row' => $row,
            ],
            'sort_order' => 990,
            'is_enabled' => true,
        ];

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  list<mixed>  $faq
     * @param  list<mixed>  $links
     * @param  array<string,mixed>  $seo
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $plannedRow
     * @return array<string,mixed>
     */
    private function comparisonSectionPayload(
        array $content,
        array $faq,
        array $links,
        array $seo,
        array $metadata,
        array $row,
        array $plannedRow,
        string $snapshotKey,
    ): array {
        return [
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => $this->nullableString($seo['h1'] ?? ($seo['seo_title'] ?? 'A/T comparison')),
            'render_variant' => 'rich_text',
            'body_md' => $this->nullableString($seo['quick_answer_summary'] ?? $this->bodyFromContent($content['quick_answer'] ?? null)),
            'body_html' => null,
            'payload_json' => [
                'snapshot_key' => $snapshotKey,
                'url' => (string) ($plannedRow['url'] ?? ''),
                'seo' => $seo,
                'content' => $content,
                'faq' => $faq,
                'internal_links' => $links,
                'structured_metadata' => $metadata,
                'identity' => $plannedRow['identity'] ?? [],
                'raw_row' => $row,
                'source' => 'mbti64_v2_1_comparison_revision_promotion',
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $preparedRow
     */
    private function applyPromotion(array $preparedRow): void
    {
        $pageType = (string) ($preparedRow['page_type'] ?? '');
        $targetId = (int) ($preparedRow['target_id'] ?? 0);
        $payload = is_array($preparedRow['promotion_preview'] ?? null) ? $preparedRow['promotion_preview'] : [];

        if ($pageType === 'comparison') {
            $this->upsertComparisonSection($targetId, $payload);

            return;
        }

        $this->upsertVariantSeo($targetId, $payload);
        $this->upsertVariantSections($targetId, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function liveMatchesPromotion(string $pageType, int $targetId, array $payload): bool
    {
        if ($pageType === 'comparison') {
            $section = PersonalityProfileSection::query()
                ->withoutGlobalScopes()
                ->where('profile_id', $targetId)
                ->where('section_key', 'mbti64_comparison_a_vs_t')
                ->first();

            return $section instanceof PersonalityProfileSection
                && $this->modelSubsetMatches($section, $payload['comparison_section'] ?? []);
        }

        return $this->variantSeoMatches($targetId, $payload)
            && $this->variantSectionsMatch($targetId, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function upsertVariantSeo(int $variantId, array $payload): void
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                ['personality_profile_variant_id' => $variantId],
                array_merge($seo, ['personality_profile_variant_id' => $variantId])
            );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function upsertVariantSections(int $variantId, array $payload): void
    {
        foreach ((array) ($payload['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            PersonalityProfileVariantSection::query()
                ->withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'personality_profile_variant_id' => $variantId,
                        'section_key' => (string) ($section['section_key'] ?? ''),
                    ],
                    array_merge($section, ['personality_profile_variant_id' => $variantId])
                );
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function upsertComparisonSection(int $profileId, array $payload): void
    {
        $section = is_array($payload['comparison_section'] ?? null) ? $payload['comparison_section'] : [];
        PersonalityProfileSection::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'profile_id' => $profileId,
                    'section_key' => (string) ($section['section_key'] ?? 'mbti64_comparison_a_vs_t'),
                ],
                array_merge($section, ['profile_id' => $profileId])
            );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function variantSeoMatches(int $variantId, array $payload): bool
    {
        $seo = PersonalityProfileVariantSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('personality_profile_variant_id', $variantId)
            ->first();
        if (! $seo instanceof PersonalityProfileVariantSeoMeta) {
            return false;
        }

        return $this->modelSubsetMatches($seo, $payload['seo'] ?? []);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function variantSectionsMatch(int $variantId, array $payload): bool
    {
        foreach ((array) ($payload['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $live = PersonalityProfileVariantSection::query()
                ->withoutGlobalScopes()
                ->where('personality_profile_variant_id', $variantId)
                ->where('section_key', (string) ($section['section_key'] ?? ''))
                ->first();

            if (! $live instanceof PersonalityProfileVariantSection || ! $this->modelSubsetMatches($live, $section)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $expected
     */
    private function modelSubsetMatches(Model $model, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (in_array($key, ['org_id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            $actual = $model->getAttribute($key);
            if (is_array($value)) {
                if ($actual !== $value) {
                    return false;
                }

                continue;
            }

            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    private function safeSectionKey(string $key): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $key) ?? '', '_'));

        return substr($normalized !== '' ? $normalized : 'content', 0, 90);
    }

    private function bodyFromContent(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['body', 'summary', 'text', 'answer'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return trim($value[$key]);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}

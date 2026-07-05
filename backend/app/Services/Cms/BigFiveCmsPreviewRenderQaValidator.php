<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class BigFiveCmsPreviewRenderQaValidator
{
    private const SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    /**
     * @return array<string,mixed>
     */
    public function validate(string $sourceHash, string $targetEnvironment, int $expectedRowCount = 42): array
    {
        if (! Schema::hasTable((new PersonalityPublicContentAsset)->getTable())) {
            throw new RuntimeException('personality_public_content_assets table is missing; run migrations before preview/readback QA.');
        }

        $assets = $this->assets($sourceHash);
        $rows = $assets
            ->map(fn (PersonalityPublicContentAsset $asset): array => $this->rowPayload($asset))
            ->values()
            ->all();

        $counts = $this->counts($assets, $sourceHash);
        $errors = $this->errors($rows, $counts, $expectedRowCount);

        return [
            'artifact' => 'BIG5-CMS-PREVIEW-RENDER-QA-11',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'target_environment' => $targetEnvironment,
            'source_package' => self::SOURCE_PACKAGE,
            'source_hash' => $sourceHash,
            'expected_row_count' => $expectedRowCount,
            'row_count' => count($rows),
            'preview_payload_count' => count($rows),
            'public_api_readback_visible_count' => $counts['public_api_readback_visible_count'],
            'public_api_draft_blocked' => $counts['public_api_readback_visible_count'] === 0,
            'faq_structured_source' => 'faq_json',
            'faq_duplicate_render_risk_count' => $counts['faq_duplicate_render_risk_count'],
            'noindex_gate' => [
                'is_public_true' => $counts['is_public_true'],
                'index_eligible_true' => $counts['index_eligible_true'],
                'sitemap_eligible_true' => $counts['sitemap_eligible_true'],
                'llms_eligible_true' => $counts['llms_eligible_true'],
                'published_at_not_null' => $counts['published_at_not_null'],
                'robots_values' => $counts['robots_values'],
                'launch_states' => $counts['launch_states'],
                'review_states' => $counts['review_states'],
            ],
            'discoverability_gates' => [
                'sitemap_blocked' => $counts['sitemap_eligible_true'] === 0,
                'llms_blocked' => $counts['llms_eligible_true'] === 0,
                'jsonld_runtime_blocked' => $counts['runtime_jsonld_enabled_count'] === 0
                    && $counts['schema_payload_non_empty_count'] === 0,
            ],
            'runtime_jsonld_enabled_count' => $counts['runtime_jsonld_enabled_count'],
            'schema_payload_non_empty_count' => $counts['schema_payload_non_empty_count'],
            'sample_preview_payloads' => array_slice($rows, 0, 3),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'jsonld_runtime_release_attempted' => false,
        ];
    }

    /**
     * @return Collection<int,PersonalityPublicContentAsset>
     */
    private function assets(string $sourceHash): Collection
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('source_package', self::SOURCE_PACKAGE)
            ->where('source_hash', $sourceHash)
            ->orderBy('locale')
            ->orderBy('entity_type')
            ->orderBy('entity_key')
            ->get();
    }

    /**
     * @param  Collection<int,PersonalityPublicContentAsset>  $assets
     * @return array<string,mixed>
     */
    private function counts(Collection $assets, string $sourceHash): array
    {
        $publicApiVisibleCount = PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('source_package', self::SOURCE_PACKAGE)
            ->where('source_hash', $sourceHash)
            ->publiclyReadable()
            ->count();

        $robotsValues = $assets
            ->map(fn (PersonalityPublicContentAsset $asset): string => (string) $asset->robots)
            ->unique()
            ->values()
            ->all();
        $launchStates = $assets
            ->map(fn (PersonalityPublicContentAsset $asset): string => (string) $asset->launch_state)
            ->unique()
            ->values()
            ->all();
        $reviewStates = $assets
            ->map(fn (PersonalityPublicContentAsset $asset): string => (string) $asset->review_state)
            ->unique()
            ->values()
            ->all();

        return [
            'public_api_readback_visible_count' => $publicApiVisibleCount,
            'is_public_true' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (bool) $asset->is_public)->count(),
            'index_eligible_true' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (bool) $asset->index_eligible)->count(),
            'sitemap_eligible_true' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (bool) $asset->sitemap_eligible)->count(),
            'llms_eligible_true' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (bool) $asset->llms_eligible)->count(),
            'published_at_not_null' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => $asset->published_at !== null)->count(),
            'robots_values' => $robotsValues,
            'launch_states' => $launchStates,
            'review_states' => $reviewStates,
            'faq_duplicate_render_risk_count' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => $this->faqLikeBodySectionCount($asset) > 0)->count(),
            'runtime_jsonld_enabled_count' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => (bool) data_get($asset->schema_json, 'runtime_jsonld_enabled'))->count(),
            'schema_payload_non_empty_count' => $assets->filter(fn (PersonalityPublicContentAsset $asset): bool => $this->schemaRuntimeEligible($asset) && is_array($asset->schema_json) && $asset->schema_json !== [])->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rowPayload(PersonalityPublicContentAsset $asset): array
    {
        $sections = is_array($asset->content_sections_json) ? $asset->content_sections_json : [];
        $faq = is_array($asset->faq_json) ? $asset->faq_json : [];
        $schemaRuntimeEligible = $this->schemaRuntimeEligible($asset);

        return [
            'id' => (int) $asset->id,
            'framework' => (string) $asset->framework,
            'entity_type' => (string) $asset->entity_type,
            'entity_key' => (string) $asset->entity_key,
            'slug' => (string) $asset->slug,
            'locale' => (string) $asset->locale,
            'canonical_path' => (string) data_get($asset->canonical_json, 'path', ''),
            'title' => (string) $asset->title,
            'section_count' => count($sections),
            'faq_count' => count($faq),
            'faq_body_section_count' => $this->faqLikeBodySectionCount($asset),
            'faq_duplicate_render_risk' => $this->faqLikeBodySectionCount($asset) > 0,
            'schema_runtime_eligible' => $schemaRuntimeEligible,
            'schema_payload_empty_for_public_runtime' => ! $schemaRuntimeEligible,
            'is_public' => (bool) $asset->is_public,
            'index_eligible' => (bool) $asset->index_eligible,
            'sitemap_eligible' => (bool) $asset->sitemap_eligible,
            'llms_eligible' => (bool) $asset->llms_eligible,
            'robots' => PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots),
            'launch_state' => (string) $asset->launch_state,
            'review_state' => (string) $asset->review_state,
            'published_at' => $asset->published_at?->toAtomString(),
        ];
    }

    private function schemaRuntimeEligible(PersonalityPublicContentAsset $asset): bool
    {
        return (string) $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && (bool) $asset->index_eligible
            && PersonalityPublicContentAsset::normalizeRobots((string) $asset->robots) === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW;
    }

    private function faqLikeBodySectionCount(PersonalityPublicContentAsset $asset): int
    {
        $sections = is_array($asset->content_sections_json) ? $asset->content_sections_json : [];

        return count(array_filter($sections, static function (mixed $section): bool {
            if (! is_array($section)) {
                return false;
            }

            $title = trim((string) ($section['title'] ?? $section['heading'] ?? ''));

            return preg_match('/^(faq|常见问题|问答|问题)$/iu', $title) === 1;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $counts
     * @return list<array<string,string>>
     */
    private function errors(array $rows, array $counts, int $expectedRowCount): array
    {
        $errors = [];

        if (count($rows) !== $expectedRowCount) {
            $errors[] = $this->issue('rows', 'row_count_mismatch', 'Expected '.$expectedRowCount.' Big Five CMS preview/readback rows.');
        }

        foreach ([
            'is_public_true',
            'index_eligible_true',
            'sitemap_eligible_true',
            'llms_eligible_true',
            'published_at_not_null',
            'public_api_readback_visible_count',
            'faq_duplicate_render_risk_count',
            'runtime_jsonld_enabled_count',
            'schema_payload_non_empty_count',
        ] as $zeroField) {
            if ((int) ($counts[$zeroField] ?? 0) !== 0) {
                $errors[] = $this->issue($zeroField, 'gate_must_remain_zero', $zeroField.' must remain 0 for Big Five CMS draft preview/readback QA.');
            }
        }

        foreach ($rows as $index => $row) {
            if ((int) ($row['faq_count'] ?? 0) < 5) {
                $errors[] = $this->issue('rows.'.((string) $index).'.faq_count', 'faq_count_too_low', 'Each preview/readback payload must retain at least 5 structured FAQ entries.');
            }

            if ((string) ($row['robots'] ?? '') !== PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW) {
                $errors[] = $this->issue('rows.'.((string) $index).'.robots', 'robots_must_remain_noindex_follow', 'Draft preview/readback rows must remain noindex,follow.');
            }

            if ((string) ($row['launch_state'] ?? '') !== PersonalityPublicContentAsset::LAUNCH_REVIEW) {
                $errors[] = $this->issue('rows.'.((string) $index).'.launch_state', 'launch_state_must_remain_review', 'Draft preview/readback rows must remain in review state.');
            }
        }

        return $errors;
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}

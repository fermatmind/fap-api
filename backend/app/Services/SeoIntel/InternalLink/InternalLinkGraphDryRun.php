<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\InternalLink;

use App\Models\ArticleSeoMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InternalLinkGraphDryRun
{
    public const RUNTIME = 'internal_link_graph_dry_run';

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $familyCounts = $this->emptyFamilyCounts();
        $sourceInventory = $this->sourceInventory();

        $familyCounts['article_to_test'] = $sourceInventory['cms_backend_authoritative']['article_related_test_slug']
            + $sourceInventory['cms_backend_authoritative']['article_editorial_package_target_tests'];
        $familyCounts['article_to_topic'] = $sourceInventory['cms_backend_authoritative']['article_editorial_package_target_topics'];
        $familyCounts['career_guide_to_test_topic_article'] = $sourceInventory['cms_backend_authoritative']['career_guide_related_articles'];
        $familyCounts['career_job_to_career_guide_test_topic'] = $sourceInventory['cms_backend_authoritative']['career_guide_related_jobs'];
        $familyCounts['personality_page_to_test_topic_article'] = $sourceInventory['cms_backend_authoritative']['career_guide_related_personality_profiles'];

        $missingEntityKeyCount = $this->missingEntityKeyCount();
        $legacyUnpairedCount = $missingEntityKeyCount + $this->missingTranslationGroupUuidCount();
        $unsafeFallbackSourceCount = array_sum($sourceInventory['unsafe_or_non_authoritative_signals']);
        $candidateOpportunityCount = array_sum($familyCounts);
        $warnings = $this->warnings($missingEntityKeyCount, $legacyUnpairedCount, $unsafeFallbackSourceCount);

        return [
            'runtime' => self::RUNTIME,
            'status' => 'success',
            'dry_run' => true,
            'no_write' => true,
            'writes_attempted' => false,
            'cms_mutation_attempted' => false,
            'link_mutation_attempted' => false,
            'fap_web_modification_attempted' => false,
            'crawler_log_read_attempted' => false,
            'crawler_log_authority_claimed' => false,
            'sitemap_graph_truth_claimed' => false,
            'frontend_fallback_authority_claimed' => false,
            'search_channel_enqueue_attempted' => false,
            'search_submission_attempted' => false,
            'observation_queue_write_attempted' => false,
            'source_inventory' => $sourceInventory,
            'graph_family_counts' => $familyCounts,
            'missing_entity_key_count' => $missingEntityKeyCount,
            'legacy_unpaired_count' => $legacyUnpairedCount,
            'candidate_opportunity_count' => $candidateOpportunityCount,
            'unsafe_fallback_source_count' => $unsafeFallbackSourceCount,
            'entity_key_policy' => [
                'preferred' => 'translation_group_uuid',
                'transitional' => 'translation_group_id_where_already_supported',
                'missing' => 'legacy_unpaired',
                'title_slug_similarity' => 'migration_helper_only_not_authority',
            ],
            'authority_policy' => [
                'truth_source' => 'backend_cms_entity_graph',
                'fap_web' => 'deterministic_renderer_observation_signal_only',
                'sitemap' => 'observation_signal_only_not_graph_truth',
                'crawler_logs' => 'aggregate_observation_only_never_link_authority',
                'gsc_ga4_referral' => 'opportunity_signal_only_never_auto_create_links',
            ],
            'warnings' => $warnings,
            'safety_flags' => [
                'dry_run_only' => true,
                'no_cms_mutation' => true,
                'no_link_mutation' => true,
                'no_fap_web_modification' => true,
                'no_crawler_log_read' => true,
                'no_crawler_derived_authority' => true,
                'no_sitemap_derived_truth' => true,
                'no_frontend_fallback_authority' => true,
                'no_auto_link_creation' => true,
                'no_search_channel_enqueue' => true,
                'no_search_submission' => true,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyFamilyCounts(): array
    {
        return [
            'article_to_test' => 0,
            'article_to_topic' => 0,
            'article_to_research' => 0,
            'article_to_related_article' => 0,
            'topic_to_test' => 0,
            'topic_to_article' => 0,
            'topic_to_personality_entity' => 0,
            'research_to_topic_test_article' => 0,
            'test_to_article_topic_research' => 0,
            'career_guide_to_test_topic_article' => 0,
            'career_job_to_career_guide_test_topic' => 0,
            'personality_page_to_test_topic_article' => 0,
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function sourceInventory(): array
    {
        return [
            'cms_backend_authoritative' => [
                'article_related_test_slug' => $this->articleRelatedTestSlugCount(),
                'article_editorial_package_target_tests' => $this->articleEditorialPackageListCount('target_tests'),
                'article_editorial_package_target_topics' => $this->articleEditorialPackageListCount('target_topics'),
                'career_guide_related_articles' => $this->tableCount('career_guide_article_map'),
                'career_guide_related_jobs' => $this->tableCount('career_guide_job_map'),
                'career_guide_related_personality_profiles' => $this->tableCount('career_guide_personality_map'),
            ],
            'deterministic_public_runtime_signals' => [
                'fap_web_rendered_links_observed' => 0,
            ],
            'unsafe_or_non_authoritative_signals' => [
                'frontend_fallback_links' => 0,
                'sitemap_derived_links' => 0,
                'llms_derived_links' => 0,
                'crawler_log_derived_links' => 0,
                'search_engine_response_derived_links' => 0,
            ],
        ];
    }

    private function articleRelatedTestSlugCount(): int
    {
        if (! Schema::hasTable('articles')) {
            return 0;
        }

        return (int) DB::table('articles')
            ->whereNotNull('related_test_slug')
            ->where('related_test_slug', '!=', '')
            ->count();
    }

    private function articleEditorialPackageListCount(string $key): int
    {
        if (! Schema::hasTable('article_seo_meta')) {
            return 0;
        }

        return ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->get(['schema_json'])
            ->sum(function (ArticleSeoMeta $seoMeta) use ($key): int {
                $items = data_get($seoMeta->schema_json, 'editorial_package_v1.'.$key, []);

                return is_array($items) ? count($items) : 0;
            });
    }

    private function missingEntityKeyCount(): int
    {
        if (! Schema::hasTable('articles')) {
            return 0;
        }

        return (int) DB::table('articles')
            ->where(static function ($query): void {
                $query
                    ->whereNull('translation_group_id')
                    ->orWhere('translation_group_id', '=', '');
            })
            ->count();
    }

    private function missingTranslationGroupUuidCount(): int
    {
        $count = 0;

        foreach (['articles', 'career_guides', 'career_jobs', 'personality_profiles'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'translation_group_uuid')) {
                continue;
            }

            $count += (int) DB::table($table)->count();
        }

        return $count;
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function warnings(int $missingEntityKeyCount, int $legacyUnpairedCount, int $unsafeFallbackSourceCount): array
    {
        $warnings = [];

        if ($missingEntityKeyCount > 0) {
            $warnings[] = [
                'code' => 'missing_entity_key',
                'message' => 'Some backend CMS entities do not have a stable entity_key candidate.',
                'count' => $missingEntityKeyCount,
            ];
        }

        if ($legacyUnpairedCount > 0) {
            $warnings[] = [
                'code' => 'legacy_unpaired',
                'message' => 'translation_group_uuid is not universally available; legacy_unpaired coverage remains transitional.',
                'count' => $legacyUnpairedCount,
            ];
        }

        if ($unsafeFallbackSourceCount > 0) {
            $warnings[] = [
                'code' => 'unsafe_fallback_source_detected',
                'message' => 'A non-authoritative link source was observed and must not become graph truth.',
                'count' => $unsafeFallbackSourceCount,
            ];
        }

        return $warnings;
    }
}

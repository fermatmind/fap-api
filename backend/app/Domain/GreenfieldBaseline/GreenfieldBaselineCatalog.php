<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

final class GreenfieldBaselineCatalog
{
    public const PACKAGE_SCHEMA = 'fermatmind.greenfield.current-baseline.v1';

    public const STREAM_SCHEMA = 'fermatmind.greenfield.current-baseline.stream.v1';

    public const PROJECTION_FILENAME = 'career-runtime-publish-projection.json';

    /**
     * Datasets are deliberately allowlisted. A table absent from this catalog can
     * never enter a Greenfield package.
     *
     * @return list<array<string, mixed>>
     */
    public static function datasets(): array
    {
        $publishedArticles = "status = 'published' AND is_public = 1 AND deleted_at IS NULL AND published_revision_id IS NOT NULL";
        $publishedContentPages = "status = 'published' AND is_public = 1";
        $publishedLandingSurfaces = "status = 'published' AND is_public = 1";
        $publishedPersonalityAssets = "launch_state = 'published' AND is_public = 1 AND published_revision_id IS NOT NULL";
        $publishedProfiles = "status = 'published' AND is_public = 1";
        $publishedTopics = "status = 'published' AND is_public = 1";
        $publishedCareerJobs = "status = 'published' AND is_public = 1";
        $publishedCareerGuides = "status = 'published' AND is_public = 1";

        return [
            self::dataset('article_categories', 'article_categories', "id IN (SELECT category_id FROM articles WHERE {$publishedArticles} AND category_id IS NOT NULL)"),
            self::dataset(
                'articles',
                'articles',
                $publishedArticles,
                deferred: ['translated_from_article_id', 'source_article_id', 'working_revision_id', 'published_revision_id'],
                nullColumns: ['author_admin_user_id', 'lifecycle_changed_by_admin_user_id'],
                alignWorkingRevision: true,
            ),
            self::dataset('article_translation_revisions', 'article_translation_revisions', "id IN (SELECT published_revision_id FROM articles WHERE {$publishedArticles})", nullColumns: ['created_by', 'reviewed_by', 'supersedes_revision_id']),
            self::dataset('article_seo_meta', 'article_seo_meta', "article_id IN (SELECT id FROM articles WHERE {$publishedArticles})"),
            self::dataset('article_tags', 'article_tags', "id IN (SELECT tag_id FROM article_tag_map WHERE article_id IN (SELECT id FROM articles WHERE {$publishedArticles}))"),
            self::dataset('article_tag_map', 'article_tag_map', "article_id IN (SELECT id FROM articles WHERE {$publishedArticles})"),
            self::optionalDataset('article_test_edges', 'article_test_edges', "article_id IN (SELECT id FROM articles WHERE {$publishedArticles})"),

            self::dataset('content_pages', 'content_pages', $publishedContentPages, deferred: ['source_content_id', 'working_revision_id', 'published_revision_id'], nullColumns: ['source_doc'], alignWorkingRevision: true),
            self::dataset('cms_translation_revisions', 'cms_translation_revisions', "id IN (SELECT published_revision_id FROM content_pages WHERE {$publishedContentPages} AND published_revision_id IS NOT NULL)", nullColumns: ['created_by_admin_id', 'supersedes_revision_id']),
            self::dataset('landing_surfaces', 'landing_surfaces', $publishedLandingSurfaces),
            self::dataset('page_blocks', 'page_blocks', "landing_surface_id IN (SELECT id FROM landing_surfaces WHERE {$publishedLandingSurfaces}) AND is_enabled = 1"),

            self::dataset(
                'personality_public_content_assets',
                'personality_public_content_assets',
                $publishedPersonalityAssets,
                deferred: ['working_revision_id', 'published_revision_id'],
                nullColumns: self::adminActorColumns(),
                alignWorkingRevision: true,
            ),
            self::dataset('personality_public_content_asset_revisions', 'personality_public_content_asset_revisions', "id IN (SELECT published_revision_id FROM personality_public_content_assets WHERE {$publishedPersonalityAssets})", nullColumns: ['created_by_admin_user_id']),
            self::dataset('personality_public_content_asset_revision_reviews', 'personality_public_content_asset_revision_reviews', "revision_id IN (SELECT published_revision_id FROM personality_public_content_assets WHERE {$publishedPersonalityAssets})", nullColumns: ['bound_by_admin_user_id']),
            self::dataset('personality_profiles', 'personality_profiles', $publishedProfiles, nullColumns: self::adminActorColumns()),
            self::dataset('personality_profile_sections', 'personality_profile_sections', "profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles}) AND is_enabled = 1"),
            self::dataset('personality_profile_seo_meta', 'personality_profile_seo_meta', "profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles})"),
            self::dataset('personality_profile_variants', 'personality_profile_variants', "personality_profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles}) AND is_published = 1"),
            self::dataset('personality_profile_variant_sections', 'personality_profile_variant_sections', "personality_profile_variant_id IN (SELECT id FROM personality_profile_variants WHERE personality_profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles}) AND is_published = 1) AND is_enabled = 1"),
            self::dataset('personality_profile_variant_seo_meta', 'personality_profile_variant_seo_meta', "personality_profile_variant_id IN (SELECT id FROM personality_profile_variants WHERE personality_profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles}) AND is_published = 1)"),
            self::optionalDataset('personality_profile_variant_clone_contents', 'personality_profile_variant_clone_contents', "personality_profile_variant_id IN (SELECT id FROM personality_profile_variants WHERE personality_profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles}) AND is_published = 1) AND status = 'published'"),

            self::dataset('topic_profiles', 'topic_profiles', $publishedTopics, nullColumns: self::adminActorColumns()),
            self::dataset('topic_profile_sections', 'topic_profile_sections', "profile_id IN (SELECT id FROM topic_profiles WHERE {$publishedTopics}) AND is_enabled = 1"),
            self::dataset('topic_profile_entries', 'topic_profile_entries', "profile_id IN (SELECT id FROM topic_profiles WHERE {$publishedTopics}) AND is_enabled = 1"),
            self::dataset('topic_profile_seo_meta', 'topic_profile_seo_meta', "profile_id IN (SELECT id FROM topic_profiles WHERE {$publishedTopics})"),
            self::optionalDataset('mbti_cross_type_comparison_authorities', 'mbti_cross_type_comparison_authorities', "publish_status = 'published' AND is_public = 1"),

            self::dataset('career_jobs', 'career_jobs', $publishedCareerJobs, nullColumns: self::careerActorColumns()),
            self::dataset('career_job_sections', 'career_job_sections', "job_id IN (SELECT id FROM career_jobs WHERE {$publishedCareerJobs}) AND is_enabled = 1"),
            self::dataset('career_job_seo_meta', 'career_job_seo_meta', "job_id IN (SELECT id FROM career_jobs WHERE {$publishedCareerJobs})"),
            self::dataset('career_guides', 'career_guides', $publishedCareerGuides, nullColumns: ['lifecycle_changed_by_admin_user_id']),
            self::dataset('career_guide_seo_meta', 'career_guide_seo_meta', "career_guide_id IN (SELECT id FROM career_guides WHERE {$publishedCareerGuides})"),
            self::dataset('career_guide_article_map', 'career_guide_article_map', "career_guide_id IN (SELECT id FROM career_guides WHERE {$publishedCareerGuides}) AND article_id IN (SELECT id FROM articles WHERE {$publishedArticles})"),
            self::dataset('career_guide_job_map', 'career_guide_job_map', "career_guide_id IN (SELECT id FROM career_guides WHERE {$publishedCareerGuides}) AND career_job_id IN (SELECT id FROM career_jobs WHERE {$publishedCareerJobs})"),
            self::dataset('career_guide_personality_map', 'career_guide_personality_map', "career_guide_id IN (SELECT id FROM career_guides WHERE {$publishedCareerGuides}) AND personality_profile_id IN (SELECT id FROM personality_profiles WHERE {$publishedProfiles})"),

            self::dataset('career_job_ai_impact_assets', 'career_job_ai_impact_assets', "status = 'production_imported'"),
            self::dataset('career_job_salary_assets', 'career_job_salary_assets', "status = 'production_imported'"),
            self::dataset('career_job_page_assembly_assets', 'career_job_page_assembly_assets', "status = 'production_imported'"),
            self::dataset('career_job_display_assets', 'career_job_display_assets', "status = 'ready_for_pilot'"),
            self::dataset('occupation_families', 'occupation_families', 'id IN (SELECT DISTINCT family_id FROM occupations WHERE '.self::occupationSelectionSql().')'),
            self::dataset('occupations', 'occupations', self::occupationSelectionSql(), deferred: ['parent_id']),
            self::dataset('occupation_aliases', 'occupation_aliases', 'occupation_id IN (SELECT id FROM occupations WHERE '.self::occupationSelectionSql().') OR family_id IN (SELECT DISTINCT family_id FROM occupations WHERE '.self::occupationSelectionSql().')'),
            self::dataset('occupation_crosswalks', 'occupation_crosswalks', 'occupation_id IN (SELECT id FROM occupations WHERE '.self::occupationSelectionSql().')'),
            self::dataset('occupation_truth_metrics', 'occupation_truth_metrics', 'occupation_id IN (SELECT id FROM occupations WHERE '.self::occupationSelectionSql().')', nullColumns: ['source_trace_id']),
            self::dataset('occupation_skill_graphs', 'occupation_skill_graphs', 'occupation_id IN (SELECT id FROM occupations WHERE '.self::occupationSelectionSql().')'),

            self::dataset('media_assets', 'media_assets', "status = 'published' AND is_public = 1", nullColumns: ['uploaded_by_admin_user_id', 'last_error']),
            self::dataset('media_variants', 'media_variants', "media_asset_id IN (SELECT id FROM media_assets WHERE status = 'published' AND is_public = 1)", nullColumns: ['last_error']),
            self::dataset('content_path_aliases', 'content_path_aliases', '1 = 1'),
            self::dataset('skus', 'skus', 'is_active = 1'),
        ];
    }

    /** @return list<string> */
    public static function forbiddenDatasetNames(): array
    {
        return [
            'users', 'user_profiles', 'admin_users', 'orders', 'payments', 'refunds',
            'subscriptions', 'attempts', 'answers', 'results', 'reports', 'invitations',
            'jobs', 'failed_jobs', 'sessions', 'cache', 'locks', 'email_outbox',
            'daily_giving_records', 'experiment_assignments',
        ];
    }

    /** @return list<string> */
    public static function forbiddenFieldNames(): array
    {
        return [
            'password', 'password_hash', 'remember_token', 'access_token', 'refresh_token',
            'secret', 'secret_key', 'private_key', 'private_path', 'proof_private_path',
            'email', 'phone', 'ip_address', 'user_agent',
        ];
    }

    /** @return array<string, int> */
    public static function expectedDatasetCounts(): array
    {
        return [
            'articles' => 129,
            'content_pages' => 56,
            'landing_surfaces' => 15,
            'personality_public_content_assets' => 220,
            'personality_profiles' => 32,
            'personality_profile_variants' => 64,
            'career_jobs' => 378,
            'career_guides' => 20,
            'occupations' => 1046,
            'career_job_ai_impact_assets' => 2092,
            'career_job_salary_assets' => 2092,
            'career_job_page_assembly_assets' => 2092,
            'media_assets' => 111,
            'media_variants' => 660,
        ];
    }

    /**
     * @param  list<string>  $deferred
     * @param  list<string>  $nullColumns
     * @return array<string, mixed>
     */
    private static function dataset(
        string $name,
        string $table,
        string $where,
        array $deferred = [],
        array $nullColumns = [],
        bool $alignWorkingRevision = false,
    ): array {
        return compact('name', 'table', 'where', 'deferred', 'nullColumns', 'alignWorkingRevision') + ['required' => true];
    }

    /**
     * @param  list<string>  $deferred
     * @param  list<string>  $nullColumns
     * @return array<string, mixed>
     */
    private static function optionalDataset(
        string $name,
        string $table,
        string $where,
        array $deferred = [],
        array $nullColumns = [],
        bool $alignWorkingRevision = false,
    ): array {
        return compact('name', 'table', 'where', 'deferred', 'nullColumns', 'alignWorkingRevision') + ['required' => false];
    }

    private static function occupationSelectionSql(): string
    {
        return <<<'SQL'
id IN (
    SELECT occupation_id FROM career_job_ai_impact_assets WHERE status = 'production_imported'
    UNION
    SELECT occupation_id FROM career_job_salary_assets WHERE status = 'production_imported'
    UNION
    SELECT occupation_id FROM career_job_page_assembly_assets WHERE status = 'production_imported'
    UNION
    SELECT occupation_id FROM career_job_display_assets WHERE status = 'ready_for_pilot'
)
SQL;
    }

    /** @return list<string> */
    private static function adminActorColumns(): array
    {
        return ['created_by_admin_user_id', 'updated_by_admin_user_id'];
    }

    /** @return list<string> */
    private static function careerActorColumns(): array
    {
        return ['created_by_admin_user_id', 'updated_by_admin_user_id', 'lifecycle_changed_by_admin_user_id'];
    }
}

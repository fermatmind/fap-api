<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class SeoOpsGaokaoV5PublishGateRepairCommand extends Command
{
    private const OUTPUT_SCHEMA = 'seo-ops-gaokao-v5-publish-gate-repair.v1';

    private const COMMAND_NAME = 'seo-ops:gaokao-v5-publish-gate-repair';

    protected $signature = 'seo-ops:gaokao-v5-publish-gate-repair
        {--package= : Path to the repaired Gaokao v5 SEO content package directory}
        {--confirm-package-sha256= : Expected SHA-256 of the package directory}
        {--article= : Exact draft article id}
        {--revision-id= : Exact working ArticleTranslationRevision id}
        {--translation-group-id= : Expected translation_group_id}
        {--expected-zh-slug= : Expected zh-CN article slug}
        {--reviewed-by= : Admin user id recorded as editorial reviewer in execute mode}
        {--artifact-dir= : Directory for evidence output}
        {--execute : Actually repair publish-gate metadata}
        {--confirm-repair= : Exact confirmation phrase required with --execute}
        {--json : Emit JSON summary}';

    protected $description = 'Repair Gaokao v5 draft publish-gate metadata without publishing, sitemap, llms, URL Truth, or search side effects.';

    public function handle(): int
    {
        $artifactDir = $this->artifactDir();
        if ($artifactDir === null) {
            return $this->finish($this->failureSummary(['artifact_dir_unwritable']));
        }

        $execute = (bool) $this->option('execute');
        $packageRoot = $this->packageRoot();
        $packageSha = $packageRoot !== null ? $this->packageSha256($packageRoot) : '';
        $expectedSha = trim((string) $this->option('confirm-package-sha256'));
        $articleId = (int) $this->option('article');
        $revisionId = (int) $this->option('revision-id');
        $translationGroupId = trim((string) $this->option('translation-group-id'));
        $expectedSlug = trim((string) $this->option('expected-zh-slug'));
        $reviewedBy = (int) $this->option('reviewed-by');
        $confirmation = trim((string) $this->option('confirm-repair'));

        $issues = $this->inputIssues($packageRoot, $packageSha, $expectedSha, $articleId, $revisionId, $translationGroupId, $expectedSlug, $reviewedBy, $execute);
        $packageContext = $packageRoot !== null ? $this->packageContext($packageRoot) : [];
        $authority = $this->authoritySnapshot($articleId, $revisionId, $translationGroupId, $expectedSlug);
        $repairPlan = $this->repairPlan($packageContext);
        $issues = array_merge($issues, $this->authorityIssues($authority));

        $requiredConfirmation = $this->confirmationPhrase($packageSha, $articleId, $revisionId, $reviewedBy);
        if ($execute && ! hash_equals($requiredConfirmation, $confirmation)) {
            $issues[] = 'confirmation_mismatch';
        }

        $writeResult = null;
        if ($issues === [] && $execute) {
            $writeResult = DB::transaction(function () use ($articleId, $revisionId, $reviewedBy, $repairPlan): array {
                return $this->executeRepair($articleId, $revisionId, $reviewedBy, $repairPlan);
            });
            $authority = $this->authoritySnapshot($articleId, $revisionId, (string) $this->option('translation-group-id'), (string) $this->option('expected-zh-slug'));
        }

        $summary = $this->summary(
            packageRoot: $packageRoot,
            packageSha: $packageSha,
            expectedSha: $expectedSha,
            articleId: $articleId,
            revisionId: $revisionId,
            translationGroupId: $translationGroupId,
            expectedSlug: $expectedSlug,
            reviewedBy: $reviewedBy,
            execute: $execute,
            packageContext: $packageContext,
            authority: $authority,
            repairPlan: $repairPlan,
            writeResult: $writeResult,
            issues: $issues,
            requiredConfirmation: $requiredConfirmation,
        );
        $summary['evidence'] = $this->writeEvidence($artifactDir, $summary);

        return $this->finish($summary);
    }

    /**
     * @return list<string>
     */
    private function inputIssues(?string $packageRoot, string $packageSha, string $expectedSha, int $articleId, int $revisionId, string $translationGroupId, string $expectedSlug, int $reviewedBy, bool $execute): array
    {
        $issues = [];
        if ($packageRoot === null) {
            $issues[] = 'package_unreadable';
        } elseif ($expectedSha === '' || ! hash_equals($expectedSha, $packageSha)) {
            $issues[] = 'package_sha_mismatch';
        }
        if ($articleId <= 0) {
            $issues[] = 'article_required';
        }
        if ($revisionId <= 0) {
            $issues[] = 'revision_id_required';
        }
        if ($translationGroupId === '') {
            $issues[] = 'translation_group_id_required';
        }
        if ($expectedSlug === '') {
            $issues[] = 'expected_zh_slug_required';
        }
        if ($execute && $reviewedBy <= 0) {
            $issues[] = 'reviewed_by_required_for_execute';
        }

        return $issues;
    }

    /**
     * @return array<string,mixed>
     */
    private function packageContext(string $packageRoot): array
    {
        $cmsFields = $this->readJsonFile($packageRoot.'/cms/CMS_FIELDS_zh-CN_'.trim((string) $this->option('expected-zh-slug')).'.json');
        if ($cmsFields === []) {
            $cmsFields = $this->readJsonFile($packageRoot.'/cms_fields.json');
        }

        $faqItems = $this->normalizedFaqItems($this->readJsonFile($packageRoot.'/FAQ.zh-CN.json'));
        $dynamicCta = $this->readJsonFile($packageRoot.'/contracts/DYNAMIC_CTA_CONTRACT.json');
        $internalLinks = $this->readJsonFile($packageRoot.'/contracts/INTERNAL_LINK_PLAN.json');

        return [
            'cms_fields' => $cmsFields,
            'faq_items' => $faqItems,
            'dynamic_cta' => $dynamicCta,
            'internal_links' => $internalLinks,
            'tag_suggestions' => $this->stringList($cmsFields['tag_suggestions'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function authoritySnapshot(int $articleId, int $revisionId, string $translationGroupId, string $expectedSlug): array
    {
        $article = $articleId > 0
            ? Article::query()->withoutGlobalScopes()->with(['workingRevision', 'seoMeta', 'tags'])->find($articleId)
            : null;
        $revision = $revisionId > 0
            ? ArticleTranslationRevision::query()->withoutGlobalScopes()->find($revisionId)
            : null;
        $import = $article instanceof Article
            ? ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $articleId)->latest('id')->first()
            : null;
        $seoMeta = $article?->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;

        return [
            'article_found' => $article instanceof Article,
            'article' => $article instanceof Article ? [
                'id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'working_revision_id' => (int) ($article->working_revision_id ?? 0),
                'published_revision_id' => $article->published_revision_id,
                'tag_count' => $article->tags->count(),
            ] : null,
            'revision_found' => $revision instanceof ArticleTranslationRevision,
            'revision' => $revision instanceof ArticleTranslationRevision ? [
                'id' => (int) $revision->id,
                'article_id' => (int) $revision->article_id,
                'revision_status' => (string) $revision->revision_status,
                'reviewed_by' => $revision->reviewed_by,
                'reviewed_at' => $revision->reviewed_at?->toIso8601String(),
                'approved_at' => $revision->approved_at?->toIso8601String(),
            ] : null,
            'import_found' => $import instanceof ArticleEditorialPackageImport,
            'latest_import' => $import instanceof ArticleEditorialPackageImport ? [
                'id' => (int) $import->id,
                'status' => (string) $import->status,
                'references_count' => (int) $import->references_count,
                'references_status' => (string) data_get($import->references_json, 'status', ''),
                'graph_status' => (string) data_get($import->graph_json, 'status', ''),
                'answer_surface_status' => (string) data_get($import->answer_surface_json, 'status', ''),
            ] : null,
            'seo_meta_found' => $seoMeta instanceof ArticleSeoMeta,
            'seo_meta' => $seoMeta instanceof ArticleSeoMeta ? [
                'id' => (int) $seoMeta->id,
                'robots' => (string) $seoMeta->robots,
                'is_indexable' => (bool) $seoMeta->is_indexable,
                'cta_count' => count((array) data_get($seoMeta->schema_json, 'editorial_package_v1.cta_slots', [])),
                'faq_count' => count((array) data_get($seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items', [])),
            ] : null,
            'expected' => [
                'translation_group_id' => $translationGroupId,
                'slug' => $expectedSlug,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $authority
     * @return list<string>
     */
    private function authorityIssues(array $authority): array
    {
        $issues = [];
        $article = $authority['article'] ?? null;
        $revision = $authority['revision'] ?? null;

        if (! ($authority['article_found'] ?? false) || ! is_array($article)) {
            return ['article_not_found'];
        }
        if (! ($authority['revision_found'] ?? false) || ! is_array($revision)) {
            $issues[] = 'revision_not_found';
        }
        if (($authority['import_found'] ?? false) !== true) {
            $issues[] = 'latest_import_missing';
        }
        if (($authority['seo_meta_found'] ?? false) !== true) {
            $issues[] = 'seo_meta_missing';
        }
        if ((string) ($article['locale'] ?? '') !== 'zh-CN') {
            $issues[] = 'locale_not_zh_cn';
        }
        if ((string) ($article['slug'] ?? '') !== (string) data_get($authority, 'expected.slug')) {
            $issues[] = 'slug_mismatch';
        }
        if ((string) ($article['translation_group_id'] ?? '') !== (string) data_get($authority, 'expected.translation_group_id')) {
            $issues[] = 'translation_group_id_mismatch';
        }
        if ((string) ($article['status'] ?? '') !== 'draft') {
            $issues[] = 'article_not_draft';
        }
        if ((bool) ($article['is_public'] ?? false)) {
            $issues[] = 'article_already_public';
        }
        if ((bool) ($article['is_indexable'] ?? false)) {
            $issues[] = 'article_already_indexable';
        }
        if (($article['published_revision_id'] ?? null) !== null) {
            $issues[] = 'article_already_has_published_revision';
        }
        if (is_array($revision)) {
            if ((int) ($revision['article_id'] ?? 0) !== (int) ($article['id'] ?? 0)) {
                $issues[] = 'revision_article_mismatch';
            }
            if ((int) ($article['working_revision_id'] ?? 0) !== (int) ($revision['id'] ?? 0)) {
                $issues[] = 'revision_not_current_working_revision';
            }
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $packageContext
     * @return array<string,mixed>
     */
    private function repairPlan(array $packageContext): array
    {
        $faqItems = $packageContext['faq_items'] ?? [];
        $tags = $this->stringList($packageContext['tag_suggestions'] ?? []);
        $primary = (string) data_get($packageContext, 'dynamic_cta.primary', '/zh/tests/holland-career-interest-test-riasec');
        $secondary = (string) data_get($packageContext, 'dynamic_cta.secondary', '/zh/tests/mbti-personality-test-16-personality-types');
        $links = array_values(array_filter((array) data_get($packageContext, 'internal_links.links', []), static fn (mixed $link): bool => is_array($link)));

        return [
            'references_json' => [
                'status' => 'complete',
                'count' => 3,
                'items' => [
                    [
                        'kind' => 'official_admission_rules',
                        'label' => '各省考试院 / 招生考试院 / 官方志愿填报系统',
                        'usage' => '志愿规则、批次、位次、招生计划和投档信息必须以官方渠道为准。',
                    ],
                    [
                        'kind' => 'school_admission_authority',
                        'label' => '学校招生章程、学院官网、教务处和培养方案',
                        'usage' => '选科、体检、单科限制、课程结构和转专业政策必须回到学校官方信息核验。',
                    ],
                    [
                        'kind' => 'fermatmind_assessment_context',
                        'label' => 'FermatMind RIASEC / MBTI public test routes',
                        'usage' => '仅用于兴趣、任务偏好和沟通偏好的自我探索参考，不预测录取或就业结果。',
                    ],
                ],
            ],
            'graph_json' => [
                'status' => 'complete',
                'target_topics' => ['gaokao_major_choice', 'riasec', 'family_decision'],
                'primary_cta' => $primary,
                'secondary_cta' => $secondary,
                'internal_links' => array_map(static fn (array $link): array => [
                    'href' => (string) ($link['href'] ?? ''),
                    'anchor' => (string) ($link['anchor'] ?? ''),
                    'purpose' => (string) ($link['purpose'] ?? ''),
                ], $links),
            ],
            'answer_surface_json' => [
                'status' => 'complete',
                'visible_faq_items' => count((array) $faqItems),
                'schema_enabled' => false,
                'faq_schema_eligible' => false,
            ],
            'tags' => $tags,
            'cta_slots' => [
                [
                    'slot_id' => 'gaokao_riasec_primary',
                    'label' => '先做霍兰德职业兴趣测试',
                    'href' => $primary,
                    'purpose' => '把兴趣结果转成专业验证问题',
                ],
                [
                    'slot_id' => 'gaokao_mbti_secondary',
                    'label' => '用 MBTI 补充理解学习和决策偏好',
                    'href' => $secondary,
                    'purpose' => '补充学习方式、沟通偏好和家庭讨论语境',
                ],
            ],
            'faq_items' => $faqItems,
        ];
    }

    /**
     * @param  array<string,mixed>  $repairPlan
     * @return array<string,mixed>
     */
    private function executeRepair(int $articleId, int $revisionId, int $reviewedBy, array $repairPlan): array
    {
        $now = now();
        $article = Article::query()->withoutGlobalScopes()->whereKey($articleId)->lockForUpdate()->firstOrFail();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($revisionId)->lockForUpdate()->firstOrFail();
        $import = ArticleEditorialPackageImport::query()->withoutGlobalScopes()->where('article_id', $articleId)->latest('id')->lockForUpdate()->firstOrFail();
        $seoMeta = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $articleId)->lockForUpdate()->firstOrFail();

        $import->forceFill([
            'references_json' => $repairPlan['references_json'],
            'references_count' => (int) data_get($repairPlan, 'references_json.count', 0),
            'graph_json' => $repairPlan['graph_json'],
            'answer_surface_json' => $repairPlan['answer_surface_json'],
            'missing_fields_json' => [],
            'blocked_reasons_json' => [],
        ])->save();

        $schema = is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
        $editorial = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];
        $editorial['source'] = self::COMMAND_NAME;
        $editorial['schema_hold'] = true;
        $editorial['hreflang_hold'] = true;
        $editorial['search_submission_allowed'] = false;
        $editorial['sitemap_hold'] = true;
        $editorial['llms_hold'] = true;
        $editorial['cta_slots'] = $repairPlan['cta_slots'];
        $editorial['answer_surface_v1'] = array_merge(
            is_array($editorial['answer_surface_v1'] ?? null) ? $editorial['answer_surface_v1'] : [],
            [
                'faq_items' => $repairPlan['faq_items'],
                'faq_schema_eligible' => false,
                'schema_enabled' => false,
                'source' => self::COMMAND_NAME,
            ]
        );
        $schema['editorial_package_v1'] = $editorial;
        $seoMeta->forceFill(['schema_json' => $schema])->save();

        $tagIds = $this->ensureTags((int) $article->org_id, (array) $repairPlan['tags']);
        $article->tags()->syncWithoutDetaching($this->tagSyncPayload($tagIds, (int) $article->org_id));

        $revision->forceFill([
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $now,
            'approved_at' => $now,
        ])->save();

        return [
            'updated_import_id' => (int) $import->id,
            'updated_seo_meta_id' => (int) $seoMeta->id,
            'updated_revision_id' => (int) $revision->id,
            'tag_ids' => $tagIds,
            'reviewed_by' => $reviewedBy,
        ];
    }

    /**
     * @param  list<string>  $tagNames
     * @return list<int>
     */
    private function ensureTags(int $orgId, array $tagNames): array
    {
        $ids = [];
        foreach ($tagNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
                ['org_id' => $orgId, 'name' => $name],
                ['slug' => Str::slug($name) ?: substr(sha1($name), 0, 12), 'is_active' => true],
            );
            $ids[] = (int) $tag->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $tagIds
     * @return array<int,array<string,mixed>>
     */
    private function tagSyncPayload(array $tagIds, int $orgId): array
    {
        $now = now();
        $payload = [];
        foreach ($tagIds as $tagId) {
            $payload[$tagId] = [
                'org_id' => $orgId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $authority
     * @param  array<string,mixed>  $repairPlan
     * @param  array<string,mixed>|null  $writeResult
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function summary(
        ?string $packageRoot,
        string $packageSha,
        string $expectedSha,
        int $articleId,
        int $revisionId,
        string $translationGroupId,
        string $expectedSlug,
        int $reviewedBy,
        bool $execute,
        array $packageContext,
        array $authority,
        array $repairPlan,
        ?array $writeResult,
        array $issues,
        string $requiredConfirmation,
    ): array {
        $ok = $issues === [];

        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan '.self::COMMAND_NAME,
            'ok' => $ok,
            'status' => $ok ? ($execute ? 'success' : 'planned') : 'blocked',
            'dry_run' => ! $execute,
            'execute' => $execute,
            'generated_at' => now()->utc()->toIso8601String(),
            'source_package' => [
                'path' => $packageRoot,
                'sha256' => $packageSha,
                'expected_sha256' => $expectedSha,
                'translation_group_id' => $translationGroupId,
                'expected_zh_slug' => $expectedSlug,
            ],
            'target' => [
                'article_id' => $articleId,
                'revision_id' => $revisionId,
                'locale' => 'zh-CN',
                'reviewed_by' => $reviewedBy > 0 ? $reviewedBy : null,
            ],
            'authority_sources' => [
                'article_authority' => 'backend.articles + article_translation_revisions + article_seo_meta',
                'import_gate_authority' => 'backend.article_editorial_package_imports latest row',
                'package_authority' => 'repaired generated Gaokao v5 SEO content package',
            ],
            'authority_snapshot' => $authority,
            'planned_metadata_repairs' => [
                'revision_editorial_approval' => true,
                'references_count' => (int) data_get($repairPlan, 'references_json.count', 0),
                'graph_status' => (string) data_get($repairPlan, 'graph_json.status', ''),
                'tags_count' => count((array) ($repairPlan['tags'] ?? [])),
                'cta_slots_count' => count((array) ($repairPlan['cta_slots'] ?? [])),
                'faq_items_count' => count((array) ($repairPlan['faq_items'] ?? [])),
            ],
            'repair_payload_summary' => [
                'tags' => $repairPlan['tags'] ?? [],
                'cta_slots' => $repairPlan['cta_slots'] ?? [],
                'faq_questions' => array_map(static fn (array $item): string => (string) ($item['question'] ?? ''), (array) ($repairPlan['faq_items'] ?? [])),
                'references' => $repairPlan['references_json'] ?? [],
            ],
            'write_result' => $writeResult,
            'required_confirmation_phrase' => $requiredConfirmation,
            'next_publish_dry_run_command_after_execute' => "php artisan articles:publish-controlled --article={$articleId} --dry-run --make-indexable --json",
            'package_context_counts' => [
                'faq_items' => count((array) ($packageContext['faq_items'] ?? [])),
                'tag_suggestions' => count((array) ($packageContext['tag_suggestions'] ?? [])),
            ],
            'issues' => array_values(array_unique($issues)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    private function confirmationPhrase(string $packageSha, int $articleId, int $revisionId, int $reviewedBy): string
    {
        return "I explicitly approve SEO-OPS-GAOKAO-V5-PUBLISH-GATE-REPAIR-01 to repair draft metadata for article {$articleId} revision {$revisionId} from package sha256 {$packageSha} reviewed_by {$reviewedBy}; no publish, no URL Truth, no sitemap/llms, no schema/hreflang enablement, no Search Channel, no IndexNow/Baidu/GSC, no deploy/revalidation.";
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'cms_publish' => false,
            'article_publication_state_change' => false,
            'article_indexable_change' => false,
            'url_truth_write' => false,
            'sitemap_llms_mutation' => false,
            'schema_hreflang_enablement' => false,
            'search_channel_enqueue' => false,
            'indexnow_live_submit' => false,
            'google_baidu_gsc_submit' => false,
            'scheduler_or_queue_worker_start' => false,
            'frontend_deploy_or_revalidation' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array{question:string,answer:string}>
     */
    private function normalizedFaqItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['q'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? $item['a'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $normalized[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $items
        ), static fn (string $item): bool => $item !== ''));
    }

    private function packageRoot(): ?string
    {
        $path = trim((string) $this->option('package'));

        return $path !== '' && is_dir($path) ? rtrim($path, '/') : null;
    }

    private function artifactDir(): ?string
    {
        $path = trim((string) $this->option('artifact-dir'));
        if ($path === '') {
            return null;
        }
        File::ensureDirectoryExists($path);

        return is_dir($path) && is_writable($path) ? rtrim($path, '/') : null;
    }

    private function packageSha256(string $packageRoot): string
    {
        $files = collect(File::allFiles($packageRoot))
            ->filter(static fn ($file): bool => $file->isFile())
            ->map(static fn ($file): string => $file->getPathname())
            ->sort()
            ->values();

        $hashInput = '';
        foreach ($files as $file) {
            $relative = ltrim(str_replace($packageRoot, '', $file), '/');
            $hashInput .= $relative."\0".hash_file('sha256', $file)."\n";
        }

        return hash('sha256', $hashInput);
    }

    /**
     * @param  list<string>  $issues
     * @return array<string,mixed>
     */
    private function failureSummary(array $issues): array
    {
        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'command' => 'php artisan '.self::COMMAND_NAME,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => ! (bool) $this->option('execute'),
            'execute' => (bool) $this->option('execute'),
            'generated_at' => now()->utc()->toIso8601String(),
            'issues' => $issues,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function writeEvidence(string $artifactDir, array $summary): array
    {
        $basename = 'seo-ops-gaokao-v5-publish-gate-repair-'.now()->utc()->format('Ymd\THis\Z').'.json';
        $path = $artifactDir.'/'.$basename;
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        File::put($path, $json === false ? '{}' : $json);

        return [
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
            $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
            $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
            $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
            $this->line('issues='.implode(',', (array) ($summary['issues'] ?? [])));
            $this->line('evidence='.(string) data_get($summary, 'evidence.path', ''));
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}

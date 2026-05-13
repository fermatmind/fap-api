<?php

declare(strict_types=1);

namespace App\Services\Cms\EditorialPackage;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EditorialPackageDraftImporter
{
    private const CONTENT_TRACKS = ['evergreen_knowledge', 'editorial_journal'];

    private const AUDIENCE_INTENTS = [
        'self_understanding',
        'career_decision',
        'relationship',
        'workstyle',
        'ai_and_personality',
        'mental_health_screening',
        'ability_assessment',
    ];

    private const SIGNAL_SOURCES = [
        'MBTI',
        'Big Five',
        'RIASEC',
        'Enneagram',
        'EQ',
        'IQ',
        'Depression',
        'Anxiety',
        'Career',
        'General',
    ];

    private const SIGNAL_TYPES = [
        'identity',
        'trait',
        'interest',
        'ability',
        'emotion',
        'relationship',
        'workstyle',
        'career_evidence',
    ];

    private const DECISION_DOMAINS = [
        'self',
        'career',
        'relationship',
        'workstyle',
        'learning',
        'emotional_state',
    ];

    private const CLAIM_LEVELS = [
        'descriptive',
        'evidence_supported',
        'exploratory',
        'sensitive',
        'prohibited_if_overstated',
    ];

    private const SENSITIVITY_LEVELS = [
        'normal',
        'career_sensitive',
        'health_sensitive',
        'ability_sensitive',
    ];

    private const REVIEWERS = ['editor', 'psychometrics', 'legal', 'founder'];

    private const FORBIDDEN_CLAIMS = [
        '最适合' => '值得探索',
        '精准匹配' => '可能相关',
        '预测职业成功' => '职业兴趣方向',
        '真实智商' => '在线估测',
        '权威诊断' => '自评筛查',
        '确诊' => '自评筛查',
        '治愈' => '支持性建议',
        '治疗建议' => '决策参考',
        'AI 比人更懂你' => 'AI 可作为辅助镜子',
        '100% 判断' => '决策参考',
        '一定适合' => '可能适合探索',
        '职业成功率' => '工作结构线索',
        '录用概率' => '职业准备线索',
    ];

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $revisionWorkspace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function planFromFile(string $file, ?string $localeOverride = null, bool $allowClaimWarnings = false): array
    {
        $package = $this->readPackage($file);

        return $this->plan($package, $localeOverride, $allowClaimWarnings);
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public function plan(array $package, ?string $localeOverride = null, bool $allowClaimWarnings = false): array
    {
        $normalized = $this->normalize($package, $localeOverride);
        $validation = $this->validate($normalized, $allowClaimWarnings);
        $article = $this->existingArticle($normalized);
        if ($article instanceof Article && $this->isPublishedOrPublic($article)) {
            $validation['warnings'][] = $this->issue(
                'slug',
                'existing_published_article',
                'Existing published/public articles cannot be mutated by editorial package draft import.'
            );
        }
        $action = $this->actionFor($article, $validation);

        return [
            'ok' => $validation['errors'] === [],
            'action' => $action,
            'package' => $normalized,
            'body_hash' => $this->normalizedBodyHash((string) $normalized['body_markdown']),
            'answer_surface_hash' => $this->answerSurfaceHash($normalized['answer_surface_v1']),
            'first_500_chars' => mb_substr($this->normalizeText((string) $normalized['body_markdown']), 0, 500),
            'heading_sequence' => $this->headingSequence((string) $normalized['body_markdown']),
            'references_count' => count($normalized['references']),
            'claim_matches' => $validation['claim_matches'],
            'warnings' => $validation['warnings'],
            'errors' => $validation['errors'],
            'existing_article_id' => $article instanceof Article ? (int) $article->id : null,
            'would_write' => $validation['errors'] === [] && in_array($action, ['will_create', 'will_update'], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function importFromFile(string $file, ?string $localeOverride = null, bool $allowClaimWarnings = false): array
    {
        $plan = $this->planFromFile($file, $localeOverride, $allowClaimWarnings);
        if (($plan['errors'] ?? []) !== []) {
            return $plan;
        }

        if (! in_array($plan['action'], ['will_create', 'will_update'], true)) {
            return $plan;
        }

        $package = $plan['package'];

        return DB::transaction(function () use ($plan, $package): array {
            $category = $this->resolveCategory((string) $package['category']);
            $tags = $this->resolveTags($package['tags']);
            $article = $this->existingArticle($package);

            if ($article instanceof Article && $this->isPublishedOrPublic($article)) {
                throw new RuntimeException('Existing published/public articles cannot be mutated by editorial package draft import.');
            }

            if (! $article instanceof Article) {
                $article = Article::query()->withoutGlobalScopes()->create([
                    'org_id' => 0,
                    'category_id' => (int) $category->id,
                    'author_name' => (string) $package['author'],
                    'reviewer_name' => null,
                    'reading_minutes' => $this->readingMinutes((string) $package['body_markdown']),
                    'slug' => (string) $package['slug'],
                    'locale' => (string) $package['locale'],
                    'title' => (string) $package['title'],
                    'excerpt' => (string) $package['excerpt'],
                    'content_md' => (string) $package['body_markdown'],
                    'content_html' => null,
                    'cover_image_url' => (string) $package['cover_image'],
                    'cover_image_alt' => (string) $package['cover_image_alt'],
                    'cover_image_width' => null,
                    'cover_image_height' => null,
                    'cover_image_variants' => $this->editorialMetadata($package, $plan),
                    'status' => 'draft',
                    'is_public' => false,
                    'is_indexable' => (bool) $package['indexability'],
                    'published_at' => null,
                    'scheduled_at' => null,
                    'published_revision_id' => null,
                ]);
            } else {
                $article->forceFill([
                    'category_id' => (int) $category->id,
                    'author_name' => (string) $package['author'],
                    'reading_minutes' => $this->readingMinutes((string) $package['body_markdown']),
                    'slug' => (string) $package['slug'],
                    'locale' => (string) $package['locale'],
                    'title' => (string) $package['title'],
                    'excerpt' => (string) $package['excerpt'],
                    'content_md' => (string) $package['body_markdown'],
                    'content_html' => null,
                    'cover_image_url' => (string) $package['cover_image'],
                    'cover_image_alt' => (string) $package['cover_image_alt'],
                    'cover_image_variants' => $this->editorialMetadata($package, $plan),
                    'status' => 'draft',
                    'is_public' => false,
                    'is_indexable' => (bool) $package['indexability'],
                    'published_at' => null,
                    'scheduled_at' => null,
                    'published_revision_id' => null,
                ])->save();
            }

            $article->tags()->sync($this->tagSyncPayload($tags));

            $revisionStatus = $this->workingRevisionStatus((string) $package['intended_status'], $plan['warnings'] ?? []);
            $revision = $this->revisionWorkspace->saveWorkingRevision($article, [
                'title' => (string) $package['title'],
                'excerpt' => (string) $package['excerpt'],
                'content_md' => (string) $package['body_markdown'],
                'seo_title' => (string) $package['seo_title'],
                'seo_description' => (string) $package['meta_description'],
                'working_revision_status' => $revisionStatus,
            ]);

            ArticleSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'org_id' => 0,
                    'article_id' => (int) $article->id,
                    'locale' => (string) $package['locale'],
                ],
                [
                    'seo_title' => (string) $package['seo_title'],
                    'seo_description' => (string) $package['meta_description'],
                    'canonical_url' => $package['canonical'] ?: null,
                    'og_title' => (string) $package['seo_title'],
                    'og_description' => (string) $package['meta_description'],
                    'og_image_url' => (string) $package['cover_image'],
                    'robots' => (bool) $package['indexability'] ? 'index,follow' : 'noindex,nofollow',
                    'schema_json' => [
                        'editorial_package_v1' => $this->editorialMetadata($package, $plan)['editorial_package_v1'],
                    ],
                    'is_indexable' => (bool) $package['indexability'],
                ],
            );

            return array_merge($plan, [
                'imported' => true,
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $revision->id,
                'working_revision_status' => (string) $revision->revision_status,
                'published_revision_id' => null,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackage(string $file): array
    {
        $path = trim($file);
        if ($path === '') {
            throw new RuntimeException('--file is required.');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            throw new RuntimeException('Editorial package file not found: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Editorial package file must be valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function normalize(array $package, ?string $localeOverride): array
    {
        $body = (string) ($package['body_markdown'] ?? '');
        $answerSurface = is_array($package['answer_surface_v1'] ?? null) ? $package['answer_surface_v1'] : [];

        return [
            'package_version' => trim((string) ($package['package_version'] ?? 'editorial_package.v1')),
            'title' => trim((string) ($package['title'] ?? '')),
            'slug' => Str::slug(trim((string) ($package['slug'] ?? ''))),
            'locale' => trim((string) ($localeOverride ?: ($package['locale'] ?? 'en'))),
            'author' => trim((string) ($package['author'] ?? 'Fermat Institute')),
            'intended_status' => trim((string) ($package['intended_status'] ?? 'draft')),
            'body_markdown' => trim($body),
            'references' => $this->stringList($package['references'] ?? []),
            'seo_title' => trim((string) ($package['seo_title'] ?? '')),
            'meta_description' => trim((string) ($package['meta_description'] ?? '')),
            'excerpt' => trim((string) ($package['excerpt'] ?? '')),
            'canonical' => trim((string) ($package['canonical'] ?? '')),
            'indexability' => (bool) ($package['indexability'] ?? true),
            'content_track' => trim((string) ($package['content_track'] ?? '')),
            'category' => trim((string) ($package['category'] ?? '')),
            'tags' => $this->stringList($package['tags'] ?? []),
            'topic_cluster' => trim((string) ($package['topic_cluster'] ?? '')),
            'content_series' => trim((string) ($package['content_series'] ?? '')),
            'audience_intent' => trim((string) ($package['audience_intent'] ?? '')),
            'commercial_priority' => trim((string) ($package['commercial_priority'] ?? 'low')),
            'signal_source' => trim((string) ($package['signal_source'] ?? 'General')),
            'signal_type' => trim((string) ($package['signal_type'] ?? 'identity')),
            'decision_domains' => $this->stringList($package['decision_domains'] ?? []),
            'target_tests' => $this->stringList($package['target_tests'] ?? []),
            'target_topics' => $this->stringList($package['target_topics'] ?? []),
            'target_personality_pages' => $this->stringList($package['target_personality_pages'] ?? []),
            'target_career_pages' => $this->stringList($package['target_career_pages'] ?? []),
            'target_reports' => $this->stringList($package['target_reports'] ?? []),
            'next_action' => trim((string) ($package['next_action'] ?? '')),
            'internal_links' => $this->stringList($package['internal_links'] ?? []),
            'graph_edges' => is_array($package['graph_edges'] ?? null) ? $package['graph_edges'] : [],
            'recommended_reverse_links' => is_array($package['recommended_reverse_links'] ?? null) ? $package['recommended_reverse_links'] : [],
            'cover_image' => trim((string) ($package['cover_image'] ?? '')),
            'cover_image_alt' => trim((string) ($package['cover_image_alt'] ?? '')),
            'cover_image_prompt' => trim((string) ($package['cover_image_prompt'] ?? '')),
            'cover_image_style_tag' => trim((string) ($package['cover_image_style_tag'] ?? '')),
            'answer_surface_policy' => trim((string) ($package['answer_surface_policy'] ?? 'none')),
            'answer_surface_v1' => $answerSurface,
            'answer_surface_visibility' => trim((string) ($package['answer_surface_visibility'] ?? 'disabled')),
            'cta_slots' => is_array($package['cta_slots'] ?? null) ? $package['cta_slots'] : [],
            'primary_cta' => trim((string) ($package['primary_cta'] ?? '')),
            'secondary_cta' => trim((string) ($package['secondary_cta'] ?? '')),
            'freemium_entry' => trim((string) ($package['freemium_entry'] ?? '')),
            'report_upsell_allowed' => (bool) ($package['report_upsell_allowed'] ?? false),
            'claim_boundary_notes' => $this->stringList($package['claim_boundary_notes'] ?? []),
            'claim_level' => trim((string) ($package['claim_level'] ?? 'descriptive')),
            'sensitivity_level' => trim((string) ($package['sensitivity_level'] ?? 'normal')),
            'medical_disclaimer_required' => (bool) ($package['medical_disclaimer_required'] ?? false),
            'ability_disclaimer_required' => (bool) ($package['ability_disclaimer_required'] ?? false),
            'external_references_required' => (bool) ($package['external_references_required'] ?? false),
            'review_required_by' => $this->stringList($package['review_required_by'] ?? ['editor']),
            'standalone_editorial' => (bool) ($package['standalone_editorial'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>,claim_matches:list<array<string,mixed>>}
     */
    private function validate(array $package, bool $allowClaimWarnings): array
    {
        $errors = [];
        $warnings = [];

        foreach (['title', 'slug', 'locale', 'body_markdown', 'seo_title', 'meta_description', 'excerpt', 'content_track', 'category'] as $field) {
            if ((string) ($package[$field] ?? '') === '') {
                $errors[] = $this->issue($field, 'missing_required_field', $field.' is required.');
            }
        }

        if (! in_array($package['intended_status'], ['draft', 'review_pending'], true)) {
            $errors[] = $this->issue('intended_status', 'published_not_allowed', 'Editorial package import cannot request published status.');
        }

        $this->enum($package, 'content_track', self::CONTENT_TRACKS, $errors);
        $this->enum($package, 'audience_intent', self::AUDIENCE_INTENTS, $errors);
        $this->enum($package, 'signal_source', self::SIGNAL_SOURCES, $errors);
        $this->enum($package, 'signal_type', self::SIGNAL_TYPES, $errors);
        $this->enum($package, 'claim_level', self::CLAIM_LEVELS, $errors);
        $this->enum($package, 'sensitivity_level', self::SENSITIVITY_LEVELS, $errors);

        if (! in_array($package['commercial_priority'], ['low', 'medium', 'high'], true)) {
            $errors[] = $this->issue('commercial_priority', 'invalid_enum', 'commercial_priority must be low, medium, or high.');
        }

        if ($package['decision_domains'] === []) {
            $errors[] = $this->issue('decision_domains', 'missing_decision_domains', 'decision_domains is required.');
        }
        foreach ($package['decision_domains'] as $domain) {
            if (! in_array($domain, self::DECISION_DOMAINS, true)) {
                $errors[] = $this->issue('decision_domains', 'invalid_decision_domain', 'Invalid decision domain: '.$domain);
            }
        }
        foreach ($package['review_required_by'] as $reviewer) {
            if (! in_array($reviewer, self::REVIEWERS, true)) {
                $errors[] = $this->issue('review_required_by', 'invalid_reviewer', 'Invalid reviewer: '.$reviewer);
            }
        }

        if (! (bool) $package['standalone_editorial'] && $package['target_tests'] === [] && $package['target_topics'] === []) {
            $errors[] = $this->issue('target_tests', 'missing_graph_target', 'target_tests or target_topics is required unless standalone_editorial is true.');
        }

        if ((string) $package['cover_image'] === '') {
            $errors[] = $this->issue('cover_image', 'missing_cover_image', 'cover_image or explicit placeholder is required.');
        }
        foreach (['cover_image_alt', 'cover_image_prompt', 'cover_image_style_tag'] as $field) {
            if ((string) ($package[$field] ?? '') === '') {
                $errors[] = $this->issue($field, 'missing_cover_metadata', $field.' is required.');
            }
        }

        if ((bool) $package['external_references_required'] && $package['references'] === []) {
            $errors[] = $this->issue('references', 'references_required', 'external references are required for this package.');
        }

        if ($package['sensitivity_level'] === 'health_sensitive' && ! (bool) $package['medical_disclaimer_required']) {
            $errors[] = $this->issue('medical_disclaimer_required', 'medical_disclaimer_required', 'health_sensitive content requires a medical disclaimer.');
        }
        if ($package['sensitivity_level'] === 'ability_sensitive' && ! (bool) $package['ability_disclaimer_required']) {
            $errors[] = $this->issue('ability_disclaimer_required', 'ability_disclaimer_required', 'ability_sensitive content requires an ability disclaimer.');
        }

        $this->trackSpecificValidation($package, $errors);
        $this->answerSurfaceBoundaryValidation($package, $errors);

        $claimMatches = $this->claimMatches($package);
        foreach ($claimMatches as $match) {
            if ($allowClaimWarnings) {
                $warnings[] = $match;
            } else {
                $errors[] = $match;
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'claim_matches' => $claimMatches,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function trackSpecificValidation(array $package, array &$errors): void
    {
        $body = (string) $package['body_markdown'];
        $headings = $this->headingSequence($body);
        $headingText = mb_strtolower(implode("\n", $headings));

        if ($package['content_track'] === 'evergreen_knowledge') {
            if (! str_contains($headingText, 'definition') && ! str_contains($headingText, '定义') && ! str_contains($headingText, '是什么')) {
                $errors[] = $this->issue('body_markdown', 'evergreen_definition_required', 'evergreen_knowledge requires a definition section.');
            }
            if (! str_contains($headingText, 'method') && ! str_contains($headingText, 'theory') && ! str_contains($headingText, '方法') && ! str_contains($headingText, '理论')) {
                $errors[] = $this->issue('body_markdown', 'evergreen_method_required', 'evergreen_knowledge requires method or theory explanation.');
            }
            if (! str_contains($headingText, 'faq') && ! str_contains($headingText, '常见问题') && ! str_contains($headingText, 'key questions')) {
                $errors[] = $this->issue('body_markdown', 'evergreen_faq_required', 'evergreen_knowledge requires FAQ or key questions.');
            }
            if ($package['target_tests'] === []) {
                $errors[] = $this->issue('target_tests', 'evergreen_test_cta_required', 'evergreen_knowledge requires at least one test CTA target.');
            }
            if ($package['target_topics'] === []) {
                $errors[] = $this->issue('target_topics', 'evergreen_topic_link_required', 'evergreen_knowledge requires at least one Topic link.');
            }
            if ($package['references'] === []) {
                $errors[] = $this->issue('references', 'evergreen_references_required', 'evergreen_knowledge requires references or method sources.');
            }
        }

        if ($package['content_track'] === 'editorial_journal') {
            if (! str_contains($headingText, 'executive summary') && ! str_contains($headingText, '执行摘要')) {
                $errors[] = $this->issue('body_markdown', 'editorial_summary_required', 'editorial_journal requires an executive summary.');
            }
            if (! str_contains($body, 'Evidence Note')) {
                $errors[] = $this->issue('body_markdown', 'editorial_evidence_note_required', 'editorial_journal requires an Evidence Note.');
            }
            if ($package['claim_boundary_notes'] === []) {
                $errors[] = $this->issue('claim_boundary_notes', 'claim_boundary_required', 'editorial_journal requires claim boundary notes.');
            }
            if ($package['references'] === []) {
                $errors[] = $this->issue('references', 'editorial_references_required', 'editorial_journal requires references for academic claims.');
            }
            if ($package['target_tests'] === [] && (string) $package['primary_cta'] === '' && $package['cta_slots'] === []) {
                $errors[] = $this->issue('cta_slots', 'editorial_cta_required', 'editorial_journal requires a related test CTA.');
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function answerSurfaceBoundaryValidation(array $package, array &$errors): void
    {
        $policy = (string) $package['answer_surface_policy'];
        if (! in_array($policy, ['none', 'editor_supplied', 'generate_later'], true)) {
            $errors[] = $this->issue('answer_surface_policy', 'invalid_answer_surface_policy', 'Invalid answer_surface_policy.');
        }
        if (! in_array($package['answer_surface_visibility'], ['above_body', 'below_intro', 'below_body', 'disabled'], true)) {
            $errors[] = $this->issue('answer_surface_visibility', 'invalid_answer_surface_visibility', 'Invalid answer_surface_visibility.');
        }

        $body = $this->normalizeText((string) $package['body_markdown']);
        $quickAnswer = trim((string) data_get($package, 'answer_surface_v1.quick_answer', ''));
        if ($quickAnswer !== '' && str_contains($body, $this->normalizeText($quickAnswer))) {
            $errors[] = $this->issue('answer_surface_v1.quick_answer', 'answer_surface_merged_into_body', 'answer_surface_v1.quick_answer must not be merged into body_markdown.');
        }

        if ($policy !== 'editor_supplied' && preg_match('/^#+\s*(快速答案|Quick Answer)/mi', (string) $package['body_markdown']) === 1) {
            $errors[] = $this->issue('body_markdown', 'quick_answer_heading_without_editor_policy', 'Quick answer blocks in body require answer_surface_policy=editor_supplied.');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function claimMatches(array $package): array
    {
        $fields = [
            'title' => (string) $package['title'],
            'seo_title' => (string) $package['seo_title'],
            'meta_description' => (string) $package['meta_description'],
            'excerpt' => (string) $package['excerpt'],
            'body_markdown' => (string) $package['body_markdown'],
        ];

        $matches = [];
        foreach ($fields as $field => $text) {
            foreach (self::FORBIDDEN_CLAIMS as $phrase => $replacement) {
                $position = mb_strpos($text, $phrase);
                if ($position === false) {
                    continue;
                }

                $matches[] = [
                    'field' => $field,
                    'code' => 'claim_boundary_forbidden_phrase',
                    'message' => 'Forbidden claim phrase found: '.$phrase,
                    'phrase' => $phrase,
                    'suggested_replacement' => $replacement,
                    'snippet' => mb_substr($text, max(0, $position - 24), mb_strlen($phrase) + 48),
                ];
            }
        }

        return $matches;
    }

    private function existingArticle(array $package): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', (string) $package['locale'])
            ->where('slug', (string) $package['slug'])
            ->first();
    }

    private function actionFor(?Article $article, array $validation): string
    {
        if (($validation['errors'] ?? []) !== []) {
            return 'will_skip';
        }

        if (! $article instanceof Article) {
            return 'will_create';
        }

        if ($this->isPublishedOrPublic($article)) {
            return 'will_skip';
        }

        return 'will_update';
    }

    private function isPublishedOrPublic(Article $article): bool
    {
        return (string) $article->status === 'published'
            || (bool) $article->is_public
            || $article->published_revision_id !== null;
    }

    private function resolveCategory(string $name): ArticleCategory
    {
        return ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'name' => $name],
            ['slug' => Str::slug($name) ?: substr(sha1($name), 0, 12)],
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<ArticleTag>
     */
    private function resolveTags(array $names): array
    {
        $tags = [];
        foreach ($names as $name) {
            $tags[] = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
                ['org_id' => 0, 'name' => $name],
                ['slug' => Str::slug($name) ?: substr(sha1($name), 0, 12)],
            );
        }

        return $tags;
    }

    /**
     * @param  list<ArticleTag>  $tags
     * @return array<int, array<string, mixed>>
     */
    private function tagSyncPayload(array $tags): array
    {
        $now = now();
        $payload = [];
        foreach ($tags as $tag) {
            $payload[(int) $tag->id] = [
                'org_id' => 0,
                'created_at' => $now,
            ];
        }

        return $payload;
    }

    private function workingRevisionStatus(string $intendedStatus, array $warnings = []): string
    {
        if ($warnings !== []) {
            return ArticleTranslationRevision::STATUS_MACHINE_DRAFT;
        }

        return $intendedStatus === 'review_pending'
            ? ArticleTranslationRevision::STATUS_HUMAN_REVIEW
            : ArticleTranslationRevision::STATUS_MACHINE_DRAFT;
    }

    /**
     * @return array<string, mixed>
     */
    private function editorialMetadata(array $package, array $plan): array
    {
        return [
            'hero' => (string) $package['cover_image'],
            'editorial_package_v1' => [
                'package_version' => $package['package_version'],
                'content_track' => $package['content_track'],
                'topic_cluster' => $package['topic_cluster'],
                'content_series' => $package['content_series'],
                'audience_intent' => $package['audience_intent'],
                'commercial_priority' => $package['commercial_priority'],
                'signal_source' => $package['signal_source'],
                'signal_type' => $package['signal_type'],
                'decision_domains' => $package['decision_domains'],
                'target_tests' => $package['target_tests'],
                'target_topics' => $package['target_topics'],
                'target_personality_pages' => $package['target_personality_pages'],
                'target_career_pages' => $package['target_career_pages'],
                'target_reports' => $package['target_reports'],
                'next_action' => $package['next_action'],
                'internal_links' => $package['internal_links'],
                'graph_edges' => $package['graph_edges'],
                'recommended_reverse_links' => $package['recommended_reverse_links'],
                'cover_image_prompt' => $package['cover_image_prompt'],
                'cover_image_style_tag' => $package['cover_image_style_tag'],
                'answer_surface_policy' => $package['answer_surface_policy'],
                'answer_surface_v1' => $package['answer_surface_v1'],
                'answer_surface_visibility' => $package['answer_surface_visibility'],
                'cta_slots' => $package['cta_slots'],
                'primary_cta' => $package['primary_cta'],
                'secondary_cta' => $package['secondary_cta'],
                'freemium_entry' => $package['freemium_entry'],
                'report_upsell_allowed' => $package['report_upsell_allowed'],
                'claim_boundary_notes' => $package['claim_boundary_notes'],
                'claim_level' => $package['claim_level'],
                'sensitivity_level' => $package['sensitivity_level'],
                'medical_disclaimer_required' => $package['medical_disclaimer_required'],
                'ability_disclaimer_required' => $package['ability_disclaimer_required'],
                'external_references_required' => $package['external_references_required'],
                'review_required_by' => $package['review_required_by'],
                'references' => $package['references'],
                'validation' => [
                    'body_hash' => $plan['body_hash'] ?? '',
                    'answer_surface_hash' => $plan['answer_surface_hash'] ?? '',
                    'heading_sequence' => $plan['heading_sequence'] ?? [],
                    'references_count' => $plan['references_count'] ?? 0,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = trim((string) $item);
            if ($normalized !== '') {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
    }

    private function enum(array $package, string $field, array $allowed, array &$errors): void
    {
        if (! in_array($package[$field] ?? null, $allowed, true)) {
            $errors[] = $this->issue($field, 'invalid_enum', $field.' is invalid.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function normalizedBodyHash(string $body): string
    {
        return hash('sha256', $this->normalizeText($body));
    }

    private function answerSurfaceHash(mixed $answerSurface): string
    {
        return hash('sha256', json_encode($answerSurface ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    private function headingSequence(string $body): array
    {
        preg_match_all('/^(#{1,6})\s+(.+)$/m', $body, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): string => strlen((string) $match[1]).':'.trim((string) $match[2]),
            $matches,
        );
    }

    private function readingMinutes(string $body): int
    {
        $length = max(1, mb_strlen(strip_tags($body)));

        return max(1, (int) ceil($length / 600));
    }
}

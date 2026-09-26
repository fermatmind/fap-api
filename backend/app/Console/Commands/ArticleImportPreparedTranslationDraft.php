<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleImportPreparedTranslationDraft extends Command
{
    protected $signature = 'articles:import-prepared-translation-draft
        {--file= : Local JSON package containing one complete English draft and exact source locks}
        {--sha256= : Exact SHA-256 of package bytes}
        {--dry-run : Read and validate only}
        {--execute : Create one private working draft}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Import one operator-supplied English article draft with exact source locks; never approve or publish it.';

    public function handle(AuditLogger $auditLogger): int
    {
        $errors = [];
        $execute = (bool) $this->option('execute');
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }

        try {
            $package = $this->readPackage();
            if ($execute && ! hash_equals($this->confirmation($package), trim((string) $this->option('confirm')))) {
                $errors[] = 'confirmation_mismatch';
            }
            $before = $this->snapshot($package, false);
            $errors = array_values(array_unique([...$errors, ...$before['errors']]));
        } catch (Throwable) {
            $package = [];
            $before = ['errors' => ['package_or_snapshot_invalid']];
            $errors[] = 'package_or_snapshot_invalid';
        }

        $after = null;
        if ($execute && $errors === []) {
            try {
                $after = DB::transaction(function () use ($package, $auditLogger): array {
                    $locked = $this->snapshot($package, true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException(implode(',', $locked['errors']));
                    }
                    /** @var Article $source */
                    $source = $locked['source'];
                    $copy = $package['translation'];
                    $sourceHash = (string) $source->source_version_hash;
                    $target = Article::query()->create([
                        'org_id' => 0,
                        'slug' => (string) $source->slug,
                        'locale' => 'en',
                        'translation_group_id' => (string) $source->translation_group_id,
                        'source_locale' => 'zh-CN',
                        'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
                        'source_article_id' => (int) $source->id,
                        'translated_from_article_id' => (int) $source->id,
                        'translated_from_version_hash' => $sourceHash,
                        'title' => $copy['title'],
                        'excerpt' => $copy['excerpt'],
                        'content_md' => $copy['content_md'],
                        'status' => 'draft',
                        'is_public' => false,
                        'is_indexable' => false,
                        'sitemap_eligible' => false,
                        'llms_eligible' => false,
                        'published_at' => null,
                    ]);
                    // Article::saving computes a row-content hash. Translation freshness uses
                    // the exact source row hash, as in ArticleTranslationWorkflowService.
                    DB::table('articles')->where('id', (int) $target->id)->update([
                        'source_version_hash' => $sourceHash,
                    ]);
                    $revision = ArticleTranslationRevision::query()->create([
                        'org_id' => 0,
                        'article_id' => (int) $target->id,
                        'source_article_id' => (int) $source->id,
                        'translation_group_id' => (string) $source->translation_group_id,
                        'locale' => 'en',
                        'source_locale' => 'zh-CN',
                        'revision_number' => 1,
                        'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                        'source_version_hash' => $sourceHash,
                        'translated_from_version_hash' => $sourceHash,
                        'title' => $copy['title'],
                        'excerpt' => $copy['excerpt'],
                        'content_md' => $copy['content_md'],
                        'seo_title' => $copy['seo_title'],
                        'seo_description' => $copy['seo_description'],
                        'authority_metadata_json' => [
                            'draft_origin' => 'operator_supplied_ai_draft',
                            'package_sha256' => (string) $this->option('sha256'),
                            'source_content_sha256' => $package['source']['content_sha256'],
                            'editorial_review_state' => 'pending',
                        ],
                    ]);
                    $target->forceFill(['working_revision_id' => (int) $revision->id])->saveQuietly();
                    ArticleSeoMeta::query()->create([
                        'org_id' => 0,
                        'article_id' => (int) $target->id,
                        'locale' => 'en',
                        'seo_title' => $copy['seo_title'],
                        'seo_description' => $copy['seo_description'],
                        'is_indexable' => false,
                    ]);

                    $readback = $target->fresh(['workingRevision', 'seoMeta']);
                    if (! $readback instanceof Article
                        || (int) $readback->source_article_id !== (int) $source->id
                        || (int) $readback->working_revision_id !== (int) $revision->id
                        || $readback->published_revision_id !== null
                        || (string) $readback->status !== 'draft'
                        || (bool) $readback->is_public || (bool) $readback->is_indexable
                        || (bool) $readback->sitemap_eligible || (bool) $readback->llms_eligible
                        || ! hash_equals($sourceHash, (string) $readback->translated_from_version_hash)
                        || ! hash_equals(hash('sha256', $copy['content_md']), hash('sha256', (string) $readback->workingRevision?->content_md))
                        || (string) $readback->workingRevision?->revision_status !== ArticleTranslationRevision::STATUS_MACHINE_DRAFT
                        || (bool) $readback->seoMeta?->is_indexable) {
                        throw new RuntimeException('draft_readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/article-translation/import-prepared-draft', 'POST'),
                        'article_translation_prepared_draft_imported',
                        'article_translation',
                        (string) $target->id,
                        [
                            'source_article_id' => (int) $source->id,
                            'target_article_id' => (int) $target->id,
                            'working_revision_id' => (int) $revision->id,
                            'translation_group_id' => (string) $source->translation_group_id,
                            'source_version_hash' => $sourceHash,
                            'source_content_sha256' => $package['source']['content_sha256'],
                            'package_sha256' => (string) $this->option('sha256'),
                            'draft_origin' => 'operator_supplied_ai_draft',
                            'public_state_changed' => false,
                            'editorial_review_state' => 'pending',
                        ],
                        reason: 'controlled_private_article_translation_draft_import',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'article_translation_prepared_draft_imported')
                        ->where('target_type', 'article_translation')->where('target_id', (string) $target->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return [
                        'target_article_id' => (int) $target->id,
                        'working_revision_id' => (int) $revision->id,
                        'audit_id' => (int) $audit->id,
                        'published_revision_id' => null,
                    ];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $result = [
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'source_article_id' => (int) ($package['source']['article_id'] ?? 0),
            'translation_group_id' => (string) ($package['source']['translation_group_id'] ?? ''),
            'package_sha256' => (string) $this->option('sha256'),
            'before' => $this->publicSnapshot($before),
            'after' => $after,
            'errors' => array_values(array_unique($errors)),
        ];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readPackage(): array
    {
        $path = trim((string) $this->option('file'));
        $expected = strtolower(trim((string) $this->option('sha256')));
        if ($path === '' || ! preg_match('/^[0-9a-f]{64}$/', $expected)
            || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('invalid_package_path_or_hash');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals($expected, hash('sha256', $bytes))) {
            throw new RuntimeException('package_sha256_mismatch');
        }
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($package) || array_keys($package) !== ['schema', 'source', 'translation']
            || $package['schema'] !== 'fermat_article_translation_draft_v1'
            || ! is_array($package['source']) || ! is_array($package['translation'])
            || array_keys($package['source']) !== [
                'article_id', 'translation_group_id', 'slug', 'locale', 'source_version_hash',
                'content_sha256', 'published_revision_id', 'published_revision_content_sha256',
                'published_revision_updated_at', 'updated_at',
            ] || array_keys($package['translation']) !== [
                'locale', 'title', 'excerpt', 'content_md', 'seo_title', 'seo_description',
            ]) {
            throw new RuntimeException('package_schema_invalid');
        }
        $source = $package['source'];
        $copy = $package['translation'];
        if (! is_int($source['article_id']) || $source['article_id'] <= 0
            || ! is_int($source['published_revision_id']) || $source['published_revision_id'] <= 0
            || $source['locale'] !== 'zh-CN' || $copy['locale'] !== 'en'
            || ! preg_match('/^[0-9a-f]{64}$/', (string) $source['source_version_hash'])
            || ! preg_match('/^[0-9a-f]{64}$/', (string) $source['content_sha256'])
            || ! preg_match('/^[0-9a-f]{64}$/', (string) $source['published_revision_content_sha256'])
            || ! is_string($source['translation_group_id']) || trim($source['translation_group_id']) === ''
            || ! is_string($source['slug']) || trim($source['slug']) === ''
            || ! is_string($source['updated_at']) || trim($source['updated_at']) === ''
            || ! is_string($source['published_revision_updated_at']) || trim($source['published_revision_updated_at']) === '') {
            throw new RuntimeException('source_lock_invalid');
        }
        foreach (['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'] as $field) {
            if (! is_string($copy[$field]) || trim($copy[$field]) === '' || preg_match('/\p{Han}/u', $copy[$field])) {
                throw new RuntimeException('translation_copy_invalid');
            }
        }

        return $package;
    }

    /** @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    private function snapshot(array $package, bool $lock): array
    {
        $sourceLock = $package['source'];
        $query = Article::query()->withoutGlobalScopes()->whereKey($sourceLock['article_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->first();
        if (! $source instanceof Article) {
            return ['errors' => ['source_missing']];
        }
        $errors = [];
        if ((int) $source->org_id !== 0 || ! $source->isSourceArticle()
            || (string) $source->locale !== 'zh-CN'
            || (string) $source->status !== 'published' || ! (bool) $source->is_public
            || (int) $source->working_revision_id !== (int) $source->published_revision_id) {
            $errors[] = 'source_identity_or_state_mismatch';
        }
        if ((string) $source->translation_group_id !== $sourceLock['translation_group_id']
            || (string) $source->slug !== $sourceLock['slug']
            || ! hash_equals($sourceLock['source_version_hash'], (string) $source->source_version_hash)
            || ! hash_equals((string) $source->source_version_hash, $source->computeSourceVersionHash())
            || ! hash_equals($sourceLock['content_sha256'], hash('sha256', (string) $source->content_md))
            || (int) $source->published_revision_id !== $sourceLock['published_revision_id']
            || (string) $source->getRawOriginal('updated_at') !== $sourceLock['updated_at']) {
            $errors[] = 'source_lock_mismatch';
        }
        $published = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereKey($sourceLock['published_revision_id'])->first();
        if (! $published instanceof ArticleTranslationRevision
            || (int) $published->article_id !== (int) $source->id
            || (int) $published->source_article_id !== (int) $source->id
            || (string) $published->locale !== 'zh-CN'
            || ! in_array((string) $published->revision_status, [
                ArticleTranslationRevision::STATUS_SOURCE,
                ArticleTranslationRevision::STATUS_PUBLISHED,
            ], true)
            || (string) $published->title !== (string) $source->title
            || (string) $published->excerpt !== (string) $source->excerpt
            || ! hash_equals($sourceLock['published_revision_content_sha256'], hash('sha256', (string) $published->content_md))
            || ! hash_equals(hash('sha256', (string) $source->content_md), hash('sha256', (string) $published->content_md))
            || (string) $published->getRawOriginal('updated_at') !== $sourceLock['published_revision_updated_at']) {
            $errors[] = 'source_revision_mismatch';
        }
        $collisions = Article::query()->withoutGlobalScopes()->withTrashed()
            ->where('org_id', 0)->where('locale', 'en')
            ->where(function ($query) use ($source): void {
                $query->where('translation_group_id', (string) $source->translation_group_id)
                    ->orWhere('slug', (string) $source->slug);
            });
        if ($lock) {
            $collisions->lockForUpdate();
        }
        if ($collisions->exists()) {
            $errors[] = 'english_identity_collision';
        }

        return [
            'source' => $source,
            'errors' => $errors,
            'source_version_hash' => (string) $source->source_version_hash,
            'published_revision_id' => (int) $source->published_revision_id,
            'english_identity_collision' => in_array('english_identity_collision', $errors, true),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'source_version_hash' => $snapshot['source_version_hash'] ?? null,
            'published_revision_id' => $snapshot['published_revision_id'] ?? null,
            'english_identity_collision' => $snapshot['english_identity_collision'] ?? null,
        ];
    }

    /** @param array<string, mixed> $package */
    private function confirmation(array $package): string
    {
        return sprintf(
            'Import private English draft for source %d in group %s with package %s.',
            $package['source']['article_id'],
            $package['source']['translation_group_id'],
            (string) $this->option('sha256'),
        );
    }
}

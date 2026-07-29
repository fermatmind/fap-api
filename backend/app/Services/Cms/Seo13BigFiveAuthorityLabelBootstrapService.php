<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Seo13BigFiveAuthorityLabelBootstrapService
{
    public const TARGET_COUNT = 2;

    public const ARTICLE_METADATA_WRITE_COUNT = 2;

    /**
     * @var list<array{
     *   article_id:int,
     *   slug:string,
     *   translation_group_id:string,
     *   published_revision_id:int,
     *   author_name:string,
     *   reviewer_name:string
     * }>
     */
    private const TARGETS = [
        [
            'article_id' => 1,
            'slug' => 'big-five-growth-guide',
            'translation_group_id' => 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61',
            'published_revision_id' => 446,
            'author_name' => 'FermatMind Editorial',
            'reviewer_name' => 'Content Review Desk',
        ],
        [
            'article_id' => 2,
            'slug' => 'big-five-narrative-portrait',
            'translation_group_id' => 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970',
            'published_revision_id' => 445,
            'author_name' => 'FermatMind Editorial',
            'reviewer_name' => 'Content Review Desk',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        return $this->snapshot(lockForUpdate: false);
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(string $expectedStateSha256): array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $expectedStateSha256) !== 1) {
            throw new RuntimeException('expected_state_sha256_invalid');
        }

        return DB::transaction(function () use ($expectedStateSha256): array {
            $before = $this->snapshot(lockForUpdate: true);
            if (($before['ok'] ?? false) !== true
                || ($before['repair_required'] ?? false) !== true
                || ($before['apply_supported'] ?? false) !== true) {
                throw new RuntimeException('bootstrap_preflight_rejected');
            }
            if (! hash_equals($expectedStateSha256, (string) ($before['state_sha256'] ?? ''))) {
                throw new RuntimeException('bootstrap_state_drift');
            }

            $metadataWrites = 0;
            foreach (self::TARGETS as $target) {
                $article = Article::query()
                    ->withoutGlobalScopes()
                    ->whereKey($target['article_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $article->forceFill([
                    'author_name' => $target['author_name'],
                    'reviewer_name' => $target['reviewer_name'],
                ])->saveQuietly();
                $metadataWrites++;
            }

            $orgIds = collect((array) $before['rows'])->pluck('org_id')->unique()->values();
            if ($orgIds->count() !== 1 || $metadataWrites !== self::ARTICLE_METADATA_WRITE_COUNT) {
                throw new RuntimeException('bootstrap_write_count_mismatch');
            }

            AuditLog::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $orgIds->first(),
                'actor_admin_id' => null,
                'action' => 'seo13_big_five_authority_label_bootstrap',
                'target_type' => 'article_batch',
                'target_id' => 'seo13-big-five-authority-labels-2',
                'meta_json' => [
                    'target_count' => self::TARGET_COUNT,
                    'target_set_sha256' => (string) $before['target_set_sha256'],
                    'preflight_state_sha256' => (string) $before['state_sha256'],
                    'article_metadata_write_count' => $metadataWrites,
                    'article_body_write_count' => 0,
                    'revision_write_count' => 0,
                    'publication_write_count' => 0,
                    'indexability_write_count' => 0,
                    'schema_write_count' => 0,
                    'hreflang_write_count' => 0,
                    'revalidation_count' => 0,
                    'sitemap_eligibility_write_count' => 0,
                    'llms_eligibility_write_count' => 0,
                    'search_submission_count' => 0,
                ],
                'ip' => null,
                'user_agent' => 'seo13-big-five-authority-label-bootstrap',
                'request_id' => '',
                'reason' => 'seo13_schema_release_big_five_authority_prerequisite',
                'result' => 'success',
                'created_at' => now(),
            ]);

            $after = $this->snapshot(lockForUpdate: true);
            if (($after['ok'] ?? false) !== true
                || ($after['repair_required'] ?? true) !== false
                || ($after['readback_complete'] ?? false) !== true) {
                throw new RuntimeException('bootstrap_readback_failed');
            }

            return [
                'before' => $before,
                'after' => $after,
                'writes' => [
                    'article_metadata_write_count' => $metadataWrites,
                    'audit_write_count' => 1,
                ],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(bool $lockForUpdate): array
    {
        $rows = [];
        $errors = [];
        $missingCount = 0;
        $completeCount = 0;

        foreach (self::TARGETS as $target) {
            $query = Article::query()->withoutGlobalScopes()->whereKey($target['article_id']);
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $article = $query->first();
            if (! $article instanceof Article) {
                $errors[] = $this->issue($target['article_id'], 'article_not_found');

                continue;
            }

            $revision = $this->revision($target, $lockForUpdate);
            foreach ($this->identityErrors($article, $revision, $target) as $code) {
                $errors[] = $this->issue($target['article_id'], $code);
            }

            $author = trim((string) ($article->author_name ?? ''));
            $reviewer = trim((string) ($article->reviewer_name ?? ''));
            $labelState = match (true) {
                $author === '' && $reviewer === '' => 'missing',
                $author === $target['author_name'] && $reviewer === $target['reviewer_name'] => 'complete',
                default => 'partial_or_drifted',
            };
            if ($labelState === 'missing') {
                $missingCount++;
            } elseif ($labelState === 'complete') {
                $completeCount++;
            } else {
                $errors[] = $this->issue($target['article_id'], 'big_five_authority_label_partial_or_drifted');
            }

            $rows[] = [
                'article_id' => (int) $article->id,
                'org_id' => (int) $article->org_id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
                'published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'label_state' => $labelState,
                'author_name_sha256' => $this->textHash($author),
                'reviewer_name_sha256' => $this->textHash($reviewer),
                'desired_author_name_sha256' => $this->textHash($target['author_name']),
                'desired_reviewer_name_sha256' => $this->textHash($target['reviewer_name']),
                'title_sha256' => $this->textHash((string) $article->title),
                'body_sha256' => $this->textHash((string) $article->content_md),
                'revision_status' => $revision instanceof ArticleTranslationRevision ? (string) $revision->revision_status : null,
                'revision_title_sha256' => $this->textHash($revision instanceof ArticleTranslationRevision ? (string) $revision->title : ''),
                'revision_excerpt_sha256' => $this->textHash($revision instanceof ArticleTranslationRevision ? (string) $revision->excerpt : ''),
                'revision_body_sha256' => $this->textHash($revision instanceof ArticleTranslationRevision ? (string) $revision->content_md : ''),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['article_id'] <=> $right['article_id']);
        usort($errors, static fn (array $left, array $right): int => [$left['article_id'], $left['code']] <=> [$right['article_id'], $right['code']]);

        return [
            'ok' => $errors === []
                && count($rows) === self::TARGET_COUNT
                && (
                    $missingCount === self::TARGET_COUNT
                    || $completeCount === self::TARGET_COUNT
                ),
            'target_count' => self::TARGET_COUNT,
            'missing_count' => $missingCount,
            'complete_count' => $completeCount,
            'repair_required' => $missingCount === self::TARGET_COUNT,
            'apply_supported' => $errors === [] && $missingCount === self::TARGET_COUNT,
            'readback_complete' => $errors === [] && $completeCount === self::TARGET_COUNT,
            'target_set_sha256' => $this->deterministicHash(self::TARGETS),
            'state_sha256' => $this->deterministicHash($rows),
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     */
    private function revision(array $target, bool $lockForUpdate): ?ArticleTranslationRevision
    {
        $query = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($target['published_revision_id'])
            ->where('article_id', $target['article_id']);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param  array<string,mixed>  $target
     * @return list<string>
     */
    private function identityErrors(
        Article $article,
        ?ArticleTranslationRevision $revision,
        array $target,
    ): array {
        $errors = [];
        if ((string) $article->slug !== (string) $target['slug']
            || (string) $article->locale !== 'zh-CN'
            || (string) $article->translation_group_id !== (string) $target['translation_group_id']) {
            $errors[] = 'article_identity_mismatch';
        }
        if ((string) $article->status !== 'published'
            || ! (bool) $article->is_public
            || ! (bool) $article->is_indexable) {
            $errors[] = 'public_indexable_state_mismatch';
        }
        if ((int) ($article->published_revision_id ?? 0) !== (int) $target['published_revision_id']
            || ! $revision instanceof ArticleTranslationRevision
            || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = 'published_revision_lock_mismatch';
        } elseif ((int) $revision->article_id !== (int) $article->id
            || (int) $revision->org_id !== (int) $article->org_id
            || (string) $revision->locale !== 'zh-CN'
            || (string) $revision->translation_group_id !== (string) $article->translation_group_id
            || (string) $revision->title !== (string) $article->title
            || (string) $revision->excerpt !== (string) $article->excerpt
            || (string) $revision->content_md !== (string) $article->content_md) {
            $errors[] = 'published_revision_identity_mismatch';
        }

        return $errors;
    }

    /**
     * @return array{article_id:int,code:string}
     */
    private function issue(int $articleId, string $code): array
    {
        return ['article_id' => $articleId, 'code' => $code];
    }

    /**
     * @param  mixed  $value
     */
    private function deterministicHash($value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function textHash(string $value): string
    {
        return hash('sha256', $value);
    }
}

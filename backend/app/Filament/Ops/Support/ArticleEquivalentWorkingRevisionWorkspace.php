<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\CanonicalTranslationPayloadHash as Hash;
use App\Support\SchemaBaseline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** @review-surface article_translation_revision */
final class ArticleEquivalentWorkingRevisionWorkspace
{
    private const FIELDS = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];

    public function available(Article $selected): bool
    {
        try {
            $this->selected($selected);

            return true;
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }

    public function prepare(Article $selected): array
    {
        return DB::transaction(function () use ($selected): array {
            [$article, $working, $published] = $this->selected($selected, true);

            return [
                'target_hash' => ArticleSourceTargetSnapshot::publicationHash($article, true),
                'published_sha256' => Hash::hash($published->getAttributes()),
                'archive_equivalent' => false,
                'revision_context' => json_encode([
                    'article_id' => $article->id,
                    'working_revision_id' => $working->id,
                    'published_revision_id' => $published->id,
                    'equivalent_fields' => self::FIELDS,
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            ];
        });
    }

    public function archive(Article $selected, array $data): void
    {
        $this->require(($data['archive_equivalent'] ?? false) === true, 'equivalent_working_confirmation_required');
        DB::transaction(function () use ($selected, $data): void {
            [$article, $working, $published] = $this->selected($selected, true);
            $this->require(SchemaBaseline::hasTable('audit_logs'), 'equivalent_working_audit_unavailable');
            try {
                ArticleSourceTargetSnapshot::assertPublicationHash($article, (string) ($data['target_hash'] ?? ''), true);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'article_publication_state_changed') {
                    throw $exception;
                }
                $this->require(false, 'article_publication_state_changed');
            }
            $publishedBefore = $published->getAttributes();
            $this->require(hash_equals(Hash::hash($publishedBefore), (string) ($data['published_sha256'] ?? '')), 'published_revision_changed');
            $articleBefore = $article->getAttributes();
            $workingBefore = $working->getAttributes();
            $working->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED])->saveQuietly();
            $updates = ['working_revision_id' => (int) $published->id];
            if ($article->isSourceArticle()) {
                $updates['source_version_hash'] = $published->source_version_hash;
            }
            Article::withoutTimestamps(fn () => $article->forceFill($updates)->saveQuietly());
            $article->refresh();
            $working->refresh();
            $published->refresh();
            $this->require(Hash::sameValue(array_replace($articleBefore, $updates), $article->getAttributes()), 'article_archive_readback_mismatch');
            $this->require(Hash::sameValue($publishedBefore, $published->getAttributes()), 'published_revision_mutated');
            $this->require($working->revision_status === ArticleTranslationRevision::STATUS_ARCHIVED
                && Hash::sameValue(
                    array_diff_key($workingBefore, array_flip(['revision_status', 'updated_at'])),
                    array_diff_key($working->getAttributes(), array_flip(['revision_status', 'updated_at'])),
                ), 'working_archive_readback_mismatch');
            $lastAudit = (int) AuditLog::withoutGlobalScopes()->max('id');
            $request = Request::create('/ops/articles/archive-equivalent-working', 'POST');
            $request->attributes->set('org_id', (int) $article->org_id);
            app(AuditLogger::class)->log($request, 'article_equivalent_working_revision_archived', 'article', (string) $article->id, [
                'resource_org_id' => $article->org_id,
                'archived_revision_id' => $working->id,
                'published_revision_id' => $published->id,
                'before_sha256' => $data['target_hash'],
                'published_sha256' => Hash::hash($publishedBefore),
                'working_before_sha256' => Hash::hash($workingBefore),
                'working_after_sha256' => Hash::hash($working->getAttributes()),
                'source_hash_before' => $articleBefore['source_version_hash'],
                'source_hash_after' => $article->source_version_hash,
                'content_changed' => false,
                'published' => false,
                'source_review_completed' => false,
            ], reason: 'archive_equivalent_private_working_revision', result: 'success');
            $this->require(AuditLog::withoutGlobalScopes()->where('id', '>', $lastAudit)
                ->where('action', 'article_equivalent_working_revision_archived')
                ->where('target_type', 'article')->where('target_id', (string) $article->id)
                ->where('actor_admin_id', (int) auth((string) config('admin.guard', 'admin'))->id())
                ->where('result', 'success')->exists(), 'equivalent_working_audit_missing');
        });
    }

    /** @return array{Article, ArticleTranslationRevision, ArticleTranslationRevision} */
    private function selected(Article $selected, bool $lock = false): array
    {
        abort_unless(ContentAccess::canWrite() || ContentAccess::canRelease(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_unless((int) auth($guard)->id() > 0, 403);
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $this->require((int) $article->org_id === 0 && $article->deleted_at === null
            && in_array($article->locale, ['en', 'zh-CN'], true)
            && $article->status === 'published' && $article->is_public
            && $article->published_at !== null && ! $article->published_at->isFuture()
            && ! in_array($article->lifecycle_state, [Article::LIFECYCLE_ARCHIVED, Article::LIFECYCLE_SOFT_DELETED], true)
            && $article->working_revision_id !== null && $article->published_revision_id !== null
            && $article->working_revision_id !== $article->published_revision_id,
            'equivalent_private_working_required');
        $rows = ArticleTranslationRevision::withoutGlobalScopes()
            ->whereIn('id', [$article->working_revision_id, $article->published_revision_id])->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $working = $rows->get($article->working_revision_id);
        $published = $rows->get($article->published_revision_id);
        $this->require($working instanceof ArticleTranslationRevision && $published instanceof ArticleTranslationRevision, 'equivalent_revision_missing');
        $sourceId = $article->isSourceArticle() ? (int) $article->id : (int) $article->source_article_id;
        foreach ([$working, $published] as $revision) {
            $this->require((int) $revision->org_id === 0 && (int) $revision->article_id === (int) $article->id
                && $revision->locale === $article->locale && $revision->source_locale === $article->source_locale
                && trim((string) $article->translation_group_id) !== ''
                && $revision->translation_group_id === $article->translation_group_id
                && $sourceId > 0 && (int) $revision->source_article_id === $sourceId,
                'equivalent_revision_identity_mismatch');
        }
        $this->require($published->isPubliclyReadableForArticle($article)
            && $published->published_at !== null && ! $published->published_at->isFuture() && $working->published_at === null
            && $working->revision_status !== ArticleTranslationRevision::STATUS_ARCHIVED, 'equivalent_revision_state_invalid');
        $this->require(! $article->isSourceArticle()
            || preg_match('/^[a-f0-9]{64}$/D', (string) $published->source_version_hash) === 1, 'published_source_hash_invalid');
        foreach (self::FIELDS as $field) {
            $this->require($working->getRawOriginal($field) === $published->getRawOriginal($field), 'equivalent_revision_field_mismatch:'.$field);
        }
        foreach (['title', 'excerpt', 'content_md'] as $field) {
            $this->require($article->getRawOriginal($field) === $published->getRawOriginal($field), 'published_projection_mismatch:'.$field);
        }

        return [$article, $working, $published];
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['archive_equivalent' => $code]);
        }
    }
}

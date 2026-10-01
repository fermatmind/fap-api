<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class ArticleForkExistingTranslationDraft extends Command
{
    private const PAIRS = [3 => 188, 4 => 191, 5 => 195, 6 => 196, 7 => 198, 8 => 200, 9 => 201, 10 => 202, 11 => 26, 12 => 27, 13 => 29, 14 => 28, 15 => 24, 16 => 25, 46 => 47, 48 => 49, 50 => 197, 51 => 190, 52 => 189, 58 => 192];

    private const REBASE_PAIRS = [10 => 202, 40 => 41];

    private const LOCALIZED_SLUGS = [
        40 => ['riasec-holland-career-interest-test-explained', 'what-is-riasec-holland-code-career-interest-test'],
        46 => ['career-interest-vs-personality-test-differences', 'career-interest-test-vs-personality-test'],
        48 => ['career-confusion-test-map', 'choose-career-using-personality-tests'],
    ];

    private const LEGACY_SOURCE_PAIRS = [12 => 27, 13 => 29, 14 => 28, 15 => 24, 16 => 25];

    protected $signature = 'articles:fork-existing-translation-draft
        {--file= : Complete English candidate with exact source and published-target locks}
        {--sha256= : Exact package SHA256}
        {--restore-audit-id= : Restore the untouched private draft by its import audit}
        {--dry-run : Read only}
        {--execute : Fork or restore a private working version}
        {--confirm= : Exact execute confirmation}
        {--json : Metadata only}';

    protected $description = 'Fork a complete pending English candidate for explicitly scoped existing targets; preserve public projections and all prior versions.';

    public function handle(AuditLogger $logger): int
    {
        $errors = [];
        $after = null;
        $execute = (bool) $this->option('execute');
        $restore = (int) $this->option('restore-audit-id');
        try {
            if ($execute === (bool) $this->option('dry-run')
                || ($this->option('restore-audit-id') !== null && $restore < 1)) {
                throw new RuntimeException('mode_invalid');
            }
            $p = $this->package();
            $confirmation = sprintf('%s existing Article translation draft for target %d with package %s%s.',
                $restore ? 'Restore' : 'Fork', $p['target_id'], $this->option('sha256'), $restore ? ' and audit '.$restore : '');
            if ($execute && ! hash_equals($confirmation, (string) $this->option('confirm'))) {
                throw new RuntimeException('confirmation_mismatch');
            }
            $this->snapshot($p, $restore, false);
            if ($execute) {
                $after = DB::transaction(function () use ($p, $restore, $logger): array {
                    [$source, $target, $working, $revisions] = $this->snapshot($p, $restore, true);
                    $before = $target->getAttributes();
                    $history = ArticleSourceTargetSnapshot::sourceRevisions($target);
                    if ($restore) {
                        $new = $working;
                        $target->forceFill(['working_revision_id' => $p['working_revision_id']])->saveQuietly();
                        $new->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED])->saveQuietly();
                    } else {
                        $new = ArticleTranslationRevision::create([
                            'org_id' => 0, 'article_id' => $target->id, 'source_article_id' => $source->id,
                            'translation_group_id' => $source->translation_group_id, 'locale' => 'en', 'source_locale' => 'zh-CN',
                            'revision_number' => (int) $revisions->max('revision_number') + 1,
                            'supersedes_revision_id' => $working->id,
                            'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                            'source_version_hash' => $source->source_version_hash,
                            'translated_from_version_hash' => $source->source_version_hash,
                            ...$p['translation'],
                            'authority_metadata_json' => [
                                'draft_origin' => 'operator_supplied_ai_draft', 'editorial_review_state' => 'pending',
                                'package_sha256' => (string) $this->option('sha256'),
                                'source_published_revision_id' => $p['source_published_revision_id'],
                                ...$p['schema'] === 'fermat_existing_article_translation_independent_v3' ? [
                                    'draft_origin' => 'published_english_editorial_adaptation',
                                    'adapted_from_revision_id' => $p['target_published_revision_id'],
                                    'adapted_from_content_sha256' => hash('sha256', (string) $target->publishedRevision->content_md),
                                    'target_tags_sha256' => $p['target_tags_sha256'],
                                    'source_fidelity_review_state' => 'pending',
                                ] : [],
                                'source_fields_sha256' => $p['source_fields_sha256'],
                                'source_snapshot_hash' => $p['source_snapshot_hash'],
                                'source_published_version_hash' => $source->publishedRevision->source_version_hash,
                                'source_published_hash_matches_current' => hash_equals((string) $source->source_version_hash, (string) $source->publishedRevision->source_version_hash),
                            ],
                        ]);
                        $target->forceFill(['working_revision_id' => $new->id])->saveQuietly();
                    }
                    if (isset($p['target_tags_sha256']) && ! hash_equals($p['target_tags_sha256'], self::targetTagsHash($target, true))) {
                        throw new RuntimeException('published_target_lock_invalid');
                    }
                    $target->refresh();
                    $new->refresh();
                    $current = $target->getAttributes();
                    foreach (['working_revision_id', 'updated_at'] as $key) {
                        unset($before[$key], $current[$key]);
                    }
                    if ($before !== $current
                        || ! hash_equals($p['source_snapshot_hash'], ArticleForkPrivateTranslationLinks::sourceHash($source->fresh()))) {
                        throw new RuntimeException('public_or_source_readback_mismatch');
                    }
                    $expectedHistory = $restore ? array_values(array_filter($history, fn (array $r): bool => $r['revision_id'] !== (int) $new->id)) : $history;
                    if ($expectedHistory !== ArticleSourceTargetSnapshot::sourceRevisions($target, [(int) $new->id])) {
                        throw new RuntimeException('historical_readback_mismatch');
                    }
                    if ($restore) {
                        if ((int) $target->working_revision_id !== $p['working_revision_id'] || $new->revision_status !== 'archived') {
                            throw new RuntimeException('restore_readback_mismatch');
                        }
                    } else {
                        $this->assertDraft($new, $p);
                    }
                    $action = $restore ? 'article_existing_translation_draft_restored' : 'article_existing_translation_draft_forked';
                    $last = (int) AuditLog::withoutGlobalScopes()->max('id');
                    $logger->log(Request::create('/ops/article-translation/existing-draft', 'POST'), $action, 'article_translation', (string) $target->id, [
                        'package_sha256' => (string) $this->option('sha256'), 'source_id' => (int) $source->id,
                        'source_snapshot_hash' => $p['source_snapshot_hash'],
                        'target_snapshot_hash_after' => ArticleForkPrivateTranslationLinks::targetHash($source),
                        'old_working_revision_id' => $p['working_revision_id'], 'new_revision_id' => (int) $new->id,
                        'restore_audit_id' => $restore ?: null, 'public_state_changed' => false, 'human_review_completed' => false,
                    ], reason: $action, result: 'success');
                    $audit = AuditLog::withoutGlobalScopes()->where('id', '>', $last)->where('action', $action)
                        ->where('target_type', 'article_translation')->where('target_id', (string) $target->id)->latest('id')->first();
                    if (! $audit instanceof AuditLog || $audit->result !== 'success') {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['target_id' => (int) $target->id, 'new_revision_id' => (int) $new->id,
                        'working_revision_id' => (int) $target->working_revision_id, 'audit_id' => (int) $audit->id];
                });
            }
        } catch (Throwable $e) {
            $errors[] = $e instanceof RuntimeException && in_array($e->getMessage(), [
                'mode_invalid', 'confirmation_mismatch', 'package_invalid', 'source_identity_or_lock_invalid',
                'source_revision_invalid', 'target_identity_invalid', 'published_target_lock_invalid', 'restore_drift',
                'draft_invalid', 'source_fields_mismatch',
            ], true) ? $e->getMessage() : 'snapshot_or_transaction_failed';
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run', 'restore' => $restore > 0,
            'errors' => $errors, 'after' => $after];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function package(): array
    {
        $file = (string) $this->option('file');
        $sha = (string) $this->option('sha256');
        if (! is_file($file) || filesize($file) > 1048576 || ! preg_match('/^[a-f0-9]{64}$/', $sha)
            || ! hash_equals($sha, hash_file('sha256', $file))) {
            throw new RuntimeException('package_invalid');
        }
        $p = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        $keys = array_keys($p);
        sort($keys);
        $expected = ['schema', 'source_id', 'target_id', 'working_revision_id', 'source_published_revision_id',
            'source_snapshot_hash', 'target_snapshot_hash', 'source_fields_sha256', 'translation'];
        $independent = ($p['schema'] ?? null) === 'fermat_existing_article_translation_independent_v3';
        $rebase = ($p['schema'] ?? null) === 'fermat_existing_article_translation_rebase_v2' || $independent;
        if ($independent) {
            $expected[] = 'target_tags_sha256';
        }
        if ($rebase) {
            $expected[] = 'target_published_revision_id';
        }
        sort($expected);
        if ($keys !== $expected || (! $rebase && $p['schema'] !== 'fermat_existing_article_translation_draft_v1')
            || ! is_int($p['source_id']) || ! is_int($p['target_id'])
            || (($rebase ? self::REBASE_PAIRS : self::PAIRS)[$p['source_id']] ?? null) !== $p['target_id']
            || ($independent && ($p['source_id'] !== 40 || $p['target_id'] !== 41))) {
            throw new RuntimeException('package_invalid');
        }
        foreach ($rebase ? ['working_revision_id', 'source_published_revision_id', 'target_published_revision_id'] : ['working_revision_id', 'source_published_revision_id'] as $key) {
            if (! is_int($p[$key]) || $p[$key] < 1) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach ($independent ? ['source_snapshot_hash', 'target_snapshot_hash', 'target_tags_sha256'] : ['source_snapshot_hash', 'target_snapshot_hash'] as $key) {
            if (! is_string($p[$key]) || ! preg_match('/^[a-f0-9]{64}$/', $p[$key])) {
                throw new RuntimeException('package_invalid');
            }
        }
        $fields = ['title', 'excerpt', 'content_md', 'seo_title', 'seo_description'];
        foreach (['translation', 'source_fields_sha256'] as $key) {
            if (! is_array($p[$key]) || count($p[$key]) !== count($fields) || array_diff($fields, array_keys($p[$key])) !== []) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach ($fields as $field) {
            if (! is_string($p['translation'][$field]) || trim($p['translation'][$field]) === ''
                || preg_match('/\p{Han}|__FM_TOKEN_/u', $p['translation'][$field])
                || ! is_string($p['source_fields_sha256'][$field]) || ! preg_match('/^[a-f0-9]{64}$/', $p['source_fields_sha256'][$field])) {
                throw new RuntimeException('package_invalid');
            }
        }
        foreach (['title' => 255, 'seo_title' => 60, 'seo_description' => 160] as $field => $limit) {
            if (mb_strlen($p['translation'][$field]) > $limit) {
                throw new RuntimeException('package_invalid');
            }
        }

        return $p;
    }

    public static function targetTagsHash(Article $target, bool $lock = false): string
    {
        $query = DB::table('article_tag_map')->where('article_id', $target->id)->orderBy('org_id')->orderBy('tag_id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(function ($row): array {
            $attributes = (array) $row;
            ksort($attributes);

            return $attributes;
        })->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function snapshot(array $p, int $restore, bool $lock): array
    {
        $q = Article::withoutGlobalScopes()->withTrashed()->where('org_id', 0)->whereIn('id', [$p['source_id'], $p['target_id']])->orderBy('id');
        $rows = ($lock ? $q->lockForUpdate() : $q)->get()->keyBy('id');
        $s = $rows->get($p['source_id']);
        $t = $rows->get($p['target_id']);
        if (! $s instanceof Article || $s->trashed() || $s->locale !== 'zh-CN' || ! $s->isSourceArticle()
            || $s->status !== 'published' || ! $s->is_public || (int) $s->published_revision_id !== $p['source_published_revision_id']
            || ! hash_equals($p['source_snapshot_hash'], ArticleForkPrivateTranslationLinks::sourceHash($s, $lock))) {
            throw new RuntimeException('source_identity_or_lock_invalid');
        }
        $r = $s->publishedRevision;
        if (! $r instanceof ArticleTranslationRevision || (int) $r->org_id !== 0 || (int) $r->article_id !== (int) $s->id
            || (int) $r->source_article_id !== (int) $s->id || $r->locale !== 'zh-CN'
            || ! in_array($r->revision_status, ['source', 'published'], true)
            || $r->translation_group_id !== $s->translation_group_id || $r->source_locale !== 'zh-CN'
            || (! hash_equals((string) $s->source_version_hash, (string) $r->source_version_hash)
                && (self::LEGACY_SOURCE_PAIRS[(int) $s->id] ?? null) !== (int) $t?->id)
            || $r->title !== $s->title || $r->excerpt !== $s->excerpt || $r->content_md !== $s->content_md
            || ! hash_equals((string) $s->source_version_hash, $s->computeSourceVersionHash())) {
            throw new RuntimeException('source_revision_invalid');
        }
        foreach ($p['source_fields_sha256'] as $key => $sha) {
            $value = in_array($key, ['seo_title', 'seo_description'], true) ? $s->seoMeta?->$key : $s->$key;
            if (! hash_equals($sha, hash('sha256', (string) $value))) {
                throw new RuntimeException('source_fields_mismatch');
            }
        }
        $slugMatches = $t instanceof Article && $t->slug === $s->slug;
        if (isset(self::LOCALIZED_SLUGS[$p['source_id']])) {
            [$sourceSlug, $targetSlug] = self::LOCALIZED_SLUGS[$p['source_id']];
            $slugMatches = $s->slug === $sourceSlug && $t?->slug === $targetSlug;
        }
        if (! $t instanceof Article || $t->trashed() || $t->locale !== 'en' || $t->isSourceArticle()
            || $t->status !== 'published' || ! $t->is_public || ! $t->published_revision_id
            || (int) $t->source_article_id !== (int) $s->id || (int) $t->translated_from_article_id !== (int) $s->id
            || $t->translation_group_id !== $s->translation_group_id || ! $slugMatches || $t->source_locale !== 'zh-CN') {
            throw new RuntimeException('target_identity_invalid');
        }
        $hash = ArticleForkPrivateTranslationLinks::targetHash($s, $lock);
        $q = ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $t->id)->orderBy('id');
        $revisions = ($lock ? $q->lockForUpdate() : $q)->get();
        $w = $revisions->firstWhere('id', $t->working_revision_id);
        $public = $revisions->firstWhere('id', $t->published_revision_id);
        if (! $public instanceof ArticleTranslationRevision || (int) $public->org_id !== 0 || $public->locale !== 'en'
            || $public->revision_status !== 'published') {
            throw new RuntimeException('published_target_lock_invalid');
        }
        if ($restore) {
            if (! $w instanceof ArticleTranslationRevision) {
                throw new RuntimeException('draft_invalid');
            }
            $this->assertDraft($w, $p);
            $q = AuditLog::withoutGlobalScopes()->whereKey($restore);
            $a = ($lock ? $q->lockForUpdate() : $q)->first();
            $m = $a?->meta_json ?? [];
            if (! $a instanceof AuditLog || $a->action !== 'article_existing_translation_draft_forked' || $a->result !== 'success'
                || $a->target_type !== 'article_translation' || (string) $a->target_id !== (string) $t->id
                || ($m['package_sha256'] ?? null) !== $this->option('sha256') || ($m['source_id'] ?? null) !== (int) $s->id
                || ($m['source_snapshot_hash'] ?? null) !== $p['source_snapshot_hash'] || ($m['target_snapshot_hash_after'] ?? null) !== $hash
                || ($m['new_revision_id'] ?? null) !== (int) $w->id || ($m['old_working_revision_id'] ?? null) !== $p['working_revision_id']
                || (int) $t->published_revision_id !== ($p['target_published_revision_id'] ?? $p['working_revision_id'])) {
                throw new RuntimeException('restore_drift');
            }
        } elseif (! $w instanceof ArticleTranslationRevision || (int) $w->id !== $p['working_revision_id']
            || (int) $t->published_revision_id !== ($p['target_published_revision_id'] ?? $p['working_revision_id']) || ! hash_equals($p['target_snapshot_hash'], $hash)) {
            throw new RuntimeException('published_target_lock_invalid');
        }
        if (isset($p['target_published_revision_id'])) {
            $previous = $revisions->firstWhere('id', $p['working_revision_id']);
            if (! $previous instanceof ArticleTranslationRevision
                || (int) $previous->id === (int) $public->id
                || (int) $previous->org_id !== 0 || (int) $previous->source_article_id !== (int) $s->id
                || $previous->locale !== 'en' || $previous->source_locale !== 'zh-CN'
                || $previous->translation_group_id !== $s->translation_group_id
                || $previous->revision_status !== 'machine_draft'
                || (int) $s->working_revision_id !== (int) $s->published_revision_id
                || $previous->reviewed_by !== null || $previous->reviewed_at !== null
                || $previous->approved_at !== null || $previous->published_at !== null
                || ($previous->authority_metadata_json['editorial_review_state'] ?? null) !== 'pending'
                || ! filled($previous->translated_from_version_hash)
                || (($p['schema'] === 'fermat_existing_article_translation_independent_v3')
                    ? (! hash_equals((string) $previous->translated_from_version_hash, (string) $s->source_version_hash)
                        || ! hash_equals((string) $previous->source_version_hash, (string) $s->source_version_hash))
                    : hash_equals((string) $previous->translated_from_version_hash, (string) $s->source_version_hash))) {
                throw new RuntimeException('draft_invalid');
            }
        }

        if ($p['schema'] === 'fermat_existing_article_translation_independent_v3') {
            if (! hash_equals($p['target_tags_sha256'], self::targetTagsHash($t, $lock))) {
                throw new RuntimeException('published_target_lock_invalid');
            }
            foreach (['title', 'excerpt', 'seo_title', 'seo_description'] as $field) {
                $value = in_array($field, ['seo_title', 'seo_description'], true)
                    ? ($public->$field ?? $t->seoMeta?->$field) : $public->$field;
                if ($p['translation'][$field] !== (string) $value) {
                    throw new RuntimeException('published_target_lock_invalid');
                }
            }
        }

        return [$s, $t, $w, $revisions];
    }

    private function assertDraft(ArticleTranslationRevision $r, array $p): void
    {
        if ((int) $r->org_id !== 0 || (int) $r->article_id !== $p['target_id'] || (int) $r->source_article_id !== $p['source_id']
            || $r->locale !== 'en' || $r->source_locale !== 'zh-CN' || $r->revision_status !== 'machine_draft'
            || $r->reviewed_by !== null || $r->reviewed_at !== null || $r->approved_at !== null || $r->published_at !== null
            || (int) $r->supersedes_revision_id !== $p['working_revision_id']
            || ($r->authority_metadata_json['package_sha256'] ?? null) !== $this->option('sha256')
            || ($r->authority_metadata_json['editorial_review_state'] ?? null) !== 'pending') {
            throw new RuntimeException('draft_invalid');
        }
        foreach ($p['translation'] as $key => $value) {
            if ($value !== $r->$key) {
                throw new RuntimeException('draft_invalid');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @review-surface article
 */
final class ArticleRepairTranslationLineage extends Command
{
    protected $signature = 'articles:repair-translation-lineage
        {--source-article-id= : Exact canonical source article id}
        {--target-article-id= : Exact translation target article id}
        {--source-revision-id= : Exact current source working and published revision id}
        {--target-published-revision-id= : Exact current target published revision id}
        {--target-working-revision-id= : Exact target working revision id}
        {--translation-group-id= : Exact shared translation group id}
        {--source-locale= : Exact source locale}
        {--target-locale= : Exact target locale}
        {--expected-source-slug= : Exact source slug}
        {--expected-target-slug= : Exact target slug}
        {--expected-source-canonical= : Exact source canonical path or URL}
        {--expected-target-canonical= : Exact target canonical path or URL}
        {--expected-source-body-sha256= : Exact source revision body SHA256}
        {--expected-target-published-body-sha256= : Exact published target body SHA256}
        {--expected-target-working-body-sha256= : Exact working target body SHA256}
        {--confirm= : Exact execute confirmation}
        {--dry-run : Validate and report without writing}
        {--execute : Apply the lineage repair transactionally}
        {--json : Emit JSON output}';

    protected $description = 'Repair one article translation group with exact identity, revision, canonical, and body locks.';

    public function handle(AuditLogger $auditLogger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $errors = [];

        if ($dryRun === $execute) {
            $errors[] = $this->issue('mode', 'exactly_one_mode_required', 'Choose exactly one of --dry-run or --execute.');
        }

        $expectedConfirmation = $this->expectedConfirmation();
        if ($execute && ! hash_equals($expectedConfirmation, trim((string) $this->option('confirm')))) {
            $errors[] = $this->issue('confirm', 'confirmation_mismatch', 'Exact lineage repair confirmation is required.');
        }

        try {
            $plan = $this->snapshot(false);
            $errors = array_merge($errors, $plan['errors']);
        } catch (Throwable $exception) {
            $plan = $this->emptyPlan();
            $errors[] = $this->issue('command', 'snapshot_failed', $exception->getMessage());
        }

        $summary = [
            'ok' => $errors === [],
            'dry_run' => $dryRun,
            'execute' => $execute,
            'action' => $execute ? 'repair_translation_lineage' : 'would_repair_translation_lineage',
            'expected_confirmation' => $expectedConfirmation,
            'plan' => $plan,
            'errors' => $errors,
        ];

        if ($execute && $errors === []) {
            try {
                $result = DB::transaction(function (): array {
                    $locked = $this->snapshot(true);
                    if ($locked['errors'] !== []) {
                        throw new \RuntimeException(implode('; ', array_column($locked['errors'], 'code')));
                    }

                    if (! $locked['would_write']) {
                        return $locked;
                    }

                    /** @var Article $source */
                    $source = $locked['records']['source'];
                    /** @var Article $target */
                    $target = $locked['records']['target'];
                    /** @var ArticleTranslationRevision $sourceRevision */
                    $sourceRevision = $locked['records']['source_revision'];
                    /** @var ArticleTranslationRevision $targetPublishedRevision */
                    $targetPublishedRevision = $locked['records']['target_published_revision'];
                    /** @var ArticleTranslationRevision $targetWorkingRevision */
                    $targetWorkingRevision = $locked['records']['target_working_revision'];
                    $sourceHash = (string) $sourceRevision->source_version_hash;

                    $source->forceFill([
                        'source_locale' => (string) $this->option('source-locale'),
                        'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                        'translated_from_article_id' => null,
                        'source_article_id' => null,
                        'translated_from_version_hash' => null,
                    ])->saveQuietly();

                    $target->forceFill([
                        'source_locale' => (string) $this->option('source-locale'),
                        'source_article_id' => (int) $source->id,
                        'translated_from_article_id' => (int) $source->id,
                        'translated_from_version_hash' => $sourceHash,
                        'translation_status' => (string) $targetWorkingRevision->revision_status,
                    ])->saveQuietly();

                    $sourceRevision->forceFill([
                        'source_article_id' => (int) $source->id,
                        'source_locale' => (string) $source->locale,
                        'translated_from_version_hash' => $sourceHash,
                        'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
                    ])->save();

                    foreach ([$targetPublishedRevision, $targetWorkingRevision] as $revision) {
                        $revision->forceFill([
                            'source_article_id' => (int) $source->id,
                            'source_locale' => (string) $source->locale,
                            'source_version_hash' => $sourceHash,
                            'translated_from_version_hash' => $sourceHash,
                        ])->save();
                    }

                    $readback = $this->snapshot(false);
                    if ($readback['errors'] !== [] || $readback['would_write']) {
                        throw new \RuntimeException('lineage_readback_failed');
                    }

                    return $readback;
                });

                if ($plan['would_write']) {
                    $this->logRepair($auditLogger, $result);
                }
                $summary['plan'] = $result;
                $summary['action'] = $plan['would_write'] ? 'translation_lineage_repaired' : 'translation_lineage_already_repaired';
            } catch (Throwable $exception) {
                $summary['ok'] = false;
                $summary['errors'][] = $this->issue('execute', 'lineage_repair_failed', $exception->getMessage());
            }
        }

        unset($summary['plan']['records']);
        $this->emit($summary);

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function snapshot(bool $lock): array
    {
        $ids = [
            'source' => (int) $this->option('source-article-id'),
            'target' => (int) $this->option('target-article-id'),
            'source_revision' => (int) $this->option('source-revision-id'),
            'target_published_revision' => (int) $this->option('target-published-revision-id'),
            'target_working_revision' => (int) $this->option('target-working-revision-id'),
        ];
        $errors = [];
        foreach ($ids as $field => $id) {
            if ($id <= 0) {
                $errors[] = $this->issue($field, 'positive_integer_required', 'All article and revision ids must be positive integers.');
            }
        }

        $articleQuery = Article::query()->withoutGlobalScopes();
        $revisionQuery = ArticleTranslationRevision::query()->withoutGlobalScopes();
        if ($lock) {
            $articleQuery->lockForUpdate();
            $revisionQuery->lockForUpdate();
        }

        $articles = $articleQuery->whereIn('id', [$ids['source'], $ids['target']])->get()->keyBy('id');
        $revisions = $revisionQuery->whereIn('id', [
            $ids['source_revision'],
            $ids['target_published_revision'],
            $ids['target_working_revision'],
        ])->get()->keyBy('id');

        /** @var Article|null $source */
        $source = $articles->get($ids['source']);
        /** @var Article|null $target */
        $target = $articles->get($ids['target']);
        /** @var ArticleTranslationRevision|null $sourceRevision */
        $sourceRevision = $revisions->get($ids['source_revision']);
        /** @var ArticleTranslationRevision|null $targetPublishedRevision */
        $targetPublishedRevision = $revisions->get($ids['target_published_revision']);
        /** @var ArticleTranslationRevision|null $targetWorkingRevision */
        $targetWorkingRevision = $revisions->get($ids['target_working_revision']);

        if (! $source instanceof Article || ! $target instanceof Article) {
            $errors[] = $this->issue('articles', 'article_not_found', 'The exact source and target articles must exist.');
        }
        if (! $sourceRevision instanceof ArticleTranslationRevision
            || ! $targetPublishedRevision instanceof ArticleTranslationRevision
            || ! $targetWorkingRevision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('revisions', 'revision_not_found', 'The exact source, published target, and working target revisions must exist.');
        }

        if ($errors !== []) {
            return $this->emptyPlan($errors);
        }

        $group = trim((string) $this->option('translation-group-id'));
        $sourceLocale = trim((string) $this->option('source-locale'));
        $targetLocale = trim((string) $this->option('target-locale'));
        if ($group === '' || $sourceLocale === '' || $targetLocale === '') {
            $errors[] = $this->issue('identity', 'identity_lock_required', 'Translation group and locale locks are required.');
        }
        if ((string) $source->translation_group_id !== $group || (string) $target->translation_group_id !== $group) {
            $errors[] = $this->issue('translation_group_id', 'translation_group_mismatch', 'Article translation group does not match the lock.');
        }
        if ((string) $source->locale !== $sourceLocale || (string) $target->locale !== $targetLocale) {
            $errors[] = $this->issue('locale', 'locale_mismatch', 'Article locale does not match the lock.');
        }
        if ((string) $source->slug !== trim((string) $this->option('expected-source-slug'))
            || (string) $target->slug !== trim((string) $this->option('expected-target-slug'))) {
            $errors[] = $this->issue('slug', 'slug_mismatch', 'Article slug does not match the lock.');
        }
        if ((int) $source->working_revision_id !== $ids['source_revision']
            || (int) $source->published_revision_id !== $ids['source_revision']) {
            $errors[] = $this->issue('source_revision', 'source_revision_pointer_mismatch', 'Source working and published pointers must match the locked source revision.');
        }
        if ((int) $target->published_revision_id !== $ids['target_published_revision']
            || (int) $target->working_revision_id !== $ids['target_working_revision']) {
            $errors[] = $this->issue('target_revisions', 'target_revision_pointer_mismatch', 'Target revision pointers do not match the locks.');
        }
        foreach ([
            'source_revision' => [$sourceRevision, $source],
            'target_published_revision' => [$targetPublishedRevision, $target],
            'target_working_revision' => [$targetWorkingRevision, $target],
        ] as $field => [$revision, $article]) {
            if ((int) $revision->article_id !== (int) $article->id
                || (string) $revision->translation_group_id !== $group
                || (string) $revision->locale !== (string) $article->locale) {
                $errors[] = $this->issue($field, 'revision_ownership_mismatch', 'Revision ownership does not match the locked article identity.');
            }
        }
        if (! in_array((string) $sourceRevision->revision_status, [
            ArticleTranslationRevision::STATUS_PUBLISHED,
            ArticleTranslationRevision::STATUS_SOURCE,
        ], true)) {
            $errors[] = $this->issue('source_revision', 'source_revision_status_mismatch', 'The locked source revision must be the currently published source revision or the normalized source revision.');
        }
        if (! in_array((string) $targetWorkingRevision->revision_status, [
            ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            ArticleTranslationRevision::STATUS_APPROVED,
        ], true)) {
            $errors[] = $this->issue('target_working_revision', 'target_working_revision_status_mismatch', 'Target working revision must be human_review or approved.');
        }

        $sourceCanonical = $this->canonicalFor($source);
        $targetCanonical = $this->canonicalFor($target);
        if ($sourceCanonical !== $this->canonicalPath((string) $this->option('expected-source-canonical'))
            || $targetCanonical !== $this->canonicalPath((string) $this->option('expected-target-canonical'))) {
            $errors[] = $this->issue('canonical', 'canonical_mismatch', 'Article canonical does not match the lock.');
        }

        $bodyHashes = [
            'source' => $this->bodyHash((string) $sourceRevision->content_md),
            'target_published' => $this->bodyHash((string) $targetPublishedRevision->content_md),
            'target_working' => $this->bodyHash((string) $targetWorkingRevision->content_md),
        ];
        foreach ([
            'source' => 'expected-source-body-sha256',
            'target_published' => 'expected-target-published-body-sha256',
            'target_working' => 'expected-target-working-body-sha256',
        ] as $field => $option) {
            $expected = trim((string) $this->option($option));
            if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1 || ! hash_equals($expected, $bodyHashes[$field])) {
                $errors[] = $this->issue($option, 'body_hash_mismatch', 'Revision body SHA256 does not match the lock.');
            }
        }

        $sourceHash = trim((string) $sourceRevision->source_version_hash);
        if ($sourceHash === '') {
            $errors[] = $this->issue('source_version_hash', 'source_version_hash_missing', 'Source revision version hash is required.');
        } else {
            foreach ([
                'target_article' => (string) $target->translated_from_version_hash,
                'target_published_revision_source' => (string) $targetPublishedRevision->source_version_hash,
                'target_published_revision' => (string) $targetPublishedRevision->translated_from_version_hash,
                'target_working_revision_source' => (string) $targetWorkingRevision->source_version_hash,
                'target_working_revision' => (string) $targetWorkingRevision->translated_from_version_hash,
            ] as $field => $translatedFromHash) {
                if ($translatedFromHash === '' || ! hash_equals($sourceHash, $translatedFromHash)) {
                    $errors[] = $this->issue($field, 'translation_provenance_unverified', 'Target translation must already be bound to the exact current source hash before lineage repair.');
                }
            }
        }

        $sourceDesired = (string) $source->source_locale === $sourceLocale
            && (string) $source->translation_status === Article::TRANSLATION_STATUS_SOURCE
            && $source->source_article_id === null
            && $source->translated_from_article_id === null;
        $targetPrestateAllowed = $target->isSourceArticle() || $this->targetDesired($target, $source, $targetWorkingRevision, $sourceHash);
        if (! $sourceDesired) {
            $errors[] = $this->issue('source_article', 'source_article_state_mismatch', 'Source article is not the locked canonical source.');
        }
        if (! $targetPrestateAllowed) {
            $errors[] = $this->issue('target_article', 'target_article_state_mismatch', 'Target article is neither the known split-source prestate nor the desired translation state.');
        }

        $wouldWrite = ! $this->targetDesired($target, $source, $targetWorkingRevision, $sourceHash)
            || ! $this->sourceRevisionDesired($sourceRevision, $source, $sourceHash)
            || ! $this->revisionDesired($targetPublishedRevision, $source, $sourceHash)
            || ! $this->revisionDesired($targetWorkingRevision, $source, $sourceHash);

        return [
            'ok' => $errors === [],
            'would_write' => $wouldWrite,
            'source_article_id' => (int) $source->id,
            'target_article_id' => (int) $target->id,
            'source_revision_id' => (int) $sourceRevision->id,
            'target_published_revision_id' => (int) $targetPublishedRevision->id,
            'target_working_revision_id' => (int) $targetWorkingRevision->id,
            'translation_group_id' => $group,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'source_version_hash' => $sourceHash,
            'canonicals' => ['source' => $sourceCanonical, 'target' => $targetCanonical],
            'body_sha256' => $bodyHashes,
            'errors' => $errors,
            'records' => [
                'source' => $source,
                'target' => $target,
                'source_revision' => $sourceRevision,
                'target_published_revision' => $targetPublishedRevision,
                'target_working_revision' => $targetWorkingRevision,
            ],
        ];
    }

    private function targetDesired(Article $target, Article $source, ArticleTranslationRevision $workingRevision, string $sourceHash): bool
    {
        return (string) $target->source_locale === (string) $source->locale
            && (int) $target->source_article_id === (int) $source->id
            && (int) $target->translated_from_article_id === (int) $source->id
            && (string) $target->translated_from_version_hash === $sourceHash
            && (string) $target->translation_status === (string) $workingRevision->revision_status;
    }

    private function revisionDesired(ArticleTranslationRevision $revision, Article $source, string $sourceHash): bool
    {
        return (int) $revision->source_article_id === (int) $source->id
            && (string) $revision->source_locale === (string) $source->locale
            && (string) $revision->source_version_hash === $sourceHash
            && (string) $revision->translated_from_version_hash === $sourceHash;
    }

    private function sourceRevisionDesired(ArticleTranslationRevision $revision, Article $source, string $sourceHash): bool
    {
        return $this->revisionDesired($revision, $source, $sourceHash)
            && (string) $revision->revision_status === ArticleTranslationRevision::STATUS_SOURCE;
    }

    private function canonicalFor(Article $article): string
    {
        $canonical = ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->where('locale', (string) $article->locale)
            ->value('canonical_url');

        return $this->canonicalPath((string) $canonical);
    }

    private function canonicalPath(string $canonical): string
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return '';
        }

        $path = (string) (parse_url($canonical, PHP_URL_PATH) ?: $canonical);
        $path = '/'.ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }

    private function expectedConfirmation(): string
    {
        return sprintf(
            'I explicitly approve article translation lineage repair from source %d to target %d.',
            (int) $this->option('source-article-id'),
            (int) $this->option('target-article-id'),
        );
    }

    /** @param list<array<string,string>> $errors @return array<string,mixed> */
    private function emptyPlan(array $errors = []): array
    {
        return ['ok' => false, 'would_write' => false, 'errors' => $errors, 'records' => []];
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }

    /** @param array<string,mixed> $result */
    private function logRepair(AuditLogger $auditLogger, array $result): void
    {
        $auditLogger->log(
            Request::create('/ops/articles/repair-translation-lineage', 'POST'),
            'article_translation_lineage_repaired',
            'article',
            (string) $result['target_article_id'],
            [
                'source_article_id' => $result['source_article_id'],
                'target_article_id' => $result['target_article_id'],
                'source_revision_id' => $result['source_revision_id'],
                'target_published_revision_id' => $result['target_published_revision_id'],
                'target_working_revision_id' => $result['target_working_revision_id'],
                'translation_group_id' => $result['translation_group_id'],
                'source_locale' => $result['source_locale'],
                'target_locale' => $result['target_locale'],
                'source_version_hash' => $result['source_version_hash'],
                'body_sha256' => $result['body_sha256'],
                'source' => 'controlled_article_translation_lineage_repair',
            ],
            reason: 'controlled_article_translation_lineage_repair',
            result: 'success',
        );
    }

    /** @param array<string,mixed> $summary */
    private function emit(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? 'will_skip'));
        $this->line('errors_count='.count((array) ($summary['errors'] ?? [])));
    }
}

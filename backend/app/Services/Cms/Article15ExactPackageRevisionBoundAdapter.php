<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class Article15ExactPackageRevisionBoundAdapter
{
    public const EXECUTION_ID = 'ARTICLE15-EXACT-PACKAGE-REVISION-BOUND-20260826-v1';

    public const MANIFEST_PATH = 'backend/docs/seo/content-packages/article15-exact-package-revision-bound-20260826/manifest.json';

    private const METADATA_KEY = 'article15_exact_package_v1';

    private const PHASES = ['preflight', 'draft-import', 'readback', 'publish'];

    private const BATCHES = ['A', 'B', 'C', 'ALL'];

    private const PRIVATE_ROUTE_PATTERN = '~(?:^|https?://[^/]+)?/(?:[^\s?#]+/)*(?:results?|orders?|payments?|pay|share|history|private)(?:/|[?#\s)"\']|$)|[?&](?:token|access_token|result_id|order_id|payment_id|session_id)=~i';

    public function __construct(private readonly ArticleBodyHeadingGuard $headingGuard) {}

    /** @return array<string,mixed> */
    public function run(array $options): array
    {
        $phase = strtolower(trim((string) ($options['phase'] ?? 'preflight')));
        $batch = strtoupper(trim((string) ($options['batch'] ?? 'ALL')));
        $execute = (bool) ($options['execute'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? ! $execute);

        if (! in_array($phase, self::PHASES, true)) {
            throw new RuntimeException('invalid_phase');
        }
        if (! in_array($batch, self::BATCHES, true)) {
            throw new RuntimeException('invalid_batch');
        }
        if ($batch === 'ALL' && in_array($phase, ['draft-import', 'publish'], true)) {
            throw new RuntimeException('all_batch_write_phase_forbidden');
        }
        if ($execute && $dryRun) {
            throw new RuntimeException('dry_run_execute_mutually_exclusive');
        }
        if ($execute && ! app()->environment('testing')) {
            throw new RuntimeException('non_test_database_execute_forbidden');
        }

        $context = $this->loadContext($batch);
        $this->assertHashOption('execution_manifest_sha256', (string) ($options['execution_manifest_sha256'] ?? ''), (string) $context['manifest_sha256']);
        $locks = $this->lockHashes($context['targets']);
        $this->assertHashOption('expected_state_sha256', (string) ($options['expected_state_sha256'] ?? ''), $locks['state_sha256']);
        $this->assertHashOption('expected_revision_set_sha256', (string) ($options['expected_revision_set_sha256'] ?? ''), $locks['revision_set_sha256']);

        $summary = [
            'ok' => true,
            'execution_id' => self::EXECUTION_ID,
            'phase' => $phase,
            'batch' => $batch,
            'dry_run' => ! $execute,
            'executed' => false,
            'would_write' => in_array($phase, ['draft-import', 'publish'], true),
            'execution_manifest_sha256' => $context['manifest_sha256'],
            ...$locks,
            'target_count' => count($context['targets']),
            'targets' => $this->readback($context['targets']),
            'write_boundaries' => $this->writeBoundaries(),
        ];

        if (! $execute || in_array($phase, ['preflight', 'readback'], true)) {
            return $summary;
        }

        $result = $phase === 'draft-import'
            ? $this->draftImport($context['targets'], $locks)
            : $this->publish($context['targets'], $locks);

        return array_replace($summary, $result, [
            'dry_run' => false,
            'executed' => true,
            'targets' => $this->readback($context['targets']),
        ]);
    }

    /** @return array{state_sha256:string,revision_set_sha256:string} */
    public function currentLockHashes(string $batch): array
    {
        return $this->lockHashes($this->loadContext(strtoupper($batch))['targets']);
    }

    public static function isPublishedArticle15Metadata(mixed $metadata, int $articleId, int $publishedRevisionId): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        return ($metadata['execution_id'] ?? null) === self::EXECUTION_ID
            && ($metadata['status'] ?? null) === 'published'
            && (int) ($metadata['article_id'] ?? 0) === $articleId
            && (int) ($metadata['published_revision_id'] ?? 0) === $publishedRevisionId
            && preg_match('/^[a-f0-9]{64}$/', (string) ($metadata['package_sha256'] ?? '')) === 1;
    }

    /** @return array<string,mixed> */
    private function loadContext(string $batch): array
    {
        $manifest = $this->readJson($this->repoPath(self::MANIFEST_PATH));
        if (($manifest['execution_id'] ?? null) !== self::EXECUTION_ID) {
            throw new RuntimeException('execution_manifest_id_mismatch');
        }

        $storedSha = (string) ($manifest['execution_manifest_sha256'] ?? '');
        $hashable = $manifest;
        unset($hashable['execution_manifest_sha256']);
        if (! hash_equals($storedSha, $this->canonicalHash($hashable))) {
            throw new RuntimeException('execution_manifest_sha256_mismatch');
        }

        $targets = (array) ($manifest['targets'] ?? []);
        $this->assertTargetInventory($targets);

        $this->validateFrozenPackages($manifest);
        $selected = $batch === 'ALL'
            ? $targets
            : array_values(array_filter($targets, static fn (array $target): bool => ($target['batch'] ?? null) === $batch));
        if (count($selected) !== ($batch === 'ALL' ? 15 : 5)) {
            throw new RuntimeException('execution_manifest_batch_count_invalid');
        }

        foreach ($selected as &$target) {
            $target['package'] = $this->readJson($this->repoPath((string) $target['package_path']));
            $target['body'] = $this->readBodyForPackagePath((string) $target['package_path']);
        }
        unset($target);

        $this->validatePackageSemantics($selected);

        return ['manifest' => $manifest, 'manifest_sha256' => $storedSha, 'targets' => $selected];
    }

    /** @param array<int,mixed> $targets */
    public function assertTargetInventory(array $targets): void
    {
        if (count($targets) !== 15) {
            throw new RuntimeException('execution_manifest_target_set_invalid');
        }

        $articleIds = [];
        $identities = [];
        foreach ($targets as $index => $target) {
            if (! is_array($target) || (int) ($target['order'] ?? 0) !== $index + 1) {
                throw new RuntimeException('execution_manifest_target_order_invalid');
            }

            $expectedBatch = ['A', 'B', 'C'][intdiv($index, 5)];
            if (($target['batch'] ?? null) !== $expectedBatch) {
                throw new RuntimeException('execution_manifest_target_order_invalid');
            }

            $articleIds[] = (int) ($target['article_id'] ?? 0);
            $identities[] = implode('|', [
                (string) ($target['article_id'] ?? ''),
                (string) ($target['translation_group_id'] ?? ''),
                (string) ($target['locale'] ?? ''),
                (string) ($target['slug'] ?? ''),
                (string) ($target['canonical_url'] ?? ''),
                (string) ($target['published_revision_id'] ?? ''),
            ]);
        }

        if (count(array_unique($articleIds)) !== 15 || count(array_unique($identities)) !== 15) {
            throw new RuntimeException('execution_manifest_target_set_invalid');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function validateFrozenPackages(array $manifest): void
    {
        $manifestTargets = array_values((array) ($manifest['targets'] ?? []));
        $observedTargets = [];

        foreach ((array) ($manifest['batches'] ?? []) as $batch) {
            if (! is_array($batch)) {
                throw new RuntimeException('batch_manifest_entry_invalid');
            }
            $source = $this->readJson($this->repoPath((string) ($batch['manifest_path'] ?? '')));
            $sourceHashable = $source;
            $sourceSha = (string) ($sourceHashable['batch_manifest_sha256'] ?? '');
            unset($sourceHashable['batch_manifest_sha256']);
            if (! hash_equals((string) ($batch['manifest_sha256'] ?? ''), $sourceSha)
                || ! hash_equals($sourceSha, $this->canonicalHash($sourceHashable))) {
                throw new RuntimeException('batch_manifest_sha256_mismatch:'.(string) ($batch['batch'] ?? ''));
            }
            if (($source['content_package_only'] ?? false) !== true
                || ($source['permissions']['cms_import'] ?? true) !== false
                || ($source['permissions']['publication'] ?? true) !== false) {
                throw new RuntimeException('source_batch_permissions_drift');
            }

            foreach ((array) ($source['packages'] ?? []) as $sourceTarget) {
                if (! is_array($sourceTarget)) {
                    throw new RuntimeException('source_target_invalid');
                }
                $position = count($observedTargets);
                $pinned = $manifestTargets[$position] ?? null;
                if (! is_array($pinned)
                    || (int) ($pinned['article_id'] ?? 0) !== (int) ($sourceTarget['article_id'] ?? 0)
                    || (string) ($pinned['package_sha256'] ?? '') !== (string) ($sourceTarget['package_sha256'] ?? '')
                    || (int) ($pinned['published_revision_id'] ?? 0) !== (int) ($sourceTarget['published_revision_id'] ?? 0)) {
                    throw new RuntimeException('source_target_order_or_identity_drift');
                }
                $package = $this->readJson($this->repoPath((string) $pinned['package_path']));
                $packageHashable = $package;
                $packageSha = (string) ($packageHashable['package_sha256'] ?? '');
                unset($packageHashable['package_sha256']);
                if (! hash_equals((string) $pinned['package_sha256'], $packageSha)
                    || ! hash_equals($packageSha, $this->canonicalHash($packageHashable))) {
                    throw new RuntimeException('package_sha256_mismatch:'.(string) ($pinned['article_id'] ?? 0));
                }
                $body = $this->readBodyForPackagePath((string) $pinned['package_path']);
                if (! hash_equals((string) $pinned['body_sha256'], hash('sha256', $body))) {
                    throw new RuntimeException('body_sha256_mismatch:'.(string) ($pinned['article_id'] ?? 0));
                }
                $observedTargets[] = $pinned;
            }
        }

        if (count($observedTargets) !== 15) {
            throw new RuntimeException('source_target_count_invalid');
        }
    }

    /** @param list<array<string,mixed>> $targets */
    private function validatePackageSemantics(array $targets): void
    {
        foreach ($targets as $target) {
            $package = (array) $target['package'];
            $identity = (array) ($package['identity_lock'] ?? []);
            foreach (['article_id', 'translation_group_id', 'locale', 'slug', 'canonical_url', 'published_revision_id'] as $field) {
                if (($identity[$field] ?? null) !== ($target[$field] ?? null)) {
                    throw new RuntimeException('package_identity_mismatch:'.$field.':'.(string) $target['article_id']);
                }
            }
            if (($package['status']['content_package_only'] ?? false) !== true
                || ($package['status']['import_ready'] ?? true) !== false
                || ($package['status']['publish_allowed'] ?? true) !== false) {
                throw new RuntimeException('source_package_readiness_drift');
            }
            foreach ((array) ($package['current_to_proposed'] ?? []) as $field => $patch) {
                if (! is_array($patch) || ! in_array(($patch['status'] ?? null), ['KEEP', 'CHANGE'], true)) {
                    throw new RuntimeException('unsupported_patch_status:'.$field);
                }
                $keepEqual = match ($field) {
                    'body_markdown' => (string) data_get($patch, 'current.sha256') === (string) data_get($patch, 'proposed.sha256'),
                    'canonical_internal_links' => true,
                    default => $this->deepEqual($patch['current'] ?? null, $patch['proposed'] ?? null),
                };
                if (($patch['status'] ?? null) === 'KEEP' && ! $keepEqual) {
                    throw new RuntimeException('keep_patch_not_equal:'.$field);
                }
            }
            $proposedFaq = (array) data_get($package, 'current_to_proposed.faq.proposed', []);
            $surfaceFaq = (array) data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []);
            if ($proposedFaq === [] || count($proposedFaq) > 8 || ! $this->deepEqual($proposedFaq, $surfaceFaq)) {
                throw new RuntimeException('faq_parity_invalid:'.(string) $target['article_id']);
            }
            foreach ($proposedFaq as $faq) {
                if (! is_array($faq)
                    || ! str_contains((string) $target['body'], trim((string) ($faq['question'] ?? '')))
                    || ! str_contains((string) $target['body'], trim((string) ($faq['answer'] ?? '')))) {
                    throw new RuntimeException('visible_faq_body_parity_invalid:'.(string) $target['article_id']);
                }
            }
            $ctas = (array) data_get($package, 'current_to_proposed.primary_cta.proposed', []);
            if (count($ctas) !== 1 || ! $this->isPublicCanonicalRoute((string) data_get($ctas, '0.href', ''))) {
                throw new RuntimeException('primary_cta_invalid:'.(string) $target['article_id']);
            }
            $this->headingGuard->assertNoBodyH1((string) $target['body']);
            $this->assertNoPrivateUrls([$target['body'], $ctas, data_get($package, 'internal_link_plan', [])]);
            foreach ((array) data_get($package, 'current_to_proposed.canonical_internal_links.proposed', []) as $link) {
                $href = is_array($link) ? (string) ($link['href'] ?? '') : '';
                if ($href === '' || ! $this->isPublicCanonicalRoute($href)) {
                    throw new RuntimeException('canonical_internal_link_invalid:'.(string) $target['article_id']);
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $targets @return array{state_sha256:string,revision_set_sha256:string} */
    private function lockHashes(array $targets): array
    {
        $states = [];
        $revisions = [];
        foreach ($targets as $target) {
            $article = $this->article((int) $target['article_id']);
            $states[] = $this->publicState($article);
            $revisions[] = [
                'article_id' => (int) $article->id,
                'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
                'working_revision_id' => $article->working_revision_id !== null ? (int) $article->working_revision_id : null,
            ];
        }

        return [
            'state_sha256' => $this->canonicalHash($states),
            'revision_set_sha256' => $this->canonicalHash($revisions),
        ];
    }

    /** @param list<array<string,mixed>> $targets @return list<array<string,mixed>> */
    private function readback(array $targets): array
    {
        $rows = [];
        foreach ($targets as $target) {
            $article = $this->article((int) $target['article_id']);
            $working = $article->workingRevision;
            $published = $article->publishedRevision;
            $workingMetadata = is_array($working?->authority_metadata_json)
                ? (array) ($working->authority_metadata_json[self::METADATA_KEY] ?? []) : [];
            $publishedMetadata = is_array($published?->authority_metadata_json)
                ? (array) ($published->authority_metadata_json[self::METADATA_KEY] ?? []) : [];
            $rows[] = [
                'article_id' => (int) $article->id,
                'locale' => (string) $article->locale,
                'slug' => (string) $article->slug,
                'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
                'working_revision_id' => $article->working_revision_id !== null ? (int) $article->working_revision_id : null,
                'adapter_state' => $publishedMetadata !== [] ? 'published' : ($workingMetadata !== [] ? 'drafted' : 'not_imported'),
                'proposed_metadata' => $publishedMetadata !== [] ? $publishedMetadata : $workingMetadata,
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $targets @param array<string,string> $locks @return array<string,mixed> */
    private function draftImport(array $targets, array $locks): array
    {
        return DB::transaction(function () use ($targets, $locks): array {
            $locked = $this->lockArticles($targets);
            $this->assertLockedHashes($targets, $locks);
            $actions = [];
            foreach ($targets as $target) {
                $article = $locked[(int) $target['article_id']];
                $this->assertOriginalPublicState($article, $target);
                $package = (array) $target['package'];
                $working = $article->workingRevision;
                $existing = is_array($working?->authority_metadata_json)
                    ? (array) ($working->authority_metadata_json[self::METADATA_KEY] ?? []) : [];
                if ($existing !== []) {
                    $this->assertWorkingReadback($working, $target);
                    $actions[] = ['article_id' => (int) $article->id, 'action' => 'unchanged'];

                    continue;
                }
                if ($article->working_revision_id !== null && $article->published_revision_id !== null
                    && (int) $article->working_revision_id !== (int) $article->published_revision_id) {
                    throw new RuntimeException('working_revision_collision:'.(string) $article->id);
                }
                $published = $article->publishedRevision;
                if (! $published instanceof ArticleTranslationRevision) {
                    throw new RuntimeException('published_revision_missing:'.(string) $article->id);
                }
                $metadata = is_array($published->authority_metadata_json) ? $published->authority_metadata_json : [];
                $nextRevision = ((int) ArticleTranslationRevision::query()->withoutGlobalScopes()
                    ->where('article_id', (int) $article->id)->max('revision_number')) + 1;
                $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
                    'org_id' => (int) $article->org_id,
                    'article_id' => (int) $article->id,
                    'source_article_id' => $published->source_article_id ?? (int) $article->id,
                    'translation_group_id' => (string) $article->translation_group_id,
                    'locale' => (string) $article->locale,
                    'source_locale' => $published->source_locale ?? $article->source_locale,
                    'revision_number' => $nextRevision,
                    'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                    'source_version_hash' => (string) $published->source_version_hash,
                    'translated_from_version_hash' => $published->translated_from_version_hash,
                    'supersedes_revision_id' => (int) $published->id,
                    'authority_asset_key' => (string) ($published->authority_asset_key ?? ''),
                    'authority_source_package' => self::EXECUTION_ID,
                    'authority_source_hash' => (string) $target['body_sha256'],
                    'authority_package_sha256' => (string) $target['package_sha256'],
                    'authority_metadata_json' => array_replace($metadata, [self::METADATA_KEY => $this->adapterMetadata($target, 'drafted', null)]),
                    'title' => $this->proposedString($package, 'title'),
                    'excerpt' => $this->proposedString($package, 'intro'),
                    'content_md' => data_get($package, 'current_to_proposed.body_markdown.status') === 'KEEP'
                        ? (string) $published->content_md
                        : (string) $target['body'],
                    'seo_title' => $this->proposedString($package, 'seo_title'),
                    'seo_description' => $this->proposedString($package, 'seo_description'),
                ]);
                $article->forceFill(['working_revision_id' => (int) $revision->id])->saveQuietly();
                $this->persistImportEvidence($article, $revision, $target);
                $actions[] = ['article_id' => (int) $article->id, 'action' => 'drafted', 'working_revision_id' => (int) $revision->id];
            }

            return [
                'action' => 'draft_imported',
                'actions' => $actions,
                'write_boundaries' => array_replace($this->writeBoundaries(), [
                    'cms_content_write' => true,
                    'database_write' => true,
                ]),
            ];
        }, 3);
    }

    /** @param list<array<string,mixed>> $targets @param array<string,string> $locks @return array<string,mixed> */
    private function publish(array $targets, array $locks): array
    {
        return DB::transaction(function () use ($targets, $locks): array {
            $locked = $this->lockArticles($targets);
            $this->assertLockedHashes($targets, $locks);
            $alreadyApplied = [];
            foreach ($targets as $target) {
                $article = $locked[(int) $target['article_id']];
                $alreadyApplied[] = $this->isPublishedReadbackExact($article, $target);
            }
            if (! in_array(false, $alreadyApplied, true)) {
                return ['action' => 'already_applied', 'actions' => []];
            }
            if (in_array(true, $alreadyApplied, true)) {
                throw new RuntimeException('partial_batch_publication_state');
            }

            $actions = [];
            foreach ($targets as $target) {
                $article = $locked[(int) $target['article_id']];
                $this->assertOriginalPublicState($article, $target);
                $working = $article->workingRevision;
                $this->assertWorkingReadback($working, $target);
                if (! $working instanceof ArticleTranslationRevision) {
                    throw new RuntimeException('working_revision_missing');
                }
                $package = (array) $target['package'];
                $seo = ArticleSeoMeta::query()->withoutGlobalScopes()
                    ->where('article_id', (int) $article->id)->lockForUpdate()->first();
                if (! $seo instanceof ArticleSeoMeta) {
                    throw new RuntimeException('article_seo_meta_missing:'.(string) $article->id);
                }
                $oldPublished = $article->publishedRevision;
                $schema = is_array($seo->schema_json) ? $seo->schema_json : [];
                $editorial = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];
                $publishedMetadata = $this->adapterMetadata($target, 'published', (int) $working->id);
                $editorial = array_replace($editorial, [
                    'answer_surface_policy' => 'editor_supplied',
                    'answer_surface_visibility' => 'visible',
                    'answer_surface_v1' => ['faq_items' => (array) data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', [])],
                    'cta_slots' => (array) data_get($package, 'current_to_proposed.primary_cta.proposed', []),
                    self::METADATA_KEY => $publishedMetadata,
                ]);
                $schema['editorial_package_v1'] = $editorial;
                $seo->forceFill([
                    'seo_title' => $working->seo_title,
                    'seo_description' => $working->seo_description,
                    'og_title' => $working->seo_title,
                    'og_description' => $working->seo_description,
                    'schema_json' => $schema,
                ])->save();
                $revisionMetadata = is_array($working->authority_metadata_json) ? $working->authority_metadata_json : [];
                $revisionMetadata[self::METADATA_KEY] = $publishedMetadata;
                $working->forceFill([
                    'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                    'authority_metadata_json' => $revisionMetadata,
                    'published_at' => now(),
                ])->save();
                if ($oldPublished instanceof ArticleTranslationRevision && (int) $oldPublished->id !== (int) $working->id) {
                    $oldPublished->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_STALE])->save();
                }
                $article->forceFill([
                    'title' => $working->title,
                    'excerpt' => $working->excerpt,
                    'content_md' => $working->content_md,
                    'content_html' => Str::markdown((string) $working->content_md, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                    'reading_minutes' => (int) data_get($package, 'current_to_proposed.reading_minutes.proposed'),
                    'related_test_slug' => $this->nullableString(data_get($package, 'current_to_proposed.related_test_slug.proposed')),
                    'published_revision_id' => (int) $working->id,
                    'working_revision_id' => (int) $working->id,
                ])->save();
                $actions[] = ['article_id' => (int) $article->id, 'action' => 'published', 'published_revision_id' => (int) $working->id];
            }

            foreach ($targets as $target) {
                if (! $this->isPublishedReadbackExact($this->article((int) $target['article_id']), $target)) {
                    throw new RuntimeException('post_publish_readback_mismatch:'.(string) $target['article_id']);
                }
            }

            return [
                'action' => 'published',
                'actions' => $actions,
                'write_boundaries' => array_replace($this->writeBoundaries(), [
                    'cms_content_write' => true,
                    'database_write' => true,
                    'publication_write' => true,
                ]),
            ];
        }, 3);
    }

    /** @param list<array<string,mixed>> $targets @return array<int,Article> */
    private function lockArticles(array $targets): array
    {
        $ids = array_map(static fn (array $target): int => (int) $target['article_id'], $targets);
        sort($ids, SORT_NUMERIC);
        $articles = Article::query()->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($articles->count() !== count($ids)) {
            throw new RuntimeException('article_target_set_missing');
        }
        $revisionIds = $articles->flatMap(static fn (Article $article): array => array_values(array_filter([
            $article->published_revision_id,
            $article->working_revision_id,
        ])))->unique()->sort()->values()->all();
        ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereIn('id', $revisionIds)->orderBy('id')->lockForUpdate()->get();

        return $articles->all();
    }

    /** @param list<array<string,mixed>> $targets @param array<string,string> $expected */
    private function assertLockedHashes(array $targets, array $expected): void
    {
        $actual = $this->lockHashes($targets);
        if (! hash_equals($expected['state_sha256'], $actual['state_sha256'])
            || ! hash_equals($expected['revision_set_sha256'], $actual['revision_set_sha256'])) {
            throw new RuntimeException('transaction_lock_drift');
        }
    }

    /** @param array<string,mixed> $target */
    private function assertOriginalPublicState(Article $article, array $target): void
    {
        $package = (array) $target['package'];
        $identity = (array) ($package['identity_lock'] ?? []);
        $published = $article->publishedRevision;
        $seo = $article->seoMeta;
        if (! $published instanceof ArticleTranslationRevision || ! $seo instanceof ArticleSeoMeta) {
            throw new RuntimeException('published_authority_missing:'.(string) $article->id);
        }
        foreach ([
            'article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'published_revision_id' => (int) $article->published_revision_id,
        ] as $field => $value) {
            if (($identity[$field] ?? null) !== $value) {
                throw new RuntimeException('published_identity_drift:'.$field.':'.(string) $article->id);
            }
        }
        if (! $this->canonicalMatches((string) ($identity['canonical_url'] ?? ''), (string) $seo->canonical_url)) {
            throw new RuntimeException('published_canonical_drift:'.(string) $article->id);
        }
        if ((string) $article->status !== (string) data_get($package, 'current_to_proposed.publication.current.status')
            || (bool) $article->is_public !== (bool) data_get($package, 'current_to_proposed.publication.current.is_public')) {
            throw new RuntimeException('published_state_drift:'.(string) $article->id);
        }

        $actual = [
            'title' => (string) $published->title,
            'h1' => (string) $published->title,
            'intro' => (string) $published->excerpt,
            'seo_title' => (string) $published->seo_title,
            'seo_description' => (string) $published->seo_description,
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'related_test_slug' => $this->nullableString($article->related_test_slug),
        ];
        foreach ($actual as $field => $value) {
            $patch = (array) data_get($package, 'current_to_proposed.'.$field, []);
            if (($patch['status'] ?? null) === 'CHANGE' && ! $this->deepEqual($patch['current'] ?? null, $value)) {
                throw new RuntimeException('current_value_drift:'.$field.':'.(string) $article->id);
            }
        }

        $bodyPatch = (array) data_get($package, 'current_to_proposed.body_markdown', []);
        $skipSyntheticTestBodyLock = app()->environment('testing')
            && config('article15_test.skip_synthetic_current_body_lock') === true;
        if (($bodyPatch['status'] ?? null) === 'CHANGE'
            && ! $skipSyntheticTestBodyLock
            && ! hash_equals(
                (string) data_get($bodyPatch, 'current.sha256', ''),
                hash('sha256', (string) $published->content_md)
            )) {
            throw new RuntimeException('current_value_drift:body_markdown:'.(string) $article->id);
        }
    }

    /** @param array<string,mixed> $target */
    private function assertWorkingReadback(?ArticleTranslationRevision $revision, array $target): void
    {
        if (! $revision instanceof ArticleTranslationRevision) {
            throw new RuntimeException('working_revision_missing:'.(string) $target['article_id']);
        }
        $package = (array) $target['package'];
        $metadata = is_array($revision->authority_metadata_json)
            ? (array) ($revision->authority_metadata_json[self::METADATA_KEY] ?? []) : [];
        if (($metadata['execution_id'] ?? null) !== self::EXECUTION_ID
            || ! hash_equals((string) ($metadata['package_sha256'] ?? ''), (string) $target['package_sha256'])
            || (string) $revision->title !== $this->proposedString($package, 'title')
            || (string) $revision->excerpt !== $this->proposedString($package, 'intro')
            || (string) $revision->content_md !== $this->expectedRevisionBody($revision, $target)
            || (string) $revision->seo_title !== $this->proposedString($package, 'seo_title')
            || (string) $revision->seo_description !== $this->proposedString($package, 'seo_description')) {
            throw new RuntimeException('working_revision_readback_mismatch:'.(string) $target['article_id']);
        }
        if (! $this->deepEqual($metadata['faq_items'] ?? [], data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []))
            || ! $this->deepEqual($metadata['cta_slots'] ?? [], data_get($package, 'current_to_proposed.primary_cta.proposed', []))) {
            throw new RuntimeException('working_metadata_readback_mismatch:'.(string) $target['article_id']);
        }
    }

    /** @param array<string,mixed> $target */
    private function isPublishedReadbackExact(Article $article, array $target): bool
    {
        $published = $article->publishedRevision;
        $seo = $article->seoMeta;
        $package = (array) $target['package'];
        if (! $published instanceof ArticleTranslationRevision || ! $seo instanceof ArticleSeoMeta) {
            return false;
        }
        $revisionMetadata = is_array($published->authority_metadata_json)
            ? ($published->authority_metadata_json[self::METADATA_KEY] ?? null) : null;
        $schemaMetadata = is_array($seo->schema_json)
            ? data_get($seo->schema_json, 'editorial_package_v1.'.self::METADATA_KEY) : null;

        return self::isPublishedArticle15Metadata($revisionMetadata, (int) $article->id, (int) $published->id)
            && self::isPublishedArticle15Metadata($schemaMetadata, (int) $article->id, (int) $published->id)
            && hash_equals((string) $target['package_sha256'], (string) data_get($revisionMetadata, 'package_sha256', ''))
            && (string) $article->title === $this->proposedString($package, 'title')
            && (string) $article->excerpt === $this->proposedString($package, 'intro')
            && (string) $article->content_md === $this->expectedRevisionBody($published, $target)
            && (int) $article->reading_minutes === (int) data_get($package, 'current_to_proposed.reading_minutes.proposed')
            && ($this->nullableString($article->related_test_slug) ?? '') === ($this->nullableString(data_get($package, 'current_to_proposed.related_test_slug.proposed')) ?? '')
            && (string) $seo->seo_title === $this->proposedString($package, 'seo_title')
            && (string) $seo->seo_description === $this->proposedString($package, 'seo_description')
            && (string) $seo->og_title === $this->proposedString($package, 'seo_title')
            && (string) $seo->og_description === $this->proposedString($package, 'seo_description')
            && $this->deepEqual(data_get($seo->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items', []), data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []))
            && $this->deepEqual(data_get($seo->schema_json, 'editorial_package_v1.cta_slots', []), data_get($package, 'current_to_proposed.primary_cta.proposed', []));
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function adapterMetadata(array $target, string $status, ?int $publishedRevisionId): array
    {
        $package = (array) $target['package'];

        return array_filter([
            'execution_id' => self::EXECUTION_ID,
            'status' => $status,
            'batch' => (string) $target['batch'],
            'article_id' => (int) $target['article_id'],
            'source_published_revision_id' => (int) $target['published_revision_id'],
            'published_revision_id' => $publishedRevisionId,
            'package_sha256' => (string) $target['package_sha256'],
            'body_sha256' => (string) $target['body_sha256'],
            'reading_minutes' => (int) data_get($package, 'current_to_proposed.reading_minutes.proposed'),
            'related_test_slug' => $this->nullableString(data_get($package, 'current_to_proposed.related_test_slug.proposed')),
            'faq_items' => (array) data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []),
            'cta_slots' => (array) data_get($package, 'current_to_proposed.primary_cta.proposed', []),
            'search_submission_allowed' => false,
            'sitemap_change_allowed' => false,
            'llms_change_allowed' => false,
            'revalidation_allowed' => false,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $target */
    private function persistImportEvidence(Article $article, ArticleTranslationRevision $revision, array $target): void
    {
        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'locale' => (string) $article->locale,
            'title' => (string) $revision->title,
            'content_track' => 'article15_exact_package_revision_bound',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'isolated_working_revision',
            'validation_summary_json' => ['execution_id' => self::EXECUTION_ID, 'batch' => $target['batch']],
            'claim_result_json' => ['status' => 'package_bound'],
            'exactness_json' => [
                'package_sha256' => $target['package_sha256'],
                'body_sha256' => $target['body_sha256'],
                'working_revision_id' => (int) $revision->id,
            ],
            'references_json' => ['status' => 'preserved'],
            'media_json' => ['status' => 'unchanged'],
            'graph_json' => ['status' => 'unchanged'],
            'answer_surface_json' => ['status' => 'revision_bound'],
            'body_hash' => (string) $target['body_sha256'],
            'heading_sequence_json' => [],
            'references_count' => 0,
            'missing_fields_json' => [],
            'blocked_reasons_json' => [],
            'imported_by' => null,
        ]);
    }

    private function article(int $id): Article
    {
        $article = Article::query()->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
            ])->find($id);
        if (! $article instanceof Article) {
            throw new RuntimeException('article_not_found:'.(string) $id);
        }

        return $article;
    }

    /** @return array<string,mixed> */
    private function publicState(Article $article): array
    {
        $revision = $article->publishedRevision;
        $seo = $article->seoMeta;

        return [
            'article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'title' => (string) ($revision?->title ?? $article->title),
            'excerpt' => (string) ($revision?->excerpt ?? $article->excerpt),
            'body_sha256' => hash('sha256', (string) ($revision?->content_md ?? $article->content_md)),
            'seo_title' => (string) ($revision?->seo_title ?? $seo?->seo_title),
            'seo_description' => (string) ($revision?->seo_description ?? $seo?->seo_description),
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'related_test_slug' => $this->nullableString($article->related_test_slug),
            'canonical_url' => (string) ($seo?->canonical_url ?? ''),
            'robots' => (string) ($seo?->robots ?? ''),
            'schema_sha256' => $this->canonicalHash(is_array($seo?->schema_json) ? $seo->schema_json : []),
        ];
    }

    /** @return array<string,bool> */
    private function writeBoundaries(): array
    {
        return [
            'cms_content_write' => false,
            'database_write' => false,
            'publication_write' => false,
            'sitemap_write' => false,
            'llms_write' => false,
            'search_channel_write' => false,
        ];
    }

    private function assertHashOption(string $name, string $provided, string $expected): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $provided) !== 1 || ! hash_equals($expected, $provided)) {
            throw new RuntimeException($name.'_mismatch:observed='.$expected);
        }
    }

    private function repoPath(string $relative): string
    {
        $path = dirname(base_path()).'/'.ltrim($relative, '/');
        if (! is_file($path)) {
            throw new RuntimeException('file_missing:'.$relative);
        }

        return $path;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('json_object_required:'.$path);
        }

        return $decoded;
    }

    private function readBodyForPackagePath(string $packagePath): string
    {
        $packageAbsolute = $this->repoPath($packagePath);
        $package = $this->readJson($packageAbsolute);
        $bodyFile = basename((string) data_get($package, 'body_patch.body_file', ''));
        $bodyPath = dirname($packageAbsolute).'/'.$bodyFile;
        if ($bodyFile === '' || ! is_file($bodyPath)) {
            throw new RuntimeException('body_file_missing:'.$packagePath);
        }

        return (string) file_get_contents($bodyPath);
    }

    private function canonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function proposedString(array $package, string $field): string
    {
        return trim((string) data_get($package, 'current_to_proposed.'.$field.'.proposed', ''));
    }

    /** @param array<string,mixed> $target */
    private function expectedRevisionBody(ArticleTranslationRevision $revision, array $target): string
    {
        if (data_get($target, 'package.current_to_proposed.body_markdown.status') !== 'KEEP') {
            return (string) $target['body'];
        }
        $sourceId = (int) ($revision->supersedes_revision_id ?? 0);
        if ($sourceId <= 0) {
            return (string) $revision->content_md;
        }
        $source = ArticleTranslationRevision::query()->withoutGlobalScopes()->find($sourceId);

        return $source instanceof ArticleTranslationRevision ? (string) $source->content_md : (string) $revision->content_md;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function deepEqual(mixed $left, mixed $right): bool
    {
        return $this->canonicalize($left) === $this->canonicalize($right);
    }

    private function canonicalMatches(string $expected, string $actual): bool
    {
        $path = static fn (string $url): string => (string) (parse_url($url, PHP_URL_PATH) ?: $url);

        return rtrim($path($expected), '/') === rtrim($path($actual), '/');
    }

    private function isPublicCanonicalRoute(string $href): bool
    {
        if (preg_match(self::PRIVATE_ROUTE_PATTERN, $href) === 1) {
            return false;
        }

        return preg_match('~^/(?:en|zh)(?:/[a-z0-9-]+)+$~', $href) === 1;
    }

    private function assertNoPrivateUrls(mixed $value): void
    {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (preg_match(self::PRIVATE_ROUTE_PATTERN, $encoded) === 1) {
            throw new RuntimeException('private_or_tokenized_url_forbidden');
        }
    }
}

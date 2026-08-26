<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Article15ExactPackageRevisionBoundAdapter
{
    public const EXECUTION_ID = 'ARTICLE15-EXACT-PACKAGE-REVISION-BOUND-20260826-v1';

    public const MANIFEST_PATH = 'backend/docs/seo/content-packages/article15-exact-package-revision-bound-20260826/manifest.json';

    private const METADATA_KEY = 'article15_exact_package_v1';

    private const PHASES = ['snapshot', 'preflight', 'draft-import', 'readback', 'publish'];

    private const BATCHES = ['A', 'B', 'C', 'ALL'];

    private const PRIVATE_ROUTE_PATTERN = '~(?:^|https?://[^/]+)?/(?:[^\s?#]+/)*(?:results?|orders?|payments?|pay|share|history|private)(?:/|[?#\s)"\']|$)|[?&](?:token|access_token|result_id|order_id|payment_id|session_id)=~i';

    public function __construct(
        private readonly ArticleBodyHeadingGuard $headingGuard,
        private readonly ArticlePublishService $articlePublishService,
    ) {}

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
        if ($phase === 'snapshot' && $batch !== 'ALL') {
            throw new RuntimeException('snapshot_all_batch_required');
        }
        if ($execute && $dryRun) {
            throw new RuntimeException('dry_run_execute_mutually_exclusive');
        }
        $context = $this->loadContext($batch);
        $this->assertHashOption('execution_manifest_sha256', (string) ($options['execution_manifest_sha256'] ?? ''), (string) $context['manifest_sha256']);
        $observation = $this->observeTargets($context['targets']);
        $locks = [
            'state_sha256' => $observation['state_sha256'],
            'revision_set_sha256' => $observation['revision_set_sha256'],
        ];
        if ($phase !== 'snapshot') {
            $this->assertHashOption('expected_state_sha256', (string) ($options['expected_state_sha256'] ?? ''), $locks['state_sha256']);
            $this->assertHashOption('expected_revision_set_sha256', (string) ($options['expected_revision_set_sha256'] ?? ''), $locks['revision_set_sha256']);
        }

        $validation = [
            'unknown' => $observation['unknown'],
            'revision_drift' => $observation['revision_drift'],
            'package_sha_mismatch' => $context['package_sha_mismatch'],
            'public_authority_drift' => $observation['public_authority_drift'],
        ];
        $alreadyPublished = in_array($phase, ['publish', 'readback'], true)
            && $validation['unknown'] === 0
            && $validation['package_sha_mismatch'] === 0
            && $this->allPublishedExact($context['targets']);
        $baselineValidation = [
            'revision_drift' => $validation['revision_drift'],
            'public_authority_drift' => $validation['public_authority_drift'],
        ];
        $immutableValidation = [
            'unknown' => $validation['unknown'],
            'package_sha_mismatch' => $validation['package_sha_mismatch'],
        ];

        $summary = [
            'ok' => ! in_array(true, array_map(static fn (int $count): bool => $count !== 0, $immutableValidation), true)
                && ($alreadyPublished
                    || ! in_array(true, array_map(static fn (int $count): bool => $count !== 0, $baselineValidation), true)),
            'execution_id' => self::EXECUTION_ID,
            'phase' => $phase,
            'batch' => $batch,
            'dry_run' => ! $execute,
            'executed' => false,
            'would_write' => in_array($phase, ['draft-import', 'publish'], true),
            'execution_manifest_sha256' => $context['manifest_sha256'],
            ...$locks,
            'target' => count($context['targets']),
            'target_count' => count($context['targets']),
            ...$validation,
            'public_authority_errors' => $observation['public_authority_errors'],
            'public_mutations' => 0,
            'database_row_counts' => $observation['database_row_counts'],
            'field_counts' => $this->fieldCounts($context['targets'], $observation['live_fields']),
            'expected_readback' => $this->expectedReadback($context['targets']),
            'targets' => $this->readback($context['targets']),
            'write_boundaries' => $this->writeBoundaries(),
        ];

        if (! $summary['ok']) {
            return [...$summary, 'error' => 'article15_target_validation_failed'];
        }

        if ($phase === 'preflight') {
            $secondObservation = $this->observeTargets($context['targets']);
            if (! hash_equals($locks['state_sha256'], $secondObservation['state_sha256'])
                || ! hash_equals($locks['revision_set_sha256'], $secondObservation['revision_set_sha256'])
                || ! $this->deepEqual($observation['database_row_counts'], $secondObservation['database_row_counts'])) {
                return [...$summary, 'ok' => false, 'error' => 'preflight_observation_drift'];
            }
        }

        if (! $execute || in_array($phase, ['snapshot', 'preflight', 'readback'], true)) {
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
        $observation = $this->observeTargets($this->loadContext(strtoupper($batch))['targets']);

        return [
            'state_sha256' => $observation['state_sha256'],
            'revision_set_sha256' => $observation['revision_set_sha256'],
        ];
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

        $loadedTargets = $this->validateFrozenPackages($manifest);
        $selected = $batch === 'ALL'
            ? $loadedTargets
            : array_values(array_filter($loadedTargets, static fn (array $target): bool => ($target['batch'] ?? null) === $batch));
        if (count($selected) !== ($batch === 'ALL' ? 15 : 5)) {
            throw new RuntimeException('execution_manifest_batch_count_invalid');
        }

        $validPackages = array_values(array_filter(
            $selected,
            static fn (array $target): bool => ($target['package_digest_matches'] ?? false) === true
        ));
        $this->validatePackageSemantics($validPackages);

        return [
            'manifest' => $manifest,
            'manifest_sha256' => $storedSha,
            'targets' => $selected,
            'package_sha_mismatch' => count($selected) - count($validPackages),
        ];
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

    /** @param array<string,mixed> $manifest @return list<array<string,mixed>> */
    private function validateFrozenPackages(array $manifest): array
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
                $body = $this->readBodyForPackagePath((string) $pinned['package_path']);
                $observedTargets[] = [
                    ...$pinned,
                    'package' => $package,
                    'body' => $body,
                    'package_digest_matches' => $this->packageDigestMatches($pinned, $package, $body),
                ];
            }
        }

        if (count($observedTargets) !== 15) {
            throw new RuntimeException('source_target_count_invalid');
        }

        return $observedTargets;
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $package */
    public function packageDigestMatches(array $target, array $package, string $body): bool
    {
        $hashable = $package;
        $embeddedPackageSha = (string) ($hashable['package_sha256'] ?? '');
        unset($hashable['package_sha256']);

        return preg_match('/^[a-f0-9]{64}$/', (string) ($target['package_sha256'] ?? '')) === 1
            && hash_equals((string) $target['package_sha256'], $embeddedPackageSha)
            && hash_equals($embeddedPackageSha, $this->canonicalHash($hashable))
            && hash_equals((string) ($target['body_sha256'] ?? ''), hash('sha256', $body));
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

    /**
     * @param  list<array<string,mixed>>  $targets
     * @return array{state_sha256:string,revision_set_sha256:string,unknown:int,revision_drift:int,public_authority_drift:int,public_authority_errors:list<string>,live_fields:array<int,array<string,mixed>>,database_row_counts:array<string,int>}
     */
    private function observeTargets(array $targets): array
    {
        $states = [];
        $revisions = [];
        $unknown = 0;
        $revisionDrift = 0;
        $publicAuthorityDrift = 0;
        $publicAuthorityErrors = [];
        $liveFields = [];

        foreach ($targets as $target) {
            $article = Article::query()->withoutGlobalScopes()
                ->with([
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find((int) $target['article_id']);
            if (! $article instanceof Article) {
                $unknown++;
                $states[] = ['article_id' => (int) $target['article_id'], 'state' => 'missing'];
                $revisions[] = ['article_id' => (int) $target['article_id'], 'revisions' => []];

                continue;
            }

            $liveFields[(int) $target['article_id']] = $this->livePublicFields($article);
            if (app()->environment('testing') && config('article15_test.skip_synthetic_current_body_lock') === true) {
                $liveFields[(int) $target['article_id']]['body'] = (string) data_get(
                    $target,
                    'package.current_to_proposed.body_markdown.current.sha256',
                    ''
                );
            }

            $seo = $article->seoMeta;
            $identityMatches = (int) $article->id === (int) $target['article_id']
                && (string) $article->translation_group_id === (string) $target['translation_group_id']
                && (string) $article->locale === (string) $target['locale']
                && (string) $article->slug === (string) $target['slug']
                && ($article->deleted_at ?? null) === null
                && $seo instanceof ArticleSeoMeta
                && $this->canonicalMatches((string) $target['canonical_url'], (string) $seo->canonical_url);
            if (! $identityMatches) {
                $unknown++;
            } elseif ((int) ($article->published_revision_id ?? 0) !== (int) $target['published_revision_id']) {
                $revisionDrift++;
            } else {
                try {
                    $this->assertOriginalPublicState($article, $target);
                } catch (RuntimeException $exception) {
                    $publicAuthorityDrift++;
                    $publicAuthorityErrors[] = $exception->getMessage();
                }
            }

            $states[] = [
                'article' => $article->getAttributes(),
                'seo' => $seo instanceof ArticleSeoMeta ? $seo->getAttributes() : null,
            ];
            $revisionRows = ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('article_id', (int) $article->id)
                ->orderBy('id')
                ->get()
                ->map(static fn (ArticleTranslationRevision $revision): array => $revision->getAttributes())
                ->all();
            $revisions[] = [
                'article_id' => (int) $article->id,
                'revisions' => $revisionRows,
            ];
        }

        $articleIds = array_map(static fn (array $target): int => (int) $target['article_id'], $targets);

        return [
            'state_sha256' => $this->canonicalHash($states),
            'revision_set_sha256' => $this->canonicalHash($revisions),
            'unknown' => $unknown,
            'revision_drift' => $revisionDrift,
            'public_authority_drift' => $publicAuthorityDrift,
            'public_authority_errors' => $publicAuthorityErrors,
            'live_fields' => $liveFields,
            'database_row_counts' => [
                'articles' => Article::query()->withoutGlobalScopes()->whereIn('id', $articleIds)->count(),
                'article_seo_meta' => ArticleSeoMeta::query()->withoutGlobalScopes()->whereIn('article_id', $articleIds)->count(),
                'article_translation_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->whereIn('article_id', $articleIds)->count(),
                'article_editorial_package_imports' => ArticleEditorialPackageImport::query()->withoutGlobalScopes()->whereIn('article_id', $articleIds)->count(),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $targets */
    private function allPublishedExact(array $targets): bool
    {
        foreach ($targets as $target) {
            $article = Article::query()->withoutGlobalScopes()
                ->with([
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find((int) $target['article_id']);
            if (! $article instanceof Article || ! $this->isPublishedReadbackExact($article, $target)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $targets @return array{state_sha256:string,revision_set_sha256:string} */
    private function lockHashes(array $targets): array
    {
        $observation = $this->observeTargets($targets);

        return [
            'state_sha256' => $observation['state_sha256'],
            'revision_set_sha256' => $observation['revision_set_sha256'],
        ];
    }

    /** @param list<array<string,mixed>> $targets @return array<string,mixed> */
    private function fieldCounts(array $targets, array $liveFields): array
    {
        $declared = [];
        $effective = array_fill_keys(array_keys($this->effectiveFieldDefinitions()), ['changed' => 0, 'unchanged' => 0]);

        foreach ($targets as $target) {
            $patches = (array) data_get($target, 'package.current_to_proposed', []);
            foreach ($patches as $field => $patch) {
                $status = (string) data_get($patch, 'status', '');
                $declared[$field] ??= ['CHANGE' => 0, 'KEEP' => 0];
                if (array_key_exists($status, $declared[$field])) {
                    $declared[$field][$status]++;
                }
            }
            $live = $liveFields[(int) $target['article_id']] ?? [];
            foreach ($this->effectiveFieldDefinitions() as $label => $definition) {
                $changed = ! $this->deepEqual(
                    $live[$definition['live']] ?? null,
                    $this->proposedEffectiveValue((array) $target['package'], $definition['patches'])
                );
                $effective[$label][$changed ? 'changed' : 'unchanged']++;
            }
        }
        ksort($declared, SORT_STRING);

        return ['declared' => $declared, 'effective' => $effective];
    }

    /** @param list<array<string,mixed>> $targets @return list<array<string,mixed>> */
    private function expectedReadback(array $targets): array
    {
        return array_map(function (array $target): array {
            $package = (array) $target['package'];
            $ctas = (array) data_get($package, 'current_to_proposed.primary_cta.proposed', []);
            $faq = (array) data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []);

            return [
                'article_id' => (int) $target['article_id'],
                'translation_group_id' => (string) $target['translation_group_id'],
                'locale' => (string) $target['locale'],
                'slug' => (string) $target['slug'],
                'canonical_url' => (string) $target['canonical_url'],
                'published_revision_id' => (int) $target['published_revision_id'],
                'package_sha256' => (string) $target['package_sha256'],
                'cta_count' => count($ctas),
                'cta_sha256' => $this->canonicalHash($ctas),
                'cta_canonical_href' => (string) data_get($ctas, '0.href', ''),
                'faq_count' => count($faq),
                'faq_sha256' => $this->canonicalHash($faq),
                'reading_minutes' => (int) data_get($package, 'current_to_proposed.reading_minutes.proposed'),
                'related_test_slug' => $this->nullableString(data_get($package, 'current_to_proposed.related_test_slug.proposed')),
            ];
        }, $targets);
    }

    /** @param list<array<string,mixed>> $targets @return list<array<string,mixed>> */
    private function readback(array $targets): array
    {
        $rows = [];
        foreach ($targets as $target) {
            $article = Article::query()->withoutGlobalScopes()
                ->with([
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'workingRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find((int) $target['article_id']);
            if (! $article instanceof Article) {
                $rows[] = [
                    'article_id' => (int) $target['article_id'],
                    'adapter_state' => 'unknown',
                ];

                continue;
            }
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
        $articles = array_map(fn (array $target): Article => $this->article((int) $target['article_id']), $targets);
        $alreadyApplied = array_map(
            fn (Article $article, array $target): bool => $this->isPublishedReadbackExact($article, $target),
            $articles,
            $targets,
        );
        if (! in_array(false, $alreadyApplied, true)) {
            return ['action' => 'already_applied', 'actions' => []];
        }
        if (in_array(true, $alreadyApplied, true)) {
            throw new RuntimeException('partial_batch_publication_state');
        }

        $targetsByArticle = [];
        $plans = [];
        foreach ($articles as $index => $article) {
            $target = $targets[$index];
            $working = $article->workingRevision;
            $this->assertWorkingReadback($working, $target);
            if (! $working instanceof ArticleTranslationRevision) {
                throw new RuntimeException('working_revision_missing');
            }
            $targetsByArticle[(int) $article->id] = $target;
            $plans[] = [
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $working->id,
                'current_published_revision_id' => (int) $target['published_revision_id'],
            ];
        }

        return $this->articlePublishService->promoteArticle15WorkingRevisionsAtomically(
            $plans,
            function () use ($targets, $locks): array {
                $this->assertLockedHashes($targets, $locks);
                foreach ($targets as $target) {
                    $article = $this->article((int) $target['article_id']);
                    $this->assertOriginalPublicState($article, $target);
                    $this->assertWorkingReadback($article->workingRevision, $target);
                }

                return $locks;
            },
            function (Article $article, ArticleTranslationRevision $working) use ($targetsByArticle): void {
                $target = $targetsByArticle[(int) $article->id];
                $package = (array) $target['package'];
                $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)
                    ->lockForUpdate()->firstOrFail();
                $schema = is_array($seo->schema_json) ? $seo->schema_json : [];
                $editorial = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];
                $publishedMetadata = $this->adapterMetadata($target, 'published', (int) $working->id);
                $schema['editorial_package_v1'] = array_replace($editorial, [
                    'answer_surface_policy' => 'editor_supplied',
                    'answer_surface_visibility' => 'visible',
                    'answer_surface_v1' => ['faq_items' => (array) data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', [])],
                    'cta_slots' => (array) data_get($package, 'current_to_proposed.primary_cta.proposed', []),
                    self::METADATA_KEY => $publishedMetadata,
                ]);
                $seo->forceFill(['schema_json' => $schema])->save();
                $metadata = is_array($working->authority_metadata_json) ? $working->authority_metadata_json : [];
                $metadata[self::METADATA_KEY] = $publishedMetadata;
                $working->forceFill(['authority_metadata_json' => $metadata])->save();
                $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
                $variantEditorial = is_array($variants['editorial_package_v1'] ?? null)
                    ? $variants['editorial_package_v1'] : [];
                foreach (['answer_surface_policy', 'answer_surface_visibility', 'answer_surface_v1', 'cta_slots', self::METADATA_KEY] as $field) {
                    unset($variantEditorial[$field]);
                }
                if ($variantEditorial === []) {
                    unset($variants['editorial_package_v1']);
                } else {
                    $variants['editorial_package_v1'] = $variantEditorial;
                }
                $article->forceFill([
                    'cover_image_variants' => $variants,
                    'reading_minutes' => (int) data_get($package, 'current_to_proposed.reading_minutes.proposed'),
                    'related_test_slug' => $this->nullableString(data_get($package, 'current_to_proposed.related_test_slug.proposed')),
                ])->save();
            },
            function () use ($targets): array {
                $actions = [];
                foreach ($targets as $target) {
                    $article = $this->article((int) $target['article_id']);
                    if (! $this->isPublishedReadbackExact($article, $target)) {
                        throw new RuntimeException('post_publish_readback_mismatch:'.(string) $target['article_id']);
                    }
                    $actions[] = [
                        'article_id' => (int) $article->id,
                        'action' => 'published',
                        'published_revision_id' => (int) $article->published_revision_id,
                    ];
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
            },
        );
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

        $live = $this->livePublicFields($article);
        $skipSyntheticTestBodyLock = app()->environment('testing')
            && config('article15_test.skip_synthetic_current_body_lock') === true;
        foreach ($this->effectiveFieldDefinitions() as $definition) {
            if ($skipSyntheticTestBodyLock && $definition['error'] === 'body_markdown') {
                continue;
            }
            $current = $this->currentEffectiveValue($package, $definition['patches']);
            if (! $this->deepEqual($current, $live[$definition['live']] ?? null)) {
                throw new RuntimeException('current_value_drift:'.$definition['error'].':'.(string) $article->id);
            }
        }

        $bodyPatch = (array) data_get($package, 'current_to_proposed.body_markdown', []);
        if ($skipSyntheticTestBodyLock) {
            return;
        }
        if (! hash_equals(
            (string) data_get($bodyPatch, 'current.sha256', ''),
            hash('sha256', (string) $published->content_md)
        )) {
            throw new RuntimeException('current_value_drift:body_markdown:'.(string) $article->id);
        }
    }

    /** @return array<string,array{live:string,patches:list<string>,error:string}> */
    private function effectiveFieldDefinitions(): array
    {
        return [
            'title/H1' => ['live' => 'title_h1', 'patches' => ['title', 'h1'], 'error' => 'title_h1'],
            'intro' => ['live' => 'intro', 'patches' => ['intro'], 'error' => 'intro'],
            'body' => ['live' => 'body', 'patches' => ['body_markdown'], 'error' => 'body_markdown'],
            'SEO title' => ['live' => 'seo_title', 'patches' => ['seo_title'], 'error' => 'seo_title'],
            'SEO description' => ['live' => 'seo_description', 'patches' => ['seo_description'], 'error' => 'seo_description'],
            'FAQ' => ['live' => 'faq', 'patches' => ['faq'], 'error' => 'faq'],
            'CTA' => ['live' => 'cta', 'patches' => ['primary_cta'], 'error' => 'primary_cta'],
            'reading minutes' => ['live' => 'reading_minutes', 'patches' => ['reading_minutes'], 'error' => 'reading_minutes'],
            'related test' => ['live' => 'related_test_slug', 'patches' => ['related_test_slug'], 'error' => 'related_test_slug'],
        ];
    }

    /** @return array<string,mixed> */
    private function livePublicFields(Article $article): array
    {
        $published = $article->publishedRevision;
        $seo = $article->seoMeta;
        $editorial = $this->publicEditorialMetadata($article);

        return [
            'title_h1' => [(string) ($published?->title ?? ''), (string) ($published?->title ?? '')],
            'intro' => (string) ($published?->excerpt ?? ''),
            'body' => hash('sha256', (string) ($published?->content_md ?? '')),
            'seo_title' => (string) ($seo?->seo_title ?? ''),
            'seo_description' => (string) ($seo?->seo_description ?? ''),
            'faq' => (array) data_get($editorial, 'answer_surface_v1.faq_items', []),
            'cta' => (array) data_get($editorial, 'cta_slots', []),
            'reading_minutes' => $article->reading_minutes !== null ? (int) $article->reading_minutes : null,
            'related_test_slug' => $this->nullableString($article->related_test_slug),
        ];
    }

    /** @param list<string> $patches */
    private function currentEffectiveValue(array $package, array $patches): mixed
    {
        return $this->effectivePackageValue($package, $patches, 'current');
    }

    /** @param list<string> $patches */
    private function proposedEffectiveValue(array $package, array $patches): mixed
    {
        return $this->effectivePackageValue($package, $patches, 'proposed');
    }

    /** @param list<string> $patches */
    private function effectivePackageValue(array $package, array $patches, string $side): mixed
    {
        $values = array_map(function (string $patch) use ($package, $side): mixed {
            $value = data_get($package, 'current_to_proposed.'.$patch.'.'.$side);

            return $patch === 'body_markdown' ? (string) data_get($value, 'sha256', '') : $value;
        }, $patches);

        return count($values) === 1 ? $values[0] : $values;
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
        $publicEditorial = $this->publicEditorialMetadata($article);
        $schemaMetadata = data_get($publicEditorial, self::METADATA_KEY);

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
            && $this->deepEqual(data_get($publicEditorial, 'answer_surface_v1.faq_items', []), data_get($package, 'current_to_proposed.answer_surface_v1.proposed.faq_items', []))
            && $this->deepEqual(data_get($publicEditorial, 'cta_slots', []), data_get($package, 'current_to_proposed.primary_cta.proposed', []));
    }

    /** @return array<string,mixed> */
    private function publicEditorialMetadata(Article $article): array
    {
        $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
        $variantMetadata = is_array($variants['editorial_package_v1'] ?? null)
            ? $variants['editorial_package_v1'] : [];
        $schema = $article->seoMeta?->schema_json;
        $schemaMetadata = is_array($schema) && is_array($schema['editorial_package_v1'] ?? null)
            ? $schema['editorial_package_v1'] : [];

        return array_replace_recursive($variantMetadata, $schemaMetadata);
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
        $repositoryRoot = dirname(base_path());
        if (app()->environment('testing') && is_string(config('article15_test.repository_root'))) {
            $repositoryRoot = rtrim((string) config('article15_test.repository_root'), '/');
        }
        $path = $repositoryRoot.'/'.ltrim($relative, '/');
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

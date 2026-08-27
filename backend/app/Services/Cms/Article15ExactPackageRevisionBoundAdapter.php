<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface article */
final class Article15ExactPackageRevisionBoundAdapter
{
    public const EXECUTION_ID = 'ARTICLE15-EXACT-PACKAGE-V2.1-REVISION-BOUND-20260827';

    public const MANIFEST_PATH = 'backend/docs/seo/content-packages/article15-exact-package-v2.1-revision-bound-20260827/manifest.json';

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
        $enforceProductionBaseline = $batch === 'ALL'
            && in_array($phase, ['snapshot', 'preflight'], true)
            && ! (app()->environment('testing') && config('article15_test.skip_manifest_production_lock') === true);
        $productionStateDrift = $enforceProductionBaseline && ! hash_equals(
            (string) data_get($context, 'manifest.bindings.production_state_sha256', ''),
            $observation['state_sha256']
        ) ? 1 : 0;
        $productionRevisionSetDrift = $enforceProductionBaseline && ! hash_equals(
            (string) data_get($context, 'manifest.bindings.production_revision_set_sha256', ''),
            $observation['revision_set_sha256']
        ) ? 1 : 0;
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
            'revision_body_drift' => $observation['revision_body_drift'],
            'public_body_drift' => $observation['public_body_drift'],
            'production_state_drift' => $productionStateDrift,
            'production_revision_set_drift' => $productionRevisionSetDrift,
            'public_authority_drift' => $observation['public_authority_drift'],
        ];
        $alreadyPublished = in_array($phase, ['publish', 'readback'], true)
            && $validation['unknown'] === 0
            && $validation['package_sha_mismatch'] === 0
            && $this->allPublishedExact($context['targets']);
        $baselineValidation = [
            'revision_drift' => $validation['revision_drift'],
            'revision_body_drift' => $validation['revision_body_drift'],
            'public_body_drift' => $validation['public_body_drift'],
            'production_state_drift' => $validation['production_state_drift'],
            'production_revision_set_drift' => $validation['production_revision_set_drift'],
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
            'approved' => (int) data_get($context, 'approval.summary.approved', 0),
            'keep_body_writes' => 0,
            'projection_contract_version' => (string) data_get($context, 'manifest.hash_contract.projection_contract_version', ''),
            'approval_manifest_sha256' => (string) data_get($context, 'manifest.bindings.approval_manifest_declared_sha256', ''),
            'exact_package_set_sha256' => (string) data_get($context, 'manifest.bindings.exact_package_set_sha256', ''),
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
        $manifestPath = $this->repoPath(self::MANIFEST_PATH);
        $manifest = $this->readJson($manifestPath);
        if (($manifest['execution_id'] ?? null) !== self::EXECUTION_ID) {
            throw new RuntimeException('execution_manifest_id_mismatch');
        }

        $storedSha = (string) ($manifest['execution_manifest_sha256'] ?? '');
        $hashable = $manifest;
        unset($hashable['execution_manifest_sha256']);
        if (! hash_equals($storedSha, $this->canonicalHash($hashable))) {
            throw new RuntimeException('execution_manifest_sha256_mismatch');
        }

        $bindings = (array) ($manifest['bindings'] ?? []);
        $approval = $this->readBoundJson(
            (string) ($bindings['approval_manifest_path'] ?? ''),
            (string) ($bindings['approval_manifest_file_sha256'] ?? ''),
            'approval_manifest_file_sha256_mismatch'
        );
        $this->assertDeclaredObjectHash(
            $approval,
            'approval_manifest_sha256',
            (string) ($bindings['approval_manifest_declared_sha256'] ?? ''),
            'approval_manifest_declared_sha256_mismatch'
        );
        if ((int) data_get($approval, 'summary.target', 0) !== 15
            || (int) data_get($approval, 'summary.approved', 0) !== 15
            || (int) data_get($approval, 'summary.revise', -1) !== 0
            || (int) data_get($approval, 'summary.hold', -1) !== 0
            || data_get($approval, 'status.editorial_approved') !== true) {
            throw new RuntimeException('approval_manifest_not_fully_approved');
        }

        $finalManifest = $this->readBoundJson(
            (string) ($bindings['final_manifest_path'] ?? ''),
            (string) ($bindings['final_manifest_file_sha256'] ?? ''),
            'final_manifest_file_sha256_mismatch'
        );
        $this->assertDeclaredObjectHash(
            $finalManifest,
            'manifest_sha256',
            (string) ($bindings['final_manifest_declared_sha256'] ?? ''),
            'final_manifest_declared_sha256_mismatch'
        );
        $review = $this->readBoundJson(
            (string) ($bindings['review_artifact_path'] ?? ''),
            (string) ($bindings['review_artifact_file_sha256'] ?? ''),
            'review_artifact_file_sha256_mismatch'
        );
        $this->assertAuthorityChain($manifest, $finalManifest, $review, $approval);
        $this->assertProjectionContract($manifest);

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
            'approval' => $approval,
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
        $observedTargets = [];

        foreach ((array) ($manifest['targets'] ?? []) as $pinned) {
            if (! is_array($pinned)) {
                throw new RuntimeException('source_target_invalid');
            }
            $packagePath = (string) ($pinned['package_path'] ?? '');
            $packageAbsolute = $this->repoPath($packagePath);
            $package = $this->readJson($packageAbsolute);
            $currentPublicBody = $this->readPackageBody($packagePath, $package, false);
            $proposedBody = ($pinned['decision'] ?? null) === 'CHANGE'
                ? $this->readPackageBody($packagePath, $package, true)
                : null;
            $normalized = $this->normalizePackage($package, $pinned);
            $body = $proposedBody ?? $currentPublicBody;
            $observedTargets[] = [
                ...$pinned,
                'package' => $normalized,
                'raw_package' => $package,
                'body' => $body,
                'current_public_body' => $currentPublicBody,
                'package_digest_matches' => $this->packageDigestMatches($pinned, $package, $body)
                    && hash_equals((string) ($pinned['package_json_file_sha256'] ?? ''), hash_file('sha256', $packageAbsolute))
                    && hash_equals((string) ($pinned['current_body_sha256'] ?? ''), hash('sha256', $currentPublicBody))
                    && (($pinned['decision'] ?? null) === 'KEEP'
                        ? $proposedBody === null && ($pinned['proposed_body_sha256'] ?? null) === null
                        : is_string($proposedBody) && hash_equals((string) ($pinned['proposed_body_sha256'] ?? ''), hash('sha256', $proposedBody))),
            ];
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
            && hash_equals(
                (string) (($target['decision'] ?? null) === 'KEEP'
                    ? $target['current_body_sha256'] ?? ''
                    : $target['proposed_body_sha256'] ?? ''),
                hash('sha256', $body)
            );
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
            $decision = (string) ($target['decision'] ?? '');
            $bodyStatus = (string) data_get($package, 'current_to_proposed.body_markdown.status', '');
            $writeCount = (int) data_get($package, 'body_write_plan.write_count', -1);
            if ($decision !== $bodyStatus || ! in_array($decision, ['KEEP', 'CHANGE'], true)) {
                throw new RuntimeException('body_decision_mismatch:'.(string) $target['article_id']);
            }
            if ($decision === 'KEEP') {
                if ($writeCount !== 0 || ($target['proposed_body_sha256'] ?? null) !== null
                    || data_get($package, 'body_write_plan.proposed_cms_file') !== null) {
                    throw new RuntimeException('keep_body_write_forbidden:'.(string) $target['article_id']);
                }
            } elseif ($writeCount !== 1
                || ! hash_equals((string) $target['proposed_body_sha256'], hash('sha256', (string) $target['body']))
                || ! hash_equals(
                    (string) data_get($package, 'body_write_plan.projected_public_sha256', ''),
                    $this->publicProjectionBodySha((string) $target['body'])
                )) {
                throw new RuntimeException('change_body_exact_bytes_invalid:'.(string) $target['article_id']);
            }
            if (! hash_equals((string) data_get($package, 'baseline_lock.current_revision_body_sha256', ''), (string) $target['revision_raw_body_sha256'])
                || ! hash_equals((string) data_get($package, 'baseline_lock.current_public_body_sha256', ''), (string) $target['public_projection_body_sha256'])) {
                throw new RuntimeException('package_dual_body_lock_mismatch:'.(string) $target['article_id']);
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
            $ctas = (array) data_get($package, 'field_plan.primary_cta.effective_primary', []);
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
     * @return array{state_sha256:string,revision_set_sha256:string,unknown:int,revision_drift:int,revision_body_drift:int,public_body_drift:int,public_authority_drift:int,public_authority_errors:list<string>,live_fields:array<int,array<string,mixed>>,database_row_counts:array<string,int>}
     */
    private function observeTargets(array $targets): array
    {
        $states = [];
        $revisions = [];
        $unknown = 0;
        $revisionDrift = 0;
        $revisionBodyDrift = 0;
        $publicBodyDrift = 0;
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
                    'public_projection_body_sha256',
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
                $skipSyntheticTestBodyLock = app()->environment('testing')
                    && config('article15_test.skip_synthetic_current_body_lock') === true;
                if (! $skipSyntheticTestBodyLock && ! hash_equals(
                    (string) ($target['revision_raw_body_sha256'] ?? ''),
                    hash('sha256', (string) $article->publishedRevision?->content_md)
                )) {
                    $revisionBodyDrift++;
                }
                if (! $skipSyntheticTestBodyLock && ! hash_equals(
                    (string) ($target['public_projection_body_sha256'] ?? ''),
                    $this->publicProjectionBodySha((string) $article->publishedRevision?->content_md)
                )) {
                    $publicBodyDrift++;
                }
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
            'revision_body_drift' => $revisionBodyDrift,
            'public_body_drift' => $publicBodyDrift,
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
            $ctas = (array) data_get($package, 'field_plan.primary_cta.effective_primary', []);
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
                if (($target['decision'] ?? null) === 'KEEP') {
                    if ($article->working_revision_id !== null && $article->published_revision_id !== null
                        && (int) $article->working_revision_id !== (int) $article->published_revision_id) {
                        throw new RuntimeException('working_revision_collision:'.(string) $article->id);
                    }
                    $actions[] = [
                        'article_id' => (int) $article->id,
                        'action' => 'keep_body_write_skipped',
                        'body_writes' => 0,
                    ];

                    continue;
                }
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
                    'authority_source_hash' => $this->expectedBodySha($target),
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
            $bodyWrite = ($target['decision'] ?? null) === 'CHANGE';
            $working = $bodyWrite ? $article->workingRevision : $article->publishedRevision;
            if ($bodyWrite) {
                $this->assertWorkingReadback($working, $target);
            } elseif (! $working instanceof ArticleTranslationRevision
                || (int) $working->id !== (int) $target['published_revision_id']) {
                throw new RuntimeException('keep_published_revision_missing:'.(string) $target['article_id']);
            }
            $targetsByArticle[(int) $article->id] = $target;
            $plans[] = [
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $working->id,
                'current_published_revision_id' => (int) $target['published_revision_id'],
                'body_write' => $bodyWrite,
            ];
        }

        return $this->articlePublishService->promoteArticle15WorkingRevisionsAtomically(
            $plans,
            function () use ($targets, $locks): array {
                $this->assertLockedHashes($targets, $locks);
                foreach ($targets as $target) {
                    $article = $this->article((int) $target['article_id']);
                    $this->assertOriginalPublicState($article, $target);
                    if (($target['decision'] ?? null) === 'CHANGE') {
                        $this->assertWorkingReadback($article->workingRevision, $target);
                    }
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
                $seo->forceFill([
                    'seo_title' => $this->proposedString($package, 'seo_title'),
                    'seo_description' => $this->proposedString($package, 'seo_description'),
                    'og_title' => $this->proposedString($package, 'seo_title'),
                    'og_description' => $this->proposedString($package, 'seo_description'),
                    'schema_json' => $schema,
                ])->save();
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
                    'title' => $this->proposedString($package, 'title'),
                    'excerpt' => $this->proposedString($package, 'intro'),
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

        if ($skipSyntheticTestBodyLock) {
            return;
        }
        if (! hash_equals(
            (string) ($target['revision_raw_body_sha256'] ?? ''),
            hash('sha256', (string) $published->content_md)
        )) {
            throw new RuntimeException('revision_body_drift:'.(string) $article->id);
        }
        if (! hash_equals(
            (string) ($target['public_projection_body_sha256'] ?? ''),
            $this->publicProjectionBodySha((string) $published->content_md)
        )) {
            throw new RuntimeException('public_body_drift:'.(string) $article->id);
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
            'body' => $this->publicProjectionBodySha((string) ($published?->content_md ?? '')),
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
            || ! hash_equals($this->expectedBodySha($target), hash('sha256', (string) $revision->content_md))
            || ! hash_equals($this->expectedProjectedBodySha($target), $this->publicProjectionBodySha((string) $revision->content_md))
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
            && hash_equals($this->expectedBodySha($target), hash('sha256', (string) $published->content_md))
            && hash_equals($this->expectedProjectedBodySha($target), $this->publicProjectionBodySha((string) $published->content_md))
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
            'body_sha256' => $this->expectedBodySha($target),
            'revision_raw_body_sha256' => (string) $target['revision_raw_body_sha256'],
            'public_projection_body_sha256' => (string) $target['public_projection_body_sha256'],
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
                'body_sha256' => $this->expectedBodySha($target),
                'working_revision_id' => (int) $revision->id,
            ],
            'references_json' => ['status' => 'preserved'],
            'media_json' => ['status' => 'unchanged'],
            'graph_json' => ['status' => 'unchanged'],
            'answer_surface_json' => ['status' => 'revision_bound'],
            'body_hash' => $this->expectedBodySha($target),
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
            'cache_write' => false,
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

    /** @return array<string,mixed> */
    private function readBoundJson(string $relativePath, string $expectedFileSha, string $error): array
    {
        $path = $this->repoPath($relativePath);
        if (preg_match('/^[a-f0-9]{64}$/', $expectedFileSha) !== 1
            || ! hash_equals($expectedFileSha, hash_file('sha256', $path))) {
            throw new RuntimeException($error);
        }

        return $this->readJson($path);
    }

    /** @param array<string,mixed> $object */
    private function assertDeclaredObjectHash(array $object, string $field, string $expected, string $error): void
    {
        $declared = (string) ($object[$field] ?? '');
        $hashable = $object;
        unset($hashable[$field]);
        if (! hash_equals($expected, $declared) || ! hash_equals($declared, $this->canonicalHash($hashable))) {
            throw new RuntimeException($error);
        }
    }

    /** @param array<string,mixed> $execution @param array<string,mixed> $final @param array<string,mixed> $review @param array<string,mixed> $approval */
    private function assertAuthorityChain(array $execution, array $final, array $review, array $approval): void
    {
        $executionTargets = array_values((array) ($execution['targets'] ?? []));
        $finalPackages = array_values((array) ($final['packages'] ?? []));
        $reviewPages = array_values((array) data_get($review, 'final_v2_1_review.pages', []));
        $approvedPackages = array_values((array) ($approval['exact_packages'] ?? []));
        if (count($executionTargets) !== 15 || count($finalPackages) !== 15
            || count($reviewPages) !== 15 || count($approvedPackages) !== 15) {
            throw new RuntimeException('authority_chain_target_count_invalid');
        }

        $packageSet = [];
        foreach ($executionTargets as $index => $target) {
            $finalPackage = (array) ($finalPackages[$index] ?? []);
            $reviewPage = (array) ($reviewPages[$index] ?? []);
            $approvedPackage = (array) ($approvedPackages[$index] ?? []);
            $declared = (string) ($target['package_sha256'] ?? '');
            $fileSha = (string) ($target['package_json_file_sha256'] ?? '');
            if ((int) ($target['order'] ?? 0) !== $index + 1
                || (int) ($target['article_id'] ?? 0) !== (int) ($finalPackage['article_id'] ?? 0)
                || (int) ($target['article_id'] ?? 0) !== (int) ($reviewPage['article_id'] ?? 0)
                || (int) ($target['article_id'] ?? 0) !== (int) ($approvedPackage['article_id'] ?? 0)
                || ! hash_equals($declared, (string) ($finalPackage['package_sha256'] ?? ''))
                || ! hash_equals($declared, (string) ($reviewPage['declared_package_sha256'] ?? ''))
                || ! hash_equals($declared, (string) ($approvedPackage['package_sha256'] ?? ''))
                || ! hash_equals($fileSha, (string) ($finalPackage['package_json_file_sha256'] ?? ''))
                || ! hash_equals($fileSha, (string) ($reviewPage['package_json_file_sha256'] ?? ''))
                || ! hash_equals($fileSha, (string) ($approvedPackage['package_json_file_sha256'] ?? ''))
                || ($reviewPage['decision'] ?? null) !== 'APPROVE') {
                throw new RuntimeException('declared_package_sha_authority_drift:'.(string) ($target['article_id'] ?? 0));
            }
            $packageSet[] = [
                'order' => (int) $finalPackage['order'],
                'article_id' => (int) $finalPackage['article_id'],
                'locale' => (string) $finalPackage['locale'],
                'slug' => (string) $finalPackage['slug'],
                'package_sha256' => (string) $finalPackage['package_sha256'],
                'package_json_file_sha256' => (string) $finalPackage['package_json_file_sha256'],
                'current_body_sha256' => (string) $finalPackage['current_body_sha256'],
                'proposed_body_sha256' => $finalPackage['proposed_body_sha256'] ?? null,
            ];
        }
        $expectedSet = (string) data_get($execution, 'bindings.exact_package_set_sha256', '');
        if (! hash_equals($expectedSet, $this->canonicalHash($packageSet))
            || ! hash_equals($expectedSet, (string) data_get($approval, 'bindings.exact_package_set_sha256', ''))
            || ! hash_equals($expectedSet, (string) data_get($review, 'review_scope.exact_package_set_sha256', ''))) {
            throw new RuntimeException('exact_package_set_sha256_mismatch');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertProjectionContract(array $manifest): void
    {
        $contract = (array) data_get($manifest, 'bindings.projection_contract', []);
        if (($contract['version'] ?? null) !== data_get($manifest, 'hash_contract.projection_contract_version')) {
            throw new RuntimeException('projection_contract_version_mismatch');
        }
        foreach ((array) ($contract['implementation_file_sha256'] ?? []) as $path => $sha) {
            if (! is_string($path) || ! is_string($sha)
                || ! hash_equals($sha, hash_file('sha256', $this->repoPath($path)))) {
                throw new RuntimeException('projection_contract_file_sha256_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $package */
    private function readPackageBody(string $packagePath, array $package, bool $proposed): string
    {
        $locale = (string) data_get($package, 'identity_lock.locale', '');
        $fileKey = $proposed ? 'proposed.cms.'.$locale.'.md' : 'current.public.'.$locale.'.md';
        $file = (string) ($proposed
            ? data_get($package, 'body_write_plan.proposed_cms_file', $fileKey)
            : $fileKey);
        $bodyPath = dirname($this->repoPath($packagePath)).'/'.basename($file);
        if ($file === '' || ! is_file($bodyPath)) {
            throw new RuntimeException('body_file_missing:'.$packagePath);
        }

        return (string) file_get_contents($bodyPath);
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $target @return array<string,mixed> */
    private function normalizePackage(array $package, array $target): array
    {
        $fields = (array) ($package['field_plan'] ?? []);
        $patch = static function (array $field, string $currentKey = 'current', string $proposedKey = 'proposed'): array {
            return [
                'status' => (string) ($field['status'] ?? ''),
                'current' => $field[$currentKey] ?? null,
                'proposed' => $field[$proposedKey] ?? null,
            ];
        };
        $bodyStatus = (string) data_get($fields, 'body_markdown.status', '');
        $currentToProposed = [];
        foreach (['title', 'h1', 'intro', 'seo_title', 'seo_description', 'reading_minutes', 'related_test_slug'] as $field) {
            $currentToProposed[$field] = $patch((array) ($fields[$field] ?? []));
        }
        $currentToProposed['body_markdown'] = [
            'status' => $bodyStatus,
            'current' => ['sha256' => (string) ($target['public_projection_body_sha256'] ?? '')],
            'proposed' => ['sha256' => $target['proposed_body_sha256'] ?? $target['public_projection_body_sha256'] ?? ''],
        ];
        $currentToProposed['faq'] = $patch((array) ($fields['faq_visible_body'] ?? []));
        $answerSurface = $patch((array) ($fields['answer_surface_v1'] ?? []), 'current_public_api');
        $currentToProposed['answer_surface_v1'] = [
            ...$answerSurface,
            'current' => ['faq_items' => (array) $answerSurface['current']],
            'proposed' => ['faq_items' => (array) $answerSurface['proposed']],
        ];
        $currentToProposed['primary_cta'] = $patch((array) ($fields['primary_cta'] ?? []), 'current_public_api');
        $currentToProposed['publication'] = [
            'status' => 'KEEP',
            'current' => ['status' => 'published', 'is_public' => true],
            'proposed' => ['status' => 'published', 'is_public' => true],
        ];
        $currentToProposed['canonical_internal_links'] = [
            'status' => 'KEEP',
            'current' => (array) ($package['internal_links'] ?? []),
            'proposed' => (array) ($package['internal_links'] ?? []),
        ];

        return [...$package, 'current_to_proposed' => $currentToProposed];
    }

    /** @param array<string,mixed> $target */
    private function expectedBodySha(array $target): string
    {
        return (string) (($target['decision'] ?? null) === 'KEEP'
            ? $target['revision_raw_body_sha256'] ?? ''
            : $target['proposed_body_sha256'] ?? '');
    }

    /** @param array<string,mixed> $target */
    private function expectedProjectedBodySha(array $target): string
    {
        return (string) (($target['decision'] ?? null) === 'KEEP'
            ? $target['public_projection_body_sha256'] ?? ''
            : data_get($target, 'package.body_write_plan.projected_public_sha256', ''));
    }

    private function publicProjectionBodySha(string $rawBody): string
    {
        return hash('sha256', $this->headingGuard->downgradeMarkdownH1ToH2($rawBody));
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

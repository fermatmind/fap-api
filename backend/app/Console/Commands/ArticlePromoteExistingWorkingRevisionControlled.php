<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleEditorialCompletenessGate;
use App\Services\Cms\ArticlePublishService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * @review-surface article
 */
final class ArticlePromoteExistingWorkingRevisionControlled extends Command
{
    private const SEO13_BATCH = 'seo13-20260726';

    private const SEO13_TARGET_COUNT = 13;

    private const SEO13_CONTENT_SET_SHA256 = 'b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e';

    private const SEO13_TARGET_SET_SHA256 = '67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c';

    private const PRIVATE_ROUTE_PATTERN = '~(?<![A-Za-z0-9_-])/(?:result|results|orders|order|share|pay|payment|history|take)(?:/|[?#\s)"\']|$)~i';

    private const SENSITIVE_QUERY_PATTERN = '/(?:[?&]|^)(?:result_id|order_id|payment_id|token|score|user_id|report_id)=/i';

    protected $signature = 'articles:promote-existing-working-revision
        {--batch= : Fixed controlled batch name; supported value: seo13-20260726}
        {--expected-target-count= : Exact batch target count lock}
        {--expected-state-sha256= : Execute-only immutable preflight state lock}
        {--expected-revision-set-sha256= : Execute-only immutable revision-set lock}
        {--article-id= : Exact already-published article id}
        {--working-revision-id= : Exact approved working revision id to promote}
        {--current-published-revision-id= : Exact currently-published revision id lock}
        {--translation-group-id= : Expected translation group lock}
        {--expected-slug= : Expected existing slug lock}
        {--expected-canonical= : Expected canonical path or URL lock}
        {--confirm= : Exact user confirmation phrase}
        {--ack-claim-warning= : Article id whose boundary-context claim warnings are acknowledged}
        {--preview-approved : Acknowledge authenticated preview QA passed for this exact working revision}
        {--schema-hold : Confirm schema generation/enqueue stays held}
        {--hreflang-hold : Confirm hreflang enablement stays held}
        {--search-hold : Confirm search enqueue stays held}
        {--no-revalidation : Confirm no frontend/API revalidation will be triggered}
        {--no-sitemap : Confirm sitemap eligibility will not be changed}
        {--no-llms : Confirm llms eligibility will not be changed}
        {--dry-run : Validate and plan without writing}
        {--execute : Promote after exact confirmation and preflight}
        {--json : Emit a JSON summary}';

    protected $description = 'Promote an approved working revision for an already-published existing article through a controlled fail-closed runtime.';

    public function __construct(
        private readonly ArticleEditorialCompletenessGate $editorialCompletenessGate,
    ) {
        parent::__construct();
    }

    public function handle(ArticlePublishService $publisher, AuditLogger $auditLogger): int
    {
        $batch = trim((string) $this->option('batch'));
        if ($batch !== '') {
            return $this->handleBatch($publisher, $auditLogger, $batch);
        }

        $articleId = $this->positiveIntOption('article-id');
        $workingRevisionId = $this->positiveIntOption('working-revision-id');
        $currentPublishedRevisionId = $this->positiveIntOption('current-published-revision-id');
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $expectedConfirmation = $this->expectedConfirmation($articleId, $workingRevisionId);
        $confirmation = trim((string) $this->option('confirm'));

        $errors = [];

        if ($dryRun && $execute) {
            $errors[] = $this->issue('mode', 'dry_run_execute_conflict', 'Choose either --dry-run or --execute, not both.');
        }

        if (! $dryRun && ! $execute) {
            $errors[] = $this->issue('mode', 'mode_required', 'Choose --dry-run or --execute.');
        }

        foreach ([
            'article-id' => $articleId,
            'working-revision-id' => $workingRevisionId,
            'current-published-revision-id' => $currentPublishedRevisionId,
        ] as $option => $value) {
            if ($value <= 0) {
                $errors[] = $this->issue($option, 'positive_integer_required', "Option --{$option} must be a positive integer.");
            }
        }

        if ($execute && ! hash_equals($expectedConfirmation, $confirmation)) {
            $errors[] = $this->issue(
                'confirm',
                'confirmation_mismatch',
                'Exact confirmation phrase is required before existing-article promotion.',
                ['expected_confirmation' => $expectedConfirmation]
            );
        }

        if ($execute && ! (bool) $this->option('preview-approved')) {
            $errors[] = $this->issue('preview-approved', 'preview_approval_required', 'Authenticated preview QA acknowledgement is required before execute.');
        }

        if ($execute) {
            foreach ($this->requiredHoldOptions() as $option) {
                if (! (bool) $this->option($option)) {
                    $errors[] = $this->issue($option, 'required_hold_flag_missing', "Execute requires --{$option}.");
                }
            }
        }

        $plan = $articleId > 0 && $workingRevisionId > 0 && $currentPublishedRevisionId > 0
            ? $this->preflight($articleId, $workingRevisionId, $currentPublishedRevisionId)
            : [
                'article_id' => $articleId,
                'working_revision_id' => $workingRevisionId,
                'current_published_revision_id' => $currentPublishedRevisionId,
                'ok' => false,
                'errors' => [],
            ];

        foreach ((array) ($plan['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = $error;
            }
        }

        $summary = [
            'ok' => $errors === [],
            'dry_run' => $dryRun,
            'execute' => $execute,
            'action' => $execute ? 'promote_existing_working_revision' : 'would_promote_existing_working_revision',
            'expected_confirmation' => $expectedConfirmation,
            'article_id' => $articleId,
            'working_revision_id' => $workingRevisionId,
            'current_published_revision_id' => $currentPublishedRevisionId,
            'preview_approved' => (bool) $this->option('preview-approved'),
            'hold_flags' => $this->holdFlags(),
            'plan' => $plan,
            'errors' => $errors,
            'promoted_article_id' => null,
        ];

        if ($errors === [] && $execute) {
            try {
                $article = $publisher->promoteExistingWorkingRevision(
                    $articleId,
                    $workingRevisionId,
                    $currentPublishedRevisionId,
                    'controlled_existing_article_working_revision_promotion',
                    dispatchFollowUp: false,
                    transactionGuard: fn (Article $lockedArticle, ArticleTranslationRevision $lockedRevision) => $this->assertEditorialCompleteness(
                        $lockedArticle,
                        $lockedRevision,
                    ),
                );

                $this->logPromotion($auditLogger, $article, $plan, $confirmation);

                $summary['promoted_article_id'] = (int) $article->id;
                $summary['plan'] = $this->preflight(
                    $articleId,
                    $workingRevisionId,
                    $currentPublishedRevisionId,
                    afterPromotion: true
                );
            } catch (RuntimeException|\InvalidArgumentException $exception) {
                $errors[] = $this->issue(
                    'promotion',
                    'promotion_failed',
                    $exception->getMessage(),
                    ['article_id' => $articleId, 'working_revision_id' => $workingRevisionId]
                );
                $summary['ok'] = false;
                $summary['errors'] = $errors;
            }
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function handleBatch(
        ArticlePublishService $publisher,
        AuditLogger $auditLogger,
        string $batch,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $expectedTargetCount = $this->positiveIntOption('expected-target-count');
        $expectedState = trim((string) $this->option('expected-state-sha256'));
        $expectedRevisionSet = trim((string) $this->option('expected-revision-set-sha256'));
        $confirmation = trim((string) $this->option('confirm'));
        $errors = [];

        if ($batch !== self::SEO13_BATCH) {
            $errors[] = $this->issue('batch', 'unsupported_batch', 'Only the locked SEO 13 batch is supported.');
        }
        if ($expectedTargetCount !== self::SEO13_TARGET_COUNT) {
            $errors[] = $this->issue(
                'expected-target-count',
                'target_count_lock_mismatch',
                'The SEO 13 batch requires an exact target count of 13.',
            );
        }
        if ($dryRun === $execute) {
            $errors[] = $this->issue('mode', 'exactly_one_mode_required', 'Choose exactly one of --dry-run or --execute.');
        }
        foreach ([
            'article-id',
            'working-revision-id',
            'current-published-revision-id',
            'translation-group-id',
            'expected-slug',
            'expected-canonical',
            'ack-claim-warning',
        ] as $singleOption) {
            if (trim((string) $this->option($singleOption)) !== '') {
                $errors[] = $this->issue(
                    $singleOption,
                    'single_target_option_forbidden_in_batch',
                    'Single-target identity options cannot be combined with --batch.',
                );
            }
        }

        if ($execute) {
            foreach ($this->requiredHoldOptions() as $option) {
                if (! (bool) $this->option($option)) {
                    $errors[] = $this->issue($option, 'required_hold_flag_missing', "Execute requires --{$option}.");
                }
            }
            if (! (bool) $this->option('preview-approved')) {
                $errors[] = $this->issue(
                    'preview-approved',
                    'preview_approval_required',
                    'Authenticated preview QA acknowledgement is required before batch execute.',
                );
            }
            foreach ([
                'expected-state-sha256' => $expectedState,
                'expected-revision-set-sha256' => $expectedRevisionSet,
            ] as $field => $hash) {
                if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                    $errors[] = $this->issue($field, 'sha256_lock_required', "Execute requires a valid --{$field}.");
                }
            }
        } elseif ($expectedState !== '' || $expectedRevisionSet !== '' || $confirmation !== '') {
            $errors[] = $this->issue(
                'preflight-locks',
                'execute_only_lock_supplied',
                'Dry-run must discover state and cannot accept execute-only state, revision-set, or confirmation locks.',
            );
        }

        $snapshot = $batch === self::SEO13_BATCH
            ? $this->batchSnapshot()
            : $this->emptyBatchSnapshot();
        $errors = array_merge($errors, $snapshot['errors']);
        $stateSha = (string) $snapshot['preflight_state_sha256'];
        $revisionSetSha = (string) $snapshot['revision_set_sha256'];
        $expectedConfirmation = $this->expectedBatchConfirmation($stateSha, $revisionSetSha);

        if ($execute && $errors === []) {
            if (! hash_equals($expectedState, $stateSha)) {
                $errors[] = $this->issue('expected-state-sha256', 'preflight_state_drift', 'Live batch state no longer matches the approved preflight.');
            }
            if (! hash_equals($expectedRevisionSet, $revisionSetSha)) {
                $errors[] = $this->issue(
                    'expected-revision-set-sha256',
                    'revision_set_drift',
                    'Live batch revision set no longer matches the approved preflight.',
                );
            }
            if (! hash_equals($expectedConfirmation, $confirmation)) {
                $errors[] = $this->issue(
                    'confirm',
                    'confirmation_mismatch',
                    'Exact batch confirmation phrase is required before atomic promotion.',
                    ['expected_confirmation' => $expectedConfirmation],
                );
            }
        }

        $summary = $this->batchSummary(
            ok: $errors === [],
            dryRun: $dryRun,
            execute: $execute,
            snapshot: $snapshot,
            errors: $errors,
            expectedConfirmation: $expectedConfirmation,
        );

        if ($execute && $errors === []) {
            try {
                $summary = $publisher->promoteExistingWorkingRevisionsAtomically(
                    $this->seo13Targets(),
                    validateLockedBatch: function () use ($expectedState, $expectedRevisionSet): array {
                        $lockedSnapshot = $this->batchSnapshot();
                        if ($lockedSnapshot['errors'] !== []) {
                            throw new RuntimeException('locked_preflight_failed');
                        }
                        if (! hash_equals($expectedState, (string) $lockedSnapshot['preflight_state_sha256'])) {
                            throw new RuntimeException('locked_preflight_state_drift');
                        }
                        if (! hash_equals($expectedRevisionSet, (string) $lockedSnapshot['revision_set_sha256'])) {
                            throw new RuntimeException('locked_revision_set_drift');
                        }

                        return $lockedSnapshot;
                    },
                    transactionGuard: fn (
                        Article $lockedArticle,
                        ArticleTranslationRevision $lockedRevision,
                    ) => $this->assertEditorialCompleteness($lockedArticle, $lockedRevision),
                    validateReadback: function (array $lockedSnapshot) use (
                        $auditLogger,
                        $confirmation,
                        $expectedState,
                        $expectedRevisionSet,
                    ): array {
                        $readback = $this->batchSnapshot(afterPromotion: true);
                        $this->assertBatchPromotionReadback($lockedSnapshot, $readback);
                        $this->logBatchPromotion($auditLogger, $lockedSnapshot, $readback, $confirmation);

                        return $this->batchSummary(
                            ok: true,
                            dryRun: false,
                            execute: true,
                            snapshot: $readback,
                            errors: [],
                            expectedConfirmation: $this->expectedBatchConfirmation(
                                $expectedState,
                                $expectedRevisionSet,
                            ),
                            beforeSnapshot: $lockedSnapshot,
                        );
                    },
                );
            } catch (Throwable $exception) {
                $errors[] = $this->issue(
                    'promotion',
                    'atomic_batch_promotion_failed',
                    $this->safeBatchFailure($exception),
                );
                $summary = $this->batchSummary(
                    ok: false,
                    dryRun: false,
                    execute: true,
                    snapshot: $snapshot,
                    errors: $errors,
                    expectedConfirmation: $expectedConfirmation,
                );
            }
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   errors:list<array<string,mixed>>,
     *   preflight_state_sha256:string,
     *   revision_set_sha256:string
     * }
     */
    private function batchSnapshot(bool $afterPromotion = false): array
    {
        $rows = [];
        $errors = [];

        foreach ($this->seo13Targets() as $target) {
            $row = $this->preflight(
                $target['article_id'],
                $target['working_revision_id'],
                $target['current_published_revision_id'],
                afterPromotion: $afterPromotion,
                identityLock: $target,
                claimWarningAcknowledged: false,
            );
            $rows[] = $row;
            foreach ((array) ($row['errors'] ?? []) as $error) {
                if (is_array($error)) {
                    $errors[] = ['article_id' => $target['article_id']] + $error;
                }
            }
        }

        $revisionSet = array_map(
            static fn (array $row): array => [
                'article_id' => (int) ($row['article_id'] ?? 0),
                'working_revision_id' => (int) ($row['working_revision_id'] ?? 0),
                'current_published_revision_id' => (int) ($row['current_published_revision_id'] ?? 0),
            ],
            $rows,
        );
        $stateRows = array_map(fn (array $row): array => $this->batchStateRow($row), $rows);

        return [
            'rows' => $rows,
            'errors' => $errors,
            'preflight_state_sha256' => $this->deterministicHash($stateRows),
            'revision_set_sha256' => $this->deterministicHash($revisionSet),
        ];
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   errors:list<array<string,mixed>>,
     *   preflight_state_sha256:string,
     *   revision_set_sha256:string
     * }
     */
    private function emptyBatchSnapshot(): array
    {
        return [
            'rows' => [],
            'errors' => [],
            'preflight_state_sha256' => str_repeat('0', 64),
            'revision_set_sha256' => str_repeat('0', 64),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function batchStateRow(array $row): array
    {
        return [
            'article_id' => (int) ($row['article_id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'translation_group_id' => (string) ($row['translation_group_id'] ?? ''),
            'canonical_url' => (string) ($row['canonical_url'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'is_public' => (bool) ($row['is_public'] ?? false),
            'is_indexable' => (bool) ($row['is_indexable'] ?? false),
            'sitemap_eligible' => (bool) ($row['sitemap_eligible'] ?? false),
            'llms_eligible' => (bool) ($row['llms_eligible'] ?? false),
            'published_revision_id' => (int) ($row['published_revision_id'] ?? 0),
            'working_revision_id' => (int) ($row['working_revision_id'] ?? 0),
            'working_revision_status' => (string) ($row['working_revision_status'] ?? ''),
            'working_revision_body_hash' => (string) ($row['working_revision_body_hash'] ?? ''),
            'working_revision_title_hash' => (string) ($row['working_revision_title_hash'] ?? ''),
            'working_revision_excerpt_hash' => (string) ($row['working_revision_excerpt_hash'] ?? ''),
            'working_revision_seo_title_hash' => (string) ($row['working_revision_seo_title_hash'] ?? ''),
            'working_revision_seo_description_hash' => (string) ($row['working_revision_seo_description_hash'] ?? ''),
            'seo_title_hash' => (string) ($row['seo_title_hash'] ?? ''),
            'seo_description_hash' => (string) ($row['seo_description_hash'] ?? ''),
            'seo_schema_hash' => (string) ($row['seo_schema_hash'] ?? ''),
            'seo_robots' => (string) ($row['seo_robots'] ?? ''),
            'import_id' => (int) ($row['import_id'] ?? 0),
            'import_status' => (string) ($row['import_status'] ?? ''),
            'claim_status' => (string) ($row['claim_status'] ?? ''),
            'han_character_count' => (int) data_get($row, 'editorial_completeness.actual_han_characters', 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function assertBatchPromotionReadback(array $before, array $after): void
    {
        if ($after['errors'] !== [] || count($after['rows']) !== self::SEO13_TARGET_COUNT) {
            throw new RuntimeException('post_promotion_preflight_failed');
        }

        $beforeById = collect($before['rows'])->keyBy('article_id');
        foreach ($after['rows'] as $row) {
            $articleId = (int) ($row['article_id'] ?? 0);
            $previous = $beforeById->get($articleId);
            if (! is_array($previous)) {
                throw new RuntimeException('post_promotion_target_set_drift');
            }
            if ((int) ($row['published_revision_id'] ?? 0) !== (int) ($row['working_revision_id'] ?? 0)
                || (string) ($row['working_revision_status'] ?? '') !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                throw new RuntimeException('post_promotion_revision_readback_failed');
            }
            foreach ([
                'slug',
                'translation_group_id',
                'canonical_url',
                'is_public',
                'is_indexable',
                'sitemap_eligible',
                'llms_eligible',
                'seo_schema_hash',
                'seo_robots',
            ] as $heldField) {
                if (($row[$heldField] ?? null) !== ($previous[$heldField] ?? null)) {
                    throw new RuntimeException('post_promotion_hold_drift');
                }
            }
            if ((string) ($row['seo_title_hash'] ?? '') !== (string) ($previous['working_revision_seo_title_hash'] ?? '')
                || (string) ($row['seo_description_hash'] ?? '') !== (string) ($previous['working_revision_seo_description_hash'] ?? '')) {
                throw new RuntimeException('post_promotion_seo_readback_failed');
            }

            $oldRevision = ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->find((int) ($previous['current_published_revision_id'] ?? 0));
            if (! $oldRevision instanceof ArticleTranslationRevision
                || (string) $oldRevision->revision_status !== ArticleTranslationRevision::STATUS_STALE) {
                throw new RuntimeException('previous_revision_not_stale');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  list<array<string,mixed>>  $errors
     * @param  array<string,mixed>|null  $beforeSnapshot
     * @return array<string,mixed>
     */
    private function batchSummary(
        bool $ok,
        bool $dryRun,
        bool $execute,
        array $snapshot,
        array $errors,
        string $expectedConfirmation,
        ?array $beforeSnapshot = null,
    ): array {
        return [
            'contract_version' => 'seo13.article_atomic_promotion.v1',
            'ok' => $ok,
            'dry_run' => $dryRun,
            'execute' => $execute,
            'action' => $execute ? 'promote_seo13_atomic_batch' : 'would_promote_seo13_atomic_batch',
            'batch' => self::SEO13_BATCH,
            'target_count' => count($snapshot['rows']),
            'content_set_sha256' => self::SEO13_CONTENT_SET_SHA256,
            'target_set_sha256' => self::SEO13_TARGET_SET_SHA256,
            'preflight_state_sha256' => $beforeSnapshot['preflight_state_sha256']
                ?? $snapshot['preflight_state_sha256'],
            'revision_set_sha256' => $beforeSnapshot['revision_set_sha256']
                ?? $snapshot['revision_set_sha256'],
            'expected_confirmation' => $expectedConfirmation,
            'preview_approved' => (bool) $this->option('preview-approved'),
            'hold_flags' => $this->holdFlags(),
            'rows' => $snapshot['rows'],
            'errors' => $errors,
            'production_write_execution' => $ok && $execute,
            'publish_count' => $ok && $execute ? self::SEO13_TARGET_COUNT : 0,
            'schema_write_count' => 0,
            'hreflang_write_count' => 0,
            'search_submission_count' => 0,
            'revalidation_count' => 0,
            'sitemap_eligibility_write_count' => 0,
            'llms_eligibility_write_count' => 0,
            'queue_dispatch_count' => 0,
            'gsc_request_count' => 0,
            'url_inspection_count' => 0,
            'deploy_count' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private function logBatchPromotion(
        AuditLogger $auditLogger,
        array $before,
        array $after,
        string $confirmation,
    ): void {
        $auditLogger->log(
            Request::create('/ops/articles/promote-existing-working-revision/batch', 'POST'),
            'codex_controlled_seo13_atomic_working_revision_promotion',
            'article_batch',
            self::SEO13_BATCH,
            [
                'confirmation_sha256' => hash('sha256', $confirmation),
                'target_count' => self::SEO13_TARGET_COUNT,
                'content_set_sha256' => self::SEO13_CONTENT_SET_SHA256,
                'target_set_sha256' => self::SEO13_TARGET_SET_SHA256,
                'preflight_state_sha256' => (string) $before['preflight_state_sha256'],
                'revision_set_sha256' => (string) $before['revision_set_sha256'],
                'published_revision_set_sha256' => $this->deterministicHash(array_map(
                    static fn (array $row): array => [
                        'article_id' => (int) ($row['article_id'] ?? 0),
                        'published_revision_id' => (int) ($row['published_revision_id'] ?? 0),
                    ],
                    $after['rows'],
                )),
                'hold_flags' => $this->holdFlags(),
                'preview_approved' => true,
                'follow_up_dispatch' => false,
                'discoverability_cache_invalidation' => false,
            ],
            reason: 'seo13_atomic_existing_article_working_revision_promotion',
            result: 'success',
        );
    }

    private function expectedBatchConfirmation(string $stateSha, string $revisionSetSha): string
    {
        return 'I explicitly approve Codex to atomically promote SEO 13 batch '
            .self::SEO13_BATCH
            .' state '.$stateSha
            .' revision set '.$revisionSetSha
            .' content set '.self::SEO13_CONTENT_SET_SHA256
            .' after preflight passes.';
    }

    /**
     * @return list<array{
     *   article_id:int,
     *   working_revision_id:int,
     *   current_published_revision_id:int,
     *   translation_group_id:string,
     *   slug:string,
     *   canonical:string
     * }>
     */
    private function seo13Targets(): array
    {
        $targets = [
            [1, 446, 341, 'big-five-growth-guide'],
            [2, 445, 347, 'big-five-narrative-portrait'],
            [5, 444, 5, 'iq-test-growth-guide'],
            [6, 443, 6, 'iq-test-narrative-portrait'],
            [7, 442, 7, 'iq-test-tool-guide'],
            [9, 441, 9, 'mbti-growth-guide'],
            [10, 440, 10, 'mbti-narrative-portrait'],
            [11, 436, 30, 'are-infj-men-rare-or-socially-silenced'],
            [12, 437, 31, 'best-valentines-date-by-personality-and-relationship-science'],
            [13, 439, 32, 'childhood-dream-job-still-shapes-career-choice'],
            [14, 438, 33, 'how-16-personality-types-talk-to-an-ai-coach'],
            [15, 434, 34, 'how-personality-shapes-attitude-toward-ai'],
            [16, 435, 35, 'which-love-script-fits-you-best'],
        ];

        return array_map(
            static fn (array $target): array => [
                'article_id' => $target[0],
                'working_revision_id' => $target[1],
                'current_published_revision_id' => $target[2],
                'translation_group_id' => 'article-'.$target[0],
                'slug' => $target[3],
                'canonical' => 'https://fermatmind.com/zh/articles/'.$target[3],
            ],
            $targets,
        );
    }

    private function deterministicHash(mixed $value): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $this->sortRecursively($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    private function safeBatchFailure(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'locked_preflight_failed',
            'locked_preflight_state_drift',
            'locked_revision_set_drift',
            'post_promotion_preflight_failed',
            'post_promotion_target_set_drift',
            'post_promotion_revision_readback_failed',
            'post_promotion_hold_drift',
            'post_promotion_seo_readback_failed',
            'previous_revision_not_stale' => $exception->getMessage(),
            default => 'atomic_batch_runtime_failed',
        };
    }

    private function positiveIntOption(string $option): int
    {
        $value = $this->option($option);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function expectedConfirmation(int $articleId, int $workingRevisionId): string
    {
        return "I explicitly approve Codex to promote article id {$articleId} working revision {$workingRevisionId} after preflight passes.";
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(
        int $articleId,
        int $workingRevisionId,
        int $currentPublishedRevisionId,
        bool $afterPromotion = false,
        ?array $identityLock = null,
        bool $claimWarningAcknowledged = false,
    ): array {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta', 'category', 'tags'])
            ->find($articleId);

        if (! $article instanceof Article) {
            return [
                'article_id' => $articleId,
                'ok' => false,
                'errors' => [$this->issue('article', 'article_not_found', 'Article not found.', ['article_id' => $articleId])],
            ];
        }

        $workingRevision = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($workingRevisionId)
            ->first();
        $seoMeta = $article->seoMeta instanceof ArticleSeoMeta ? $article->seoMeta : null;
        $import = ArticleEditorialPackageImport::query()
            ->withoutGlobalScopes()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first();

        $expectedTranslationGroupId = trim((string) ($identityLock['translation_group_id'] ?? $this->option('translation-group-id')));
        $expectedSlug = trim((string) ($identityLock['slug'] ?? $this->option('expected-slug')));
        $expectedCanonical = trim((string) ($identityLock['canonical'] ?? $this->option('expected-canonical')));
        $bodyHash = $workingRevision instanceof ArticleTranslationRevision
            ? $this->bodyHash((string) $workingRevision->content_md)
            : '';
        $claimStatus = (string) data_get($import?->claim_result_json, 'status', '');
        $claimMatches = is_array(data_get($import?->claim_result_json, 'matches'))
            ? (array) data_get($import?->claim_result_json, 'matches')
            : [];
        $mediaStatus = (string) data_get($import?->media_json, 'status', '');
        $referencesStatus = (string) data_get($import?->references_json, 'status', '');
        $graphStatus = (string) data_get($import?->graph_json, 'status', '');
        $answerSurfaceStatus = (string) data_get($import?->answer_surface_json, 'status', '');

        $errors = [];
        $warnings = [];

        if ((string) $article->status !== 'published' || ! (bool) $article->is_public) {
            $errors[] = $this->issue('article.status', 'article_not_published_public', 'Existing-article promotion requires an already-published public article.');
        }

        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $errors[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_publishable', 'Archived or soft-deleted articles cannot be promoted.');
        }

        if (method_exists($article, 'trashed') && $article->trashed()) {
            $errors[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be promoted.');
        }

        if ((int) ($article->published_revision_id ?? 0) !== $currentPublishedRevisionId && ! $afterPromotion) {
            $errors[] = $this->issue('published_revision_id', 'published_revision_lock_mismatch', 'Current published revision lock does not match article state.');
        }

        if ((int) ($article->working_revision_id ?? 0) !== $workingRevisionId) {
            $errors[] = $this->issue('working_revision_id', 'working_revision_lock_mismatch', 'Working revision lock does not match article state.');
        }

        if ($workingRevisionId === $currentPublishedRevisionId && ! $afterPromotion) {
            $errors[] = $this->issue('working_revision_id', 'working_revision_not_isolated', 'Working revision must be isolated from the current published revision.');
        }

        if ($expectedTranslationGroupId === '') {
            $errors[] = $this->issue('translation-group-id', 'translation_group_lock_required', 'Expected translation group lock is required.');
        } elseif ((string) $article->translation_group_id !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('translation_group_id', 'translation_group_mismatch', 'Article translation group does not match expected lock.');
        }

        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected-slug', 'slug_lock_required', 'Expected slug lock is required.');
        } elseif ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('slug', 'slug_lock_mismatch', 'Article slug does not match expected lock.');
        }

        if ($expectedCanonical === '') {
            $errors[] = $this->issue('expected-canonical', 'canonical_lock_required', 'Expected canonical lock is required.');
        } elseif (! $seoMeta instanceof ArticleSeoMeta || $this->canonicalPath((string) $seoMeta->canonical_url) !== $this->canonicalPath($expectedCanonical)) {
            $errors[] = $this->issue('canonical_url', 'canonical_lock_mismatch', 'SEO canonical does not match expected lock.');
        }

        if (! $workingRevision instanceof ArticleTranslationRevision) {
            $errors[] = $this->issue('working_revision', 'working_revision_not_found', 'Working revision not found.');
        } else {
            if ((int) $workingRevision->article_id !== (int) $article->id
                || (int) $workingRevision->org_id !== (int) $article->org_id
                || (string) $workingRevision->locale !== (string) $article->locale) {
                $errors[] = $this->issue('working_revision', 'working_revision_identity_mismatch', 'Working revision does not match article identity.');
            }

            if ((string) $workingRevision->translation_group_id !== (string) $article->translation_group_id) {
                $errors[] = $this->issue('working_revision.translation_group_id', 'working_revision_translation_group_mismatch', 'Working revision translation group does not match article.');
            }

            if (! $afterPromotion && (string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_APPROVED) {
                $errors[] = $this->issue('working_revision.revision_status', 'revision_not_editorially_approved', 'Working revision must be editorially approved before promotion.');
            }

            if ($afterPromotion && (string) $workingRevision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
                $errors[] = $this->issue('working_revision.revision_status', 'revision_not_published_after_promotion', 'Working revision should be published after promotion.');
            }

            if (! $afterPromotion && ((int) ($workingRevision->reviewed_by ?? 0) <= 0 || $workingRevision->reviewed_at === null)) {
                $errors[] = $this->issue('working_revision.review', 'revision_review_missing', 'Approved working revision must include review actor and timestamp.');
            }

            if (! $afterPromotion && $workingRevision->approved_at === null) {
                $errors[] = $this->issue('working_revision.approved_at', 'revision_approval_missing', 'Approved working revision must include approval timestamp.');
            }

            if (trim((string) $workingRevision->title) === '') {
                $errors[] = $this->issue('working_revision.title', 'revision_title_missing', 'Working revision title must be present.');
            }

            if (trim((string) $workingRevision->content_md) === '') {
                $errors[] = $this->issue('working_revision.content_md', 'revision_body_missing', 'Working revision body must be present.');
            }
        }

        if (! $import instanceof ArticleEditorialPackageImport) {
            $errors[] = $this->issue('import', 'missing_existing_update_import_gate', 'Existing-article promotion requires a latest import gate record.');
        } else {
            if ((string) $import->content_track !== 'seo_content_package_existing_article_update') {
                $errors[] = $this->issue('import.content_track', 'invalid_existing_update_content_track', 'Latest import must come from the existing-article SEO update writer.');
            }

            if (! in_array((string) $import->status, [
                ArticleEditorialPackageImport::STATUS_IMPORTED,
                ArticleEditorialPackageImport::STATUS_WARNING,
            ], true)) {
                $errors[] = $this->issue('import.status', 'invalid_import_status', 'Import gate status must be imported or warning.');
            }

            if ((string) $import->intended_status !== 'working_revision_human_review') {
                $errors[] = $this->issue('import.intended_status', 'invalid_import_intended_status', 'Existing update import must target a human-review working revision.');
            }

            if ((string) data_get($import->validation_summary_json, 'operation') !== 'update_existing_article_working_revision') {
                $errors[] = $this->issue('import.validation_summary_json.operation', 'invalid_import_operation', 'Existing update import operation does not match promotion lane.');
            }

            if (! (bool) data_get($import->validation_summary_json, 'schema_hreflang_search_hold')) {
                $errors[] = $this->issue('import.validation_summary_json.schema_hreflang_search_hold', 'missing_downstream_hold_record', 'Import gate must record schema/hreflang/search holds.');
            }

            if ($bodyHash !== '' && ! hash_equals((string) $import->body_hash, $bodyHash)) {
                $errors[] = $this->issue('body_hash', 'body_hash_mismatch', 'Working revision body hash does not match latest import gate hash.');
            }

            if ((string) data_get($import->exactness_json, 'status') !== 'passed') {
                $errors[] = $this->issue('import.exactness_json.status', 'exactness_not_passed', 'Import exactness gate must be passed.');
            }

            if ($expectedSlug !== '' && (string) $import->slug !== $expectedSlug) {
                $errors[] = $this->issue('import.slug', 'import_slug_lock_mismatch', 'Import slug does not match expected slug lock.');
            }

            if ($expectedCanonical !== ''
                && (string) data_get($import->exactness_json, 'canonical_url', $expectedCanonical) !== ''
                && $this->canonicalPath((string) data_get($import->exactness_json, 'canonical_url')) !== $this->canonicalPath($expectedCanonical)) {
                $errors[] = $this->issue('import.exactness_json.canonical_url', 'import_canonical_lock_mismatch', 'Import canonical lock does not match expected canonical.');
            }

            if (! in_array($mediaStatus, ['complete', 'unchanged_hold'], true)) {
                $errors[] = $this->issue('import.media_json.status', 'media_gate_not_acceptable', 'Existing update media status must be complete or unchanged_hold.');
            }

            if ($referencesStatus !== 'complete' && (int) $import->references_count <= 0) {
                $warnings[] = $this->issue('import.references_json.status', 'references_operator_review_hold', 'References remain under existing-article operator-review hold.');
            }

            if (! in_array($graphStatus, ['complete', 'unchanged_hold'], true)) {
                $warnings[] = $this->issue('import.graph_json.status', 'graph_operator_review_hold', 'Graph metadata remains unchanged for existing article promotion.');
            }

            if (! in_array($answerSurfaceStatus, ['complete', 'visible_only'], true)) {
                $warnings[] = $this->issue('import.answer_surface_json.status', 'answer_surface_visible_only_hold', 'Answer surface remains visible-only for existing article promotion.');
            }
        }

        if ($claimStatus === 'blocked') {
            $errors[] = $this->issue('claim', 'claim_blocked', 'Claim linter blocked this article.');
        }

        if ($claimStatus === 'warning') {
            $allBoundaryContext = $claimMatches !== [] && collect($claimMatches)->every(
                static fn (mixed $match): bool => is_array($match) && (bool) ($match['boundary_context'] ?? false)
            );

            if (! $allBoundaryContext) {
                $errors[] = $this->issue('claim', 'claim_warning_not_boundary_context', 'Claim warnings include non-boundary context matches.');
            }

            if (! $claimWarningAcknowledged && (int) $this->positiveIntOption('ack-claim-warning') !== $articleId) {
                $errors[] = $this->issue('claim', 'claim_warning_ack_required', 'Boundary-context claim warnings must be explicitly acknowledged for this article.');
            }
        }

        if (trim((string) $article->cover_image_url) === '' || trim((string) $article->cover_image_alt) === '') {
            $errors[] = $this->issue('cover_image', 'cover_image_or_alt_missing', 'Article cover image URL and alt text must be present.');
        }

        if (! $article->category) {
            $errors[] = $this->issue('category', 'category_missing', 'Article category must be present.');
        }

        if ($article->tags->count() <= 0) {
            $errors[] = $this->issue('tags', 'tags_missing', 'Article tags must be present.');
        }

        if (! $seoMeta instanceof ArticleSeoMeta) {
            $errors[] = $this->issue('seo', 'seo_meta_missing', 'SEO meta must be present.');
        } else {
            foreach (['seo_title', 'seo_description', 'canonical_url', 'og_image_url'] as $field) {
                if (trim((string) $seoMeta->{$field}) === '') {
                    $errors[] = $this->issue("seo.{$field}", 'seo_field_missing', "SEO field {$field} must be present.");
                }
            }

            if (! (bool) $article->is_indexable || (string) $seoMeta->robots !== 'index,follow') {
                $errors[] = $this->issue('indexability', 'existing_article_not_indexable', 'Existing SEO article must already be indexable before promotion.');
            }
        }

        $editorialCompleteness = $this->editorialCompleteness($article, $workingRevision);
        foreach ((array) $editorialCompleteness['issues'] as $issue) {
            if (is_array($issue)) {
                $errors[] = $issue;
            }
        }
        $readerFacingText = implode("\n", [
            (string) ($workingRevision?->title ?? ''),
            (string) ($workingRevision?->excerpt ?? ''),
            (string) ($workingRevision?->content_md ?? ''),
            (string) ($workingRevision?->seo_title ?? ''),
            (string) ($workingRevision?->seo_description ?? ''),
        ]);
        if (preg_match(self::PRIVATE_ROUTE_PATTERN, $readerFacingText) === 1) {
            $errors[] = $this->issue(
                'working_revision.private_url_guard',
                'private_route_found_in_working_revision',
                'Private routes are forbidden in the promoted working revision.',
            );
        }
        if (preg_match(self::SENSITIVE_QUERY_PATTERN, $readerFacingText) === 1) {
            $errors[] = $this->issue(
                'working_revision.private_url_guard',
                'sensitive_query_key_found_in_working_revision',
                'Sensitive query keys are forbidden in the promoted working revision.',
            );
        }

        return [
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'translation_group_id' => (string) $article->translation_group_id,
            'canonical_url' => (string) ($seoMeta?->canonical_url ?? ''),
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
            'current_published_revision_id' => $currentPublishedRevisionId,
            'working_revision_id' => $workingRevisionId,
            'working_revision_status' => $workingRevision?->revision_status,
            'working_revision_body_hash' => $bodyHash,
            'working_revision_title_hash' => $this->textHash((string) ($workingRevision?->title ?? '')),
            'working_revision_excerpt_hash' => $this->textHash((string) ($workingRevision?->excerpt ?? '')),
            'working_revision_seo_title_hash' => $this->textHash((string) ($workingRevision?->seo_title ?? '')),
            'working_revision_seo_description_hash' => $this->textHash((string) ($workingRevision?->seo_description ?? '')),
            'seo_title_hash' => $this->textHash((string) ($seoMeta?->seo_title ?? '')),
            'seo_description_hash' => $this->textHash((string) ($seoMeta?->seo_description ?? '')),
            'seo_schema_hash' => $this->jsonHash($seoMeta?->schema_json),
            'seo_robots' => (string) ($seoMeta?->robots ?? ''),
            'import_id' => $import?->id,
            'import_status' => $import?->status,
            'import_content_track' => $import?->content_track,
            'claim_status' => $claimStatus,
            'claim_warning_acknowledged' => $claimWarningAcknowledged
                || (int) $this->positiveIntOption('ack-claim-warning') === $articleId,
            'media_status' => $mediaStatus,
            'references_status' => $referencesStatus,
            'references_count' => (int) ($import?->references_count ?? 0),
            'graph_status' => $graphStatus,
            'answer_surface_status' => $answerSurfaceStatus,
            'editorial_completeness' => $editorialCompleteness,
            'after_promotion' => $afterPromotion,
            'ok' => $errors === [],
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function editorialCompleteness(Article $article, ?ArticleTranslationRevision $workingRevision): array
    {
        return $this->editorialCompletenessGate->inspect(
            (string) $article->locale,
            (string) ($workingRevision?->content_md ?? ''),
            [
                'working_revision.title' => (string) ($workingRevision?->title ?? ''),
                'working_revision.excerpt' => (string) ($workingRevision?->excerpt ?? ''),
                'working_revision.content_md' => (string) ($workingRevision?->content_md ?? ''),
                'working_revision.seo_title' => (string) ($workingRevision?->seo_title ?? ''),
                'working_revision.seo_description' => (string) ($workingRevision?->seo_description ?? ''),
            ],
        );
    }

    private function assertEditorialCompleteness(Article $article, ArticleTranslationRevision $workingRevision): void
    {
        $result = $this->editorialCompleteness($article, $workingRevision);
        if ((bool) $result['ok']) {
            return;
        }

        $codes = array_values(array_filter(array_column((array) $result['issues'], 'code'), 'is_string'));

        throw new RuntimeException('editorial completeness failed: '.implode(',', $codes));
    }

    /**
     * @return list<string>
     */
    private function requiredHoldOptions(): array
    {
        return ['schema-hold', 'hreflang-hold', 'search-hold', 'no-revalidation', 'no-sitemap', 'no-llms'];
    }

    /**
     * @return array<string,bool>
     */
    private function holdFlags(): array
    {
        $flags = [];
        foreach ($this->requiredHoldOptions() as $option) {
            $flags[$option] = (bool) $this->option($option);
        }

        return $flags;
    }

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)));
    }

    private function textHash(string $value): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($value)));
    }

    private function jsonHash(mixed $value): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            )
        );
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

    /**
     * @param  array<string,mixed>  $plan
     */
    private function logPromotion(AuditLogger $auditLogger, Article $article, array $plan, string $confirmation): void
    {
        $auditLogger->log(
            Request::create('/ops/articles/promote-existing-working-revision', 'POST'),
            'codex_controlled_existing_article_working_revision_promotion',
            'article',
            (string) $article->id,
            [
                'confirmation_sha256' => hash('sha256', $confirmation),
                'article_id' => (int) $article->id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'working_revision_id' => (int) ($plan['working_revision_id'] ?? 0),
                'previous_published_revision_id' => (int) ($plan['current_published_revision_id'] ?? 0),
                'new_published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'body_hash' => (string) ($plan['working_revision_body_hash'] ?? ''),
                'import_id' => (int) ($plan['import_id'] ?? 0),
                'claim_status' => (string) ($plan['claim_status'] ?? ''),
                'hold_flags' => $this->holdFlags(),
                'preview_approved' => (bool) $this->option('preview-approved'),
                'source' => 'controlled_existing_article_working_revision_promotion',
            ],
            reason: 'controlled_existing_article_working_revision_promotion',
            result: 'success',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('execute='.(($summary['execute'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? ''));
        $this->line('article_id='.(string) ($summary['article_id'] ?? ''));
        $this->line('working_revision_id='.(string) ($summary['working_revision_id'] ?? ''));
        $this->line('current_published_revision_id='.(string) ($summary['current_published_revision_id'] ?? ''));
        $this->line('expected_confirmation='.(string) ($summary['expected_confirmation'] ?? ''));
        $this->line('promoted_article_id='.(string) ($summary['promoted_article_id'] ?? ''));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('error='.$this->issueLine($error));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode('|', array_filter([
            'field='.(string) ($issue['field'] ?? ''),
            'code='.(string) ($issue['code'] ?? ''),
            'message='.(string) ($issue['message'] ?? ''),
        ]));
    }
}

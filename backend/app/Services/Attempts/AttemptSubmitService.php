<?php

namespace App\Services\Attempts;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\AssessmentEngine;
use App\Services\Assessments\AssessmentService;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportGatekeeper;
use App\Services\Report\ReportSnapshotStore;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttemptSubmitService
{
    private const B2B_CREDIT_BENEFIT_CODE = 'B2B_ASSESSMENT_ATTEMPT_SUBMIT';

    public function __construct(
        private ScaleRegistry $registry,
        private AssessmentEngine $engine,
        private ReportSnapshotStore $reportSnapshots,
        private EntitlementManager $entitlements,
        private EventRecorder $eventRecorder,
        private BenefitWalletService $benefitWallets,
        private AttemptProgressService $progressService,
        private AnswerSetStore $answerSets,
        private AnswerRowWriter $answerRowWriter,
        private AssessmentService $assessments,
        private ReportGatekeeper $reportGatekeeper,
    ) {}

    public function submit(OrgContext $ctx, string $attemptId, array $validated): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            abort(400, 'attempt_id is required.');
        }

        $answers = $validated['answers'] ?? [];
        if (! is_array($answers)) {
            $answers = [];
        }

        $durationMs = (int) ($validated['duration_ms'] ?? 0);
        $inviteToken = trim((string) ($validated['invite_token'] ?? ''));

        $orgId = $ctx->orgId();
        $attempt = $this->ownedAttemptQuery($ctx, $attemptId)->firstOrFail();

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            abort(400, 'scale_code missing on attempt.');
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (! $row) {
            abort(404, 'scale not found.');
        }

        $packId = (string) ($attempt->pack_id ?? $row['default_pack_id'] ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            abort(500, 'scale pack not configured.');
        }

        $region = (string) ($attempt->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($attempt->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $draftAnswers = $this->progressService->loadDraftAnswers($attempt);
        $mergedAnswers = $this->mergeAnswersForSubmit($answers, $draftAnswers);
        if (empty($mergedAnswers)) {
            abort(422, 'answers required.');
        }

        $answersDigest = $this->computeAnswersDigest($mergedAnswers, $scaleCode, $packId, $dirVersion);

        $engineAttempt = [
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
        ];
        $scoreContext = [
            'duration_ms' => $durationMs,
            'started_at' => $attempt->started_at,
            'submitted_at' => now(),
            'region' => $region,
            'locale' => $locale,
            'org_id' => $orgId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
        ];

        $scored = $this->engine->score($engineAttempt, $mergedAnswers, $scoreContext);
        if (! ($scored['ok'] ?? false)) {
            abort(500, (string) ($scored['message'] ?? 'scoring failed.'));
        }

        $scoreResult = $scored['result'];
        $contentPackageVersion = (string) ($scored['pack']['content_package_version'] ?? '');
        $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');

        $commercial = $row['commercial_json'] ?? null;
        if (is_string($commercial)) {
            $decoded = json_decode($commercial, true);
            $commercial = is_array($decoded) ? $decoded : null;
        }

        $creditBenefitCode = '';
        if (is_array($commercial)) {
            $creditBenefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
        }

        $entitlementBenefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
        if ($entitlementBenefitCode === '') {
            $entitlementBenefitCode = $creditBenefitCode;
        }

        $responsePayload = null;
        $postCommitCtx = null;

        DB::transaction(function () use (
            $ctx,
            &$responsePayload,
            &$postCommitCtx,
            $attemptId,
            $mergedAnswers,
            $answersDigest,
            $durationMs,
            $orgId,
            $scaleCode,
            $packId,
            $dirVersion,
            $region,
            $locale,
            $inviteToken,
            $creditBenefitCode,
            $entitlementBenefitCode,
            $scoreResult,
            $contentPackageVersion,
            $scoringSpecVersion
        ) {
            $locked = $this->ownedAttemptQuery($ctx, $attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingDigest = trim((string) ($locked->answers_digest ?? ''));
            if ($locked->submitted_at && $existingDigest !== '') {
                if ($existingDigest === $answersDigest) {
                    $existingResult = $this->findResult($orgId, $attemptId);
                    if ($existingResult) {
                        $responsePayload = $this->buildSubmitPayload($locked, $existingResult, false);
                        $postCommitCtx = [
                            'org_id' => $orgId,
                            'attempt_id' => $attemptId,
                            'scale_code' => $scaleCode,
                            'pack_id' => (string) ($locked->pack_id ?? $packId),
                            'dir_version' => (string) ($locked->dir_version ?? $dirVersion),
                            'scoring_spec_version' => (string) ($locked->scoring_spec_version ?? $scoringSpecVersion),
                            'invite_token' => $inviteToken,
                            'credit_benefit_code' => $creditBenefitCode,
                            'entitlement_benefit_code' => $entitlementBenefitCode,
                        ];

                        return;
                    }
                }

                abort(409, 'attempt already submitted with different answers.');
            }

            if ($locked->submitted_at && $existingDigest === '') {
                $existingResult = $this->findResult($orgId, $attemptId);
                if ($existingResult) {
                    $responsePayload = $this->buildSubmitPayload($locked, $existingResult, false);
                    $postCommitCtx = [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'scale_code' => $scaleCode,
                        'pack_id' => (string) ($locked->pack_id ?? $packId),
                        'dir_version' => (string) ($locked->dir_version ?? $dirVersion),
                        'scoring_spec_version' => (string) ($locked->scoring_spec_version ?? $scoringSpecVersion),
                        'invite_token' => $inviteToken,
                        'credit_benefit_code' => $creditBenefitCode,
                        'entitlement_benefit_code' => $entitlementBenefitCode,
                    ];

                    return;
                }
            }

            $locked->fill([
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : null,
                'scoring_spec_version' => $scoringSpecVersion !== '' ? $scoringSpecVersion : null,
                'region' => $region,
                'locale' => $locale,
                'submitted_at' => now(),
                'duration_ms' => $durationMs,
                'answers_digest' => $answersDigest,
            ]);
            if ($locked->started_at === null) {
                $locked->started_at = now();
            }
            $locked->save();

            $stored = $this->answerSets->storeFinalAnswers($locked, $mergedAnswers, $durationMs, $scoringSpecVersion);
            if (! ($stored['ok'] ?? false)) {
                abort(500, (string) ($stored['message'] ?? 'failed to store answer set.'));
            }

            $rowsWritten = $this->answerRowWriter->writeRows($locked, $mergedAnswers, $durationMs);
            if (! ($rowsWritten['ok'] ?? false)) {
                abort(500, (string) ($rowsWritten['message'] ?? 'failed to write answer rows.'));
            }

            $axisScores = is_array($scoreResult->axisScoresJson ?? null)
                ? $scoreResult->axisScoresJson
                : [];

            $scoresJson = $axisScores['scores_json'] ?? null;
            if (! is_array($scoresJson)) {
                $scoresJson = is_array($scoreResult->breakdownJson) ? $scoreResult->breakdownJson : [];
            }

            $scoresPct = $axisScores['scores_pct'] ?? null;
            $axisStates = $axisScores['axis_states'] ?? null;

            $resultJson = $scoreResult->toArray();
            $resultJson['scale_code'] = $scaleCode;
            $resultJson['pack_id'] = $packId;
            $resultJson['dir_version'] = $dirVersion;
            $resultJson['content_package_version'] = $contentPackageVersion;
            $resultJson['scoring_spec_version'] = $scoringSpecVersion;
            $resultJson['computed_at'] = now()->toISOString();

            $resultData = [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'scale_code' => $scaleCode,
                'scale_version' => (string) ($locked->scale_version ?? 'v0.3'),
                'type_code' => (string) ($scoreResult->typeCode ?? ''),
                'scores_json' => $scoresJson,
                'scores_pct' => is_array($scoresPct) ? $scoresPct : null,
                'axis_states' => is_array($axisStates) ? $axisStates : null,
                'profile_version' => null,
                'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : null,
                'result_json' => $resultJson,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'scoring_spec_version' => $scoringSpecVersion !== '' ? $scoringSpecVersion : null,
                'report_engine_version' => 'v1.2',
                'is_valid' => true,
                'computed_at' => now(),
            ];

            $existingResult = $this->findResult($orgId, $attemptId);
            if ($existingResult) {
                $existingResult->fill($resultData);
                $existingResult->save();
                $result = $existingResult;
            } else {
                $resultData['id'] = (string) Str::uuid();
                $result = Result::create($resultData);
            }

            $responsePayload = $this->buildSubmitPayload($locked, $result, true);
            $postCommitCtx = [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'scoring_spec_version' => $scoringSpecVersion,
                'invite_token' => $inviteToken,
                'credit_benefit_code' => $creditBenefitCode,
                'entitlement_benefit_code' => $entitlementBenefitCode,
            ];
        });

        if (! is_array($responsePayload)) {
            abort(500, 'unexpected submit state.');
        }

        $snapshotJobCtx = null;
        if (($responsePayload['ok'] ?? false) === true && is_array($postCommitCtx)) {
            $snapshotJobCtx = $this->runSubmitPostCommitSideEffects($ctx, $postCommitCtx);
        }

        if (is_array($snapshotJobCtx)) {
            GenerateReportSnapshotJob::dispatch(
                (int) $snapshotJobCtx['org_id'],
                (string) $snapshotJobCtx['attempt_id'],
                (string) $snapshotJobCtx['trigger_source'],
                $snapshotJobCtx['order_no'] !== null ? (string) $snapshotJobCtx['order_no'] : null,
            )->afterCommit();
        }

        if (($responsePayload['ok'] ?? false) === true) {
            $this->progressService->clearProgress($attemptId);
            $this->eventRecorder->record('test_submit', $this->resolveUserIdInt($ctx), [
                'scale_code' => (string) ($postCommitCtx['scale_code'] ?? ''),
                'pack_id' => (string) ($postCommitCtx['pack_id'] ?? ''),
                'dir_version' => (string) ($postCommitCtx['dir_version'] ?? ''),
                'attempt_id' => $attemptId,
            ], [
                'org_id' => $orgId,
                'anon_id' => $this->resolveAnonId($ctx, request()),
                'attempt_id' => $attemptId,
                'pack_id' => (string) ($postCommitCtx['pack_id'] ?? ''),
                'dir_version' => (string) ($postCommitCtx['dir_version'] ?? ''),
            ]);
        }

        $this->appendReportPayload($ctx, $attemptId, $responsePayload);

        return $responsePayload;
    }

    private function runSubmitPostCommitSideEffects(OrgContext $ctx, array $payload): ?array
    {
        $orgId = (int) ($payload['org_id'] ?? 0);
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return null;
        }

        $scaleCode = strtoupper(trim((string) ($payload['scale_code'] ?? '')));
        $packId = trim((string) ($payload['pack_id'] ?? ''));
        $dirVersion = trim((string) ($payload['dir_version'] ?? ''));
        $scoringSpecVersion = trim((string) ($payload['scoring_spec_version'] ?? ''));
        $inviteToken = trim((string) ($payload['invite_token'] ?? ''));
        $creditBenefitCode = strtoupper(trim((string) ($payload['credit_benefit_code'] ?? '')));
        $entitlementBenefitCode = strtoupper(trim((string) ($payload['entitlement_benefit_code'] ?? '')));

        $consumeB2BCredit = false;
        if ($inviteToken !== '' && $orgId > 0) {
            try {
                $assignment = $this->assessments->attachAttemptByInviteToken($orgId, $inviteToken, $attemptId);
                $consumeB2BCredit = $assignment !== null;
            } catch (\Throwable $e) {
                Log::error('SUBMIT_POST_COMMIT_ATTACH_INVITE_FAILED', [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'invite_token' => $inviteToken,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $creditOk = true;
        if ($consumeB2BCredit) {
            try {
                $consume = $this->benefitWallets->consume($orgId, self::B2B_CREDIT_BENEFIT_CODE, $attemptId);
                $creditOk = (bool) ($consume['ok'] ?? false);
                if (! $creditOk) {
                    Log::warning('SUBMIT_POST_COMMIT_B2B_CREDIT_CONSUME_FAILED', [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'error_code' => (string) data_get($consume, 'error_code', data_get($consume, 'error', 'CREDITS_CONSUME_FAILED')),
                        'message' => $consume['message'] ?? 'credits consume failed.',
                    ]);
                }
            } catch (\Throwable $e) {
                $creditOk = false;
                Log::error('SUBMIT_POST_COMMIT_B2B_CREDIT_CONSUME_EXCEPTION', [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($orgId > 0 && $creditBenefitCode !== '' && ! $consumeB2BCredit) {
            try {
                $consume = $this->benefitWallets->consume($orgId, $creditBenefitCode, $attemptId);
                $creditOk = (bool) ($consume['ok'] ?? false);
                if ($creditOk) {
                    $this->eventRecorder->record('wallet_consumed', $this->resolveUserIdInt($ctx), [
                        'scale_code' => $scaleCode,
                        'pack_id' => $packId,
                        'dir_version' => $dirVersion,
                        'attempt_id' => $attemptId,
                        'benefit_code' => $creditBenefitCode,
                        'sku' => null,
                    ], [
                        'org_id' => $orgId,
                        'anon_id' => $this->resolveAnonId($ctx, request()),
                        'attempt_id' => $attemptId,
                        'pack_id' => $packId,
                        'dir_version' => $dirVersion,
                    ]);
                } else {
                    Log::warning('SUBMIT_POST_COMMIT_CREDIT_CONSUME_FAILED', [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'benefit_code' => $creditBenefitCode,
                        'error_code' => (string) data_get($consume, 'error_code', data_get($consume, 'error', 'INSUFFICIENT_CREDITS')),
                        'message' => $consume['message'] ?? 'insufficient credits.',
                    ]);
                }
            } catch (\Throwable $e) {
                $creditOk = false;
                Log::error('SUBMIT_POST_COMMIT_CREDIT_CONSUME_EXCEPTION', [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'benefit_code' => $creditBenefitCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($creditOk && $orgId > 0 && $entitlementBenefitCode !== '') {
            $userIdRaw = $this->resolveUserId($ctx, request());
            $anonIdRaw = $this->resolveAnonId($ctx, request());

            try {
                $grant = $this->entitlements->grantAttemptUnlock(
                    $orgId,
                    $userIdRaw,
                    $anonIdRaw,
                    $entitlementBenefitCode,
                    $attemptId,
                    null
                );

                if (! ($grant['ok'] ?? false)) {
                    Log::warning('SUBMIT_POST_COMMIT_GRANT_FAILED', [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'benefit_code' => $entitlementBenefitCode,
                        'error_code' => (string) data_get($grant, 'error_code', data_get($grant, 'error', 'ENTITLEMENT_GRANT_FAILED')),
                        'message' => $grant['message'] ?? 'entitlement grant failed.',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('SUBMIT_POST_COMMIT_GRANT_EXCEPTION', [
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'benefit_code' => $entitlementBenefitCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->reportSnapshots->seedPendingSnapshot($orgId, $attemptId, null, [
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'scoring_spec_version' => $scoringSpecVersion,
            ]);
        } catch (\Throwable $e) {
            Log::error('SUBMIT_POST_COMMIT_SEED_SNAPSHOT_FAILED', [
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return [
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'trigger_source' => 'submit',
            'order_no' => null,
        ];
    }

    private function appendReportPayload(OrgContext $ctx, string $attemptId, array &$responsePayload): void
    {
        $userId = $this->resolveUserId($ctx, request());
        $anonId = $this->resolveAnonId($ctx, request());

        try {
            $gate = $this->reportGatekeeper->resolve(
                $ctx->orgId(),
                $attemptId,
                $userId,
                $anonId,
                $ctx->role(),
            );
        } catch (\Throwable $e) {
            Log::warning('SUBMIT_REPORT_GATE_EXCEPTION', [
                'org_id' => $ctx->orgId(),
                'attempt_id' => $attemptId,
                'error' => $e->getMessage(),
            ]);

            $responsePayload['report'] = [
                'ok' => false,
                'locked' => true,
                'access_level' => 'free',
            ];

            return;
        }

        if (! ($gate['ok'] ?? false)) {
            Log::warning('SUBMIT_REPORT_GATE_FAILED', [
                'org_id' => $ctx->orgId(),
                'attempt_id' => $attemptId,
                'error_code' => (string) data_get($gate, 'error_code', data_get($gate, 'error', 'REPORT_FAILED')),
                'message' => (string) ($gate['message'] ?? 'report generation failed.'),
            ]);

            $responsePayload['report'] = [
                'ok' => false,
                'locked' => true,
                'access_level' => 'free',
            ];

            return;
        }

        $responsePayload['report'] = $gate;
    }

    private function ownedAttemptQuery(OrgContext $ctx, string $attemptId): Builder
    {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->orgId());

        $role = (string) ($ctx->role() ?? '');
        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        $request = request();
        $userId = null;
        $anonId = null;

        if ($request instanceof Request) {
            $userId = $this->resolveUserId($ctx, $request);
            $anonId = $this->resolveAnonId($ctx, $request);
        } else {
            $ctxUserId = $ctx->userId();
            if ($ctxUserId !== null) {
                $candidate = trim((string) $ctxUserId);
                $userId = $candidate !== '' ? $candidate : null;
            }

            $ctxAnonId = $ctx->anonId();
            if (is_string($ctxAnonId)) {
                $candidate = trim($ctxAnonId);
                $anonId = $candidate !== '' ? $candidate : null;
            }
        }

        if (in_array($role, ['member', 'viewer'], true)) {
            if ($userId !== null) {
                return $query->where('user_id', $userId);
            }

            if ($anonId !== null) {
                return $query->where('anon_id', $anonId);
            }

            return $query->whereRaw('1=0');
        }

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($anonId !== null) {
            return $query->where('anon_id', $anonId);
        }

        return $query->whereRaw('1=0');
    }

    private function resolveUserId(OrgContext $ctx, ?Request $request): ?string
    {
        $candidates = [
            $ctx->userId(),
            $request?->attributes->get('fm_user_id'),
            $request?->attributes->get('user_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveAnonId(OrgContext $ctx, ?Request $request): ?string
    {
        $candidates = [
            $ctx->anonId(),
            $request?->attributes->get('anon_id'),
            $request?->attributes->get('fm_anon_id'),
            $request?->query('anon_id'),
            $request?->header('X-Anon-Id'),
            $request?->header('X-Fm-Anon-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveUserIdInt(OrgContext $ctx): ?int
    {
        $value = $this->resolveUserId($ctx, request());
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function computeAnswersDigest(array $answers, string $scaleCode, string $packId, string $dirVersion): string
    {
        $canonical = $this->answerSets->canonicalJson($answers);
        $raw = strtoupper($scaleCode).'|'.$packId.'|'.$dirVersion.'|'.$canonical;

        return hash('sha256', $raw);
    }

    private function mergeAnswersForSubmit(array $requestAnswers, array $draftAnswers): array
    {
        $map = [];

        foreach ($draftAnswers as $answer) {
            if (! is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $map[$qid] = $answer;
        }

        foreach ($requestAnswers as $answer) {
            if (! is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $map[$qid] = $answer;
        }

        ksort($map);

        return array_values($map);
    }

    private function buildSubmitPayload(Attempt $attempt, Result $result, bool $fresh): array
    {
        $payload = $result->result_json;
        if (! is_array($payload)) {
            $payload = [];
        }

        $compatTypeCode = (string) (($payload['type_code'] ?? null) ?? ($result->type_code ?? ''));

        $compatScores = $result->scores_json;
        if (! is_array($compatScores)) {
            $compatScores = $payload['scores_json'] ?? $payload['scores'] ?? [];
        }
        if (! is_array($compatScores)) {
            $compatScores = [];
        }

        $compatScoresPct = $result->scores_pct;
        if (! is_array($compatScoresPct)) {
            $compatScoresPct = $payload['scores_pct'] ?? ($payload['axis_scores_json']['scores_pct'] ?? null);
        }
        if (! is_array($compatScoresPct)) {
            $compatScoresPct = [];
        }

        return [
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'type_code' => $compatTypeCode,
            'scores' => $compatScores,
            'scores_pct' => $compatScoresPct,
            'result' => $payload,
            'meta' => [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ],
            'idempotent' => ! $fresh,
        ];
    }

    private function findResult(int $orgId, string $attemptId): ?Result
    {
        return Result::where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();
    }
}

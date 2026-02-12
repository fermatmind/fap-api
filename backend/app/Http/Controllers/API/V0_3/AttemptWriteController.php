<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Requests\V0_3\StartAttemptRequest;
use App\Http\Requests\V0_3\SubmitAttemptRequest;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessments\AssessmentService;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Attempts\AnswerSetStore;
use App\Services\Attempts\AttemptProgressService;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\AssessmentEngine;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\ContentPacksIndex;
use App\Services\Report\ReportSnapshotStore;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttemptWriteController extends Controller
{
    use ResolvesAttemptOwnership;

    private const B2B_CREDIT_BENEFIT_CODE = 'B2B_ASSESSMENT_ATTEMPT_SUBMIT';

    public function __construct(
        private ScaleRegistry $registry,
        private ContentPacksIndex $packsIndex,
        private AssessmentEngine $engine,
        private ReportSnapshotStore $reportSnapshots,
        private EntitlementManager $entitlements,
        private EventRecorder $eventRecorder,
        protected OrgContext $orgContext,
        private BenefitWalletService $benefitWallets,
        private AttemptProgressService $progressService,
        private AnswerSetStore $answerSets,
        private AnswerRowWriter $answerRowWriter,
        private AssessmentService $assessments,
    ) {
    }

    /**
     * POST /api/v0.3/attempts/start
     */
    public function start(StartAttemptRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $orgId = $this->orgContext->orgId();
        $scaleCode = strtoupper(trim((string) $payload['scale_code']));
        if ($scaleCode === '') {
            abort(400, 'scale_code is required.');
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (!$row) {
            abort(404, 'scale not found.');
        }

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            abort(500, 'scale pack not configured.');
        }

        $region = (string) ($payload['region'] ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($payload['locale'] ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $questionCount = $this->resolveQuestionCount($packId, $dirVersion);

        $contentPackageVersion = $this->resolveContentPackageVersion($packId, $dirVersion);

        $anonId = trim((string) ($payload['anon_id'] ?? $request->header('X-Anon-Id') ?? ''));
        if ($anonId === '') {
            $anonId = 'anon_' . Str::uuid();
        }

        $clientPlatform = (string) ($payload['client_platform'] ?? $request->header('X-Client-Platform') ?? 'unknown');
        $clientVersion = (string) ($payload['client_version'] ?? $request->header('X-App-Version') ?? '');
        $channel = (string) ($payload['channel'] ?? $request->header('X-Channel') ?? '');
        $referrer = (string) ($payload['referrer'] ?? $request->header('X-Referrer') ?? '');

        $attempt = Attempt::create([
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $this->orgContext->userId(),
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => $region,
            'locale' => $locale,
            'question_count' => $questionCount,
            'client_platform' => $clientPlatform,
            'client_version' => $clientVersion !== '' ? $clientVersion : null,
            'channel' => $channel !== '' ? $channel : null,
            'referrer' => $referrer !== '' ? $referrer : null,
            'started_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : null,
            'answers_summary_json' => [
                'stage' => 'start',
                'created_at_ms' => (int) round(microtime(true) * 1000),
                'meta' => $payload['meta'] ?? null,
            ],
        ]);

        $draft = $this->progressService->createDraftForAttempt($attempt);
        if (!empty($draft['expires_at'])) {
            $attempt->resume_expires_at = $draft['expires_at'];
            $attempt->save();
        }

        $this->eventRecorder->recordFromRequest($request, 'test_start', $this->resolveUserId($request), [
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'attempt_id' => (string) $attempt->id,
        ]);

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'region' => $region,
            'locale' => $locale,
            'resume_token' => (string) ($draft['token'] ?? ''),
            'resume_expires_at' => !empty($draft['expires_at']) ? $draft['expires_at']->toISOString() : null,
        ]);
    }

    /**
     * POST /api/v0.3/attempts/submit
     */
    public function submit(SubmitAttemptRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $attemptId = trim((string) $payload['attempt_id']);
        $answers = $payload['answers'] ?? [];
        $durationMs = (int) $payload['duration_ms'];
        $inviteToken = trim((string) ($payload['invite_token'] ?? ''));
        if (!is_array($answers)) {
            $answers = [];
        }

        $orgId = $this->orgContext->orgId();
        $attempt = $this->ownedAttemptQuery($attemptId)->firstOrFail();

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            abort(400, 'scale_code missing on attempt.');
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (!$row) {
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
        $answers = $mergedAnswers;

        $response = null;
        $postCommitCtx = null;

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
        $scored = $this->engine->score($engineAttempt, $answers, $scoreContext);
        if (!($scored['ok'] ?? false)) {
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

        DB::transaction(function () use (
            &$response,
            $attemptId,
            $answers,
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
            $scoringSpecVersion,
            &$postCommitCtx
        ) {
            $locked = $this->ownedAttemptQuery($attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingDigest = trim((string) ($locked->answers_digest ?? ''));
            if ($locked->submitted_at && $existingDigest !== '') {
                if ($existingDigest === $answersDigest) {
                    $existingResult = $this->findResult($orgId, $attemptId);
                    if ($existingResult) {
                        $response = $this->buildSubmitResponse($locked, $existingResult, false);
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
                    $response = $this->buildSubmitResponse($locked, $existingResult, false);
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

            $stored = $this->answerSets->storeFinalAnswers($locked, $answers, $durationMs, $scoringSpecVersion);
            if (!($stored['ok'] ?? false)) {
                abort(500, (string) ($stored['message'] ?? 'failed to store answer set.'));
            }

            $rowsWritten = $this->answerRowWriter->writeRows($locked, $answers, $durationMs);
            if (!($rowsWritten['ok'] ?? false)) {
                abort(500, (string) ($rowsWritten['message'] ?? 'failed to write answer rows.'));
            }

            $axisScores = is_array($scoreResult->axisScoresJson ?? null)
                ? $scoreResult->axisScoresJson
                : [];

            $scoresJson = $axisScores['scores_json'] ?? null;
            if (!is_array($scoresJson)) {
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

            $response = $this->buildSubmitResponse($locked, $result, true);
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

        $snapshotJobCtx = null;
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200 && is_array($postCommitCtx)) {
            $snapshotJobCtx = $this->runSubmitPostCommitSideEffects($request, $postCommitCtx);
        }

        if (is_array($snapshotJobCtx)) {
            GenerateReportSnapshotJob::dispatch(
                (int) $snapshotJobCtx['org_id'],
                (string) $snapshotJobCtx['attempt_id'],
                (string) $snapshotJobCtx['trigger_source'],
                $snapshotJobCtx['order_no'] !== null ? (string) $snapshotJobCtx['order_no'] : null,
            )->afterCommit();
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $this->progressService->clearProgress($attemptId);
            $this->eventRecorder->recordFromRequest($request, 'test_submit', $this->resolveUserId($request), [
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'attempt_id' => $attemptId,
            ]);
        }

        return $response instanceof JsonResponse
            ? $response
            : abort(500, 'unexpected submit state.');
    }

    private function runSubmitPostCommitSideEffects(Request $request, array $ctx): ?array
    {
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return null;
        }

        $scaleCode = strtoupper(trim((string) ($ctx['scale_code'] ?? '')));
        $packId = trim((string) ($ctx['pack_id'] ?? ''));
        $dirVersion = trim((string) ($ctx['dir_version'] ?? ''));
        $scoringSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ''));
        $inviteToken = trim((string) ($ctx['invite_token'] ?? ''));
        $creditBenefitCode = strtoupper(trim((string) ($ctx['credit_benefit_code'] ?? '')));
        $entitlementBenefitCode = strtoupper(trim((string) ($ctx['entitlement_benefit_code'] ?? '')));

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
                if (!$creditOk) {
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

        if ($orgId > 0 && $creditBenefitCode !== '' && !$consumeB2BCredit) {
            try {
                $consume = $this->benefitWallets->consume($orgId, $creditBenefitCode, $attemptId);
                $creditOk = (bool) ($consume['ok'] ?? false);
                if ($creditOk) {
                    $this->eventRecorder->record('wallet_consumed', $this->resolveUserId($request), [
                        'scale_code' => $scaleCode,
                        'pack_id' => $packId,
                        'dir_version' => $dirVersion,
                        'attempt_id' => $attemptId,
                        'benefit_code' => $creditBenefitCode,
                        'sku' => null,
                    ], [
                        'org_id' => $orgId,
                        'anon_id' => $this->orgContext->anonId(),
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
            $userIdRaw = $request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id');
            $anonIdRaw = $request->attributes->get('anon_id') ?? $request->attributes->get('fm_anon_id');

            try {
                $grant = $this->entitlements->grantAttemptUnlock(
                    $orgId,
                    $userIdRaw !== null ? (string) $userIdRaw : null,
                    $anonIdRaw !== null ? (string) $anonIdRaw : null,
                    $entitlementBenefitCode,
                    $attemptId,
                    null
                );

                if (!($grant['ok'] ?? false)) {
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

    private function resolveQuestionCount(string $packId, string $dirVersion): int
    {
        $questionsPath = '';

        $found = $this->packsIndex->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_INDEX_FIND_FAILED',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        $item = $found['item'] ?? [];
        $questionsPath = (string) ($item['questions_path'] ?? '');
        if ($questionsPath === '' || !File::exists($questionsPath)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_FILE_MISSING',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        try {
            $raw = File::get($questionsPath);
        } catch (\Throwable $e) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_FILE_READ_FAILED',
                $packId,
                $dirVersion,
                $questionsPath,
                $e
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_JSON_DECODE_FAILED',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        $items = $decoded['items'] ?? null;
        if (!is_array($items)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_JSON_INVALID_SHAPE',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        return count($items);
    }

    private function logAndThrowContentPackError(
        string $reason,
        string $packId,
        string $dirVersion,
        string $questionsPath,
        ?\Throwable $e = null
    ): never {
        Log::error($reason, [
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'questions_path' => $questionsPath,
            'exception_message' => $e?->getMessage() ?? $reason,
            'json_error' => json_last_error_msg(),
        ]);

        throw new \RuntimeException('CONTENT_PACK_ERROR', 0, $e);
    }

    private function resolveContentPackageVersion(string $packId, string $dirVersion): string
    {
        $found = $this->packsIndex->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
            return '';
        }

        $item = $found['item'] ?? [];
        return (string) ($item['content_package_version'] ?? '');
    }

    private function computeAnswersDigest(array $answers, string $scaleCode, string $packId, string $dirVersion): string
    {
        $canonical = $this->answerSets->canonicalJson($answers);
        $raw = strtoupper($scaleCode) . '|' . $packId . '|' . $dirVersion . '|' . $canonical;
        return hash('sha256', $raw);
    }

    private function mergeAnswersForSubmit(array $requestAnswers, array $draftAnswers): array
    {
        $map = [];

        foreach ($draftAnswers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $map[$qid] = $answer;
        }

        foreach ($requestAnswers as $answer) {
            if (!is_array($answer)) {
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

    private function buildSubmitResponse(Attempt $attempt, Result $result, bool $fresh): JsonResponse
    {
        $payload = $result->result_json;
        if (!is_array($payload)) {
            $payload = [];
        }

        $compatTypeCode = (string) (($payload['type_code'] ?? null) ?? ($result->type_code ?? ''));

        $compatScores = $result->scores_json;
        if (!is_array($compatScores)) {
            $compatScores = $payload['scores_json'] ?? $payload['scores'] ?? [];
        }
        if (!is_array($compatScores)) {
            $compatScores = [];
        }

        $compatScoresPct = $result->scores_pct;
        if (!is_array($compatScoresPct)) {
            $compatScoresPct = $payload['scores_pct'] ?? ($payload['axis_scores_json']['scores_pct'] ?? null);
        }
        if (!is_array($compatScoresPct)) {
            $compatScoresPct = [];
        }

        return response()->json([
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
            'idempotent' => !$fresh,
        ]);
    }

    private function findResult(int $orgId, string $attemptId): ?Result
    {
        return Result::where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();
    }

}

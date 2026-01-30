<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Attempts\AnswerSetStore;
use App\Services\Attempts\AttemptProgressService;
use App\Services\Analytics\EventRecorder;
use App\Services\Assessment\AssessmentEngine;
use App\Services\Assessment\GenericReportBuilder;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Content\ContentPacksIndex;
use App\Services\Report\ReportGatekeeper;
use App\Services\Report\ReportComposer;
use App\Services\Report\ReportSnapshotStore;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AttemptsController extends Controller
{
    public function __construct(
        private ScaleRegistry $registry,
        private ContentPacksIndex $packsIndex,
        private AssessmentEngine $engine,
        private GenericReportBuilder $genericReportBuilder,
        private ReportComposer $reportComposer,
        private ReportGatekeeper $reportGatekeeper,
        private ReportSnapshotStore $reportSnapshots,
        private EntitlementManager $entitlements,
        private EventRecorder $eventRecorder,
        private OrgContext $orgContext,
        private BenefitWalletService $benefitWallets,
        private AttemptProgressService $progressService,
        private AnswerSetStore $answerSets,
        private AnswerRowWriter $answerRowWriter,
    ) {
    }

    /**
     * POST /api/v0.3/attempts/start
     */
    public function start(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'scale_code' => ['required', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
            'anon_id' => ['nullable', 'string', 'max:64'],
            'client_platform' => ['nullable', 'string', 'max:32'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'meta' => ['sometimes', 'array'],
        ]);

        $orgId = $this->orgContext->orgId();
        $scaleCode = strtoupper(trim((string) $payload['scale_code']));
        if ($scaleCode === '') {
            return response()->json([
                'ok' => false,
                'error' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            return response()->json([
                'ok' => false,
                'error' => 'PACK_NOT_CONFIGURED',
                'message' => 'scale pack not configured.',
            ], 500);
        }

        $region = (string) ($payload['region'] ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($payload['locale'] ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $questionCount = $this->resolveQuestionCount($packId, $dirVersion);
        if ($questionCount === null) {
            return response()->json([
                'ok' => false,
                'error' => 'QUESTIONS_NOT_FOUND',
                'message' => 'questions.json not found or invalid.',
            ], 500);
        }

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
    public function submit(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'attempt_id' => ['required', 'string', 'max:64'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'string', 'max:128'],
            'answers.*.code' => ['nullable'],
            'answers.*.question_type' => ['nullable', 'string', 'max:32'],
            'answers.*.question_index' => ['nullable', 'integer', 'min:0'],
            'duration_ms' => ['required', 'integer', 'min:0'],
        ]);

        $attemptId = trim((string) $payload['attempt_id']);
        $answers = $payload['answers'] ?? [];
        $durationMs = (int) $payload['duration_ms'];
        if (!is_array($answers)) {
            $answers = [];
        }

        $orgId = $this->orgContext->orgId();
        $attempt = Attempt::where('id', $attemptId)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            return response()->json([
                'ok' => false,
                'error' => 'SCALE_REQUIRED',
                'message' => 'scale_code missing on attempt.',
            ], 400);
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $packId = (string) ($attempt->pack_id ?? $row['default_pack_id'] ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            return response()->json([
                'ok' => false,
                'error' => 'PACK_NOT_CONFIGURED',
                'message' => 'scale pack not configured.',
            ], 500);
        }

        $region = (string) ($attempt->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($attempt->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $draftAnswers = $this->progressService->loadDraftAnswers($attempt);
        $mergedAnswers = $this->mergeAnswersForSubmit($answers, $draftAnswers);
        if (empty($mergedAnswers)) {
            return response()->json([
                'ok' => false,
                'error' => 'ANSWERS_REQUIRED',
                'message' => 'answers required.',
            ], 422);
        }

        $answersDigest = $this->computeAnswersDigest($mergedAnswers, $scaleCode, $packId, $dirVersion);
        $answers = $mergedAnswers;

        $response = null;

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
            $row,
            $request
        ) {
            $locked = Attempt::where('id', $attemptId)
                ->where('org_id', $orgId)
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                $response = response()->json([
                    'ok' => false,
                    'error' => 'ATTEMPT_NOT_FOUND',
                    'message' => 'attempt not found.',
                ], 404);
                return;
            }

            $existingDigest = trim((string) ($locked->answers_digest ?? ''));
            if ($locked->submitted_at && $existingDigest !== '') {
                if ($existingDigest === $answersDigest) {
                    $existingResult = $this->findResult($orgId, $attemptId);
                    if ($existingResult) {
                        $response = $this->buildSubmitResponse($locked, $existingResult, false);
                        return;
                    }
                }

                $response = response()->json([
                    'ok' => false,
                    'error' => 'ATTEMPT_ALREADY_SUBMITTED',
                    'message' => 'attempt already submitted with different answers.',
                    'data' => [
                        'attempt_id' => $attemptId,
                        'answers_digest' => $existingDigest,
                        'incoming_digest' => $answersDigest,
                    ],
                ], 409);
                return;
            }

            if ($locked->submitted_at && $existingDigest === '') {
                $existingResult = $this->findResult($orgId, $attemptId);
                if ($existingResult) {
                    $response = $this->buildSubmitResponse($locked, $existingResult, false);
                    return;
                }
            }

            $engineAttempt = [
                'org_id' => $orgId,
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
            ];

            $ctx = [
                'duration_ms' => $durationMs,
                'started_at' => $locked->started_at,
                'submitted_at' => now(),
                'region' => $region,
                'locale' => $locale,
                'org_id' => $orgId,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
            ];

            $scored = $this->engine->score($engineAttempt, $answers, $ctx);
            if (!($scored['ok'] ?? false)) {
                $response = response()->json([
                    'ok' => false,
                    'error' => $scored['error'] ?? 'SCORING_FAILED',
                    'message' => $scored['message'] ?? 'scoring failed.',
                ], 500);
                return;
            }

            $scoreResult = $scored['result'];
            $contentPackageVersion = (string) ($scored['pack']['content_package_version'] ?? '');
            $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');

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
                $response = response()->json([
                    'ok' => false,
                    'error' => $stored['error'] ?? 'ANSWER_SET_STORE_FAILED',
                    'message' => $stored['message'] ?? 'failed to store answer set.',
                ], 500);
                return;
            }

            $rowsWritten = $this->answerRowWriter->writeRows($locked, $answers, $durationMs);
            if (!($rowsWritten['ok'] ?? false)) {
                $response = response()->json([
                    'ok' => false,
                    'error' => $rowsWritten['error'] ?? 'ANSWER_ROWS_FAILED',
                    'message' => $rowsWritten['message'] ?? 'failed to write answer rows.',
                ], 500);
                return;
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

            $commercial = $row['commercial_json'] ?? null;
            if (is_string($commercial)) {
                $decoded = json_decode($commercial, true);
                $commercial = is_array($decoded) ? $decoded : null;
            }

            $creditBenefitCode = '';
            if (is_array($commercial)) {
                $creditBenefitCode = strtoupper(trim((string) ($commercial['credit_benefit_code'] ?? '')));
            }

            if ($orgId > 0 && $creditBenefitCode !== '') {
                $consume = $this->benefitWallets->consume($orgId, $creditBenefitCode, $attemptId);
                if (!($consume['ok'] ?? false)) {
                    $status = (int) ($consume['status'] ?? 402);
                    $response = response()->json([
                        'ok' => false,
                        'error' => $consume['error'] ?? 'INSUFFICIENT_CREDITS',
                        'message' => $consume['message'] ?? 'insufficient credits.',
                    ], $status);
                    return;
                }

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

                $entitlementBenefitCode = strtoupper(trim((string) ($commercial['report_benefit_code'] ?? '')));
                if ($entitlementBenefitCode === '') {
                    $entitlementBenefitCode = $creditBenefitCode;
                }

                if ($entitlementBenefitCode !== '') {
                    $userIdRaw = $request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id');
                    $anonIdRaw = $request->attributes->get('anon_id') ?? $request->attributes->get('fm_anon_id');

                    $grant = $this->entitlements->grantAttemptUnlock(
                        $orgId,
                        $userIdRaw !== null ? (string) $userIdRaw : null,
                        $anonIdRaw !== null ? (string) $anonIdRaw : null,
                        $entitlementBenefitCode,
                        $attemptId,
                        null
                    );

                    if (!($grant['ok'] ?? false)) {
                        $status = (int) ($grant['status'] ?? 500);
                        $response = response()->json($grant, $status);
                        return;
                    }
                }

                $snapshot = $this->reportSnapshots->createSnapshotForAttempt([
                    'org_id' => $orgId,
                    'attempt_id' => $attemptId,
                    'trigger_source' => 'credit_consume',
                    'order_no' => null,
                ]);
                if (!($snapshot['ok'] ?? false)) {
                    $status = (int) ($snapshot['status'] ?? 500);
                    $response = response()->json($snapshot, $status);
                    return;
                }
            }

            $response = $this->buildSubmitResponse($locked, $result, true);
        });

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
            : response()->json([
                'ok' => false,
                'error' => 'SUBMIT_FAILED',
                'message' => 'unexpected submit state.',
            ], 500);
    }

    /**
     * GET /api/v0.3/attempts/{id}/result
     */
    public function result(Request $request, string $id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $attempt = Attempt::where('id', $id)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (!$result) {
            return response()->json([
                'ok' => false,
                'error' => 'RESULT_NOT_FOUND',
                'message' => 'result not found.',
            ], 404);
        }

        $payload = $result->result_json;
        if (!is_array($payload)) {
            $payload = [];
        }

        $this->eventRecorder->recordFromRequest($request, 'result_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
        ]);

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'result' => $payload,
            'meta' => [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ],
        ]);
    }

    /**
     * GET /api/v0.3/attempts/{id}/report
     */
    public function report(Request $request, string $id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $attempt = Attempt::where('id', $id)->where('org_id', $orgId)->first();
        if (!$attempt) {
            return response()->json([
                'ok' => false,
                'error' => 'ATTEMPT_NOT_FOUND',
                'message' => 'attempt not found.',
            ], 404);
        }

        $result = Result::where('org_id', $orgId)->where('attempt_id', $id)->first();
        if (!$result) {
            return response()->json([
                'ok' => false,
                'error' => 'RESULT_NOT_FOUND',
                'message' => 'result not found.',
            ], 404);
        }

        $gate = $this->reportGatekeeper->resolve(
            $orgId,
            $id,
            $request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id'),
            $request->attributes->get('anon_id') ?? $request->attributes->get('fm_anon_id'),
        );

        if (!($gate['ok'] ?? false)) {
            $status = (int) ($gate['status'] ?? 500);
            return response()->json($gate, $status);
        }

        $this->eventRecorder->recordFromRequest($request, 'report_view', $this->resolveUserId($request), [
            'scale_code' => (string) ($attempt->scale_code ?? ''),
            'pack_id' => (string) ($attempt->pack_id ?? ''),
            'dir_version' => (string) ($attempt->dir_version ?? ''),
            'type_code' => (string) ($result->type_code ?? ''),
            'attempt_id' => (string) $attempt->id,
            'locked' => (bool) ($gate['locked'] ?? false),
        ]);

        return response()->json(array_merge($gate, [
            'meta' => [
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'pack_id' => (string) ($attempt->pack_id ?? ''),
                'dir_version' => (string) ($attempt->dir_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
                'scoring_spec_version' => (string) ($attempt->scoring_spec_version ?? ''),
                'report_engine_version' => (string) ($result->report_engine_version ?? 'v1.2'),
            ],
        ]));
    }

    private function resolveQuestionCount(string $packId, string $dirVersion): ?int
    {
        $found = $this->packsIndex->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
            return null;
        }

        $item = $found['item'] ?? [];
        $questionsPath = (string) ($item['questions_path'] ?? '');
        if ($questionsPath === '' || !File::isFile($questionsPath)) {
            return null;
        }

        try {
            $raw = File::get($questionsPath);
        } catch (\Throwable $e) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $items = $decoded['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return count($items);
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

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
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

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }
}

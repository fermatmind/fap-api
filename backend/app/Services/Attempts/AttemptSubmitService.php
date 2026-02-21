<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\AssessmentRunner;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Report\ReportGatekeeper;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRolloutGate;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttemptSubmitService
{
    public function __construct(
        private ScaleRegistry $registry,
        private AttemptProgressService $progressService,
        private AttemptAnswerPersistence $answerPersistence,
        private AssessmentRunner $assessmentRunner,
        private AttemptSubmitSideEffects $sideEffects,
        private ReportGatekeeper $reportGatekeeper,
        private BigFiveTelemetry $bigFiveTelemetry,
    ) {}

    public function submit(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'attempt_id is required.');
        }

        $answers = $dto->answers;
        $durationMs = $dto->durationMs;
        $inviteToken = $dto->inviteToken;
        $actorUserId = $this->resolveUserId($ctx, $dto->userId);
        $actorAnonId = $this->resolveAnonId($ctx, $dto->anonId);

        $orgId = $ctx->orgId();
        $attempt = $this->ownedAttemptQuery($ctx, $attemptId, $actorUserId, $actorAnonId)->firstOrFail();

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'scale_code missing on attempt.');
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (! $row) {
            throw new ApiProblemException(404, 'NOT_FOUND', 'scale not found.');
        }

        $packId = (string) ($attempt->pack_id ?? $row['default_pack_id'] ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'scale pack not configured.');
        }

        $region = (string) ($attempt->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($attempt->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        ScaleRolloutGate::assertEnabled($scaleCode, $row, $region, $attemptId);

        $draftAnswers = $this->progressService->loadDraftAnswers($attempt);
        $mergedAnswers = $this->mergeAnswersForSubmit($answers, $draftAnswers);
        if (empty($mergedAnswers)) {
            throw new ApiProblemException(422, 'VALIDATION_FAILED', 'answers required.');
        }

        $answersDigest = $this->computeAnswersDigest($mergedAnswers, $scaleCode, $packId, $dirVersion);

        $scoreContext = [
            'duration_ms' => $durationMs,
            'started_at' => $attempt->started_at,
            'submitted_at' => now(),
            'region' => $region,
            'locale' => $locale,
            'org_id' => $orgId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'attempt_id' => $attemptId,
            'anon_id' => $actorAnonId,
            'user_id' => $actorUserId,
        ];

        $scored = $this->assessmentRunner->run(
            $scaleCode,
            $orgId,
            $packId,
            $dirVersion,
            $mergedAnswers,
            $scoreContext
        );
        if (! ($scored['ok'] ?? false)) {
            throw new ApiProblemException(500, 'SCORING_FAILED', (string) ($scored['message'] ?? 'scoring failed.'));
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
            $scoringSpecVersion,
            $actorUserId,
            $actorAnonId
        ) {
            $locked = $this->ownedAttemptQuery($ctx, $attemptId, $actorUserId, $actorAnonId)
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

                throw new ApiProblemException(409, 'CONFLICT', 'attempt already submitted with different answers.');
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

            if ($scaleCode === 'BIG5_OCEAN' && is_array($scoreResult->normedJson ?? null)) {
                $normed = (array) $scoreResult->normedJson;
                $normsNode = is_array($normed['norms'] ?? null) ? $normed['norms'] : [];

                $normsVersion = trim((string) ($normsNode['norms_version'] ?? ($normed['norms_version'] ?? '')));
                $groupId = trim((string) ($normsNode['group_id'] ?? ($normed['group_id'] ?? '')));
                $sourceId = trim((string) ($normsNode['source_id'] ?? ($normed['source_id'] ?? '')));
                $status = strtoupper(trim((string) ($normsNode['status'] ?? ($normed['status'] ?? 'MISSING'))));
                if (!in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
                    $status = 'MISSING';
                }

                if ($normsVersion !== '') {
                    $locked->norm_version = $normsVersion;
                }

                $snapshot = $locked->calculation_snapshot_json;
                if (!is_array($snapshot)) {
                    $snapshot = [];
                }

                $snapshot['norms'] = [
                    'norms_version' => $normsVersion,
                    'group_id' => $groupId,
                    'source_id' => $sourceId,
                    'status' => $status,
                ];
                $snapshot['spec_version'] = (string) ($normed['spec_version'] ?? ($snapshot['spec_version'] ?? ''));
                $snapshot['item_bank_version'] = (string) ($normed['item_bank_version'] ?? ($snapshot['item_bank_version'] ?? ''));
                $snapshot['engine_version'] = (string) ($normed['engine_version'] ?? ($snapshot['engine_version'] ?? ''));
                $locked->calculation_snapshot_json = $snapshot;
            }

            if ($locked->started_at === null) {
                $locked->started_at = now();
            }
            $locked->save();

            $this->answerPersistence->persist($locked, $mergedAnswers, $durationMs, $scoringSpecVersion);

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
            if ($scaleCode === 'BIG5_OCEAN' && is_array($resultJson['normed_json'] ?? null)) {
                $resultJson = array_merge($resultJson, $resultJson['normed_json']);
            }
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
            throw new ApiProblemException(500, 'INTERNAL_ERROR', 'unexpected submit state.');
        }

        $snapshotJobCtx = null;
        if (($responsePayload['ok'] ?? false) === true && is_array($postCommitCtx)) {
            $snapshotJobCtx = $this->sideEffects->runAfterSubmit($ctx, $postCommitCtx, $actorUserId, $actorAnonId);
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
            $this->sideEffects->recordSubmitEvent(
                $ctx,
                $attemptId,
                $actorUserId,
                $actorAnonId,
                is_array($postCommitCtx) ? $postCommitCtx : []
            );
        }

        $this->sideEffects->appendReportPayload($ctx, $attemptId, $actorUserId, $actorAnonId, $responsePayload);

        if (($responsePayload['ok'] ?? false) === true && $scaleCode === 'BIG5_OCEAN') {
            $reportPayload = is_array($responsePayload['report'] ?? null) ? $responsePayload['report'] : [];
            $scorePayload = is_array($responsePayload['result'] ?? null) ? $responsePayload['result'] : [];
            $normsPayload = is_array($scorePayload['norms'] ?? null) ? $scorePayload['norms'] : [];
            $qualityPayload = is_array($scorePayload['quality'] ?? null) ? $scorePayload['quality'] : [];

            $this->bigFiveTelemetry->recordAttemptSubmitted(
                $orgId,
                $this->numericUserId($actorUserId),
                $actorAnonId,
                $attemptId,
                $locale,
                $region,
                (string) ($normsPayload['status'] ?? 'MISSING'),
                (string) ($normsPayload['group_id'] ?? ''),
                (string) ($qualityPayload['level'] ?? 'D'),
                (string) ($reportPayload['variant'] ?? 'free'),
                (bool) ($reportPayload['locked'] ?? true),
                (bool) ($responsePayload['idempotent'] ?? false)
            );
        }

        return $responsePayload;
    }

    private function ownedAttemptQuery(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId
    ): Builder {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->orgId());

        $role = (string) ($ctx->role() ?? '');
        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        $userId = $this->resolveUserId($ctx, $actorUserId);
        $anonId = $this->resolveAnonId($ctx, $actorAnonId);

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

    private function resolveUserId(OrgContext $ctx, ?string $actorUserId): ?string
    {
        $candidates = [
            $actorUserId,
            $ctx->userId(),
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

    private function resolveAnonId(OrgContext $ctx, ?string $actorAnonId): ?string
    {
        $candidates = [
            $actorAnonId,
            $ctx->anonId(),
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

    private function computeAnswersDigest(array $answers, string $scaleCode, string $packId, string $dirVersion): string
    {
        $canonical = $this->answerPersistence->canonicalJson($answers);
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

    private function numericUserId(?string $userId): ?int
    {
        $userId = trim((string) $userId);
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }
}

<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\AssessmentRunner;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Observability\ClinicalComboTelemetry;
use App\Services\Observability\Sds20Telemetry;
use App\Services\Report\ReportGatekeeper;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleIdentityResolver;
use App\Services\Scale\ScaleIdentityRuntimePolicy;
use App\Services\Scale\ScaleIdentityWriteProjector;
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
        private AttemptDurationResolver $durationResolver,
        private ScaleIdentityResolver $identityResolver,
    ) {}

    public function submit(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto): array
    {
        $guarded = $this->stageGuard($ctx, $attemptId, $dto);
        $canonicalized = $this->stageCanonicalize($guarded);
        $scored = $this->stageScore($canonicalized);
        $tx = $this->stageTx($ctx, $canonicalized, $scored);

        return $this->stagePostCommit($ctx, $canonicalized, $scored, $tx);
    }

    /**
     * @return array<string,mixed>
     */
    private function stageGuard(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'attempt_id is required.');
        }

        $answers = $dto->answers;
        $validityItems = $dto->validityItems;
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
        $this->assertDemoScaleAllowed($scaleCode, $attemptId);

        $this->enforceConsentOnSubmit($scaleCode, $attempt, $dto);

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (! $row) {
            throw new ApiProblemException(404, 'NOT_FOUND', 'scale not found.');
        }
        $projectedIdentity = $this->identityWriteProjector()->projectFromCodes(
            $scaleCode,
            (string) ($attempt->scale_code_v2 ?? ''),
            (string) ($attempt->scale_uid ?? '')
        );
        $scaleCodeV2 = strtoupper(trim((string) ($projectedIdentity['scale_code_v2'] ?? $scaleCode)));
        if ($scaleCodeV2 === '') {
            $scaleCodeV2 = $scaleCode;
        }
        $scaleUid = $projectedIdentity['scale_uid'] ?? null;
        $writeScaleIdentity = $this->runtimePolicy()->shouldWriteScaleIdentityColumns();

        $packId = (string) ($attempt->pack_id ?? $row['default_pack_id'] ?? '');
        $dirVersion = (string) ($attempt->dir_version ?? $row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'scale pack not configured.');
        }

        $region = (string) ($attempt->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($attempt->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        ScaleRolloutGate::assertEnabled($scaleCode, $row, $region, $attemptId);

        return [
            'attempt_id' => $attemptId,
            'dto' => $dto,
            'answers' => $answers,
            'validity_items' => $validityItems,
            'duration_ms' => $durationMs,
            'invite_token' => $inviteToken,
            'actor_user_id' => $actorUserId,
            'actor_anon_id' => $actorAnonId,
            'org_id' => $orgId,
            'attempt' => $attempt,
            'scale_code' => $scaleCode,
            'registry_row' => $row,
            'scale_code_v2' => $scaleCodeV2,
            'scale_uid' => $scaleUid,
            'write_scale_identity' => $writeScaleIdentity,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'region' => $region,
            'locale' => $locale,
        ];
    }

    /**
     * @param  array<string,mixed>  $guarded
     * @return array<string,mixed>
     */
    private function stageCanonicalize(array $guarded): array
    {
        /** @var Attempt $attempt */
        $attempt = $guarded['attempt'];
        $answers = (array) ($guarded['answers'] ?? []);
        $scaleCode = (string) ($guarded['scale_code'] ?? '');
        $packId = (string) ($guarded['pack_id'] ?? '');
        $dirVersion = (string) ($guarded['dir_version'] ?? '');
        $durationMs = (int) ($guarded['duration_ms'] ?? 0);
        $orgId = (int) ($guarded['org_id'] ?? 0);
        $actorAnonId = $guarded['actor_anon_id'] ?? null;
        if ($actorAnonId !== null) {
            $actorAnonId = (string) $actorAnonId;
        }

        $actorUserId = $guarded['actor_user_id'] ?? null;
        if ($actorUserId !== null) {
            $actorUserId = (string) $actorUserId;
        }
        $attemptId = (string) ($guarded['attempt_id'] ?? '');
        $validityItems = (array) ($guarded['validity_items'] ?? []);
        $region = (string) ($guarded['region'] ?? '');
        $locale = (string) ($guarded['locale'] ?? '');

        $draftAnswers = $this->progressService->loadDraftAnswers($attempt);
        $mergedAnswers = $this->mergeAnswersForSubmit($answers, $draftAnswers);
        if (empty($mergedAnswers)) {
            throw new ApiProblemException(422, 'VALIDATION_FAILED', 'answers required.');
        }

        $answersDigest = $this->computeAnswersDigest($mergedAnswers, $scaleCode, $packId, $dirVersion);

        $attemptSummary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $attemptMeta = is_array($attemptSummary['meta'] ?? null) ? $attemptSummary['meta'] : [];
        $packReleaseManifestHash = trim((string) ($attemptMeta['pack_release_manifest_hash'] ?? ''));
        $policyHash = trim((string) ($attemptMeta['policy_hash'] ?? ''));
        $engineVersion = trim((string) ($attemptMeta['engine_version'] ?? ''));
        $submittedAt = now();
        $serverDurationSeconds = $this->durationResolver->resolveServerSecondsFromValues($attempt->started_at, $submittedAt);
        $experiments = $this->resolveScoreExperiments($orgId, $actorAnonId, $actorUserId);

        $scoreContext = [
            'duration_ms' => $durationMs,
            'started_at' => $attempt->started_at,
            'submitted_at' => $submittedAt,
            'server_duration_seconds' => $serverDurationSeconds,
            'region' => $region,
            'locale' => $locale,
            'org_id' => $orgId,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'attempt_id' => $attemptId,
            'anon_id' => $actorAnonId,
            'user_id' => $actorUserId,
            'validity_items' => $validityItems,
            'content_manifest_hash' => $packReleaseManifestHash,
            'policy_hash' => $policyHash,
            'engine_version' => $engineVersion,
            'experiments_json' => $experiments,
        ];

        return array_merge($guarded, [
            'merged_answers' => $mergedAnswers,
            'answers_digest' => $answersDigest,
            'submitted_at' => $submittedAt,
            'server_duration_seconds' => $serverDurationSeconds,
            'experiments' => $experiments,
            'score_context' => $scoreContext,
        ]);
    }

    /**
     * @param  array<string,mixed>  $canonicalized
     * @return array<string,mixed>
     */
    private function stageScore(array $canonicalized): array
    {
        $scaleCode = (string) ($canonicalized['scale_code'] ?? '');
        $orgId = (int) ($canonicalized['org_id'] ?? 0);
        $packId = (string) ($canonicalized['pack_id'] ?? '');
        $dirVersion = (string) ($canonicalized['dir_version'] ?? '');
        $mergedAnswers = (array) ($canonicalized['merged_answers'] ?? []);
        $scoreContext = (array) ($canonicalized['score_context'] ?? []);
        $registryRow = (array) ($canonicalized['registry_row'] ?? []);

        $scored = $this->assessmentRunner->run(
            $scaleCode,
            $orgId,
            $packId,
            $dirVersion,
            $mergedAnswers,
            $scoreContext
        );
        if (! ($scored['ok'] ?? false)) {
            $errorCode = strtoupper(trim((string) ($scored['error'] ?? 'SCORING_FAILED')));
            if ($errorCode === '') {
                $errorCode = 'SCORING_FAILED';
            }
            $status = match ($errorCode) {
                'SCORING_INPUT_INVALID' => 422,
                'SCALE_NOT_FOUND' => 404,
                default => 500,
            };

            throw new ApiProblemException($status, $errorCode, (string) ($scored['message'] ?? 'scoring failed.'));
        }

        $scoreResult = $scored['result'];
        $contentPackageVersion = (string) ($scored['pack']['content_package_version'] ?? '');
        $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');
        $modelSelection = is_array($scored['model_selection'] ?? null)
            ? $scored['model_selection']
            : [];

        $commercial = $registryRow['commercial_json'] ?? null;
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

        return [
            'score_result' => $scoreResult,
            'content_package_version' => $contentPackageVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'model_selection' => $modelSelection,
            'credit_benefit_code' => $creditBenefitCode,
            'entitlement_benefit_code' => $entitlementBenefitCode,
        ];
    }

    /**
     * @param  array<string,mixed>  $canonicalized
     * @param  array<string,mixed>  $scored
     * @return array<string,mixed>
     */
    private function stageTx(OrgContext $ctx, array $canonicalized, array $scored): array
    {
        $attemptId = (string) ($canonicalized['attempt_id'] ?? '');
        $mergedAnswers = (array) ($canonicalized['merged_answers'] ?? []);
        $answersDigest = (string) ($canonicalized['answers_digest'] ?? '');
        $durationMs = (int) ($canonicalized['duration_ms'] ?? 0);
        $orgId = (int) ($canonicalized['org_id'] ?? 0);
        $scaleCode = (string) ($canonicalized['scale_code'] ?? '');
        $packId = (string) ($canonicalized['pack_id'] ?? '');
        $dirVersion = (string) ($canonicalized['dir_version'] ?? '');
        $region = (string) ($canonicalized['region'] ?? '');
        $locale = (string) ($canonicalized['locale'] ?? '');
        $inviteToken = (string) ($canonicalized['invite_token'] ?? '');
        $actorUserId = $canonicalized['actor_user_id'] ?? null;
        if ($actorUserId !== null) {
            $actorUserId = (string) $actorUserId;
        }

        $actorAnonId = $canonicalized['actor_anon_id'] ?? null;
        if ($actorAnonId !== null) {
            $actorAnonId = (string) $actorAnonId;
        }
        $validityItems = (array) ($canonicalized['validity_items'] ?? []);
        $submittedAt = $canonicalized['submitted_at'] ?? now();
        $writeScaleIdentity = (bool) ($canonicalized['write_scale_identity'] ?? false);
        $scaleCodeV2 = (string) ($canonicalized['scale_code_v2'] ?? '');
        $scaleUid = $canonicalized['scale_uid'] ?? null;

        $scoreResult = $scored['score_result'];
        $contentPackageVersion = (string) ($scored['content_package_version'] ?? '');
        $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');
        $modelSelection = is_array($scored['model_selection'] ?? null)
            ? $scored['model_selection']
            : [];
        $creditBenefitCode = (string) ($scored['credit_benefit_code'] ?? '');
        $entitlementBenefitCode = (string) ($scored['entitlement_benefit_code'] ?? '');
        $experiments = is_array($canonicalized['experiments'] ?? null) ? $canonicalized['experiments'] : [];

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
            $modelSelection,
            $experiments,
            $actorUserId,
            $actorAnonId,
            $validityItems,
            $submittedAt,
            $writeScaleIdentity,
            $scaleCodeV2,
            $scaleUid
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
                            'scale_code_v2' => $scaleCodeV2,
                            'scale_uid' => $scaleUid,
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
                        'scale_code_v2' => $scaleCodeV2,
                        'scale_uid' => $scaleUid,
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

            $attemptFill = [
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : null,
                'scoring_spec_version' => $scoringSpecVersion !== '' ? $scoringSpecVersion : null,
                'region' => $region,
                'locale' => $locale,
                'submitted_at' => $submittedAt,
                'duration_ms' => $durationMs,
                'answers_digest' => $answersDigest,
            ];
            if ($writeScaleIdentity) {
                $attemptFill['scale_code_v2'] = $scaleCodeV2;
                $attemptFill['scale_uid'] = $scaleUid;
            }
            $locked->fill($attemptFill);

            if ($scaleCode === 'BIG5_OCEAN') {
                $snapshot = $locked->calculation_snapshot_json;
                if (! is_array($snapshot)) {
                    $snapshot = [];
                }
                $snapshot['validity_items'] = $validityItems;
                $locked->calculation_snapshot_json = $snapshot;
            }

            if ($scaleCode === 'BIG5_OCEAN' && is_array($scoreResult->normedJson ?? null)) {
                $normed = (array) $scoreResult->normedJson;
                $normsNode = is_array($normed['norms'] ?? null) ? $normed['norms'] : [];

                $normsVersion = trim((string) ($normsNode['norms_version'] ?? ($normed['norms_version'] ?? '')));
                $groupId = trim((string) ($normsNode['group_id'] ?? ($normed['group_id'] ?? '')));
                $sourceId = trim((string) ($normsNode['source_id'] ?? ($normed['source_id'] ?? '')));
                $status = strtoupper(trim((string) ($normsNode['status'] ?? ($normed['status'] ?? 'MISSING'))));
                if (! in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
                    $status = 'MISSING';
                }

                if ($normsVersion !== '') {
                    $locked->norm_version = $normsVersion;
                }

                $snapshot = $locked->calculation_snapshot_json;
                if (! is_array($snapshot)) {
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

            if ($scaleCode === 'CLINICAL_COMBO_68' && is_array($scoreResult->normedJson ?? null)) {
                $normed = (array) $scoreResult->normedJson;
                $versionSnapshot = is_array($normed['version_snapshot'] ?? null)
                    ? $normed['version_snapshot']
                    : [];

                $snapshot = $locked->calculation_snapshot_json;
                if (! is_array($snapshot)) {
                    $snapshot = [];
                }

                $snapshot['clinical_combo_68'] = [
                    'pack_id' => (string) ($versionSnapshot['pack_id'] ?? $packId),
                    'pack_version' => (string) ($versionSnapshot['pack_version'] ?? $dirVersion),
                    'policy_version' => (string) ($versionSnapshot['policy_version'] ?? ''),
                    'engine_version' => (string) ($versionSnapshot['engine_version'] ?? data_get($normed, 'engine_version', 'v1.0_2026')),
                    'scoring_spec_version' => (string) ($versionSnapshot['scoring_spec_version'] ?? $scoringSpecVersion),
                    'content_manifest_hash' => (string) ($versionSnapshot['content_manifest_hash'] ?? ''),
                ];
                $snapshot['quality'] = is_array($normed['quality'] ?? null) ? $normed['quality'] : [];
                $locked->calculation_snapshot_json = $snapshot;

                $locked->answers_summary_json = $this->buildClinicalAnswersSummary($mergedAnswers, $normed, $durationMs);
            }

            if ($scaleCode === 'SDS_20' && is_array($scoreResult->normedJson ?? null)) {
                $normed = (array) $scoreResult->normedJson;
                $versionSnapshot = is_array($normed['version_snapshot'] ?? null)
                    ? $normed['version_snapshot']
                    : [];
                $normsNode = is_array($normed['norms'] ?? null) ? $normed['norms'] : [];

                $snapshot = $locked->calculation_snapshot_json;
                if (! is_array($snapshot)) {
                    $snapshot = [];
                }

                $normsVersion = trim((string) ($normsNode['norms_version'] ?? ''));
                if ($normsVersion !== '') {
                    $locked->norm_version = $normsVersion;
                }

                $snapshot['sds_20'] = [
                    'pack_id' => (string) ($versionSnapshot['pack_id'] ?? $packId),
                    'pack_version' => (string) ($versionSnapshot['pack_version'] ?? $dirVersion),
                    'policy_version' => (string) ($versionSnapshot['policy_version'] ?? ''),
                    'policy_hash' => (string) ($versionSnapshot['policy_hash'] ?? ''),
                    'engine_version' => (string) ($versionSnapshot['engine_version'] ?? data_get($normed, 'engine_version', 'v2.0_Factor_Logic')),
                    'scoring_spec_version' => (string) ($versionSnapshot['scoring_spec_version'] ?? $scoringSpecVersion),
                    'content_manifest_hash' => (string) ($versionSnapshot['content_manifest_hash'] ?? ''),
                    'norms' => [
                        'status' => strtoupper(trim((string) ($normsNode['status'] ?? 'MISSING'))),
                        'group_id' => (string) ($normsNode['group_id'] ?? ''),
                        'norms_version' => $normsVersion,
                        'source_id' => (string) ($normsNode['source_id'] ?? ''),
                    ],
                ];
                $snapshot['quality'] = is_array($normed['quality'] ?? null) ? $normed['quality'] : ($snapshot['quality'] ?? []);
                $locked->calculation_snapshot_json = $snapshot;
            }

            if ($scaleCode === 'EQ_60' && is_array($scoreResult->normedJson ?? null)) {
                $normed = (array) $scoreResult->normedJson;
                $versionSnapshot = is_array($normed['version_snapshot'] ?? null)
                    ? $normed['version_snapshot']
                    : [];
                $normsNode = is_array($normed['norms'] ?? null) ? $normed['norms'] : [];
                $qualityNode = is_array($normed['quality'] ?? null) ? $normed['quality'] : [];

                $snapshot = $locked->calculation_snapshot_json;
                if (! is_array($snapshot)) {
                    $snapshot = [];
                }

                $normsVersion = trim((string) ($normsNode['version'] ?? ''));
                if ($normsVersion !== '') {
                    $locked->norm_version = $normsVersion;
                }

                $snapshot['eq_60'] = [
                    'pack_id' => (string) ($versionSnapshot['pack_id'] ?? $packId),
                    'pack_version' => (string) ($versionSnapshot['pack_version'] ?? $dirVersion),
                    'policy_version' => (string) ($versionSnapshot['policy_version'] ?? ''),
                    'policy_hash' => (string) ($versionSnapshot['policy_hash'] ?? ''),
                    'engine_version' => (string) ($versionSnapshot['engine_version'] ?? data_get($normed, 'engine_version', 'v1.0_normed_validity')),
                    'scoring_spec_version' => (string) ($versionSnapshot['scoring_spec_version'] ?? $scoringSpecVersion),
                    'content_manifest_hash' => (string) ($versionSnapshot['content_manifest_hash'] ?? ''),
                    'norms' => [
                        'status' => strtoupper(trim((string) ($normsNode['status'] ?? 'PROVISIONAL'))),
                        'group' => (string) ($normsNode['group'] ?? ''),
                        'version' => $normsVersion,
                    ],
                    'quality' => [
                        'level' => strtoupper(trim((string) ($qualityNode['level'] ?? 'A'))),
                        'flags' => array_values(array_filter(array_map('strval', (array) ($qualityNode['flags'] ?? [])))),
                    ],
                ];
                $snapshot['quality'] = $qualityNode !== [] ? $qualityNode : ($snapshot['quality'] ?? []);
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
            if (in_array($scaleCode, ['BIG5_OCEAN', 'SDS_20', 'EQ_60'], true) && is_array($resultJson['normed_json'] ?? null)) {
                $resultJson = array_merge($resultJson, $resultJson['normed_json']);
            }
            if (is_array($resultJson['quality'] ?? null)) {
                $resultJson['quality']['client_duration_ms'] = $durationMs;
            }
            $responseCodes = $this->responseProjector()->project(
                $scaleCode,
                $scaleCodeV2,
                $scaleUid
            );
            $resultJson['scale_code'] = $responseCodes['scale_code'];
            $resultJson['scale_code_legacy'] = $responseCodes['scale_code_legacy'];
            $resultJson['scale_code_v2'] = $responseCodes['scale_code_v2'];
            $resultJson['scale_uid'] = $responseCodes['scale_uid'];
            $resultJson['pack_id'] = $packId;
            $resultJson['dir_version'] = $dirVersion;
            $resultJson['content_package_version'] = $contentPackageVersion;
            $resultJson['scoring_spec_version'] = $scoringSpecVersion;
            if ($modelSelection !== []) {
                $resultJson['model_selection'] = $modelSelection;
            }
            if ($experiments !== []) {
                $resultJson['experiments_json'] = $experiments;
            }
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
            if ($writeScaleIdentity) {
                $resultData['scale_code_v2'] = $scaleCodeV2;
                $resultData['scale_uid'] = $scaleUid;
            }

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
                'scale_code_v2' => $scaleCodeV2,
                'scale_uid' => $scaleUid,
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

        return [
            'response_payload' => $responsePayload,
            'post_commit_ctx' => $postCommitCtx,
        ];
    }

    /**
     * @param  array<string,mixed>  $canonicalized
     * @param  array<string,mixed>  $scored
     * @param  array<string,mixed>  $tx
     * @return array<string,mixed>
     */
    private function stagePostCommit(OrgContext $ctx, array $canonicalized, array $scored, array $tx): array
    {
        /** @var Attempt $attempt */
        $attempt = $canonicalized['attempt'];
        $attemptId = (string) ($canonicalized['attempt_id'] ?? '');
        $orgId = (int) ($canonicalized['org_id'] ?? 0);
        $scaleCode = (string) ($canonicalized['scale_code'] ?? '');
        $locale = (string) ($canonicalized['locale'] ?? '');
        $region = (string) ($canonicalized['region'] ?? '');
        $dirVersion = (string) ($canonicalized['dir_version'] ?? '');
        $actorUserId = $canonicalized['actor_user_id'] ?? null;
        if ($actorUserId !== null) {
            $actorUserId = (string) $actorUserId;
        }

        $actorAnonId = $canonicalized['actor_anon_id'] ?? null;
        if ($actorAnonId !== null) {
            $actorAnonId = (string) $actorAnonId;
        }
        $scoringSpecVersion = (string) ($scored['scoring_spec_version'] ?? '');
        $responsePayload = is_array($tx['response_payload'] ?? null) ? $tx['response_payload'] : [];
        $postCommitCtx = is_array($tx['post_commit_ctx'] ?? null) ? $tx['post_commit_ctx'] : null;

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

            $this->bigFiveTelemetry()->recordAttemptSubmitted(
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
                (bool) ($responsePayload['idempotent'] ?? false),
                $dirVersion,
                null,
                (string) ($normsPayload['norms_version'] ?? '')
            );
        }

        if (($responsePayload['ok'] ?? false) === true && in_array($scaleCode, ['CLINICAL_COMBO_68', 'SDS_20'], true)) {
            $reportPayload = is_array($responsePayload['report'] ?? null) ? $responsePayload['report'] : [];
            $scorePayload = is_array($responsePayload['result'] ?? null) ? $responsePayload['result'] : [];
            $qualityPayload = is_array($scorePayload['quality'] ?? null)
                ? $scorePayload['quality']
                : (is_array(data_get($scorePayload, 'normed_json.quality')) ? data_get($scorePayload, 'normed_json.quality') : []);
            $crisisReasons = array_values(array_filter(array_map('strval', (array) ($qualityPayload['crisis_reasons'] ?? []))));

            $telemetry = $scaleCode === 'CLINICAL_COMBO_68'
                ? app(ClinicalComboTelemetry::class)
                : app(Sds20Telemetry::class);

            $telemetry->attemptSubmitted($attempt, [
                'quality_level' => strtoupper(trim((string) ($qualityPayload['level'] ?? 'D'))),
                'variant' => strtolower(trim((string) ($reportPayload['variant'] ?? 'free'))),
                'locked' => (bool) ($reportPayload['locked'] ?? true),
                'idempotent' => (bool) ($responsePayload['idempotent'] ?? false),
                'scoring_spec_version' => $scoringSpecVersion,
            ]);

            $telemetry->attemptScored($attempt, $scorePayload);

            if ((bool) ($qualityPayload['crisis_alert'] ?? false) === true) {
                $telemetry->crisisTriggered($attempt, [
                    'quality_level' => strtoupper(trim((string) ($qualityPayload['level'] ?? 'D'))),
                    'crisis_reasons' => $crisisReasons,
                ]);
            }
        }

        return $responsePayload;
    }

    private function bigFiveTelemetry(): BigFiveTelemetry
    {
        return app(BigFiveTelemetry::class);
    }

    private function enforceConsentOnSubmit(string $scaleCode, Attempt $attempt, SubmitAttemptDTO $dto): void
    {
        if (! in_array($scaleCode, ['CLINICAL_COMBO_68', 'SDS_20'], true)) {
            return;
        }

        $requiredCode = $scaleCode === 'SDS_20' ? 'SDS20_CONSENT_REQUIRED' : 'CONSENT_REQUIRED';
        $mismatchCode = $scaleCode === 'SDS_20' ? 'CONSENT_MISMATCH_SDS20' : 'CONSENT_MISMATCH';

        $summary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $storedConsent = is_array($meta['consent'] ?? null) ? $meta['consent'] : [];

        $storedAccepted = (bool) ($storedConsent['accepted'] ?? false);
        $storedVersion = trim((string) ($storedConsent['version'] ?? ''));
        $storedHash = trim((string) ($storedConsent['hash'] ?? ''));

        $accepted = (bool) ($dto->consentAccepted ?? false);
        $version = trim((string) ($dto->consentVersion ?? ''));
        $hash = trim((string) ($dto->consentHash ?? ''));

        if ($accepted !== true || $version === '') {
            throw new ApiProblemException(422, $requiredCode, "consent is required for {$scaleCode} submit.");
        }
        if ($storedAccepted !== true || $storedVersion === '') {
            throw new ApiProblemException(422, $requiredCode, "consent snapshot missing for {$scaleCode} submit.");
        }
        if ($version !== $storedVersion) {
            throw new ApiProblemException(422, $mismatchCode, 'consent version/hash mismatch.');
        }
        if ($storedHash !== '') {
            if ($hash === '') {
                throw new ApiProblemException(422, $requiredCode, "consent hash is required for {$scaleCode} submit.");
            }
            if (! hash_equals($storedHash, $hash)) {
                throw new ApiProblemException(422, $mismatchCode, 'consent version/hash mismatch.');
            }
        }
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

        $responseCodes = $this->responseProjector()->project(
            (string) ($attempt->scale_code ?? ''),
            (string) ($attempt->scale_code_v2 ?? ''),
            $attempt->scale_uid !== null ? (string) $attempt->scale_uid : null
        );
        $payload['scale_code'] = $responseCodes['scale_code'];
        $payload['scale_code_legacy'] = $responseCodes['scale_code_legacy'];
        $payload['scale_code_v2'] = $responseCodes['scale_code_v2'];
        $payload['scale_uid'] = $responseCodes['scale_uid'];

        return [
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'type_code' => $compatTypeCode,
            'scores' => $compatScores,
            'scores_pct' => $compatScoresPct,
            'result' => $payload,
            'meta' => [
                'scale_code' => $responseCodes['scale_code'],
                'scale_code_legacy' => $responseCodes['scale_code_legacy'],
                'scale_code_v2' => $responseCodes['scale_code_v2'],
                'scale_uid' => $responseCodes['scale_uid'],
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
        if ($userId === '' || ! preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * @return array<string,string>
     */
    private function resolveScoreExperiments(int $orgId, ?string $actorAnonId, ?string $actorUserId): array
    {
        $anonId = trim((string) ($actorAnonId ?? ''));
        if ($anonId === '') {
            return [];
        }

        try {
            $assignments = app(ExperimentAssigner::class)->assignActive(
                $orgId,
                $anonId,
                $this->numericUserId($actorUserId)
            );
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($assignments)) {
            return [];
        }

        $normalized = [];
        foreach ($assignments as $key => $variant) {
            $experimentKey = trim((string) $key);
            $experimentVariant = trim((string) $variant);
            if ($experimentKey === '' || $experimentVariant === '') {
                continue;
            }
            $normalized[$experimentKey] = $experimentVariant;
        }

        return $normalized;
    }

    private function assertDemoScaleAllowed(string $scaleCode, string $attemptId): void
    {
        $legacyCode = strtoupper(trim($scaleCode));
        if ($legacyCode === '' || $this->identityResolver->shouldAllowDemoScale($legacyCode)) {
            return;
        }

        $replacementLegacy = $this->identityResolver->demoReplacement($legacyCode);
        $replacementV2 = null;
        if ($replacementLegacy !== null) {
            $replacementIdentity = $this->identityResolver->resolveByAnyCode($replacementLegacy);
            if (is_array($replacementIdentity) && ((bool) ($replacementIdentity['is_known'] ?? false))) {
                $resolved = strtoupper(trim((string) ($replacementIdentity['scale_code_v2'] ?? '')));
                if ($resolved !== '') {
                    $replacementV2 = $resolved;
                }
            }
        }

        throw new ApiProblemException(
            410,
            'SCALE_DEPRECATED',
            'scale is deprecated.',
            [
                'attempt_id' => $attemptId,
                'scale_code_legacy' => $legacyCode,
                'replacement_scale_code' => $replacementLegacy,
                'replacement_scale_code_v2' => $replacementV2,
            ]
        );
    }

    private function runtimePolicy(): ScaleIdentityRuntimePolicy
    {
        return app(ScaleIdentityRuntimePolicy::class);
    }

    private function responseProjector(): ScaleCodeResponseProjector
    {
        return app(ScaleCodeResponseProjector::class);
    }

    private function identityWriteProjector(): ScaleIdentityWriteProjector
    {
        return app(ScaleIdentityWriteProjector::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $answers
     * @param  array<string,mixed>  $normed
     * @return array<string,mixed>
     */
    private function buildClinicalAnswersSummary(array $answers, array $normed, int $durationMs): array
    {
        $counts = [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'E' => 0,
        ];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $code = $this->normalizeAnswerCode($answer['code'] ?? null);
            if ($code === null) {
                continue;
            }
            $counts[$code] = (int) ($counts[$code] ?? 0) + 1;
        }

        $quality = is_array($normed['quality'] ?? null) ? $normed['quality'] : [];
        $metrics = is_array($quality['metrics'] ?? null) ? $quality['metrics'] : [];
        $versionSnapshot = is_array($normed['version_snapshot'] ?? null) ? $normed['version_snapshot'] : [];

        return [
            'stage' => 'submit',
            'counts' => $counts,
            'neutral_rate' => (float) ($metrics['neutral_rate'] ?? 0.0),
            'extreme_rate' => (float) ($metrics['extreme_rate'] ?? 0.0),
            'longstring_max' => (int) ($metrics['longstring_max'] ?? 0),
            'quality_level' => (string) ($quality['level'] ?? 'D'),
            'flags' => array_values(array_filter(array_map('strval', (array) ($quality['flags'] ?? [])))),
            'crisis_alert' => (bool) ($quality['crisis_alert'] ?? false),
            'completion_time_seconds' => (int) ($quality['completion_time_seconds'] ?? 0),
            'duration_ms_client' => $durationMs,
            'versions' => [
                'pack_id' => (string) ($versionSnapshot['pack_id'] ?? 'CLINICAL_COMBO_68'),
                'pack_version' => (string) ($versionSnapshot['pack_version'] ?? ''),
                'policy_version' => (string) ($versionSnapshot['policy_version'] ?? ''),
                'engine_version' => (string) ($versionSnapshot['engine_version'] ?? data_get($normed, 'engine_version', 'v1.0_2026')),
                'scoring_spec_version' => (string) ($versionSnapshot['scoring_spec_version'] ?? ''),
                'content_manifest_hash' => (string) ($versionSnapshot['content_manifest_hash'] ?? ''),
            ],
        ];
    }

    private function normalizeAnswerCode(mixed $raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['code'] ?? ($raw['value'] ?? null);
        }

        if (is_string($raw)) {
            $value = strtoupper(trim($raw));
            if (preg_match('/^[A-E]$/', $value) === 1) {
                return $value;
            }
            if (preg_match('/^[0-5]$/', $value) === 1) {
                $n = (int) $value;
                if ($n >= 1 && $n <= 5) {
                    return ['A', 'B', 'C', 'D', 'E'][$n - 1];
                }
                if ($n >= 0 && $n <= 4) {
                    return ['A', 'B', 'C', 'D', 'E'][$n];
                }
            }
        }

        if (is_int($raw) || is_float($raw)) {
            $n = (int) $raw;
            if ($n >= 1 && $n <= 5) {
                return ['A', 'B', 'C', 'D', 'E'][$n - 1];
            }
            if ($n >= 0 && $n <= 4) {
                return ['A', 'B', 'C', 'D', 'E'][$n];
            }
        }

        return null;
    }
}

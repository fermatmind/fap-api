<?php

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Result;
use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttemptSubmitTxService
{
    public function __construct(private AttemptSubmitService $core) {}

    public function handle(OrgContext $ctx, array $canonicalized, array $scored): array
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
        $shareId = trim((string) ($canonicalized['share_id'] ?? ''));
        $compareInviteId = trim((string) ($canonicalized['compare_invite_id'] ?? ''));
        $shareClickId = trim((string) ($canonicalized['share_click_id'] ?? ''));
        $entrypoint = trim((string) ($canonicalized['entrypoint'] ?? ''));
        $referrer = trim((string) ($canonicalized['referrer'] ?? ''));
        $landingPath = trim((string) ($canonicalized['landing_path'] ?? ''));
        $utm = is_array($canonicalized['utm'] ?? null) ? $canonicalized['utm'] : [];
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
            $shareId,
            $compareInviteId,
            $shareClickId,
            $entrypoint,
            $referrer,
            $landingPath,
            $utm,
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
            $locked = $this->core->ownedAttemptQuery($ctx, $attemptId, $actorUserId, $actorAnonId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
            }

            $existingDigest = trim((string) ($locked->answers_digest ?? ''));
            if ($locked->submitted_at && $existingDigest !== '') {
                if ($existingDigest === $answersDigest) {
                    $existingResult = $this->core->findResult($orgId, $attemptId);
                    if ($existingResult) {
                        $responsePayload = $this->core->buildSubmitPayload($locked, $existingResult, false);
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
                            'share_id' => $shareId,
                            'compare_invite_id' => $compareInviteId,
                            'share_click_id' => $shareClickId,
                            'entrypoint' => $entrypoint,
                            'referrer' => $referrer,
                            'landing_path' => $landingPath,
                            'utm' => $utm,
                            'credit_benefit_code' => $creditBenefitCode,
                            'entitlement_benefit_code' => $entitlementBenefitCode,
                        ];

                        return;
                    }
                }

                throw new ApiProblemException(409, 'CONFLICT', 'attempt already submitted with different answers.');
            }

            if ($locked->submitted_at && $existingDigest === '') {
                $existingResult = $this->core->findResult($orgId, $attemptId);
                if ($existingResult) {
                    $responsePayload = $this->core->buildSubmitPayload($locked, $existingResult, false);
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
                        'share_id' => $shareId,
                        'compare_invite_id' => $compareInviteId,
                        'share_click_id' => $shareClickId,
                        'entrypoint' => $entrypoint,
                        'referrer' => $referrer,
                        'landing_path' => $landingPath,
                        'utm' => $utm,
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

                $locked->answers_summary_json = $this->core->buildClinicalAnswersSummary($mergedAnswers, $normed, $durationMs);
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

            $answersSummary = is_array($locked->answers_summary_json ?? null) ? $locked->answers_summary_json : [];
            $answersSummaryMeta = is_array($answersSummary['meta'] ?? null) ? $answersSummary['meta'] : [];
            foreach ([
                'share_id' => $shareId,
                'compare_invite_id' => $compareInviteId,
                'share_click_id' => $shareClickId,
                'entrypoint' => $entrypoint,
                'referrer' => $referrer,
                'landing_path' => $landingPath,
            ] as $field => $value) {
                if ($value !== '') {
                    $answersSummaryMeta[$field] = $value;
                }
            }
            if ($utm !== []) {
                $answersSummaryMeta['utm'] = $utm;
            }
            $answersSummary['meta'] = $answersSummaryMeta;
            $locked->answers_summary_json = $answersSummary;

            $locked->save();

            $this->core->answerPersistence()->persist($locked, $mergedAnswers, $durationMs, $scoringSpecVersion);

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
            if (
                $scaleCode === 'MBTI'
                &&
                ! is_array($resultJson['quality'] ?? null)
                && is_array($resultJson['normed_json']['quality'] ?? null)
            ) {
                $resultJson['quality'] = $resultJson['normed_json']['quality'];
            }
            if (is_array($resultJson['quality'] ?? null)) {
                $resultJson['quality']['client_duration_ms'] = $durationMs;
            }
            $responseCodes = $this->core->responseProjector()->project(
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

            $existingResult = $this->core->findResult($orgId, $attemptId);
            if ($existingResult) {
                $existingResult->fill($resultData);
                $existingResult->save();
                $result = $existingResult;
            } else {
                $resultData['id'] = (string) Str::uuid();
                $result = Result::create($resultData);
            }

            $responsePayload = $this->core->buildSubmitPayload($locked, $result, true);
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
                'share_id' => $shareId,
                'compare_invite_id' => $compareInviteId,
                'share_click_id' => $shareClickId,
                'entrypoint' => $entrypoint,
                'referrer' => $referrer,
                'landing_path' => $landingPath,
                'utm' => $utm,
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
}

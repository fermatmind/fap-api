<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Services\Analytics\EventRecorder;
use App\Services\Assessments\AssessmentService;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Report\ReportGatekeeper;
use App\Services\Report\ReportSnapshotStore;
use App\Support\OrgContext;
use Illuminate\Support\Facades\Log;

final class AttemptSubmitSideEffects
{
    private const B2B_CREDIT_BENEFIT_CODE = 'B2B_ASSESSMENT_ATTEMPT_SUBMIT';

    public function __construct(
        private AssessmentService $assessments,
        private BenefitWalletService $benefitWallets,
        private EntitlementManager $entitlements,
        private ReportSnapshotStore $reportSnapshots,
        private EventRecorder $eventRecorder,
        private ReportGatekeeper $reportGatekeeper,
    ) {
    }

    public function runAfterSubmit(OrgContext $ctx, array $payload, ?string $actorUserId, ?string $actorAnonId): ?array
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
                    'exception' => $e,
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
                    'exception' => $e,
                ]);
            }
        }

        if ($orgId > 0 && $creditBenefitCode !== '' && !$consumeB2BCredit) {
            try {
                $consume = $this->benefitWallets->consume($orgId, $creditBenefitCode, $attemptId);
                $creditOk = (bool) ($consume['ok'] ?? false);
                if ($creditOk) {
                    $this->eventRecorder->record('wallet_consumed', $this->resolveUserIdInt($ctx, $actorUserId), [
                        'scale_code' => $scaleCode,
                        'pack_id' => $packId,
                        'dir_version' => $dirVersion,
                        'attempt_id' => $attemptId,
                        'benefit_code' => $creditBenefitCode,
                        'sku' => null,
                    ], [
                        'org_id' => $orgId,
                        'anon_id' => $actorAnonId,
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
                    'exception' => $e,
                ]);
            }
        }

        if ($creditOk && $orgId > 0 && $entitlementBenefitCode !== '') {
            $userIdRaw = $this->resolveUserId($ctx, $actorUserId);
            $anonIdRaw = $this->resolveAnonId($ctx, $actorAnonId);

            try {
                $grant = $this->entitlements->grantAttemptUnlock(
                    $orgId,
                    $userIdRaw,
                    $anonIdRaw,
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
                    'exception' => $e,
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
                'exception' => $e,
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

    public function recordSubmitEvent(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId,
        array $postCommitCtx
    ): void {
        $this->eventRecorder->record('test_submit', $this->resolveUserIdInt($ctx, $actorUserId), [
            'scale_code' => (string) ($postCommitCtx['scale_code'] ?? ''),
            'pack_id' => (string) ($postCommitCtx['pack_id'] ?? ''),
            'dir_version' => (string) ($postCommitCtx['dir_version'] ?? ''),
            'attempt_id' => $attemptId,
        ], [
            'org_id' => $ctx->orgId(),
            'anon_id' => $actorAnonId,
            'attempt_id' => $attemptId,
            'pack_id' => (string) ($postCommitCtx['pack_id'] ?? ''),
            'dir_version' => (string) ($postCommitCtx['dir_version'] ?? ''),
        ]);
    }

    public function appendReportPayload(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId,
        array &$responsePayload
    ): void {
        $userId = $this->resolveUserId($ctx, $actorUserId);
        $anonId = $this->resolveAnonId($ctx, $actorAnonId);

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
                'exception' => $e,
            ]);

            $responsePayload['report'] = [
                'ok' => false,
                'locked' => true,
                'access_level' => 'free',
                'variant' => 'free',
            ];

            return;
        }

        if (!($gate['ok'] ?? false)) {
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
                'variant' => 'free',
            ];

            return;
        }

        $responsePayload['report'] = $gate;
    }

    /**
     * @param array<string,mixed> $resultPayload
     * @param array<string,mixed> $versionMeta
     */
    public function recordClinicalScoreEvent(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId,
        array $resultPayload,
        array $versionMeta = []
    ): void {
        $contract = $this->extractScoreContract($resultPayload);
        $quality = is_array($contract['quality'] ?? null) ? $contract['quality'] : [];
        $scores = is_array($contract['scores'] ?? null) ? $contract['scores'] : [];
        $snapshot = is_array($contract['version_snapshot'] ?? null) ? $contract['version_snapshot'] : [];

        $this->eventRecorder->record(
            'clinical_combo_68_scored',
            $this->resolveUserIdInt($ctx, $actorUserId),
            [
                'scale_code' => 'CLINICAL_COMBO_68',
                'attempt_id' => $attemptId,
                'quality' => [
                    'level' => (string) ($quality['level'] ?? 'D'),
                    'flags' => array_values(array_filter(array_map('strval', (array) ($quality['flags'] ?? [])))),
                    'crisis_alert' => (bool) ($quality['crisis_alert'] ?? false),
                ],
                'scores' => $this->extractClinicalScoreSnapshot($scores),
                'versions' => [
                    'pack_id' => (string) ($snapshot['pack_id'] ?? ($versionMeta['pack_id'] ?? 'CLINICAL_COMBO_68')),
                    'pack_version' => (string) ($snapshot['pack_version'] ?? ''),
                    'policy_version' => (string) ($snapshot['policy_version'] ?? ''),
                    'engine_version' => (string) ($snapshot['engine_version'] ?? 'v1.0_2026'),
                    'scoring_spec_version' => (string) ($snapshot['scoring_spec_version'] ?? ($versionMeta['scoring_spec_version'] ?? 'v1.0_2026')),
                    'content_manifest_hash' => (string) ($snapshot['content_manifest_hash'] ?? ''),
                ],
            ],
            [
                'org_id' => $ctx->orgId(),
                'anon_id' => $actorAnonId,
                'attempt_id' => $attemptId,
                'pack_id' => (string) ($versionMeta['pack_id'] ?? ''),
                'dir_version' => (string) ($versionMeta['dir_version'] ?? ''),
            ]
        );
    }

    private function resolveUserId(OrgContext $ctx, ?string $actorUserId): ?string
    {
        $candidates = [$actorUserId, $ctx->userId()];

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
        $candidates = [$actorAnonId, $ctx->anonId()];

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

    private function resolveUserIdInt(OrgContext $ctx, ?string $actorUserId): ?int
    {
        $value = $this->resolveUserId($ctx, $actorUserId);
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractScoreContract(array $payload): array
    {
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (strtoupper((string) ($candidate['scale_code'] ?? '')) !== 'CLINICAL_COMBO_68') {
                continue;
            }

            return $candidate;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scores
     * @return array<string,array<string,mixed>>
     */
    private function extractClinicalScoreSnapshot(array $scores): array
    {
        $out = [];
        foreach (['depression', 'anxiety', 'stress', 'resilience', 'perfectionism', 'ocd'] as $dim) {
            $node = is_array($scores[$dim] ?? null) ? $scores[$dim] : [];
            $out[$dim] = [
                'raw' => (int) ($node['raw'] ?? 0),
                't_score' => (int) ($node['t_score'] ?? 0),
                'level' => (string) ($node['level'] ?? ''),
            ];
        }

        return $out;
    }
}

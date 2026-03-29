<?php

namespace App\Services\Attempts;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Attempt;
use App\Services\Observability\ClinicalComboTelemetry;
use App\Services\Observability\Sds20Telemetry;
use App\Support\OrgContext;

class AttemptSubmitPostCommitService
{
    public function __construct(private AttemptSubmitService $core) {}

    public function handle(OrgContext $ctx, array $canonicalized, array $scored, array $tx): array
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
            $snapshotJobCtx = $this->core->sideEffects()->runAfterSubmit($ctx, $postCommitCtx, $actorUserId, $actorAnonId);
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
            $this->core->progressService()->clearProgress($attemptId);
            $this->core->sideEffects()->recordSubmitEvent(
                $ctx,
                $attemptId,
                $actorUserId,
                $actorAnonId,
                is_array($postCommitCtx) ? $postCommitCtx : []
            );
        }

        $this->core->sideEffects()->appendReportPayload($ctx, $attemptId, $actorUserId, $actorAnonId, $responsePayload);

        if (($responsePayload['ok'] ?? false) === true && $scaleCode === 'BIG5_OCEAN') {
            $reportPayload = is_array($responsePayload['report'] ?? null) ? $responsePayload['report'] : [];
            $scorePayload = is_array($responsePayload['result'] ?? null) ? $responsePayload['result'] : [];
            $normsPayload = is_array($scorePayload['norms'] ?? null) ? $scorePayload['norms'] : [];
            $qualityPayload = is_array($scorePayload['quality'] ?? null) ? $scorePayload['quality'] : [];

            $this->core->bigFiveTelemetry()->recordAttemptSubmitted(
                $orgId,
                $this->core->numericUserId($actorUserId),
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
}

<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\AssessmentRunner;
use App\Services\Attempts\AttemptSubmitCanonicalizeService;
use App\Services\Attempts\AttemptSubmitGuardService;
use App\Services\Attempts\AttemptSubmitPostCommitService;
use App\Services\Attempts\AttemptSubmitScoreService;
use App\Services\Attempts\AttemptSubmitTxService;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Report\ReportGatekeeper;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleIdentityResolver;
use App\Services\Scale\ScaleIdentityRuntimePolicy;
use App\Services\Scale\ScaleIdentityWriteProjector;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;

class AttemptSubmitService
{
    private AttemptSubmitGuardService $guardStage;

    private AttemptSubmitCanonicalizeService $canonicalizeStage;

    private AttemptSubmitScoreService $scoreStage;

    private AttemptSubmitTxService $txStage;

    private AttemptSubmitPostCommitService $postCommitStage;

    public function __construct(
        private ScaleRegistry $registry,
        private AttemptProgressService $progressService,
        private AttemptAnswerPersistence $answerPersistence,
        private AssessmentRunner $assessmentRunner,
        private AttemptSubmitSideEffects $sideEffects,
        private ReportGatekeeper $reportGatekeeper,
        private AttemptDurationResolver $durationResolver,
        private ScaleIdentityResolver $identityResolver,
    ) {
        $this->guardStage = new AttemptSubmitGuardService($this);
        $this->canonicalizeStage = new AttemptSubmitCanonicalizeService($this);
        $this->scoreStage = new AttemptSubmitScoreService($this);
        $this->txStage = new AttemptSubmitTxService($this);
        $this->postCommitStage = new AttemptSubmitPostCommitService($this);
    }

    public function submit(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto): array
    {
        $guarded = $this->guardStage->handle($ctx, $attemptId, $dto);
        $canonicalized = $this->canonicalizeStage->handle($guarded);
        $scored = $this->scoreStage->handle($canonicalized);
        $tx = $this->txStage->handle($ctx, $canonicalized, $scored);

        return $this->postCommitStage->handle($ctx, $canonicalized, $scored, $tx);
    }

    public function registry(): ScaleRegistry
    {
        return $this->registry;
    }

    public function progressService(): AttemptProgressService
    {
        return $this->progressService;
    }

    public function answerPersistence(): AttemptAnswerPersistence
    {
        return $this->answerPersistence;
    }

    public function assessmentRunner(): AssessmentRunner
    {
        return $this->assessmentRunner;
    }

    public function sideEffects(): AttemptSubmitSideEffects
    {
        return $this->sideEffects;
    }

    public function durationResolver(): AttemptDurationResolver
    {
        return $this->durationResolver;
    }

    public function identityResolver(): ScaleIdentityResolver
    {
        return $this->identityResolver;
    }

    public function bigFiveTelemetry(): BigFiveTelemetry
    {
        return app(BigFiveTelemetry::class);
    }

    public function enforceConsentOnSubmit(string $scaleCode, Attempt $attempt, SubmitAttemptDTO $dto): void
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

    public function ownedAttemptQuery(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId
    ): Builder {
        $query = Attempt::onWriteConnection()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->scopedOrgId());

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

    public function resolveUserId(OrgContext $ctx, ?string $actorUserId): ?string
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

    public function resolveAnonId(OrgContext $ctx, ?string $actorAnonId): ?string
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

    public function computeAnswersDigest(array $answers, string $scaleCode, string $packId, string $dirVersion): string
    {
        $canonical = $this->answerPersistence->canonicalJson($answers);
        $raw = strtoupper($scaleCode).'|'.$packId.'|'.$dirVersion.'|'.$canonical;

        return hash('sha256', $raw);
    }

    public function mergeAnswersForSubmit(array $requestAnswers, array $draftAnswers): array
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

    public function buildSubmitPayload(Attempt $attempt, Result $result, bool $fresh): array
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

    public function findResult(int $orgId, string $attemptId): ?Result
    {
        return Result::where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();
    }

    public function numericUserId(?string $userId): ?int
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
    public function resolveScoreExperiments(int $orgId, ?string $actorAnonId, ?string $actorUserId): array
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

    public function assertDemoScaleAllowed(string $scaleCode, string $attemptId): void
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

    public function runtimePolicy(): ScaleIdentityRuntimePolicy
    {
        return app(ScaleIdentityRuntimePolicy::class);
    }

    public function responseProjector(): ScaleCodeResponseProjector
    {
        return app(ScaleCodeResponseProjector::class);
    }

    public function identityWriteProjector(): ScaleIdentityWriteProjector
    {
        return app(ScaleIdentityWriteProjector::class);
    }

    /**
     * @param  array<int,array<string,mixed>>  $answers
     * @param  array<string,mixed>  $normed
     * @return array<string,mixed>
     */
    public function buildClinicalAnswersSummary(array $answers, array $normed, int $durationMs): array
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

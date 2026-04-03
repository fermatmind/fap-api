<?php

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;

class AttemptSubmitCanonicalizeService
{
    public function __construct(private AttemptSubmitService $core) {}

    public function handle(array $guarded): array
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

        $draftAnswers = $this->core->progressService()->loadDraftAnswers($attempt);
        $mergedAnswers = $this->core->mergeAnswersForSubmit($answers, $draftAnswers);
        if (empty($mergedAnswers)) {
            throw new ApiProblemException(422, 'VALIDATION_FAILED', 'answers required.');
        }

        $answersDigest = $this->core->computeAnswersDigest($mergedAnswers, $scaleCode, $packId, $dirVersion);

        $attemptSummary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $attemptMeta = is_array($attemptSummary['meta'] ?? null) ? $attemptSummary['meta'] : [];
        $packReleaseManifestHash = trim((string) ($attemptMeta['pack_release_manifest_hash'] ?? ''));
        $policyHash = trim((string) ($attemptMeta['policy_hash'] ?? ''));
        $engineVersion = trim((string) ($attemptMeta['engine_version'] ?? ''));
        $scoringSpecVersion = trim((string) ($attempt->scoring_spec_version ?? ''));
        $normVersion = trim((string) ($attempt->norm_version ?? ''));
        $submittedAt = now();
        $serverDurationSeconds = $this->core->durationResolver()->resolveServerSecondsFromValues($attempt->started_at, $submittedAt);
        $experiments = $this->core->resolveScoreExperiments($orgId, $actorAnonId, $actorUserId);

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
            'scoring_spec_version' => $scoringSpecVersion,
            'norm_version' => $normVersion,
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
}

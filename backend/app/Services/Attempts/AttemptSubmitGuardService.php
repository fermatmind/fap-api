<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Support\OrgContext;
use App\Services\Scale\ScaleRolloutGate;

class AttemptSubmitGuardService
{
    public function __construct(private AttemptSubmitService $core)
    {
    }

    public function handle(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto): array
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'attempt_id is required.');
        }

        $answers = $dto->answers;
        $validityItems = $dto->validityItems;
        $durationMs = $dto->durationMs;
        $inviteToken = $dto->inviteToken;
        $actorUserId = $this->core->resolveUserId($ctx, $dto->userId);
        $actorAnonId = $this->core->resolveAnonId($ctx, $dto->anonId);

        $orgId = $ctx->orgId();
        $attempt = $this->core->ownedAttemptQuery($ctx, $attemptId, $actorUserId, $actorAnonId)->firstOrFail();

        $scaleCode = strtoupper((string) ($attempt->scale_code ?? ''));
        if ($scaleCode === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'scale_code missing on attempt.');
        }
        $this->core->assertDemoScaleAllowed($scaleCode, $attemptId);

        $this->core->enforceConsentOnSubmit($scaleCode, $attempt, $dto);

        $row = $this->core->registry()->getByCode($scaleCode, $orgId);
        if (! $row) {
            throw new ApiProblemException(404, 'NOT_FOUND', 'scale not found.');
        }
        $projectedIdentity = $this->core->identityWriteProjector()->projectFromCodes(
            $scaleCode,
            (string) ($attempt->scale_code_v2 ?? ''),
            (string) ($attempt->scale_uid ?? '')
        );
        $scaleCodeV2 = strtoupper(trim((string) ($projectedIdentity['scale_code_v2'] ?? $scaleCode)));
        if ($scaleCodeV2 === '') {
            $scaleCodeV2 = $scaleCode;
        }
        $scaleUid = $projectedIdentity['scale_uid'] ?? null;
        $writeScaleIdentity = $this->core->runtimePolicy()->shouldWriteScaleIdentityColumns();

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
}

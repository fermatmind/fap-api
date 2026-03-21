<?php

declare(strict_types=1);

namespace App\Services\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\MbtiCompareInvite;
use App\Models\Result;
use App\Models\Share;
use App\Services\InsightGraph\PrivateRelationshipContractService;
use App\Services\InsightGraph\RelationshipSyncContractService;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class MbtiCompareInviteService
{
    public function __construct(
        private readonly ShareService $shareService,
        private readonly MbtiPublicProjectionService $mbtiPublicProjectionService,
        private readonly MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
        private readonly RelationshipSyncContractService $relationshipSyncContractService,
        private readonly PrivateRelationshipContractService $privateRelationshipContractService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(string $shareId, array $payload): array
    {
        [$share, $attempt, $result] = $this->resolveMbtiShareContext($shareId);
        $inviterPayload = $this->shareService->buildPublicSummaryPayload($attempt, $result, (string) $share->id, $share->created_at?->toISOString());
        $locale = (string) ($inviterPayload['locale'] ?? 'zh-CN');

        $invite = new MbtiCompareInvite;
        $invite->id = (string) Str::uuid();
        $invite->share_id = (string) $share->id;
        $invite->inviter_attempt_id = (string) $attempt->id;
        $invite->inviter_scale_code = 'MBTI';
        $invite->locale = $locale;
        $invite->inviter_type_code = $this->nullableString($inviterPayload['type_code'] ?? null);
        $invite->status = 'pending';
        $invite->meta_json = $this->normalizeMeta($payload['meta_json'] ?? null);
        $invite->save();

        return [
            'invite_id' => (string) $invite->id,
            'share_id' => (string) $share->id,
            'scale_code' => 'MBTI',
            'locale' => $locale,
            'status' => 'pending',
            'take_path' => $this->buildTakePath($locale, (string) $share->id, (string) $invite->id, (string) ($inviterPayload['primary_cta_path'] ?? '')),
            'compare_path' => $this->buildComparePath($locale, (string) $invite->id),
            'inviter' => $this->toSummaryLite($inviterPayload, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $inviteId): array
    {
        $invite = MbtiCompareInvite::query()->findOrFail($inviteId);
        [$share, $attempt, $result] = $this->resolveMbtiShareContext((string) $invite->share_id);
        $inviterPayload = $this->shareService->buildPublicSummaryPayload($attempt, $result, (string) $share->id, $share->created_at?->toISOString());
        $locale = $this->normalizeLocale(
            $this->nullableString($invite->locale)
                ?? $this->nullableString($inviterPayload['locale'] ?? null)
                ?? 'zh-CN'
        );

        $status = $this->normalizeStatus((string) ($invite->status ?? 'pending'));
        [$inviter, $invitee, $compare] = $this->buildInviteReadContext($invite, $attempt, $result, $locale, true);

        $primaryCtaPath = $this->buildTakePath(
            $locale,
            (string) $invite->share_id,
            (string) $invite->id,
            (string) ($inviterPayload['primary_cta_path'] ?? '')
        );
        $relationshipSync = $this->relationshipSyncContractService->build(
            $inviter,
            $invitee,
            $compare,
            $status,
            $locale,
            $primaryCtaPath
        );

        return [
            'invite_id' => (string) $invite->id,
            'share_id' => (string) $invite->share_id,
            'scale_code' => 'MBTI',
            'locale' => $locale,
            'status' => $status,
            'inviter' => $inviter,
            'invitee' => $invitee,
            'compare' => $compare,
            'relationship_sync_v1' => $relationshipSync,
            'dyadic_graph_v1' => $this->relationshipSyncContractService->buildGraph($relationshipSync),
            'primary_cta_label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
            'primary_cta_path' => $primaryCtaPath,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function showPrivate(string $inviteId, mixed $userId, mixed $anonId): array
    {
        $invite = MbtiCompareInvite::query()->findOrFail($inviteId);
        [, $inviterAttempt, $inviterResult] = $this->resolveMbtiShareContext((string) $invite->share_id);
        $inviterPayload = $this->shareService->buildPublicSummaryPayload($inviterAttempt, $inviterResult, (string) $invite->share_id);
        $locale = $this->normalizeLocale(
            $this->nullableString($invite->locale)
                ?? $this->nullableString($inviterPayload['locale'] ?? null)
                ?? 'zh-CN'
        );
        $status = $this->normalizeStatus((string) ($invite->status ?? 'pending'));
        $participantContext = $this->resolveParticipantContext($invite, $userId, $anonId);
        if ($participantContext === null) {
            throw new ApiProblemException(404, 'PRIVATE_RELATIONSHIP_NOT_FOUND', 'private relationship not found.');
        }

        [$inviter, $invitee, $compare] = $this->buildInviteReadContext($invite, $inviterAttempt, $inviterResult, $locale, false);
        $relationshipSync = $this->relationshipSyncContractService->build(
            $inviter,
            $invitee,
            $compare,
            $status === 'purchased' ? 'ready' : $status,
            $locale,
            null
        );

        $accessState = $this->resolvePrivateAccessState(
            $status,
            (bool) ($participantContext['has_invitee'] ?? false),
            data_get($compare, 'title')
        );
        $subjectJoinMode = $this->resolvePrivateJoinMode($status);
        $participantAttempt = $participantContext['attempt'] ?? null;
        $privateRelationship = $this->privateRelationshipContractService->buildPrivateRelationship(
            $inviter,
            $invitee,
            $relationshipSync,
            (string) ($participantContext['participant_role'] ?? 'inviter'),
            $accessState,
            $subjectJoinMode,
            $locale,
            $participantAttempt instanceof Attempt
                ? $this->buildProtectedResultPath($locale, (string) $participantAttempt->id)
                : $this->buildProtectedHistoryPath($locale),
            $locale === 'zh-CN' ? '进入我的 MBTI 报告' : 'Open my MBTI reports'
        );
        $dyadicConsent = $this->privateRelationshipContractService->buildDyadicConsent(
            $invite,
            $this->normalizeConsentState($status),
            $accessState,
            $subjectJoinMode
        );

        return [
            'invite_id' => (string) $invite->id,
            'share_id' => (string) $invite->share_id,
            'scale_code' => 'MBTI',
            'locale' => $locale,
            'status' => $status,
            'private_relationship_v1' => $privateRelationship,
            'dyadic_consent_v1' => $dyadicConsent,
            'dyadic_graph_v1' => $this->privateRelationshipContractService->buildProtectedGraph($privateRelationship),
        ];
    }

    public function attachInviteeFromSubmit(
        string $compareInviteId,
        string $shareId,
        Attempt $attempt,
        ?string $anonId,
        ?string $userId
    ): void {
        $invite = MbtiCompareInvite::query()->findOrFail($compareInviteId);
        if ((string) $invite->share_id !== $shareId) {
            throw new ApiProblemException(422, 'COMPARE_INVITE_SHARE_MISMATCH', 'compare invite share mismatch.');
        }

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== 'MBTI') {
            throw new ApiProblemException(404, 'COMPARE_INVITE_NOT_FOUND', 'compare invite not found.');
        }

        $now = now();
        $invite->invitee_attempt_id = (string) $attempt->id;
        $invite->invitee_anon_id = $this->nullableString($anonId);
        $invite->invitee_user_id = $this->normalizeUserId($userId);
        $invite->status = 'ready';
        $invite->accepted_at = $invite->accepted_at ?? $now;
        $invite->completed_at = $now;
        $invite->save();
    }

    public function markPurchased(string $compareInviteId, string $orderNo, ?string $purchasedAt = null): void
    {
        $invite = MbtiCompareInvite::query()->find($compareInviteId);
        if (! $invite instanceof MbtiCompareInvite) {
            return;
        }

        $invite->invitee_order_no = trim($orderNo) !== '' ? trim($orderNo) : null;
        $invite->purchased_at = $purchasedAt !== null && trim($purchasedAt) !== ''
            ? Carbon::parse($purchasedAt)
            : now();
        $invite->status = 'purchased';
        $invite->save();
    }

    /**
     * @return array{0:Share,1:Attempt,2:Result}
     */
    private function resolveMbtiShareContext(string $shareId): array
    {
        $share = Share::query()->where('id', $shareId)->first();
        if (! $share instanceof Share) {
            throw (new ModelNotFoundException)->setModel(Share::class, [$shareId]);
        }

        $attempt = Attempt::query()->where('id', (string) $share->attempt_id)->first();
        if (! $attempt instanceof Attempt || strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== 'MBTI') {
            throw (new ModelNotFoundException)->setModel(Share::class, [$shareId]);
        }

        $result = Result::query()
            ->where('attempt_id', (string) $attempt->id)
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->first();

        if (! $result instanceof Result) {
            throw (new ModelNotFoundException)->setModel(Result::class, [(string) $attempt->id]);
        }

        return [$share, $attempt, $result];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toSummaryLite(array $payload, bool $includeShareId): array
    {
        $summary = [
            'type_code' => (string) ($payload['type_code'] ?? ''),
            'type_name' => (string) ($payload['type_name'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'subtitle' => $this->nullableString($payload['subtitle'] ?? null),
            'summary' => $this->nullableString($payload['summary'] ?? null),
            'tagline' => $this->nullableString($payload['tagline'] ?? null),
            'rarity' => $payload['rarity'] ?? null,
            'tags' => is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : [],
            'dimensions' => is_array($payload['dimensions'] ?? null) ? array_values($payload['dimensions']) : [],
            'mbti_public_summary_v1' => is_array($payload['mbti_public_summary_v1'] ?? null)
                ? $payload['mbti_public_summary_v1']
                : $this->mbtiPublicSummaryV1Builder->buildFromSharePayload($payload),
        ];

        if ($includeShareId) {
            $summary = [
                'share_id' => (string) ($payload['share_id'] ?? ''),
            ] + $summary;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toSummaryLiteWithProjection(array $payload, bool $includeShareId, int $orgId, string $locale): array
    {
        $summary = $this->toSummaryLite($payload, $includeShareId);
        $summary['mbti_public_projection_v1'] = $this->resolveParticipantProjection($payload, $orgId, $locale);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingInviteePayload(): array
    {
        $summary = $this->mbtiPublicSummaryV1Builder->scaffold();

        return [
            'mbti_public_summary_v1' => $summary,
            'mbti_public_projection_v1' => $this->scaffoldParticipantProjection($summary),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveParticipantProjection(array $payload, int $orgId, string $locale): array
    {
        if (is_array($payload['mbti_public_projection_v1'] ?? null)) {
            return $payload['mbti_public_projection_v1'];
        }

        $summary = is_array($payload['mbti_public_summary_v1'] ?? null)
            ? $payload['mbti_public_summary_v1']
            : $this->mbtiPublicSummaryV1Builder->buildFromSharePayload($payload, null, $locale);

        return $this->mbtiPublicProjectionService->buildForSharePayload(
            $payload + ['mbti_public_summary_v1' => $summary],
            $locale,
            $orgId
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function scaffoldParticipantProjection(array $summary): array
    {
        return [
            'runtime_type_code' => $this->nullableString($summary['runtime_type_code'] ?? null),
            'canonical_type_code' => $this->nullableString($summary['canonical_type_16'] ?? null),
            'display_type' => $this->nullableString($summary['display_type'] ?? null),
            'variant_code' => $this->nullableString($summary['variant'] ?? null),
            'profile' => [
                'type_name' => $this->nullableString(data_get($summary, 'profile.type_name')),
                'nickname' => $this->nullableString(data_get($summary, 'profile.nickname')),
                'rarity' => $this->nullableString(data_get($summary, 'profile.rarity')),
                'keywords' => is_array(data_get($summary, 'profile.keywords'))
                    ? array_values(data_get($summary, 'profile.keywords'))
                    : [],
                'hero_summary' => $this->nullableString(data_get($summary, 'profile.summary')),
            ],
            'summary_card' => [
                'title' => $this->nullableString(data_get($summary, 'summary_card.title')),
                'subtitle' => $this->nullableString(data_get($summary, 'summary_card.subtitle')),
                'summary' => $this->nullableString(data_get($summary, 'summary_card.share_text')),
                'tagline' => $this->nullableString(data_get($summary, 'profile.nickname')),
                'public_tags' => is_array(data_get($summary, 'summary_card.tags'))
                    ? array_values(data_get($summary, 'summary_card.tags'))
                    : [],
            ],
            'dimensions' => [],
            'sections' => [],
            'seo' => [
                'title' => null,
                'description' => null,
                'og_title' => null,
                'og_description' => null,
                'og_image_url' => null,
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_url' => null,
                'canonical_url' => null,
                'robots' => null,
                'jsonld' => [],
            ],
            'offer_set' => is_array($summary['offer_set'] ?? null)
                ? $summary['offer_set']
                : [],
            '_meta' => [
                'authority_source' => 'compare.pending_scaffold',
                'route_mode' => 'runtime',
                'public_route_type' => '16-type',
                'schema_version' => 'v2',
            ],
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function buildInviteReadContext(
        MbtiCompareInvite $invite,
        Attempt $inviterAttempt,
        Result $inviterResult,
        string $locale,
        bool $includeShareIdForInviter
    ): array {
        $share = Share::query()->where('id', (string) $invite->share_id)->first();
        $inviterPayload = $this->shareService->buildPublicSummaryPayload(
            $inviterAttempt,
            $inviterResult,
            (string) $invite->share_id,
            $share?->created_at?->toISOString()
        );
        $inviter = $this->toSummaryLiteWithProjection(
            $inviterPayload,
            $includeShareIdForInviter,
            (int) ($inviterAttempt->org_id ?? 0),
            $locale
        );
        $invitee = $this->pendingInviteePayload();
        $compare = [
            'mbti_public_summary_v1' => $this->mbtiPublicSummaryV1Builder->buildFromComparePayload([
                'title' => null,
                'summary' => null,
                'axes' => [],
            ], $locale),
        ];

        $inviteeContext = $this->resolveInviteeContext($invite);
        if ($inviteeContext !== null) {
            [$inviteeAttempt, $inviteeResult] = $inviteeContext;
            $inviteePayload = $this->shareService->buildPublicSummaryPayload($inviteeAttempt, $inviteeResult);
            $invitee = $this->toSummaryLiteWithProjection(
                $inviteePayload,
                false,
                (int) ($inviteeAttempt->org_id ?? 0),
                $locale
            );
            $compare = $this->buildComparePayload($inviter, $invitee, $locale);
        }

        return [$inviter, $invitee, $compare];
    }

    /**
     * @return array{0:Attempt,1:Result}|null
     */
    private function resolveInviteeContext(MbtiCompareInvite $invite): ?array
    {
        if (! in_array($this->normalizeStatus((string) ($invite->status ?? 'pending')), ['ready', 'purchased'], true)) {
            return null;
        }

        $inviteeAttemptId = trim((string) ($invite->invitee_attempt_id ?? ''));
        if ($inviteeAttemptId === '') {
            return null;
        }

        $inviteeAttempt = Attempt::query()->where('id', $inviteeAttemptId)->first();
        if (! $inviteeAttempt instanceof Attempt) {
            return null;
        }

        $inviteeResult = Result::query()
            ->where('attempt_id', (string) $inviteeAttempt->id)
            ->where('org_id', (int) ($inviteeAttempt->org_id ?? 0))
            ->first();

        if (! $inviteeResult instanceof Result) {
            return null;
        }

        return [$inviteeAttempt, $inviteeResult];
    }

    /**
     * @return array{participant_role:string,attempt:?Attempt,has_invitee:bool}|null
     */
    private function resolveParticipantContext(MbtiCompareInvite $invite, mixed $userId, mixed $anonId): ?array
    {
        $normalizedUserId = $this->nullableString($userId);
        $normalizedAnonId = $this->nullableString($anonId);
        if ($normalizedUserId === null && $normalizedAnonId === null) {
            return null;
        }

        $inviterAttempt = Attempt::query()->where('id', (string) $invite->inviter_attempt_id)->first();
        $inviteeContext = $this->resolveInviteeContext($invite);

        if ($inviterAttempt instanceof Attempt && $this->attemptBelongsToActor($inviterAttempt, $normalizedUserId, $normalizedAnonId)) {
            return [
                'participant_role' => 'inviter',
                'attempt' => $inviterAttempt,
                'has_invitee' => $inviteeContext !== null,
            ];
        }

        if ($inviteeContext !== null) {
            [$inviteeAttempt] = $inviteeContext;
            if ($this->attemptBelongsToActor($inviteeAttempt, $normalizedUserId, $normalizedAnonId)) {
                return [
                    'participant_role' => 'invitee',
                    'attempt' => $inviteeAttempt,
                    'has_invitee' => true,
                ];
            }
        }

        if (
            ($normalizedUserId !== null && $normalizedUserId === $this->nullableString($invite->invitee_user_id))
            || ($normalizedAnonId !== null && $normalizedAnonId === $this->nullableString($invite->invitee_anon_id))
        ) {
            return [
                'participant_role' => 'invitee',
                'attempt' => $inviteeContext[0] ?? null,
                'has_invitee' => $inviteeContext !== null,
            ];
        }

        return null;
    }

    private function attemptBelongsToActor(Attempt $attempt, ?string $userId, ?string $anonId): bool
    {
        $attemptUserId = $this->nullableString($attempt->user_id ?? null);
        if ($userId !== null && $attemptUserId !== null && $attemptUserId === $userId) {
            return true;
        }

        $attemptAnonId = $this->nullableString($attempt->anon_id ?? null);

        return $anonId !== null && $attemptAnonId !== null && $attemptAnonId === $anonId;
    }

    private function buildTakePath(string $locale, string $shareId, string $inviteId, string $primaryCtaPath): string
    {
        $basePath = trim($primaryCtaPath);
        if ($basePath === '') {
            $segment = $locale === 'zh-CN' ? 'zh' : 'en';
            $basePath = '/'.$segment.'/tests/mbti-personality-test-16-personality-types';
        }

        return $basePath.'/take?share_id='.rawurlencode($shareId).'&compare_invite_id='.rawurlencode($inviteId);
    }

    private function buildComparePath(string $locale, string $inviteId): string
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return '/'.$segment.'/compare/mbti/'.rawurlencode($inviteId);
    }

    private function buildProtectedResultPath(string $locale, string $attemptId): string
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return '/'.$segment.'/result/'.rawurlencode($attemptId);
    }

    private function buildProtectedHistoryPath(string $locale): string
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return '/'.$segment.'/history/mbti';
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['pending', 'ready', 'purchased'], true) ? $normalized : 'pending';
    }

    private function normalizeConsentState(string $status): string
    {
        return match ($status) {
            'purchased' => 'purchased',
            'ready' => 'joined',
            default => 'pending',
        };
    }

    private function resolvePrivateAccessState(string $status, bool $hasInvitee, mixed $compareTitle): string
    {
        if ($status === 'pending' || ! $hasInvitee) {
            return 'awaiting_second_subject';
        }

        if ($status === 'purchased') {
            return 'private_access_ready';
        }

        return trim((string) $compareTitle) === ''
            ? 'private_access_partial'
            : 'joined_public_only';
    }

    private function resolvePrivateJoinMode(string $status): string
    {
        return match ($status) {
            'purchased' => 'share_compare_invite_purchased',
            'ready' => 'share_compare_invite_joined',
            default => 'share_compare_invite_pending',
        };
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string, mixed>  $inviter
     * @param  array<string, mixed>  $invitee
     * @return array<string, mixed>
     */
    private function buildComparePayload(array $inviter, array $invitee, string $locale): array
    {
        $inviterTypeCode = strtoupper(trim((string) ($inviter['type_code'] ?? '')));
        $inviteeTypeCode = strtoupper(trim((string) ($invitee['type_code'] ?? '')));
        $sameType = $inviterTypeCode !== '' && $inviterTypeCode === $inviteeTypeCode;

        $inviteeDimensions = [];
        foreach ((array) ($invitee['dimensions'] ?? []) as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $code = strtoupper(trim((string) ($dimension['code'] ?? '')));
            if ($code === '') {
                continue;
            }

            $inviteeDimensions[$code] = $dimension;
        }

        $axes = [];
        $sharedCount = 0;
        $divergingCount = 0;

        foreach ((array) ($inviter['dimensions'] ?? []) as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $code = strtoupper(trim((string) ($dimension['code'] ?? '')));
            if ($code === '' || ! isset($inviteeDimensions[$code])) {
                continue;
            }

            $inviterSide = strtoupper(trim((string) ($dimension['side'] ?? '')));
            $inviteeSide = strtoupper(trim((string) ($inviteeDimensions[$code]['side'] ?? '')));
            $aligned = $inviterSide !== '' && $inviterSide === $inviteeSide;
            if ($aligned) {
                $sharedCount++;
            } else {
                $divergingCount++;
            }

            $axes[] = [
                'code' => $code,
                'label' => (string) ($dimension['label'] ?? ''),
                'inviter_side' => $inviterSide,
                'invitee_side' => $inviteeSide,
                'aligned' => $aligned,
            ];
        }

        [$title, $summary] = $this->resolveCompareCopy($sameType, $sharedCount, $locale);

        return [
            'same_type' => $sameType,
            'shared_count' => $sharedCount,
            'diverging_count' => $divergingCount,
            'axes' => $axes,
            'title' => $title,
            'summary' => $summary,
            'mbti_public_summary_v1' => $this->mbtiPublicSummaryV1Builder->buildFromComparePayload([
                'title' => $title,
                'summary' => $summary,
                'axes' => $axes,
            ], $locale),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveCompareCopy(bool $sameType, int $sharedCount, string $locale): array
    {
        if ($locale === 'zh-CN') {
            if ($sameType) {
                return ['你们的类型高度接近', '你们在多数公开维度上呈现出高度一致的偏好，整体风格非常接近。'];
            }

            if ($sharedCount >= 4) {
                return ['你们的风格高度同频', '你们在大多数公开维度上都表现出相近偏好，互动节奏通常更容易自然对齐。'];
            }

            if ($sharedCount >= 2) {
                return ['你们既有共鸣也有互补', '你们在部分维度上天然同频，在另外一些维度上形成互补。'];
            }

            return ['你们更偏互补型组合', '你们在公开维度上的差异更明显，但这也意味着彼此能带来不同的视角与补位。'];
        }

        if ($sameType) {
            return ['Your types are highly aligned', 'Most public dimensions point to very similar preferences and a closely matched style.'];
        }

        if ($sharedCount >= 4) {
            return ['Your styles are highly in sync', 'You line up on most public dimensions, so your default pace and preferences are likely to feel naturally aligned.'];
        }

        if ($sharedCount >= 2) {
            return ['You share resonance and contrast', 'Some dimensions show clear overlap while others create useful contrast between you.'];
        }

        return ['You are a more complementary pair', 'Your public dimensions differ more often, which can create a more complementary dynamic.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        return is_array($meta) ? $meta : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeUserId(?string $value): ?int
    {
        $normalized = $this->nullableString($value);
        if ($normalized === null || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }
}

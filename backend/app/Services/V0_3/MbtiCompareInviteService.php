<?php

declare(strict_types=1);

namespace App\Services\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\MbtiCompareInvite;
use App\Models\Result;
use App\Models\Share;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class MbtiCompareInviteService
{
    public function __construct(
        private readonly ShareService $shareService,
        private readonly MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
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
        $inviter = $this->toSummaryLite($inviterPayload, true);
        $invitee = [
            'mbti_public_summary_v1' => $this->mbtiPublicSummaryV1Builder->scaffold(),
        ];
        $compare = [
            'mbti_public_summary_v1' => $this->mbtiPublicSummaryV1Builder->buildFromComparePayload([
                'title' => null,
                'summary' => null,
                'axes' => [],
            ], $locale),
        ];

        if (in_array($status, ['ready', 'purchased'], true)) {
            $inviteeAttemptId = trim((string) ($invite->invitee_attempt_id ?? ''));
            if ($inviteeAttemptId !== '') {
                $inviteeAttempt = Attempt::query()->where('id', $inviteeAttemptId)->first();
                if ($inviteeAttempt instanceof Attempt) {
                    $inviteeResult = Result::query()
                        ->where('attempt_id', (string) $inviteeAttempt->id)
                        ->where('org_id', (int) ($inviteeAttempt->org_id ?? 0))
                        ->first();

                    if ($inviteeResult instanceof Result) {
                        $inviteePayload = $this->shareService->buildPublicSummaryPayload($inviteeAttempt, $inviteeResult);
                        $invitee = $this->toSummaryLite($inviteePayload, false);
                        $compare = $this->buildComparePayload(
                            $inviter,
                            $invitee,
                            $locale
                        );
                    }
                }
            }
        }

        return [
            'invite_id' => (string) $invite->id,
            'share_id' => (string) $invite->share_id,
            'scale_code' => 'MBTI',
            'locale' => $locale,
            'status' => $status,
            'inviter' => $inviter,
            'invitee' => $invitee,
            'compare' => $compare,
            'primary_cta_label' => $locale === 'zh-CN' ? '开始测试' : 'Take the test',
            'primary_cta_path' => $this->buildTakePath(
                $locale,
                (string) $invite->share_id,
                (string) $invite->id,
                (string) ($inviterPayload['primary_cta_path'] ?? '')
            ),
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

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return in_array($normalized, ['pending', 'ready', 'purchased'], true) ? $normalized : 'pending';
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string, mixed>  $payload
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

<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Models\ReferralRewardIssuance;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\OrderManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReferralRewardService
{
    public const REWARD_BENEFIT_CODE = 'MBTI_GIFT_CREDITS';

    public const REWARD_SKU = 'MBTI_GIFT_CREDITS';

    public const REWARD_QUANTITY = 1;

    public const STATUS_GRANTED = 'granted';

    public const STATUS_BLOCKED = 'blocked';

    public const REASON_SELF_REFERRAL_SAME_USER = 'self_referral_same_user';

    public const REASON_SELF_REFERRAL_SAME_ANON = 'self_referral_same_anon';

    public const REASON_SELF_REFERRAL_SAME_ATTEMPT = 'self_referral_same_attempt';

    public const REASON_MISSING_COMPARE_INVITE = 'missing_compare_invite';

    public const REASON_INVITE_MISMATCH = 'invite_mismatch';

    public const REASON_DUPLICATE_COMPARE_INVITE = 'duplicate_compare_invite';

    public const REASON_DUPLICATE_ORDER = 'duplicate_order';

    public const REASON_MISSING_INVITER = 'missing_inviter';

    public const REASON_MISSING_INVITEE = 'missing_invitee';

    public const REASON_NOT_PAID = 'not_paid';

    public function __construct(
        private readonly OrderManager $orders,
        private readonly BenefitWalletService $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function issueForPaidOrder(string $orderNo, int $orgId, array $context = []): array
    {
        $order = $this->orders->findOrderByOrderNo($orderNo, $orgId);
        if (! $order) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => 'order_not_found',
            ];
        }

        $attribution = $this->orders->extractAttributionFromOrder($order);
        $compareInviteId = $this->normalizeString($attribution['compare_invite_id'] ?? null);
        if ($compareInviteId === null) {
            return [
                'ok' => true,
                'ignored' => true,
                'reason' => self::REASON_MISSING_COMPARE_INVITE,
            ];
        }

        $existing = $this->findExistingIssuance($compareInviteId, (string) ($order->order_no ?? ''));
        if ($existing instanceof ReferralRewardIssuance) {
            return [
                'ok' => true,
                'idempotent' => true,
                'issuance' => $existing,
                'status' => (string) ($existing->status ?? ''),
                'reason_code' => $this->normalizeString($existing->reason_code ?? null),
            ];
        }

        try {
            return DB::transaction(function () use ($order, $orgId, $compareInviteId, $attribution, $context): array {
                $lockedExisting = $this->findExistingIssuance($compareInviteId, (string) ($order->order_no ?? ''));
                if ($lockedExisting instanceof ReferralRewardIssuance) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'issuance' => $lockedExisting,
                        'status' => (string) ($lockedExisting->status ?? ''),
                        'reason_code' => $this->normalizeString($lockedExisting->reason_code ?? null),
                    ];
                }

                $decision = $this->buildDecision($order, $compareInviteId, $attribution, $context);
                if (($decision['status'] ?? null) === self::STATUS_BLOCKED) {
                    $issuance = $this->persistIssuance($decision['payload']);

                    return [
                        'ok' => true,
                        'issuance' => $issuance,
                        'status' => self::STATUS_BLOCKED,
                        'reason_code' => $this->normalizeString($issuance->reason_code ?? null),
                        'blocked' => true,
                    ];
                }

                $grant = $this->recordRewardGrant($decision);
                $wallet = $this->wallets->topUp(
                    $orgId,
                    self::REWARD_BENEFIT_CODE,
                    self::REWARD_QUANTITY,
                    $this->walletIdempotencyKey($compareInviteId),
                    [
                        'order_no' => (string) ($order->order_no ?? ''),
                        'attempt_id' => (string) ($decision['inviter_attempt_id'] ?? ''),
                        'compare_invite_id' => $compareInviteId,
                        'provider' => $this->normalizeString($context['provider'] ?? null),
                        'provider_event_id' => $this->normalizeString($context['provider_event_id'] ?? null),
                    ]
                );

                if (! ($wallet['ok'] ?? false)) {
                    $this->failIssuance(
                        (string) ($wallet['error'] ?? 'REFERRAL_REWARD_WALLET_FAILED'),
                        (string) ($wallet['message'] ?? 'referral reward wallet topup failed.')
                    );
                }

                $issuance = $this->persistIssuance($decision['payload']);

                return [
                    'ok' => true,
                    'issuance' => $issuance,
                    'status' => self::STATUS_GRANTED,
                    'grant' => $grant,
                    'wallet' => $wallet['wallet'] ?? null,
                ];
            });
        } catch (\Throwable $e) {
            [$errorCode, $message] = $this->parseIssuanceFailure($e);

            return [
                'ok' => false,
                'error_code' => $errorCode,
                'message' => $message,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildDecision(object $order, string $compareInviteId, array $attribution, array $context): array
    {
        $basePayload = [
            'id' => (string) Str::uuid(),
            'org_id' => (int) ($order->org_id ?? 0),
            'compare_invite_id' => $compareInviteId,
            'share_id' => $this->normalizeString($attribution['share_id'] ?? null),
            'trigger_order_no' => (string) ($order->order_no ?? ''),
            'inviter_attempt_id' => '',
            'invitee_attempt_id' => '',
            'inviter_user_id' => null,
            'invitee_user_id' => null,
            'inviter_anon_id' => null,
            'invitee_anon_id' => null,
            'reward_sku' => self::REWARD_SKU,
            'reward_quantity' => self::REWARD_QUANTITY,
            'status' => self::STATUS_BLOCKED,
            'reason_code' => null,
            'attribution_json' => null,
            'granted_at' => null,
        ];

        if (! $this->orders->isPaidOrFulfilledStatus((string) ($order->status ?? ''))) {
            $basePayload['reason_code'] = self::REASON_NOT_PAID;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, null, null, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        $invite = DB::table('mbti_compare_invites')->where('id', $compareInviteId)->first();
        if (! $invite) {
            $basePayload['reason_code'] = self::REASON_MISSING_COMPARE_INVITE;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, null, null, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        $inviterAttemptId = trim((string) ($invite->inviter_attempt_id ?? ''));
        $inviteeAttemptId = trim((string) ($invite->invitee_attempt_id ?? ''));
        $basePayload['share_id'] = $this->normalizeString($invite->share_id ?? null) ?? $basePayload['share_id'];
        $basePayload['inviter_attempt_id'] = $inviterAttemptId;
        $basePayload['invitee_attempt_id'] = $inviteeAttemptId;

        $inviterAttempt = $inviterAttemptId !== ''
            ? DB::table('attempts')->where('id', $inviterAttemptId)->first()
            : null;
        $inviteeAttempt = $inviteeAttemptId !== ''
            ? DB::table('attempts')->where('id', $inviteeAttemptId)->first()
            : null;

        $basePayload['inviter_user_id'] = $this->normalizeString($inviterAttempt?->user_id);
        $basePayload['invitee_user_id'] = $this->normalizeString($invite->invitee_user_id ?? null)
            ?? $this->normalizeString($inviteeAttempt?->user_id);
        $basePayload['inviter_anon_id'] = $this->normalizeString($inviterAttempt?->anon_id);
        $basePayload['invitee_anon_id'] = $this->normalizeString($invite->invitee_anon_id ?? null)
            ?? $this->normalizeString($inviteeAttempt?->anon_id);

        if ($inviterAttemptId === '' || ! $inviterAttempt) {
            $basePayload['reason_code'] = self::REASON_MISSING_INVITER;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, null, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        if ($inviteeAttemptId === '' || ! $inviteeAttempt) {
            $basePayload['reason_code'] = self::REASON_MISSING_INVITEE;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        $targetAttemptId = trim((string) ($order->target_attempt_id ?? ''));
        if ($targetAttemptId === '' || $targetAttemptId !== $inviteeAttemptId) {
            $basePayload['reason_code'] = self::REASON_INVITE_MISMATCH;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        if (
            $basePayload['inviter_user_id'] !== null
            && $basePayload['invitee_user_id'] !== null
            && $basePayload['inviter_user_id'] === $basePayload['invitee_user_id']
        ) {
            $basePayload['reason_code'] = self::REASON_SELF_REFERRAL_SAME_USER;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        if (
            $basePayload['inviter_anon_id'] !== null
            && $basePayload['invitee_anon_id'] !== null
            && $basePayload['inviter_anon_id'] === $basePayload['invitee_anon_id']
        ) {
            $basePayload['reason_code'] = self::REASON_SELF_REFERRAL_SAME_ANON;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        if ($inviterAttemptId === $inviteeAttemptId) {
            $basePayload['reason_code'] = self::REASON_SELF_REFERRAL_SAME_ATTEMPT;
            $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

            return [
                'status' => self::STATUS_BLOCKED,
                'payload' => $basePayload,
            ];
        }

        $basePayload['status'] = self::STATUS_GRANTED;
        $basePayload['granted_at'] = now();
        $basePayload['attribution_json'] = $this->buildAttributionPayload($order, $attribution, $invite, $inviterAttempt, $context);

        return [
            'status' => self::STATUS_GRANTED,
            'payload' => $basePayload,
            'compare_invite_id' => $compareInviteId,
            'trigger_order_no' => (string) ($order->order_no ?? ''),
            'inviter_attempt_id' => $inviterAttemptId,
            'inviter_user_id' => $basePayload['inviter_user_id'],
            'inviter_anon_id' => $basePayload['inviter_anon_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function recordRewardGrant(array $decision): object
    {
        $compareInviteId = (string) ($decision['compare_invite_id'] ?? '');
        $triggerOrderNo = (string) ($decision['trigger_order_no'] ?? '');
        $inviterAttemptId = (string) ($decision['inviter_attempt_id'] ?? '');
        $inviterUserId = $this->normalizeString($decision['inviter_user_id'] ?? null);
        $inviterAnonId = $this->normalizeString($decision['inviter_anon_id'] ?? null);
        $beneficiary = $inviterUserId ?? $inviterAnonId ?? 'attempt:'.$inviterAttemptId;
        $sourceOrderId = $this->deterministicUuid('referral_reward:'.$compareInviteId);
        $benefitType = 'referral_reward';
        $benefitRef = $compareInviteId;
        $now = now();

        DB::table('benefit_grants')->insertOrIgnore([
            'id' => $this->deterministicUuid('referral_reward_grant:'.$compareInviteId),
            'org_id' => (int) ($decision['payload']['org_id'] ?? 0),
            'user_id' => mb_substr($beneficiary, 0, 64, 'UTF-8'),
            'benefit_code' => self::REWARD_BENEFIT_CODE,
            'scope' => 'org',
            'attempt_id' => $inviterAttemptId !== '' ? $inviterAttemptId : null,
            'order_no' => $triggerOrderNo !== '' ? $triggerOrderNo : null,
            'status' => 'active',
            'expires_at' => null,
            'benefit_type' => $benefitType,
            'benefit_ref' => $benefitRef,
            'source_order_id' => $sourceOrderId,
            'source_event_id' => null,
            'meta_json' => json_encode([
                'granted_via' => 'referral_reward_service',
                'compare_invite_id' => $compareInviteId,
                'reward_quantity' => self::REWARD_QUANTITY,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('benefit_grants')
            ->where('id', $this->deterministicUuid('referral_reward_grant:'.$compareInviteId))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistIssuance(array $payload): ReferralRewardIssuance
    {
        try {
            /** @var ReferralRewardIssuance $issuance */
            $issuance = ReferralRewardIssuance::query()->create($payload);

            return $issuance;
        } catch (QueryException $e) {
            $existing = $this->findExistingIssuance(
                (string) ($payload['compare_invite_id'] ?? ''),
                (string) ($payload['trigger_order_no'] ?? ''),
            );

            if ($existing instanceof ReferralRewardIssuance) {
                return $existing;
            }

            throw $e;
        }
    }

    private function findExistingIssuance(string $compareInviteId, string $triggerOrderNo): ?ReferralRewardIssuance
    {
        $normalizedInviteId = trim($compareInviteId);
        $normalizedOrderNo = trim($triggerOrderNo);
        if ($normalizedInviteId === '' && $normalizedOrderNo === '') {
            return null;
        }

        $query = ReferralRewardIssuance::query();
        if ($normalizedInviteId !== '') {
            $query->where('compare_invite_id', $normalizedInviteId);
            if ($normalizedOrderNo !== '') {
                $query->orWhere('trigger_order_no', $normalizedOrderNo);
            }
        } else {
            $query->where('trigger_order_no', $normalizedOrderNo);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildAttributionPayload(
        object $order,
        array $attribution,
        ?object $invite,
        ?object $inviterAttempt,
        array $context
    ): array {
        return [
            'source' => 'payment_webhook',
            'provider' => $this->normalizeString($context['provider'] ?? null),
            'provider_event_id' => $this->normalizeString($context['provider_event_id'] ?? null),
            'order' => [
                'order_no' => $this->normalizeString($order->order_no ?? null),
                'status' => $this->normalizeString($order->status ?? null),
                'target_attempt_id' => $this->normalizeString($order->target_attempt_id ?? null),
            ],
            'attribution' => $attribution,
            'compare_invite' => [
                'id' => $this->normalizeString($invite?->id),
                'share_id' => $this->normalizeString($invite?->share_id),
                'status' => $this->normalizeString($invite?->status),
                'inviter_attempt_id' => $this->normalizeString($invite?->inviter_attempt_id),
                'invitee_attempt_id' => $this->normalizeString($invite?->invitee_attempt_id),
                'invitee_user_id' => $this->normalizeString($invite?->invitee_user_id),
                'invitee_anon_id' => $this->normalizeString($invite?->invitee_anon_id),
            ],
            'inviter_attempt' => [
                'id' => $this->normalizeString($inviterAttempt?->id),
                'user_id' => $this->normalizeString($inviterAttempt?->user_id),
                'anon_id' => $this->normalizeString($inviterAttempt?->anon_id),
            ],
        ];
    }

    private function walletIdempotencyKey(string $compareInviteId): string
    {
        return 'REFERRAL_REWARD:'.trim($compareInviteId);
    }

    private function failIssuance(string $errorCode, string $message): never
    {
        throw new \RuntimeException($errorCode.'|'.$message);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseIssuanceFailure(\Throwable $e): array
    {
        $message = trim($e->getMessage());
        if ($message !== '' && str_contains($message, '|')) {
            [$errorCode, $errorMessage] = explode('|', $message, 2);

            return [
                trim($errorCode) !== '' ? trim($errorCode) : 'REFERRAL_REWARD_ISSUANCE_FAILED',
                trim($errorMessage) !== '' ? trim($errorMessage) : 'referral reward issuance failed.',
            ];
        }

        return [
            'REFERRAL_REWARD_ISSUANCE_FAILED',
            $message !== '' ? $message : 'referral reward issuance failed.',
        ];
    }

    private function deterministicUuid(string $seed): string
    {
        $hash = md5($seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

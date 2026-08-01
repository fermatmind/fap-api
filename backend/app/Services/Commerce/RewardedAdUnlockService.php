<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class RewardedAdUnlockService
{
    private const SESSION_TTL_SECONDS = 600;

    private const COMPLETED_SESSION_TTL_SECONDS = 300;

    private const TRUST_MODE = 'client_is_ended_residual_risk_accepted';

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly ReportUnlockOptionResolver $unlockOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(Attempt $attempt, ?string $userId, ?string $anonId, string $adUnitId): array
    {
        $actor = $this->actor($userId, $anonId);
        $adUnitId = $this->normalizeAdUnitId($adUnitId);
        if ($adUnitId === null) {
            return $this->failure(422, 'AD_UNIT_INVALID', 'A valid rewarded-ad unit is required.');
        }

        $eligibility = $this->assertEligible($attempt, $actor);
        if ($eligibility !== null) {
            return $eligibility;
        }

        $activeKey = $this->activeKey($attempt, $actor['fingerprint']);
        $activeSessionId = trim((string) Cache::get($activeKey, ''));
        if ($activeSessionId !== '') {
            $activeSession = $this->session($activeSessionId);
            if ($this->sessionBelongsTo($activeSession, $attempt, $actor) && ($activeSession['status'] ?? null) === 'pending') {
                return [
                    'ok' => true,
                    'session' => $this->presentSession($activeSession),
                    'idempotent' => true,
                ];
            }

            Cache::forget($activeKey);
        }

        $sessionId = (string) Str::uuid();
        $expiresAt = now()->addSeconds(self::SESSION_TTL_SECONDS);
        $session = [
            'id' => $sessionId,
            'org_id' => (int) $attempt->org_id,
            'attempt_id' => (string) $attempt->id,
            'actor_fingerprint' => $actor['fingerprint'],
            'ad_unit_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($adUnitId),
            'status' => 'pending',
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'trust_mode' => self::TRUST_MODE,
        ];

        Cache::put($this->sessionKey($sessionId), $session, $expiresAt);
        Cache::put($activeKey, $sessionId, $expiresAt);

        $this->audit('created', $attempt, $actor, $session, [
            'idempotent' => false,
        ]);

        return [
            'ok' => true,
            'session' => $this->presentSession($session),
            'idempotent' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Attempt $attempt, ?string $userId, ?string $anonId, string $sessionId): array
    {
        $actor = $this->actor($userId, $anonId);
        $session = $this->session($sessionId);
        if (! $this->sessionBelongsTo($session, $attempt, $actor)) {
            return $this->failure(404, 'REWARDED_AD_SESSION_NOT_FOUND', 'rewarded-ad session was not found.');
        }

        return [
            'ok' => true,
            'session' => $this->presentSession($session),
        ];
    }

    /**
     * The upstream platform exposes only a client-side isEnded signal. The explicit
     * operator-approved trust mode still binds that signal to one actor, attempt,
     * ad unit and expiring nonce; it must never be represented as a platform receipt.
     *
     * @return array<string, mixed>
     */
    public function complete(
        Attempt $attempt,
        ?string $userId,
        ?string $anonId,
        string $sessionId,
        string $adUnitId,
        bool $isEnded
    ): array {
        $actor = $this->actor($userId, $anonId);
        $adUnitId = $this->normalizeAdUnitId($adUnitId);
        if ($adUnitId === null) {
            return $this->failure(422, 'AD_UNIT_INVALID', 'A valid rewarded-ad unit is required.');
        }
        if (! $isEnded) {
            return $this->failure(422, 'REWARDED_AD_INCOMPLETE', 'rewarded video was not completed.');
        }

        $session = $this->session($sessionId);
        if (! $this->sessionBelongsTo($session, $attempt, $actor)) {
            return $this->failure(404, 'REWARDED_AD_SESSION_NOT_FOUND', 'rewarded-ad session was not found.');
        }
        if (! hash_equals((string) ($session['ad_unit_fingerprint'] ?? ''), SensitiveDiagnosticRedactor::fingerprint($adUnitId))) {
            return $this->failure(422, 'AD_UNIT_MISMATCH', 'rewarded-ad unit does not match the session.');
        }
        if (($session['status'] ?? null) === 'completed') {
            return [
                'ok' => true,
                'session' => $this->presentSession($session),
                'idempotent' => true,
                'grant_id' => isset($session['grant_id']) ? (string) $session['grant_id'] : null,
            ];
        }
        if (($session['status'] ?? null) !== 'pending') {
            return $this->failure(409, 'REWARDED_AD_SESSION_CONSUMED', 'rewarded-ad session is no longer pending.');
        }

        $completionKey = $this->completionKey($sessionId);
        if (! Cache::add($completionKey, '1', now()->addSeconds(self::SESSION_TTL_SECONDS))) {
            return $this->failure(409, 'REWARDED_AD_SESSION_IN_PROGRESS', 'rewarded-ad session completion is in progress.');
        }

        try {
            $eligibility = $this->assertEligible($attempt, $actor);
            if ($eligibility !== null) {
                return $eligibility;
            }

            $grant = $this->entitlements->grantAttemptUnlock(
                (int) $attempt->org_id,
                $actor['user_id'],
                $actor['anon_id'],
                'MBTI_REPORT_FULL',
                (string) $attempt->id,
                null,
                null,
                null,
                null,
                [
                    'unlock_source' => ReportAccess::UNLOCK_SOURCE_REWARDED_AD,
                    'granted_via' => ReportAccess::UNLOCK_SOURCE_REWARDED_AD,
                    'rewarded_ad_session_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($sessionId),
                    'rewarded_ad_unit_fingerprint' => SensitiveDiagnosticRedactor::fingerprint($adUnitId),
                    'rewarded_ad_completion_trust' => self::TRUST_MODE,
                ],
                true,
            );
            if (($grant['ok'] ?? false) !== true) {
                return $grant;
            }

            $session['status'] = 'completed';
            $session['completed_at'] = now()->toIso8601String();
            $session['grant_id'] = (string) (($grant['grant']->id ?? '') ?: '');
            Cache::put($this->sessionKey($sessionId), $session, now()->addSeconds(self::COMPLETED_SESSION_TTL_SECONDS));
            Cache::forget($this->activeKey($attempt, $actor['fingerprint']));
            $this->audit('completed', $attempt, $actor, $session, [
                'idempotent' => (bool) ($grant['idempotent'] ?? false),
                'grant_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($session['grant_id'] ?? '')),
            ]);

            return [
                'ok' => true,
                'session' => $this->presentSession($session),
                'idempotent' => (bool) ($grant['idempotent'] ?? false),
                'grant_id' => $session['grant_id'],
            ];
        } finally {
            $finalSession = $this->session($sessionId);
            if (($finalSession['status'] ?? null) !== 'completed') {
                Cache::forget($completionKey);
            }
        }
    }

    /**
     * @param  array{user_id:?string,anon_id:?string,fingerprint:string}  $actor
     * @return array<string,mixed>|null
     */
    private function assertEligible(Attempt $attempt, array $actor): ?array
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if (ReportAccess::isIqScale($scaleCode)) {
            return $this->failure(403, 'IQ_REWARDED_AD_DISABLED', 'rewarded-ad unlock is unavailable for IQ.');
        }
        if ($scaleCode !== ReportAccess::SCALE_MBTI) {
            return $this->failure(403, 'REWARDED_AD_ROLLOUT_DISABLED', 'rewarded-ad unlock is unavailable for this scale.');
        }
        if (! Result::query()->where('org_id', (int) $attempt->org_id)->where('attempt_id', (string) $attempt->id)->exists()) {
            return $this->failure(409, 'REPORT_NOT_READY', 'report is not ready for unlock.');
        }
        if ($this->entitlements->hasFullAccess(
            (int) $attempt->org_id,
            $actor['user_id'],
            $actor['anon_id'],
            (string) $attempt->id,
            'MBTI_REPORT_FULL',
        )) {
            return $this->failure(409, 'ALREADY_UNLOCKED', 'report is already unlocked.');
        }

        $contract = $this->unlockOptions->resolve(
            $scaleCode,
            trim((string) ($attempt->locale ?? 'zh-CN')),
            (int) $attempt->org_id,
            ReportAccess::UNLOCK_STAGE_LOCKED,
            ReportAccess::UNLOCK_SOURCE_NONE,
            [],
            []
        );
        $option = (array) data_get($contract, 'unlock_options.0', []);
        if (($option['method'] ?? null) !== ReportAccess::UNLOCK_SOURCE_REWARDED_AD || ($option['available'] ?? false) !== true) {
            return $this->failure(422, 'REWARDED_AD_UNAVAILABLE', 'rewarded-ad unlock is currently unavailable.');
        }

        return null;
    }

    /** @return array{user_id:?string,anon_id:?string,fingerprint:string} */
    private function actor(?string $userId, ?string $anonId): array
    {
        $userId = trim((string) $userId);
        $anonId = trim((string) $anonId);
        $identity = $userId !== '' ? 'user:'.$userId : 'anon:'.$anonId;

        return [
            'user_id' => $userId !== '' ? $userId : null,
            'anon_id' => $anonId !== '' ? $anonId : null,
            'fingerprint' => SensitiveDiagnosticRedactor::fingerprint($identity),
        ];
    }

    /** @return array<string,mixed>|null */
    private function session(string $sessionId): ?array
    {
        $session = Cache::get($this->sessionKey($sessionId));

        return is_array($session) ? $session : null;
    }

    /** @param  array<string,mixed>|null  $session
     * @param  array{user_id:?string,anon_id:?string,fingerprint:string}  $actor
     */
    private function sessionBelongsTo(?array $session, Attempt $attempt, array $actor): bool
    {
        return is_array($session)
            && (int) ($session['org_id'] ?? -1) === (int) $attempt->org_id
            && hash_equals((string) ($session['attempt_id'] ?? ''), (string) $attempt->id)
            && hash_equals((string) ($session['actor_fingerprint'] ?? ''), $actor['fingerprint']);
    }

    /** @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function presentSession(array $session): array
    {
        return [
            'id' => (string) ($session['id'] ?? ''),
            'status' => (string) ($session['status'] ?? 'pending'),
            'expires_at' => (string) ($session['expires_at'] ?? ''),
            'completed_at' => $session['completed_at'] ?? null,
            'trust_mode' => self::TRUST_MODE,
        ];
    }

    private function normalizeAdUnitId(string $adUnitId): ?string
    {
        $adUnitId = trim($adUnitId);

        return preg_match('/^adunit-[A-Za-z0-9_-]{1,128}$/', $adUnitId) === 1 ? $adUnitId : null;
    }

    private function sessionKey(string $sessionId): string
    {
        return 'rewarded-ad-unlock:session:'.hash('sha256', $sessionId);
    }

    private function activeKey(Attempt $attempt, string $actorFingerprint): string
    {
        return 'rewarded-ad-unlock:active:'.hash('sha256', implode(':', [
            (string) $attempt->org_id,
            (string) $attempt->id,
            $actorFingerprint,
        ]));
    }

    private function completionKey(string $sessionId): string
    {
        return 'rewarded-ad-unlock:completion:'.hash('sha256', $sessionId);
    }

    /**
     * @param  array{user_id:?string,anon_id:?string,fingerprint:string}  $actor
     * @param  array<string,mixed>  $session
     * @param  array<string,mixed>  $context
     */
    private function audit(string $event, Attempt $attempt, array $actor, array $session, array $context): void
    {
        Log::warning('REWARDED_AD_CLIENT_COMPLETION_AUDIT', array_merge([
            'event' => $event,
            'org_id' => (int) $attempt->org_id,
            'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) $attempt->id),
            'actor_fingerprint' => $actor['fingerprint'],
            'session_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($session['id'] ?? '')),
            'ad_unit_fingerprint' => (string) ($session['ad_unit_fingerprint'] ?? ''),
            'trust_mode' => self::TRUST_MODE,
        ], $context));
    }

    /** @return array{ok:false,status:int,error_code:string,message:string} */
    private function failure(int $status, string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }
}

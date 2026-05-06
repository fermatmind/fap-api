<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Models\AttemptEmailBinding;
use App\Support\PiiCipher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class ResultAccessTokenService
{
    public function __construct(
        private readonly PiiCipher $piiCipher,
    ) {}

    /**
     * @return array{token:string,expires_at:string}
     */
    public function issueForBinding(AttemptEmailBinding $binding): array
    {
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes($this->ttlMinutes());

        $payload = [
            'v' => 1,
            'typ' => 'result_access',
            'org_id' => (int) ($binding->org_id ?? 0),
            'attempt_id' => (string) ($binding->attempt_id ?? ''),
            'binding_id' => (string) $binding->getKey(),
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
            'nonce' => Str::random(16),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encodedPayload = $this->base64UrlEncode($payloadJson);
        $signature = $this->sign($encodedPayload);

        return [
            'token' => $encodedPayload.'.'.$signature,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{token:string,expires_at:string}|null
     */
    public function issueForActiveAttemptBindingOwner(
        int $orgId,
        string $attemptId,
        ?string $userId,
        ?string $anonId,
        ?string $contactEmailHash
    ): ?array {
        $normalizedAttemptId = trim($attemptId);
        if ($normalizedAttemptId === '') {
            return null;
        }

        $normalizedUserId = $this->normalizeOwnerString($userId);
        $normalizedAnonId = $this->normalizeOwnerString($anonId);
        $normalizedContactEmailHash = $this->normalizeContactEmailHash($contactEmailHash);
        if ($normalizedUserId === null && $normalizedAnonId === null && $normalizedContactEmailHash === null) {
            return null;
        }

        $bindings = AttemptEmailBinding::query()
            ->where('org_id', max(0, $orgId))
            ->where('attempt_id', $normalizedAttemptId)
            ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        foreach ($bindings as $binding) {
            if (! $binding instanceof AttemptEmailBinding) {
                continue;
            }

            if (! $this->bindingMatchesOwner(
                $binding,
                $normalizedUserId,
                $normalizedAnonId,
                $normalizedContactEmailHash
            )) {
                continue;
            }

            return $this->issueForBinding($binding);
        }

        return null;
    }

    /**
     * @return array{
     *   v:int,
     *   typ:string,
     *   org_id:int,
     *   attempt_id:string,
     *   binding_id:string,
     *   status:string,
     *   iat:int,
     *   exp:int,
     *   nonce:string
     * }|null
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $token, 2);
        if ($encodedPayload === '' || $signature === '' || ! hash_equals($this->sign($encodedPayload), $signature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return null;
        }

        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));
        $bindingId = trim((string) ($payload['binding_id'] ?? ''));
        $expiresAt = (int) ($payload['exp'] ?? 0);
        if (
            (int) ($payload['v'] ?? 0) !== 1
            || (string) ($payload['typ'] ?? '') !== 'result_access'
            || (string) ($payload['status'] ?? '') !== AttemptEmailBinding::STATUS_ACTIVE
            || $attemptId === ''
            || $bindingId === ''
            || $expiresAt <= Carbon::now()->getTimestamp()
        ) {
            return null;
        }

        return [
            'v' => 1,
            'typ' => 'result_access',
            'org_id' => max(0, (int) ($payload['org_id'] ?? 0)),
            'attempt_id' => $attemptId,
            'binding_id' => $bindingId,
            'status' => AttemptEmailBinding::STATUS_ACTIVE,
            'iat' => (int) ($payload['iat'] ?? 0),
            'exp' => $expiresAt,
            'nonce' => trim((string) ($payload['nonce'] ?? '')),
        ];
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('fap.result_access_tokens.ttl_minutes', 30));
    }

    private function bindingMatchesOwner(
        AttemptEmailBinding $binding,
        ?string $userId,
        ?string $anonId,
        ?string $contactEmailHash
    ): bool {
        $boundUserId = $this->normalizeOwnerString($binding->bound_user_id ?? null);
        if ($userId !== null && $boundUserId !== null && hash_equals($userId, $boundUserId)) {
            return true;
        }

        $boundAnonId = $this->normalizeOwnerString($binding->bound_anon_id ?? null);
        if ($anonId !== null && $boundAnonId !== null && hash_equals($anonId, $boundAnonId)) {
            return true;
        }

        return $contactEmailHash !== null && $this->bindingMatchesContactEmailHash($binding, $contactEmailHash);
    }

    private function bindingMatchesContactEmailHash(AttemptEmailBinding $binding, string $contactEmailHash): bool
    {
        $bindingEmailHash = $this->normalizeContactEmailHash($binding->email_hash ?? null);
        if ($bindingEmailHash !== null && hash_equals($contactEmailHash, $bindingEmailHash)) {
            return true;
        }

        try {
            $email = $this->piiCipher->decrypt($binding->email_enc ?? null);
        } catch (\Throwable) {
            return false;
        }

        if ($email === null) {
            return false;
        }

        $normalizedEmail = $this->piiCipher->normalizeEmail($email);
        if ($normalizedEmail === '') {
            return false;
        }

        return hash_equals($contactEmailHash, hash('sha256', $normalizedEmail));
    }

    private function normalizeOwnerString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeContactEmailHash(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $normalized) === 1 ? $normalized : null;
    }

    private function sign(string $encodedPayload): string
    {
        return hash_hmac('sha256', $encodedPayload, $this->signingKey());
    }

    private function signingKey(): string
    {
        $key = trim((string) config('app.key', ''));

        return $key !== '' ? $key : 'fap-result-access-token-local-key';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}

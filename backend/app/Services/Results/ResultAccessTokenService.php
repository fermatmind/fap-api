<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Models\AttemptEmailBinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class ResultAccessTokenService
{
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
    public function issueForActiveAttemptBinding(int $orgId, string $attemptId): ?array
    {
        $normalizedAttemptId = trim($attemptId);
        if ($normalizedAttemptId === '') {
            return null;
        }

        $binding = AttemptEmailBinding::query()
            ->where('org_id', max(0, $orgId))
            ->where('attempt_id', $normalizedAttemptId)
            ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        return $binding instanceof AttemptEmailBinding
            ? $this->issueForBinding($binding)
            : null;
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

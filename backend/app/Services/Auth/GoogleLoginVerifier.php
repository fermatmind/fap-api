<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleLoginVerifier
{
    public function verify(string $credential, string $nonce): array
    {
        abort_unless(config('services.google_login.enabled'), 503, 'Google login is not enabled.');
        $clientId = trim((string) config('services.google_login.client_id'));
        abort_if($clientId === '', 503, 'Google login is not configured.');
        try {
            $certs = Cache::remember('google-login:certificates', 3600, function (): array {
                return Http::connectTimeout(3)->timeout(8)
                    ->get('https://www.googleapis.com/oauth2/v1/certs')->throw()->json();
            });
        } catch (\Throwable) {
            abort(503, 'Google login verification is temporarily unavailable.');
        }
        try {
            $keys = [];
            foreach ($certs as $kid => $pem) {
                $keys[$kid] = new Key($pem, 'RS256');
            }
            $claims = (array) JWT::decode($credential, $keys);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['credential' => 'Invalid or expired Google credential.']);
        }
        $valid = in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['aud'] ?? '') === $clientId
            && (int) ($claims['exp'] ?? 0) > time()
            && is_string($claims['sub'] ?? null) && trim($claims['sub']) !== '' && strlen($claims['sub']) <= 128
            && is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce'])
            && ($claims['email_verified'] ?? false) === true
            && filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (! $valid) {
            throw ValidationException::withMessages(['credential' => 'Google credential claims do not match this login.']);
        }

        return [
            'sub' => $claims['sub'],
            'email' => mb_strtolower(trim($claims['email'])),
            'name' => mb_substr(trim((string) ($claims['name'] ?? '')), 0, 100),
        ];
    }
}

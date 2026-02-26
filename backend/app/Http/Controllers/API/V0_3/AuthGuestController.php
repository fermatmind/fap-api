<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Auth\FmTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuthGuestController extends Controller
{
    public function __invoke(Request $request, FmTokenService $tokenService): JsonResponse
    {
        $payload = $request->validate([
            'anon_id' => ['nullable', 'string', 'max:128'],
        ]);

        $anonId = $this->resolveAnonId(
            $payload['anon_id'] ?? null,
            $request
        );

        $issued = $tokenService->issueForUser($anonId, [
            'provider' => 'guest',
            'anon_id' => $anonId,
            'role' => 'public',
            'org_id' => 0,
        ]);

        $token = (string) ($issued['token'] ?? '');

        return response()->json([
            'ok' => true,
            'fm_token' => $token,
            'token' => $token,
            'auth_token' => $token,
            'expires_at' => $issued['expires_at'] ?? null,
            'anon_id' => $anonId,
        ]);
    }

    private function resolveAnonId(mixed $bodyAnonId, Request $request): string
    {
        $fromBody = $this->normalizeAnonId($bodyAnonId);
        if ($fromBody !== null) {
            return $fromBody;
        }

        $fromTransport = $this->normalizeAnonId(
            $request->attributes->get('client_anon_id')
                ?? $request->header('X-Anon-Id')
                ?? $request->cookie('fap_anonymous_id_v1')
        );
        if ($fromTransport !== null) {
            return $fromTransport;
        }

        return 'anon_' . (string) Str::uuid();
    }

    private function normalizeAnonId(mixed $candidate): ?string
    {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            return null;
        }

        $anonId = trim((string) $candidate);
        if ($anonId === '' || strlen($anonId) > 128) {
            return null;
        }

        $lower = mb_strtolower($anonId, 'UTF-8');
        foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
            if (mb_strpos($lower, $bad) !== false) {
                return null;
            }
        }

        return $anonId;
    }
}

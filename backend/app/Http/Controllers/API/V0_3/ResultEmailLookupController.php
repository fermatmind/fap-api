<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\ResultEmailLookupRequest;
use App\Services\Auth\FmTokenService;
use App\Services\Results\ResultEmailLookupService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;

final class ResultEmailLookupController extends Controller
{
    public function __construct(
        private readonly ResultEmailLookupService $lookup,
        private readonly FmTokenService $fmTokens,
        private readonly OrgContext $orgContext,
    ) {}

    /**
     * POST /api/v0.3/results/lookup-by-email
     */
    public function store(ResultEmailLookupRequest $request): JsonResponse
    {
        /** @var array{email:string,locale?:string|null} $payload */
        $payload = $request->validated();
        $identity = $this->resolveAuthenticatedIdentity($request);

        return response()->json($this->lookup->lookup(
            (string) $payload['email'],
            (int) $this->orgContext->orgId(),
            $payload['locale'] ?? null,
            $identity['user_id'],
            $identity['anon_id'],
        ));
    }

    /**
     * @return array{user_id:?int,anon_id:?string}
     */
    private function resolveAuthenticatedIdentity(ResultEmailLookupRequest $request): array
    {
        $token = $this->bearerToken($request);
        if ($token === '') {
            return ['user_id' => null, 'anon_id' => null];
        }

        $payload = $this->fmTokens->validateToken($token);
        if (($payload['ok'] ?? false) !== true) {
            return ['user_id' => null, 'anon_id' => null];
        }

        return [
            'user_id' => $this->normalizeUserId($payload['user_id'] ?? null),
            'anon_id' => $this->normalizeAnonId($payload['anon_id'] ?? null),
        ];
    }

    private function bearerToken(ResultEmailLookupRequest $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $header, $m) !== 1) {
            return '';
        }

        return trim((string) ($m[1] ?? ''));
    }

    private function normalizeUserId(mixed $candidate): ?int
    {
        if (! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $normalized = trim((string) $candidate);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        $userId = (int) $normalized;

        return $userId > 0 ? $userId : null;
    }

    private function normalizeAnonId(mixed $candidate): ?string
    {
        if (! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $normalized = trim((string) $candidate);

        return $normalized !== '' ? $normalized : null;
    }
}

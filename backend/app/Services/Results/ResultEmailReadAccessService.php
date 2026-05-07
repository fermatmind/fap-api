<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Models\AttemptEmailBinding;
use App\Support\OrgContext;
use Illuminate\Http\Request;

final class ResultEmailReadAccessService
{
    private const ATTR_CACHE_PREFIX = 'result_email_access_binding_';

    public function __construct(
        private readonly ResultAccessTokenService $tokens,
    ) {}

    public function activeBindingExists(int $orgId, string $attemptId): bool
    {
        return AttemptEmailBinding::query()
            ->where('org_id', max(0, $orgId))
            ->where('attempt_id', trim($attemptId))
            ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
            ->exists();
    }

    public function activeTokenBindingForRequest(Request $request, int $orgId, string $attemptId): ?AttemptEmailBinding
    {
        if (! (bool) config('fap.features.email_first_result_access', false)) {
            return null;
        }

        $orgId = max(0, $orgId);
        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return null;
        }

        $cacheKey = self::ATTR_CACHE_PREFIX.$orgId.':'.$attemptId;
        if ($request->attributes->has($cacheKey)) {
            $cached = $request->attributes->get($cacheKey);

            return $cached instanceof AttemptEmailBinding ? $cached : null;
        }

        $request->attributes->set($cacheKey, null);
        $token = $this->extractToken($request);
        if ($token === '') {
            return null;
        }

        $grant = $this->tokens->verify($token);
        if (! is_array($grant)) {
            return null;
        }

        if ((int) ($grant['org_id'] ?? -1) !== $orgId || (string) ($grant['attempt_id'] ?? '') !== $attemptId) {
            return null;
        }

        $binding = AttemptEmailBinding::query()
            ->where('id', (string) ($grant['binding_id'] ?? ''))
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->where('status', AttemptEmailBinding::STATUS_ACTIVE)
            ->first();

        if (! $binding instanceof AttemptEmailBinding) {
            return null;
        }

        if (! $this->bindingMatchesRequestActor($request, $binding)) {
            return null;
        }

        $request->attributes->set($cacheKey, $binding);

        return $binding;
    }

    /**
     * @return array{user_id:?string,anon_id:?string,binding_id:string}|null
     */
    public function tokenActorForRequest(Request $request, int $orgId, string $attemptId): ?array
    {
        $binding = $this->activeTokenBindingForRequest($request, $orgId, $attemptId);
        if (! $binding instanceof AttemptEmailBinding) {
            return null;
        }

        $userId = $this->normalizeNumericString($binding->bound_user_id ?? null);
        $anonId = $this->normalizeString($binding->bound_anon_id ?? null);

        return [
            'user_id' => $userId,
            'anon_id' => $anonId,
            'binding_id' => (string) $binding->getKey(),
        ];
    }

    private function extractToken(Request $request): string
    {
        $candidates = [
            $request->header('X-Result-Access-Token'),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeNumericString(mixed $candidate): ?string
    {
        $normalized = $this->normalizeString($candidate);
        if ($normalized === null || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeString(mixed $candidate): ?string
    {
        if (! is_string($candidate) && ! is_numeric($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value !== '' ? $value : null;
    }

    private function bindingMatchesRequestActor(Request $request, AttemptEmailBinding $binding): bool
    {
        $requestUserId = $this->requestUserId($request);
        $boundUserId = $this->normalizeNumericString($binding->bound_user_id ?? null);
        if ($requestUserId !== null && $boundUserId !== null && hash_equals($requestUserId, $boundUserId)) {
            return true;
        }

        $requestAnonId = $this->requestAnonId($request);
        $boundAnonId = $this->normalizeString($binding->bound_anon_id ?? null);

        return $requestAnonId !== null && $boundAnonId !== null && hash_equals($requestAnonId, $boundAnonId);
    }

    private function requestUserId(Request $request): ?string
    {
        $candidates = [
            $request->user()?->id,
            $request->attributes->get('fm_user_id'),
            $request->attributes->get('user_id'),
            app(OrgContext::class)->userId(),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeNumericString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function requestAnonId(Request $request): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            app(OrgContext::class)->anonId(),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}

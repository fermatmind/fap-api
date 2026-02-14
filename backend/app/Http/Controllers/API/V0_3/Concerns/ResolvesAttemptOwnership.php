<?php

namespace App\Http\Controllers\API\V0_3\Concerns;

use App\Models\Attempt;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ResolvesAttemptOwnership
{
    protected function ownedAttemptQuery(Request $request, string $attemptId): Builder
    {
        $orgId = (int) app(OrgContext::class)->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);

        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($anonId !== null) {
            return $query->where('anon_id', $anonId);
        }

        return $query->whereRaw('1 = 0');
    }

    protected function resolveUserId(Request $request): ?string
    {
        $candidates = [
            $request->user()?->id,
            $request->attributes->get('fm_user_id'),
            $request->attributes->get('user_id'),
            app(OrgContext::class)->userId(),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->header('X-Anon-Id'),
            $request->header('X-Fm-Anon-Id'),
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

    private function normalizeString(mixed $candidate): ?string
    {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value === '' ? null : $value;
    }
}

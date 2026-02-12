<?php

namespace App\Http\Controllers\API\V0_3\Concerns;

use App\Models\Attempt;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ResolvesAttemptOwnership
{
    protected function resolveUserId(Request $request): ?string
    {
        $orgContext = app(OrgContext::class);

        $candidates = [
            $orgContext->userId(),
            $request->attributes->get('fm_user_id'),
            $request->attributes->get('user_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function resolveAnonId(Request $request): ?string
    {
        $orgContext = app(OrgContext::class);

        $candidates = [
            $orgContext->anonId(),
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->query('anon_id'),
            $request->header('X-Anon-Id'),
            $request->header('X-Fm-Anon-Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    protected function ownedAttemptQuery(string $attemptId): Builder
    {
        $orgContext = app(OrgContext::class);

        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgContext->orgId());

        $role = (string) ($orgContext->role() ?? '');
        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        $request = request();
        $userId = null;
        $anonId = null;

        if ($request instanceof Request) {
            $userId = $this->resolveUserId($request);
            $anonId = $this->resolveAnonId($request);
        } else {
            $ctxUserId = $orgContext->userId();
            if ($ctxUserId !== null) {
                $candidate = trim((string) $ctxUserId);
                $userId = $candidate !== '' ? $candidate : null;
            }

            $ctxAnonId = $orgContext->anonId();
            if (is_string($ctxAnonId)) {
                $candidate = trim($ctxAnonId);
                $anonId = $candidate !== '' ? $candidate : null;
            }
        }

        if (in_array($role, ['member', 'viewer'], true)) {
            if ($userId !== null) {
                return $query->where('user_id', $userId);
            }

            if ($anonId !== null) {
                return $query->where('anon_id', $anonId);
            }

            return $query->whereRaw('1=0');
        }

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        if ($anonId !== null) {
            return $query->where('anon_id', $anonId);
        }

        return $query->whereRaw('1=0');
    }
}

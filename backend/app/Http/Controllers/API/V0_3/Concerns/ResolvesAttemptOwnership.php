<?php

namespace App\Http\Controllers\API\V0_3\Concerns;

use App\Models\Attempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

trait ResolvesAttemptOwnership
{
    protected function ownedAttemptQuery(string $id): Builder
    {
        $query = Attempt::query()
            ->where('id', $id)
            ->where('org_id', $this->orgContext->orgId());

        $role = (string) ($this->orgContext->role() ?? '');
        if ($this->isPrivilegedRole($role)) {
            return $query;
        }

        if ($this->isMemberLikeRole($role)) {
            $memberUserId = $this->orgContext->userId();
            if ($memberUserId === null) {
                $this->throwAttemptNotFound($id);
            }

            return $query->where('user_id', $memberUserId);
        }

        $request = request();
        $userId = $request instanceof Request
            ? $this->resolveUserId($request)
            : $this->orgContext->userId();
        $anonId = $request instanceof Request
            ? $this->resolveAnonId($request)
            : $this->orgContext->anonId();

        $user = $userId !== null ? (string) $userId : '';
        $anon = $anonId !== null ? trim($anonId) : '';

        if ($user === '' && $anon === '') {
            $this->throwAttemptNotFound($id);
        }

        return $query->where(function ($q) use ($user, $anon) {
            $applied = false;
            if ($user !== '') {
                $q->where('user_id', $user);
                $applied = true;
            }
            if ($anon !== '') {
                if ($applied) {
                    $q->orWhere('anon_id', $anon);
                } else {
                    $q->where('anon_id', $anon);
                    $applied = true;
                }
            }
            if (!$applied) {
                $q->whereRaw('1=0');
            }
        });
    }

    protected function throwAttemptNotFound(string $id): never
    {
        $exception = new ModelNotFoundException();
        $exception->setModel(Attempt::class, [$id]);

        throw $exception;
    }

    protected function isPrivilegedRole(string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    protected function isMemberLikeRole(string $role): bool
    {
        return in_array($role, ['member', 'viewer'], true);
    }

    protected function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $this->orgContext->anonId(),
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

    protected function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }
}

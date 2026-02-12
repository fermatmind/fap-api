<?php

namespace App\Http\Controllers\API\V0_3\Concerns;

use App\Models\Attempt;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ResolvesAttemptOwnership
{
    /**
     * @return array{userId:?string, anonId:?string}
     */
    protected function resolveViewerIdentity(Request $request): array
    {
        $ctx = app(OrgContext::class);

        $userId = $this->normalizeUserId(auth()->id());
        if ($userId === null) {
            $userId = $this->normalizeUserId(
                $request->attributes->get('fm_user_id')
                    ?? $request->attributes->get('user_id')
                    ?? $ctx->userId()
            );
        }

        $anonId = $this->normalizeAnonId(
            $request->header('X-Anon-Id')
                ?? $request->header('X-Fm-Anon-Id')
        );

        if ($anonId === null) {
            $anonId = $this->normalizeAnonId(
                $request->attributes->get('anon_id')
                    ?? $request->attributes->get('fm_anon_id')
                    ?? $ctx->anonId()
            );
        }

        return [
            'userId' => $userId,
            'anonId' => $anonId,
        ];
    }

    protected function orgIdFromContext(): int
    {
        $req = request();
        if ($req instanceof Request) {
            $attrOrgId = $req->attributes->get('org_id');
            if (is_numeric($attrOrgId)) {
                return (int) $attrOrgId;
            }

            $fmOrgId = $req->attributes->get('fm_org_id');
            if (is_numeric($fmOrgId)) {
                return (int) $fmOrgId;
            }
        }

        return (int) app(OrgContext::class)->orgId();
    }

    /**
     * Legacy usage:
     * - ownedAttemptQuery($attemptId)
     *
     * New usage:
     * - ownedAttemptQuery($orgId, $userId, $anonId)
     */
    protected function ownedAttemptQuery(int|string $orgIdOrAttemptId, ?string $userId = null, ?string $anonId = null): Builder
    {
        if (is_string($orgIdOrAttemptId) && func_num_args() === 1) {
            $req = request();
            if (!$req instanceof Request) {
                return $this->denyAll(Attempt::query());
            }

            $identity = $this->resolveViewerIdentity($req);
            $orgId = $this->orgIdFromContext();

            return $this->buildOwnedAttemptQuery($orgId, $identity['userId'], $identity['anonId'])
                ->where('id', $orgIdOrAttemptId);
        }

        return $this->buildOwnedAttemptQuery((int) $orgIdOrAttemptId, $userId, $anonId);
    }

    protected function resolveAttemptOr404(Request $request, string $attemptId): Attempt
    {
        $identity = $this->resolveViewerIdentity($request);

        $attempt = $this->ownedAttemptQuery(
            $this->orgIdFromContext(),
            $identity['userId'],
            $identity['anonId']
        )->where('id', $attemptId)->first();

        if (!$attempt instanceof Attempt) {
            abort(404);
        }

        return $attempt;
    }

    protected function resolveUserId(Request $request): ?string
    {
        return $this->resolveViewerIdentity($request)['userId'];
    }

    protected function resolveAnonId(Request $request): ?string
    {
        return $this->resolveViewerIdentity($request)['anonId'];
    }

    private function buildOwnedAttemptQuery(int $orgId, ?string $userId, ?string $anonId): Builder
    {
        $query = Attempt::query()->where('org_id', $orgId);

        $normalizedUserId = $this->normalizeUserId($userId);
        $normalizedAnonId = $this->normalizeAnonId($anonId);

        $role = (string) (app(OrgContext::class)->role() ?? '');
        if (in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        if ($normalizedUserId === null && $normalizedAnonId === null) {
            return $this->denyAll($query);
        }

        return $query->where(function (Builder $owned) use ($normalizedUserId, $normalizedAnonId): void {
            $applied = false;

            if ($normalizedUserId !== null) {
                $owned->where('user_id', $normalizedUserId);
                $applied = true;
            }

            if ($normalizedAnonId !== null) {
                if ($applied) {
                    $owned->orWhere('anon_id', $normalizedAnonId);
                } else {
                    $owned->where('anon_id', $normalizedAnonId);
                    $applied = true;
                }
            }

            if (!$applied) {
                $owned->where(DB::raw('1'), '=', 0);
            }
        });
    }

    private function denyAll(Builder $query): Builder
    {
        return $query->where(DB::raw('1'), '=', 0);
    }

    private function normalizeUserId(mixed $candidate): ?string
    {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return null;
        }

        return $value;
    }

    private function normalizeAnonId(mixed $candidate): ?string
    {
        if (!is_string($candidate) && !is_numeric($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value === '' ? null : $value;
    }
}

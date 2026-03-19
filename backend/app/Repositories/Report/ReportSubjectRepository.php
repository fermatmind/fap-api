<?php

declare(strict_types=1);

namespace App\Repositories\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Support\OrgContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportSubjectRepository
{
    public function __construct(private readonly OrgContext $orgContext) {}

    public function findSubjectForCurrentContext(string $attemptId, ReportAccessActor $actor): ?ReportSubject
    {
        return $this->findSubjectForRealm(
            $this->orgContext->contextKind(),
            $this->orgContext->scopedOrgId(),
            $attemptId,
            $actor,
        );
    }

    public function findSubjectForRealm(
        string $contextKind,
        int $orgId,
        string $attemptId,
        ReportAccessActor $actor,
    ): ?ReportSubject {
        $realmOrgId = $this->resolveRealmOrgId($contextKind, $orgId);
        $attempt = $this->findAttemptForRealm($contextKind, $realmOrgId, $attemptId, $actor);
        if (! $attempt instanceof Attempt) {
            return null;
        }

        $result = $this->findResultForRealm($realmOrgId, $attemptId);
        if (! $result instanceof Result) {
            return null;
        }

        return new ReportSubject($attempt, $result, $realmOrgId);
    }

    public function findSubjectForSystem(int $orgId, string $attemptId): ?ReportSubject
    {
        $attempt = $this->findAttemptRow(max(0, $orgId), $attemptId);
        if (! $attempt instanceof Attempt) {
            return null;
        }

        $result = $this->findResultForRealm((int) ($attempt->org_id ?? 0), $attemptId);
        if (! $result instanceof Result) {
            return null;
        }

        return new ReportSubject($attempt, $result, (int) ($attempt->org_id ?? 0));
    }

    public function findAttemptForCurrentContext(string $attemptId, ReportAccessActor $actor): ?Attempt
    {
        return $this->findAttemptForRealm(
            $this->orgContext->contextKind(),
            $this->orgContext->scopedOrgId(),
            $attemptId,
            $actor,
        );
    }

    public function findAttemptForSystem(int $orgId, string $attemptId): ?Attempt
    {
        return $this->findAttemptRow(max(0, $orgId), $attemptId);
    }

    public function findAttemptForRealm(
        string $contextKind,
        int $orgId,
        string $attemptId,
        ReportAccessActor $actor,
    ): ?Attempt {
        $realmOrgId = $this->resolveRealmOrgId($contextKind, $orgId);
        $query = $this->attemptBaseQuery($realmOrgId, $attemptId);

        if ($this->normalizeContextKind($contextKind) === OrgContext::KIND_TENANT) {
            $this->applyTenantActorFilter($query, $actor);
        } else {
            $this->applyPublicActorFilter($query, $actor);
        }

        $row = $query->first();

        return $row !== null ? $this->hydrateAttempt($row) : null;
    }

    public function findResultForRealm(int $orgId, string $attemptId): ?Result
    {
        $row = DB::table('results')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId)
            ->first();

        return $row !== null ? $this->hydrateResult($row) : null;
    }

    private function findAttemptRow(int $orgId, string $attemptId): ?Attempt
    {
        $row = $this->attemptBaseQuery($orgId, $attemptId)->first();

        return $row !== null ? $this->hydrateAttempt($row) : null;
    }

    private function attemptBaseQuery(int $orgId, string $attemptId): Builder
    {
        return DB::table('attempts')
            ->where('id', trim($attemptId))
            ->where('org_id', $orgId);
    }

    private function applyTenantActorFilter(Builder $query, ReportAccessActor $actor): void
    {
        if ($actor->isPrivilegedTenantRole()) {
            return;
        }

        if ($actor->isMemberLikeTenantRole()) {
            if ($actor->userId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('user_id', $actor->userId);

            return;
        }

        if ($actor->userId === null && $actor->anonId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $nested) use ($actor): void {
            if ($actor->userId !== null) {
                $nested->where('user_id', $actor->userId);
            }

            if ($actor->anonId !== null) {
                if ($actor->userId !== null) {
                    $nested->orWhere('anon_id', $actor->anonId);
                } else {
                    $nested->where('anon_id', $actor->anonId);
                }
            }
        });
    }

    private function applyPublicActorFilter(Builder $query, ReportAccessActor $actor): void
    {
        if ($actor->userId === null && $actor->anonId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $nested) use ($actor): void {
            if ($actor->userId !== null) {
                $nested->where('user_id', $actor->userId);
            }

            if ($actor->anonId !== null) {
                if ($actor->userId !== null) {
                    $nested->orWhere('anon_id', $actor->anonId);
                } else {
                    $nested->where('anon_id', $actor->anonId);
                }
            }
        });
    }

    private function resolveRealmOrgId(string $contextKind, int $orgId): int
    {
        if ($this->normalizeContextKind($contextKind) === OrgContext::KIND_TENANT) {
            return max(1, $orgId);
        }

        return 0;
    }

    private function normalizeContextKind(string $contextKind): string
    {
        $normalized = strtolower(trim($contextKind));

        return $normalized === OrgContext::KIND_TENANT ? OrgContext::KIND_TENANT : OrgContext::KIND_PUBLIC;
    }

    private function hydrateAttempt(object $row): Attempt
    {
        return (new Attempt)->newFromBuilder((array) $row);
    }

    private function hydrateResult(object $row): Result
    {
        return (new Result)->newFromBuilder((array) $row);
    }
}

<?php

namespace App\Services\Legacy;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\Share;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class LegacyShareService
{
    /**
     * Generate or fetch a share id for an attempt (v0.2 legacy).
     *
     * Security:
     * - Strict tenant/ownership filtering at SQL layer (org_id + user_id/anon_id).
     * - Unauthorized access yields ModelNotFoundException (404).
     * - share_id uses bin2hex(random_bytes(16)).
     */
    public function getOrCreateAttemptShare(string $attemptId, int $orgId, ?int $userId, ?string $anonId): array
    {
        $attempt = $this->findAccessibleAttempt($attemptId, $orgId, $userId, $anonId);

        $result = Result::query()
            ->where('attempt_id', $attempt->id)
            ->when($this->hasColumn('results', 'org_id'), fn (Builder $q) => $q->where('org_id', $orgId))
            ->first();

        if (!$result) {
            throw (new ModelNotFoundException())->setModel(Result::class, [$attempt->id]);
        }

        $share = Share::query()
            ->where('attempt_id', $attempt->id)
            ->first();

        if (!$share) {
            $share = $this->createShare($attempt, $anonId);
        }

        $typeCode = (string) ($result->type_code ?? '');
        $resultJson = is_array($result->result_json ?? null) ? $result->result_json : [];
        $typeName = (string) ($resultJson['type_name'] ?? $resultJson['type'] ?? $typeCode);

        return [
            'attempt_id' => (string) $attempt->id,
            'share_id' => (string) $share->id,
            'org_id' => (int) $orgId,
            'content_package_version' => (string) ($attempt->content_package_version ?? $result->content_package_version ?? ''),
            'type_code' => $typeCode,
            'type_name' => $typeName,
            'created_at' => $share->created_at?->toISOString(),
        ];
    }

    /**
     * Resolve share view payload by share_id (v0.2 legacy).
     */
    public function getShareView(string $shareId): array
    {
        $share = Share::query()->where('id', $shareId)->firstOrFail();

        $attempt = Attempt::query()
            ->where('id', $share->attempt_id)
            ->firstOrFail();

        $orgId = (int) ($attempt->org_id ?? 0);

        $result = Result::query()
            ->where('attempt_id', $attempt->id)
            ->when($this->hasColumn('results', 'org_id'), fn (Builder $q) => $q->where('org_id', $orgId))
            ->first();

        $typeCode = (string) ($result->type_code ?? '');
        $resultJson = is_array($result->result_json ?? null) ? $result->result_json : [];
        $typeName = (string) ($resultJson['type_name'] ?? $resultJson['type'] ?? $typeCode);

        return [
            'share_id' => (string) $share->id,
            'attempt_id' => (string) $attempt->id,
            'org_id' => $orgId,
            'type_code' => $typeCode,
            'type_name' => $typeName,
            'created_at' => $share->created_at?->toISOString(),
        ];
    }

    /**
     * Strict ownership + org_id filtering at SQL layer.
     * Unauthorized access is expressed as ModelNotFoundException (404).
     */
    private function findAccessibleAttempt(string $attemptId, int $orgId, ?int $userId, ?string $anonId): Attempt
    {
        $q = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId);

        if ($userId === null && ($anonId === null || $anonId === '')) {
            throw (new ModelNotFoundException())->setModel(Attempt::class, [$attemptId]);
        }

        $q->where(function (Builder $sub) use ($userId, $anonId) {
            if ($userId !== null) {
                $sub->orWhere('user_id', (string) $userId);
            }
            if ($anonId !== null && $anonId !== '') {
                $sub->orWhere('anon_id', $anonId);
            }
        });

        return $q->firstOrFail();
    }

    private function createShare(Attempt $attempt, ?string $anonId): Share
    {
        $id = bin2hex(random_bytes(16));

        try {
            return Share::query()->create([
                'id' => $id,
                'attempt_id' => (string) $attempt->id,
                'anon_id' => $anonId,
                'scale_code' => (string) ($attempt->scale_code ?? ''),
                'scale_version' => (string) ($attempt->scale_version ?? ''),
                'content_package_version' => (string) ($attempt->content_package_version ?? ''),
            ]);
        } catch (QueryException $e) {
            // Unique(attempt_id) race: fetch the existing row
            return Share::query()->where('attempt_id', (string) $attempt->id)->firstOrFail();
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

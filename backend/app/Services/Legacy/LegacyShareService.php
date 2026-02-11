<?php

namespace App\Services\Legacy;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\Share;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class LegacyShareService
{
    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array
    {
        $attempt = $this->findAccessibleAttempt($attemptId, $ctx);

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $ctx->orgId())
            ->firstOrFail();

        $share = Share::query()
            ->where('attempt_id', $attemptId)
            ->first();

        if (!$share) {
            $share = $this->createShare($attempt, $ctx->anonId());
        }

        $typeCode = (string) ($result->type_code ?? '');
        $resultJson = is_array($result->result_json ?? null) ? $result->result_json : [];
        $typeName = (string) ($resultJson['type_name'] ?? $resultJson['type'] ?? $typeCode);

        $shareId = (string) $share->id;
        $shareUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/share/' . $shareId;

        return [
            'share_id' => $shareId,
            'share_url' => $shareUrl,
            'attempt_id' => (string) $attempt->id,
            'created_at' => $share->created_at?->toISOString(),
            'org_id' => (int) $ctx->orgId(),
            'content_package_version' => (string) ($attempt->content_package_version ?? $result->content_package_version ?? ''),
            'type_code' => $typeCode,
            'type_name' => $typeName,
        ];
    }

    public function getShareView(string $shareId): array
    {
        $share = Share::query()->where('id', $shareId)->firstOrFail();

        $attempt = Attempt::query()
            ->where('id', $share->attempt_id)
            ->firstOrFail();

        $orgId = (int) ($attempt->org_id ?? 0);

        $result = Result::query()
            ->where('attempt_id', $attempt->id)
            ->where('org_id', $orgId)
            ->firstOrFail();

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

    private function findAccessibleAttempt(string $attemptId, OrgContext $ctx): Attempt
    {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->orgId());

        if (!$this->isAdmin($ctx)) {
            $userId = $ctx->userId();
            $anonId = $ctx->anonId();

            if ($userId === null && ($anonId === null || $anonId === '')) {
                throw (new ModelNotFoundException())->setModel(Attempt::class, [$attemptId]);
            }

            $query->where(function (Builder $sub) use ($userId, $anonId): void {
                if ($userId !== null) {
                    $sub->orWhere('user_id', (string) $userId);
                }
                if ($anonId !== null && $anonId !== '') {
                    $sub->orWhere('anon_id', $anonId);
                }
            });
        }

        return $query->firstOrFail();
    }

    private function isAdmin(OrgContext $ctx): bool
    {
        return strtolower((string) ($ctx->role() ?? '')) === 'admin';
    }

    private function createShare(Attempt $attempt, ?string $anonId): Share
    {
        $share = new Share();
        $share->id = bin2hex(random_bytes(16));
        $share->attempt_id = (string) $attempt->id;
        $share->anon_id = $anonId;
        $share->scale_code = (string) ($attempt->scale_code ?? '');
        $share->scale_version = (string) ($attempt->scale_version ?? '');
        $share->content_package_version = (string) ($attempt->content_package_version ?? '');

        try {
            $share->save();
            return $share;
        } catch (QueryException $e) {
            return Share::query()
                ->where('attempt_id', (string) $attempt->id)
                ->firstOrFail();
        }
    }
}

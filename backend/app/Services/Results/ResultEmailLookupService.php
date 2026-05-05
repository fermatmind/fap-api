<?php

declare(strict_types=1);

namespace App\Services\Results;

use App\Models\AttemptEmailBinding;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Support\PiiCipher;
use Illuminate\Support\Facades\DB;

final class ResultEmailLookupService
{
    private const EXCLUDED_SENSITIVE_SCALES = [
        'SDS_20',
        'CLINICAL_COMBO_68',
    ];

    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly PiiCipher $piiCipher,
        private readonly ScaleCodeResponseProjector $scaleCodeProjector,
        private readonly ResultAccessTokenService $tokens,
    ) {}

    /**
     * @return array{ok:bool,items:list<array<string,mixed>>}
     */
    public function lookup(string $email, int $orgId, ?string $locale = null): array
    {
        $normalizedEmail = $this->piiCipher->normalizeEmail($email);
        $emailHash = $this->piiCipher->emailHash($normalizedEmail);
        $orgId = max(0, $orgId);

        $rows = DB::table('attempt_email_bindings as b')
            ->join('attempts as a', function ($join): void {
                $join->on('a.id', '=', 'b.attempt_id')
                    ->on('a.org_id', '=', 'b.org_id');
            })
            ->join('results as r', function ($join): void {
                $join->on('r.attempt_id', '=', 'b.attempt_id')
                    ->on('r.org_id', '=', 'b.org_id');
            })
            ->where('b.org_id', $orgId)
            ->where('b.email_hash', $emailHash)
            ->where('b.status', AttemptEmailBinding::STATUS_ACTIVE)
            ->orderByDesc(DB::raw('coalesce(a.submitted_at, a.created_at)'))
            ->orderByDesc('b.created_at')
            ->limit(self::MAX_ITEMS)
            ->get([
                'b.id as binding_id',
                'b.org_id',
                'b.attempt_id',
                'b.first_bound_at',
                'b.last_accessed_at',
                'a.scale_code as attempt_scale_code',
                'a.scale_code_v2 as attempt_scale_code_v2',
                'a.scale_uid as attempt_scale_uid',
                'a.scale_version as attempt_scale_version',
                'a.locale as attempt_locale',
                'a.submitted_at',
                'a.created_at as attempt_created_at',
                'r.id as result_id',
                'r.scale_code as result_scale_code',
                'r.scale_code_v2 as result_scale_code_v2',
                'r.scale_uid as result_scale_uid',
                'r.scale_version as result_scale_version',
                'r.type_code',
                'r.computed_at',
            ]);

        if ($rows->isEmpty()) {
            return [
                'ok' => true,
                'items' => [],
            ];
        }

        $items = [];
        $bindingIds = [];
        foreach ($rows as $row) {
            $scaleIdentity = $this->resolveScaleIdentity($row);
            $scaleCode = strtoupper(trim((string) ($scaleIdentity['scale_code_legacy'] ?: $scaleIdentity['scale_code'])));
            if (in_array($scaleCode, self::EXCLUDED_SENSITIVE_SCALES, true)) {
                continue;
            }

            $binding = new AttemptEmailBinding([
                'id' => (string) ($row->binding_id ?? ''),
                'org_id' => (int) ($row->org_id ?? 0),
                'attempt_id' => (string) ($row->attempt_id ?? ''),
                'status' => AttemptEmailBinding::STATUS_ACTIVE,
            ]);
            $binding->exists = true;
            $token = $this->tokens->issueForBinding($binding);
            $attemptId = (string) ($row->attempt_id ?? '');
            $resultUrl = $this->localizedResultUrl(
                $attemptId,
                $locale ?? $row->attempt_locale ?? null,
                $token['token'],
            );

            $items[] = [
                'attempt_id' => $attemptId,
                'result_id' => (string) ($row->result_id ?? ''),
                'scale_code' => $scaleIdentity['scale_code'],
                'scale_code_legacy' => $scaleIdentity['scale_code_legacy'],
                'scale_code_v2' => $scaleIdentity['scale_code_v2'],
                'scale_uid' => $scaleIdentity['scale_uid'],
                'scale_version' => (string) (($row->result_scale_version ?? null) ?: ($row->attempt_scale_version ?? '')),
                'type_code' => (string) ($row->type_code ?? ''),
                'submitted_at' => $this->nullableTimestamp($row->submitted_at ?? null),
                'computed_at' => $this->nullableTimestamp($row->computed_at ?? null),
                'bound_at' => $this->nullableTimestamp($row->first_bound_at ?? null),
                'result_url' => $resultUrl,
                'result_access_token' => $token['token'],
                'result_access_token_expires_at' => $token['expires_at'],
            ];

            $bindingIds[] = (string) ($row->binding_id ?? '');
        }

        $bindingIds = array_values(array_filter(array_unique($bindingIds)));
        if ($bindingIds !== []) {
            DB::table('attempt_email_bindings')
                ->where('org_id', $orgId)
                ->whereIn('id', $bindingIds)
                ->update([
                    'last_accessed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array{scale_code:string,scale_code_legacy:string,scale_code_v2:string,scale_uid:string|null}
     */
    private function resolveScaleIdentity(object $row): array
    {
        return $this->scaleCodeProjector->project(
            (string) (($row->result_scale_code ?? null) ?: ($row->attempt_scale_code ?? '')),
            (string) (($row->result_scale_code_v2 ?? null) ?: ($row->attempt_scale_code_v2 ?? '')),
            ($row->result_scale_uid ?? null) !== null
                ? (string) $row->result_scale_uid
                : (($row->attempt_scale_uid ?? null) !== null ? (string) $row->attempt_scale_uid : null)
        );
    }

    private function localizedResultUrl(string $attemptId, mixed $locale, string $token): string
    {
        $normalized = strtolower(trim((string) $locale));
        $prefix = str_starts_with($normalized, 'zh') ? '/zh' : '/en';

        return "{$prefix}/result/{$attemptId}?access_token=".rawurlencode($token);
    }

    private function nullableTimestamp(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}

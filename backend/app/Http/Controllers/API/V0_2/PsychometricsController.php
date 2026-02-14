<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesOrgId;
use App\Models\Attempt;
use App\Services\Psychometrics\NormsRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PsychometricsController extends Controller
{
    use ResolvesOrgId;

    public function __construct(private NormsRegistry $normsRegistry)
    {
    }

    public function listNorms(Request $request, string $scale): JsonResponse
    {
        $scale = strtoupper(trim($scale));
        if ($scale === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
            ], 400);
        }

        $region = (string) ($request->query('region') ?? $request->header('X-Region') ?? config('content_packs.default_region', 'CN_MAINLAND'));
        $locale = (string) ($request->query('locale') ?? $request->header('X-Locale') ?? config('content_packs.default_locale', 'zh-CN'));

        $items = $this->normsRegistry->listAvailableNorms($scale, $region, $locale);
        $resolved = $this->normsRegistry->resolve($scale, $region, $locale);

        return response()->json([
            'ok' => true,
            'scale_code' => $scale,
            'region' => $region,
            'locale' => $locale,
            'items' => $items,
            'default' => $resolved['ok'] ? [
                'norm_id' => $resolved['norm_id'] ?? '',
                'version' => $resolved['version'] ?? '',
                'checksum' => $resolved['checksum'] ?? '',
                'bucket_keys' => $resolved['bucket_keys'] ?? [],
                'bucket' => $resolved['bucket'] ?? null,
            ] : null,
        ]);
    }

    public function quality(Request $request, string $id): JsonResponse
    {
        if (!\App\Support\SchemaBaseline::hasTable('attempt_quality')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'QUALITY_NOT_AVAILABLE',
            ], 404);
        }

        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $orgId = $this->resolveOrgId($request);
        $user = $userId !== null ? trim($userId) : '';
        $anon = $anonId !== null ? trim($anonId) : '';

        $query = DB::table('attempt_quality')
            ->join('attempts', 'attempts.id', '=', 'attempt_quality.attempt_id')
            ->where('attempt_quality.attempt_id', $id)
            ->where('attempts.org_id', $orgId)
            ->select('attempt_quality.*');

        if ($user === '' && $anon === '') {
            $query->whereRaw('1=0');
        } else {
            $query->where(function ($q) use ($user, $anon) {
                $applied = false;
                if ($user !== '') {
                    $q->where('attempts.user_id', $user);
                    $applied = true;
                }
                if ($anon !== '') {
                    if ($applied) {
                        $q->orWhere('attempts.anon_id', $anon);
                    } else {
                        $q->where('attempts.anon_id', $anon);
                        $applied = true;
                    }
                }
                if (!$applied) {
                    $q->whereRaw('1=0');
                }
            });
        }

        $row = $query->first();
        if (!$row) {
            abort(404);
        }

        $checks = $row->checks_json ?? null;
        if (is_string($checks)) {
            $decoded = json_decode($checks, true);
            $checks = is_array($decoded) ? $decoded : null;
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'grade' => (string) ($row->grade ?? ''),
            'checks' => $checks,
        ]);
    }

    public function stats(Request $request, string $id): JsonResponse
    {
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        $orgId = $this->resolveOrgId($request);
        $attempt = $this->ownedAttemptQuery($id, $orgId, $userId, $anonId)->firstOrFail();

        $snapshot = $attempt->calculation_snapshot_json;
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            $snapshot = is_array($decoded) ? $decoded : null;
        }

        $stats = is_array($snapshot) ? ($snapshot['stats'] ?? null) : null;

        if (!is_array($stats)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'STATS_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => $id,
            'stats' => $stats,
            'norm' => $snapshot['norm'] ?? null,
        ]);
    }

    private function resolveUserId(Request $request): ?string
    {
        $uid = $request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id');
        if (is_string($uid) || is_numeric($uid)) {
            $value = trim((string) $uid);
            if ($value !== '' && preg_match('/^\d+$/', $value)) {
                return $value;
            }
        }

        $user = $request->user();
        if ($user && isset($user->id)) {
            $value = trim((string) $user->id);
            if ($value !== '' && preg_match('/^\d+$/', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->header('X-Anon-Id'),
            $request->header('X-Fm-Anon-Id'),
            app(OrgContext::class)->anonId(),
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

    private function ownedAttemptQuery(
        string $attemptId,
        int $orgId,
        ?string $userId,
        ?string $anonId
    ): \Illuminate\Database\Eloquent\Builder {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $orgId);
        $user = $userId !== null ? trim($userId) : '';
        $anon = $anonId !== null ? trim($anonId) : '';

        if ($user === '' && $anon === '') {
            return $query->whereRaw('1=0');
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
}

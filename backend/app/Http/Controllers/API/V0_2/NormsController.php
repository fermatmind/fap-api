<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NormsController extends Controller
{
    /**
     * GET /api/v0.2/norms/percentile
     * Query: pack_id, metric_key, score
     */
    public function percentile(Request $request)
    {
        if (!$this->isNormsEnabled()) {
            return $this->notEnabled();
        }

        $v = Validator::make($request->all(), [
            'pack_id'    => ['required', 'string', 'max:64'],
            'metric_key' => ['required', 'string', 'in:EI,SN,TF,JP,AT'],
            'score'      => ['required', 'numeric'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'error_code'  => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        $data = $v->validated();
        $packId = (string) $data['pack_id'];
        $metricKey = (string) $data['metric_key'];
        $scoreInt = (int) round((float) $data['score']);

        $version = $this->resolveVersion($packId);
        if (!$version) {
            return $this->notFound();
        }

        $row = DB::table('norms_table')
            ->where('norms_version_id', (string) $version->id)
            ->where('metric_key', $metricKey)
            ->where('score_int', $scoreInt)
            ->first();

        if (!$row) {
            return $this->notFound();
        }

        $percentile = (float) ($row->percentile ?? 0.0);
        $overPercent = (int) floor($percentile * 100);

        return response()->json([
            'ok'              => true,
            'pack_id'         => $packId,
            'metric_key'      => $metricKey,
            'score_int'       => $scoreInt,
            'percentile'      => $percentile,
            'over_percent'    => $overPercent,
            'sample_n'        => (int) ($version->sample_n ?? 0),
            'window_start_at' => (string) ($version->window_start_at ?? ''),
            'window_end_at'   => (string) ($version->window_end_at ?? ''),
            'version_id'      => (string) ($version->id ?? ''),
            'rank_rule'       => (string) ($version->rank_rule ?? ''),
        ]);
    }

    private function isNormsEnabled(): bool
    {
        return (int) \App\Support\RuntimeConfig::value('NORMS_ENABLED', 0) === 1;
    }

    private function resolveVersion(string $packId): ?object
    {
        $pin = trim((string) \App\Support\RuntimeConfig::value('NORMS_VERSION_PIN', ''));
        $query = DB::table('norms_versions')->where('pack_id', $packId);

        if ($pin !== '') {
            return $query->where('id', $pin)->first();
        }

        return $query
            ->where('status', 'active')
            ->orderByDesc('computed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    private function notEnabled()
    {
        return response()->json([
            'ok'    => false,
            'error_code' => 'NOT_ENABLED',
        ]);
    }

    private function notFound()
    {
        return response()->json([
            'ok'    => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }
}

<?php

namespace App\Services\Report\Composer;

use App\Support\OrgContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ReportPayloadAssemblerNormsAndContextTrait
{
    private function resolveServerOrgId(array $ctx): int
    {
        if (array_key_exists('org_id', $ctx) && is_numeric($ctx['org_id'])) {
            return max(0, (int) $ctx['org_id']);
        }

        return max(0, (int) app(OrgContext::class)->orgId());
    }

    private function buildNormsPayload(string $packId, array $scoresPct): ?array
    {
        if (!$this->isNormsEnabled()) {
            return null;
        }
        if (!Schema::hasTable('norms_versions') || !Schema::hasTable('norms_table')) {
            return null;
        }

        $version = $this->resolveNormsVersion($packId);
        if (!$version) {
            return null;
        }

        $metrics = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $metricsPayload = [];

        foreach ($metrics as $metric) {
            if (!array_key_exists($metric, $scoresPct)) {
                return null;
            }
            $score = $scoresPct[$metric];
            if (!is_numeric($score)) {
                return null;
            }

            $scoreInt = (int) round((float) $score);
            $row = DB::table('norms_table')
                ->where('norms_version_id', (string) $version->id)
                ->where('metric_key', $metric)
                ->where('score_int', $scoreInt)
                ->first();

            if (!$row) {
                return null;
            }

            $percentile = (float) ($row->percentile ?? 0.0);
            $metricsPayload[$metric] = [
                'score_int' => $scoreInt,
                'percentile' => $percentile,
                'over_percent' => (int) floor($percentile * 100),
            ];
        }

        return [
            'pack_id' => $packId,
            'version_id' => (string) ($version->id ?? ''),
            'N' => (int) ($version->sample_n ?? 0),
            'window_start_at' => (string) ($version->window_start_at ?? ''),
            'window_end_at' => (string) ($version->window_end_at ?? ''),
            'rank_rule' => (string) ($version->rank_rule ?? 'leq'),
            'metrics' => $metricsPayload,
        ];
    }

    private function isNormsEnabled(): bool
    {
        return (int) env('NORMS_ENABLED', 0) === 1;
    }

    private function resolveNormsVersion(string $packId): ?object
    {
        $pin = trim((string) env('NORMS_VERSION_PIN', ''));
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

    private function normalizeRequestedVersion($requested): ?string
    {
        if (!is_string($requested) || $requested === '') {
            return null;
        }

        if (substr_count($requested, '.') >= 3) {
            $parts = explode('.', $requested);
            return implode('.', array_slice($parts, 3));
        }

        $pos = strripos($requested, '-v');
        if ($pos !== false) {
            return substr($requested, $pos + 1);
        }

        return $requested;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ScaleIdentityGate extends Command
{
    protected $signature = 'ops:scale-identity-gate
        {--hours=336 : Lookback window in hours}
        {--max-rows=5000 : Max rows scanned per table for dual-write mismatch checks}
        {--json=1 : Output JSON payload}
        {--strict=0 : Exit non-zero when any metric exceeds threshold}';

    protected $description = 'Compute scale identity rollout gate metrics for Phase 8~10 decisioning.';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $maxRows = max(100, (int) $this->option('max-rows'));
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subHours($hours);

        $metrics = [
            'identity_resolve_mismatch_rate' => $this->computeIdentityResolveMismatchRate(),
            'dual_write_mismatch_rate' => $this->computeDualWriteMismatchRate($windowStart, $windowEnd, $maxRows),
            'content_path_fallback_rate' => $this->computeContentPathFallbackRate(),
            'legacy_code_hit_rate' => $this->computeLegacyCodeHitRate($windowStart, $windowEnd),
            'demo_scale_hit_rate' => $this->computeDemoScaleHitRate($windowStart, $windowEnd),
        ];

        $thresholds = [
            'identity_resolve_mismatch_rate' => $this->floatEnv('FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX', 0.0),
            'dual_write_mismatch_rate' => $this->floatEnv('FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX', 0.0),
            'content_path_fallback_rate' => $this->floatEnv('FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX', 0.0),
            'legacy_code_hit_rate' => $this->floatEnv('FAP_GATE_LEGACY_CODE_HIT_RATE_MAX', 0.0),
            'demo_scale_hit_rate' => $this->floatEnv('FAP_GATE_DEMO_SCALE_HIT_RATE_MAX', 0.0),
        ];

        $strict = $this->isTruthy($this->option('strict'));
        $violations = [];
        foreach ($thresholds as $name => $max) {
            $rate = (float) ($metrics[$name]['rate'] ?? 0.0);
            if ($rate > $max) {
                $violations[] = [
                    'metric' => $name,
                    'rate' => $rate,
                    'max' => $max,
                ];
            }
        }

        $payload = [
            'ok' => true,
            'window_hours' => $hours,
            'window_start' => $windowStart->toISOString(),
            'window_end' => $windowEnd->toISOString(),
            'max_rows_per_table' => $maxRows,
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'strict' => $strict,
            'pass' => count($violations) === 0,
            'violations' => $violations,
        ];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('scale identity gate');
            foreach ($metrics as $name => $metric) {
                $this->line(sprintf(
                    '%s numerator=%d denominator=%d rate=%.4f max=%.4f',
                    $name,
                    (int) ($metric['numerator'] ?? 0),
                    (int) ($metric['denominator'] ?? 0),
                    (float) ($metric['rate'] ?? 0.0),
                    (float) ($thresholds[$name] ?? 0.0)
                ));
            }
            if ($violations !== []) {
                $this->warn('violations=' . json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($strict && $violations !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeIdentityResolveMismatchRate(): array
    {
        if (! Schema::hasTable('scale_identities')) {
            return $this->ratio(0, 0);
        }

        try {
            $checks = [];
            $identities = DB::table('scale_identities')
                ->select(['scale_uid', 'scale_code_v1', 'scale_code_v2'])
                ->where('status', 'active')
                ->get();

            foreach ($identities as $row) {
                $scaleUid = trim((string) ($row->scale_uid ?? ''));
                $codeV1 = strtoupper(trim((string) ($row->scale_code_v1 ?? '')));
                $codeV2 = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
                if ($scaleUid === '') {
                    continue;
                }
                if ($codeV1 !== '') {
                    $checks[$codeV1 . '|' . $scaleUid] = ['code' => $codeV1, 'scale_uid' => $scaleUid];
                }
                if ($codeV2 !== '') {
                    $checks[$codeV2 . '|' . $scaleUid] = ['code' => $codeV2, 'scale_uid' => $scaleUid];
                }
            }

            if (Schema::hasTable('scale_code_aliases')) {
                $aliases = DB::table('scale_code_aliases')
                    ->select(['alias_code', 'scale_uid'])
                    ->get();
                foreach ($aliases as $aliasRow) {
                    $aliasCode = strtoupper(trim((string) ($aliasRow->alias_code ?? '')));
                    $scaleUid = trim((string) ($aliasRow->scale_uid ?? ''));
                    if ($aliasCode === '' || $scaleUid === '') {
                        continue;
                    }
                    $checks[$aliasCode . '|' . $scaleUid] = ['code' => $aliasCode, 'scale_uid' => $scaleUid];
                }
            }

            $denominator = 0;
            $numerator = 0;
            foreach ($checks as $check) {
                $code = strtoupper(trim((string) ($check['code'] ?? '')));
                $expectedUid = trim((string) ($check['scale_uid'] ?? ''));
                if ($code === '' || $expectedUid === '') {
                    continue;
                }
                $denominator++;
                $resolved = $this->identityResolver->resolveByAnyCode($code);
                $isKnown = is_array($resolved) && (bool) ($resolved['is_known'] ?? false);
                $resolvedUid = $isKnown ? trim((string) ($resolved['scale_uid'] ?? '')) : '';
                if (! $isKnown || $resolvedUid === '' || $resolvedUid !== $expectedUid) {
                    $numerator++;
                }
            }

            return $this->ratio($numerator, $denominator);
        } catch (\Throwable) {
            return $this->ratio(0, 0);
        }
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeDualWriteMismatchRate(\DateTimeInterface $windowStart, \DateTimeInterface $windowEnd, int $maxRows): array
    {
        $tables = [
            ['name' => 'attempts', 'primary' => 'id'],
            ['name' => 'results', 'primary' => 'id'],
            ['name' => 'events', 'primary' => 'id'],
            ['name' => 'shares', 'primary' => 'id'],
            ['name' => 'report_snapshots', 'primary' => 'attempt_id'],
            ['name' => 'orders', 'primary' => 'id'],
            ['name' => 'payment_events', 'primary' => 'id'],
            ['name' => 'assessments', 'primary' => 'id'],
            ['name' => 'attempt_answer_sets', 'primary' => 'id'],
            ['name' => 'attempt_answer_rows', 'primary' => null],
        ];

        $denominator = 0;
        $numerator = 0;

        foreach ($tables as $tableMeta) {
            $table = (string) ($tableMeta['name'] ?? '');
            $primary = $tableMeta['primary'] ?? null;
            if ($table === '' || ! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'scale_code') || ! Schema::hasColumn($table, 'scale_code_v2')) {
                continue;
            }

            $columns = ['scale_code', 'scale_code_v2'];
            $hasCreatedAt = Schema::hasColumn($table, 'created_at');
            $hasScaleUid = Schema::hasColumn($table, 'scale_uid');
            if (is_string($primary) && $primary !== '' && Schema::hasColumn($table, $primary)) {
                $columns[] = $primary;
            } else {
                $primary = null;
            }
            if ($hasCreatedAt) {
                $columns[] = 'created_at';
            }
            if ($hasScaleUid) {
                $columns[] = 'scale_uid';
            }

            try {
                $query = DB::table($table)->select($columns)
                    ->whereNotNull('scale_code')
                    ->where('scale_code', '<>', '')
                    ->whereNotNull('scale_code_v2')
                    ->where('scale_code_v2', '<>', '');
                if ($hasCreatedAt) {
                    $query->where('created_at', '>=', $windowStart)
                        ->where('created_at', '<=', $windowEnd);
                }
                if (is_string($primary) && $primary !== '') {
                    $query->orderByDesc($primary);
                } elseif ($hasCreatedAt) {
                    $query->orderByDesc('created_at');
                }

                /** @var Collection<int,object> $rows */
                $rows = $query->limit($maxRows)->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                $legacyCode = strtoupper(trim((string) ($row->scale_code ?? '')));
                $v2Code = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
                if ($legacyCode === '' || $v2Code === '') {
                    continue;
                }

                $denominator++;
                $resolved = $this->identityResolver->resolveByAnyCode($legacyCode);
                $isKnown = is_array($resolved) && (bool) ($resolved['is_known'] ?? false);
                $expectedV2 = $isKnown ? strtoupper(trim((string) ($resolved['scale_code_v2'] ?? ''))) : '';
                $expectedUid = $isKnown ? trim((string) ($resolved['scale_uid'] ?? '')) : '';
                $currentUid = $hasScaleUid ? trim((string) ($row->scale_uid ?? '')) : '';

                $isMismatch = ! $isKnown || $expectedV2 === '' || $v2Code !== $expectedV2;
                if (! $isMismatch && $hasScaleUid && $expectedUid !== '' && $currentUid !== '' && $currentUid !== $expectedUid) {
                    $isMismatch = true;
                }

                if ($isMismatch) {
                    $numerator++;
                }
            }
        }

        return $this->ratio($numerator, $denominator);
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeContentPathFallbackRate(): array
    {
        if (! Schema::hasTable('content_path_aliases')) {
            return $this->ratio(0, 0);
        }

        try {
            $rows = DB::table('content_path_aliases')
                ->select(['scope', 'old_path', 'new_path'])
                ->where('is_active', true)
                ->get();

            $denominator = 0;
            $numerator = 0;
            foreach ($rows as $row) {
                $scope = strtolower(trim((string) ($row->scope ?? '')));
                $oldPath = trim((string) ($row->old_path ?? ''));
                $newPath = trim((string) ($row->new_path ?? ''));
                if ($scope === '' || $oldPath === '' || $newPath === '') {
                    continue;
                }
                $denominator++;

                $oldAbs = $this->absoluteContentPath($scope, $oldPath);
                $newAbs = $this->absoluteContentPath($scope, $newPath);
                if (is_dir($oldAbs) && ! is_dir($newAbs)) {
                    $numerator++;
                }
            }

            return $this->ratio($numerator, $denominator);
        } catch (\Throwable) {
            return $this->ratio(0, 0);
        }
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeLegacyCodeHitRate(\DateTimeInterface $windowStart, \DateTimeInterface $windowEnd): array
    {
        if (! Schema::hasTable('attempts') || ! Schema::hasColumn('attempts', 'scale_code')) {
            return $this->ratio(0, 0);
        }

        $legacyCodes = array_keys((array) config('scale_identity.code_map_v1_to_v2', []));
        $legacyCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $legacyCodes
        ))));
        $v2Codes = array_values(array_unique(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            array_values((array) config('scale_identity.code_map_v1_to_v2', []))
        ))));

        if ($legacyCodes === [] && $v2Codes === []) {
            return $this->ratio(0, 0);
        }

        try {
            $hasScaleCodeV2 = Schema::hasColumn('attempts', 'scale_code_v2');
            $hasCreatedAt = Schema::hasColumn('attempts', 'created_at');

            $denominatorQuery = DB::table('attempts');
            if ($hasCreatedAt) {
                $denominatorQuery->where('created_at', '>=', $windowStart)
                    ->where('created_at', '<=', $windowEnd);
            }
            $denominatorQuery->where(function ($q) use ($legacyCodes, $v2Codes, $hasScaleCodeV2): void {
                if ($legacyCodes !== []) {
                    $q->whereIn('scale_code', $legacyCodes);
                }
                if ($v2Codes !== []) {
                    $q->orWhereIn('scale_code', $v2Codes);
                    if ($hasScaleCodeV2) {
                        $q->orWhereIn('scale_code_v2', $v2Codes);
                    }
                }
            });
            $denominator = (int) $denominatorQuery->count();

            $numeratorQuery = DB::table('attempts');
            if ($hasCreatedAt) {
                $numeratorQuery->where('created_at', '>=', $windowStart)
                    ->where('created_at', '<=', $windowEnd);
            }
            if ($legacyCodes !== []) {
                $numeratorQuery->whereIn('scale_code', $legacyCodes);
            } else {
                $numeratorQuery->whereRaw('1=0');
            }
            $numerator = (int) $numeratorQuery->count();

            return $this->ratio($numerator, $denominator);
        } catch (\Throwable) {
            return $this->ratio(0, 0);
        }
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeDemoScaleHitRate(\DateTimeInterface $windowStart, \DateTimeInterface $windowEnd): array
    {
        if (! Schema::hasTable('attempts') || ! Schema::hasColumn('attempts', 'scale_code')) {
            return $this->ratio(0, 0);
        }

        try {
            $hasCreatedAt = Schema::hasColumn('attempts', 'created_at');
            $denominatorQuery = DB::table('attempts')
                ->whereNotNull('scale_code')
                ->where('scale_code', '<>', '');
            if ($hasCreatedAt) {
                $denominatorQuery->where('created_at', '>=', $windowStart)
                    ->where('created_at', '<=', $windowEnd);
            }
            $denominator = (int) $denominatorQuery->count();

            $numeratorQuery = DB::table('attempts')
                ->whereIn('scale_code', ['DEMO_ANSWERS', 'SIMPLE_SCORE_DEMO']);
            if ($hasCreatedAt) {
                $numeratorQuery->where('created_at', '>=', $windowStart)
                    ->where('created_at', '<=', $windowEnd);
            }
            $numerator = (int) $numeratorQuery->count();

            return $this->ratio($numerator, $denominator);
        } catch (\Throwable) {
            return $this->ratio(0, 0);
        }
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function ratio(int $numerator, int $denominator): array
    {
        $safeNumerator = max(0, $numerator);
        $safeDenominator = max(0, $denominator);

        return [
            'numerator' => $safeNumerator,
            'denominator' => $safeDenominator,
            'rate' => $safeDenominator > 0 ? round($safeNumerator / $safeDenominator, 4) : 0.0,
        ];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function floatEnv(string $name, float $default): float
    {
        $raw = getenv($name);
        if ($raw === false) {
            return $default;
        }
        $value = trim((string) $raw);
        if ($value === '' || ! is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }

    private function absoluteContentPath(string $scope, string $relativePath): string
    {
        $normalized = trim($relativePath, '/');
        if ($scope === 'content_packages') {
            $root = rtrim((string) config('content_packs.root', base_path('../content_packages')), DIRECTORY_SEPARATOR);
            return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        }

        return base_path($normalized);
    }
}

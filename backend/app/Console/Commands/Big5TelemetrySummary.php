<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class Big5TelemetrySummary extends Command
{
    protected $signature = 'big5:telemetry:summary
        {--hours=24 : Lookback window in hours}
        {--json=1 : Output json payload}';

    protected $description = 'Summarize BIG5 observability metrics for rollout health checks.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subHours($hours);

        $reportRows = DB::table('events')
            ->select(['event_code', 'meta_json'])
            ->whereIn('event_code', ['big5_report_composed', 'big5_report_compose_failed'])
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->get();

        $scoreRows = DB::table('events')
            ->select(['meta_json'])
            ->where('event_code', 'big5_scored')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->get();

        $webhookRows = DB::table('events')
            ->select(['meta_json'])
            ->where('event_code', 'big5_payment_webhook_processed')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->get();

        $attemptRows = DB::table('attempts')
            ->select(['locale'])
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $windowEnd)
            ->get();

        $report = $this->computeReportFailureRate($reportRows);
        $norms = $this->computeNormMetrics($scoreRows);
        $payment = $this->computePaymentMetrics($webhookRows);
        $locale = $this->computeLocaleMismatchRate($attemptRows);

        $payload = [
            'ok' => true,
            'window_hours' => $hours,
            'window_start' => $windowStart->toISOString(),
            'window_end' => $windowEnd->toISOString(),
            'metrics' => [
                'big5.report.failure_rate' => $report,
                'big5.norms.fallback_rate' => $norms['fallback'],
                'big5.norms.missing_rate' => $norms['missing'],
                'big5.payment.unlock_success_rate' => $payment['unlock_success'],
                'big5.payment.webhook_failed_rate' => $payment['webhook_failed'],
                'big5.questions.locale_mismatch_rate' => $locale,
            ],
        ];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('BIG5 telemetry summary');
            foreach ($payload['metrics'] as $name => $metric) {
                $this->line(sprintf(
                    '%s numerator=%d denominator=%d rate=%.4f',
                    $name,
                    (int) ($metric['numerator'] ?? 0),
                    (int) ($metric['denominator'] ?? 0),
                    (float) ($metric['rate'] ?? 0.0)
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param Collection<int,object> $rows
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeReportFailureRate(Collection $rows): array
    {
        $total = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $code = strtolower(trim((string) ($row->event_code ?? '')));
            $meta = $this->decodeMeta($row->meta_json ?? null);
            if ($code === '') {
                continue;
            }
            $total++;

            if ($code === 'big5_report_compose_failed') {
                $failed++;
                continue;
            }

            $sectionsCount = (int) ($meta['sections_count'] ?? 0);
            if ($sectionsCount <= 0) {
                $failed++;
            }
        }

        return $this->ratio($failed, $total);
    }

    /**
     * @param Collection<int,object> $rows
     * @return array{
     *   fallback:array{numerator:int,denominator:int,rate:float},
     *   missing:array{numerator:int,denominator:int,rate:float}
     * }
     */
    private function computeNormMetrics(Collection $rows): array
    {
        $total = 0;
        $fallback = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row->meta_json ?? null);
            $status = strtoupper(trim((string) ($meta['norms_status'] ?? '')));
            $group = strtolower(trim((string) ($meta['norm_group_id'] ?? '')));

            if ($status === '') {
                continue;
            }

            $total++;
            if ($status === 'MISSING') {
                $missing++;
            }

            $isCalibrated = $status === 'CALIBRATED';
            $isProdGroup = $group !== '' && str_contains($group, '_prod_');
            if (!$isCalibrated || !$isProdGroup) {
                $fallback++;
            }
        }

        return [
            'fallback' => $this->ratio($fallback, $total),
            'missing' => $this->ratio($missing, $total),
        ];
    }

    /**
     * @param Collection<int,object> $rows
     * @return array{
     *   unlock_success:array{numerator:int,denominator:int,rate:float},
     *   webhook_failed:array{numerator:int,denominator:int,rate:float}
     * }
     */
    private function computePaymentMetrics(Collection $rows): array
    {
        $total = 0;
        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $meta = $this->decodeMeta($row->meta_json ?? null);
            $status = strtolower(trim((string) ($meta['webhook_status'] ?? '')));
            if ($status === '') {
                continue;
            }

            $total++;
            if (in_array($status, ['processed', 'duplicate'], true)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'unlock_success' => $this->ratio($success, $total),
            'webhook_failed' => $this->ratio($failed, $total),
        ];
    }

    /**
     * @param Collection<int,object> $rows
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function computeLocaleMismatchRate(Collection $rows): array
    {
        $total = 0;
        $mismatch = 0;
        $allowed = ['zh-cn', 'en'];

        foreach ($rows as $row) {
            $locale = strtolower(trim((string) ($row->locale ?? '')));
            if ($locale === '') {
                continue;
            }

            $total++;
            if (!in_array($locale, $allowed, true)) {
                $mismatch++;
            }
        }

        return $this->ratio($mismatch, $total);
    }

    /**
     * @return array{numerator:int,denominator:int,rate:float}
     */
    private function ratio(int $numerator, int $denominator): array
    {
        $rate = $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;

        return [
            'numerator' => max(0, $numerator),
            'denominator' => max(0, $denominator),
            'rate' => $rate,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}


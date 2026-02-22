<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveTelemetrySummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_telemetry_summary_outputs_expected_metric_ratios(): void
    {
        $now = now();

        DB::table('attempts')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'anon_id' => 'anon_metric_zh',
                'scale_code' => 'BIG5_OCEAN',
                'scale_version' => 'v0.3',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'question_count' => 120,
                'client_platform' => 'test',
                'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'anon_id' => 'anon_metric_en',
                'scale_code' => 'BIG5_OCEAN',
                'scale_version' => 'v0.3',
                'region' => 'GLOBAL',
                'locale' => 'en',
                'question_count' => 120,
                'client_platform' => 'test',
                'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => 0,
                'anon_id' => 'anon_metric_bad_locale',
                'scale_code' => 'BIG5_OCEAN',
                'scale_version' => 'v0.3',
                'region' => 'CN_MAINLAND',
                'locale' => 'fr-FR',
                'question_count' => 120,
                'client_platform' => 'test',
                'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->insertEvent('big5_report_composed', ['sections_count' => 5], $now);
        $this->insertEvent('big5_report_composed', ['sections_count' => 0], $now);

        $this->insertEvent('big5_scored', [
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_prod_all_18-60',
        ], $now);
        $this->insertEvent('big5_scored', [
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_xu_all_18-60',
        ], $now);
        $this->insertEvent('big5_scored', [
            'norms_status' => 'MISSING',
            'norm_group_id' => '',
        ], $now);

        $this->insertEvent('big5_payment_webhook_processed', ['webhook_status' => 'processed'], $now);
        $this->insertEvent('big5_payment_webhook_processed', ['webhook_status' => 'duplicate'], $now);
        $this->insertEvent('big5_payment_webhook_processed', ['webhook_status' => 'sku_not_found'], $now);

        $exitCode = Artisan::call('big5:telemetry:summary', ['--hours' => 48, '--json' => 1]);
        $this->assertSame(0, $exitCode);
        $payload = $this->decodeLastJsonLine(Artisan::output());
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame(48, (int) ($payload['window_hours'] ?? 0));

        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
        $this->assertSame(0.5, (float) ($metrics['big5.report.failure_rate']['rate'] ?? -1));
        $this->assertSame(0.6667, (float) ($metrics['big5.norms.fallback_rate']['rate'] ?? -1));
        $this->assertSame(0.3333, (float) ($metrics['big5.norms.missing_rate']['rate'] ?? -1));
        $this->assertSame(0.6667, (float) ($metrics['big5.payment.unlock_success_rate']['rate'] ?? -1));
        $this->assertSame(0.3333, (float) ($metrics['big5.payment.webhook_failed_rate']['rate'] ?? -1));
        $this->assertSame(0.3333, (float) ($metrics['big5.questions.locale_mismatch_rate']['rate'] ?? -1));
    }

    public function test_big5_telemetry_summary_handles_empty_window(): void
    {
        $exitCode = Artisan::call('big5:telemetry:summary', ['--hours' => 1, '--json' => 1]);
        $this->assertSame(0, $exitCode);
        $payload = $this->decodeLastJsonLine(Artisan::output());
        $this->assertIsArray($payload);
        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];

        $this->assertSame(0.0, (float) ($metrics['big5.report.failure_rate']['rate'] ?? -1));
        $this->assertSame(0.0, (float) ($metrics['big5.norms.fallback_rate']['rate'] ?? -1));
        $this->assertSame(0.0, (float) ($metrics['big5.norms.missing_rate']['rate'] ?? -1));
        $this->assertSame(0.0, (float) ($metrics['big5.payment.unlock_success_rate']['rate'] ?? -1));
        $this->assertSame(0.0, (float) ($metrics['big5.payment.webhook_failed_rate']['rate'] ?? -1));
        $this->assertSame(0.0, (float) ($metrics['big5.questions.locale_mismatch_rate']['rate'] ?? -1));
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function insertEvent(string $eventCode, array $meta, mixed $timestamp): void
    {
        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => null,
            'session_id' => null,
            'request_id' => null,
            'attempt_id' => null,
            'channel' => 'test',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'pack_semver' => 'v1',
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeLastJsonLine(string $output): ?array
    {
        $lines = preg_split('/\R/', trim($output));
        if (!is_array($lines)) {
            return null;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) ($lines[$i] ?? ''));
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}

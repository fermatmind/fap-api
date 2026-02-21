<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_start_emits_attempt_started_telemetry(): void
    {
        (new ScaleRegistrySeeder())->run();

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => 'anon_big5_telemetry_start',
        ]);

        $response->assertStatus(200);

        $event = DB::table('events')
            ->where('event_code', 'big5_attempt_started')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($event);
        $meta = $this->decodeMeta($event->meta_json ?? null);
        $this->assertSame('BIG5_OCEAN', (string) ($meta['scale_code'] ?? ''));
        $this->assertSame('zh-CN', (string) ($meta['locale'] ?? ''));
        $this->assertSame('CN_MAINLAND', (string) ($meta['region'] ?? ''));
        $this->assertTrue((bool) ($meta['locked'] ?? false));
    }

    public function test_big5_submit_emits_scored_submit_and_report_telemetry(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_big5_telemetry_submit';
        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $token = $this->issueAnonToken($anonId);
        $answers = [];
        for ($i = 1; $i <= 120; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => '3',
            ];
        }

        $submit = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 240000,
        ]);

        $submit->assertStatus(200);

        $this->assertGreaterThan(0, DB::table('events')->where('event_code', 'big5_scored')->count());
        $this->assertGreaterThan(0, DB::table('events')->where('event_code', 'big5_attempt_submitted')->count());
        $this->assertGreaterThan(0, DB::table('events')->where('event_code', 'big5_report_composed')->count());

        $reportEvent = DB::table('events')
            ->where('event_code', 'big5_report_composed')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($reportEvent);
        $reportMeta = $this->decodeMeta($reportEvent->meta_json ?? null);
        $this->assertSame('free', (string) ($reportMeta['variant'] ?? ''));
        $this->assertTrue((bool) ($reportMeta['locked'] ?? false));

        $scoredEvent = DB::table('events')
            ->where('event_code', 'big5_scored')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($scoredEvent);
        $scoredMeta = $this->decodeMeta($scoredEvent->meta_json ?? null);
        $this->assertNotSame('', (string) ($scoredMeta['norms_status'] ?? ''));
        $this->assertNotSame('', (string) ($scoredMeta['quality_level'] ?? ''));
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

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}

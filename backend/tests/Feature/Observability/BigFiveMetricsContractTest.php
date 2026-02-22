<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveMetricsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_events_include_pack_and_norms_contract_keys(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $anonId = 'anon_big5_metrics_contract';
        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'anon_id' => $anonId,
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $attemptId);

        $answers = [];
        for ($i = 1; $i <= 120; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => '3',
            ];
        }

        $token = $this->issueAnonToken($anonId);
        $submit = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 220000,
        ]);
        $submit->assertStatus(200);

        $this->assertEventHasContractKeys('big5_attempt_started', false);
        $this->assertEventHasContractKeys('big5_scored', true);
        $this->assertEventHasContractKeys('big5_attempt_submitted', true);
        $this->assertEventHasContractKeys('big5_report_composed', true);
    }

    private function assertEventHasContractKeys(string $eventCode, bool $expectNormsVersion): void
    {
        $event = DB::table('events')->where('event_code', $eventCode)->latest('created_at')->first();
        $this->assertNotNull($event, 'missing event: '.$eventCode);

        $meta = $this->decodeMeta($event->meta_json ?? null);
        foreach (['scale_code', 'pack_version', 'manifest_hash', 'norms_version'] as $key) {
            $this->assertArrayHasKey($key, $meta, $eventCode.' missing key '.$key);
        }

        $this->assertSame('BIG5_OCEAN', (string) ($meta['scale_code'] ?? ''));
        $this->assertNotSame('', (string) ($meta['pack_version'] ?? ''));

        if ($expectNormsVersion) {
            $this->assertNotSame(
                '',
                (string) ($meta['norms_version'] ?? ''),
                $eventCode.' has empty norms_version'
            );
        }
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
        $token = 'fm_'.(string) Str::uuid();

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

<?php

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExperimentExposureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_and_report_events_include_standardized_exposure_contract(): void
    {
        $this->seedScales();

        $anonId = 'fer2_exposure_contract_anon';
        $anonToken = $this->issueAnonToken($anonId);
        $attemptId = $this->createMbtiAttemptWithResult($anonId);

        $boot = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->getJson('/api/v0.3/boot');
        $boot->assertStatus(200);
        $variant = trim((string) $boot->json('experiments.PR23_STICKY_BUCKET'));
        $this->assertNotSame('', $variant);

        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result")->assertStatus(200);

        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer ' . $anonToken,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report")->assertStatus(200);

        foreach (['result_view', 'report_view'] as $eventCode) {
            $event = $this->findLatestEventByAttempt($eventCode, $attemptId);
            $this->assertNotNull($event, 'missing event row: ' . $eventCode);

            $experiments = json_decode((string) ($event->experiments_json ?? '{}'), true) ?: [];
            $this->assertSame($variant, (string) ($experiments['PR23_STICKY_BUCKET'] ?? ''));

            $contract = $experiments['__exposure_contract__'][0] ?? null;
            $this->assertIsArray($contract);
            $this->assertSame('PR23_STICKY_BUCKET', (string) ($contract['experiment_key'] ?? ''));
            $this->assertSame($variant, (string) ($contract['variant'] ?? ''));
            $this->assertNotSame('', trim((string) ($contract['version'] ?? '')));
            $this->assertNotSame('', trim((string) ($contract['stage'] ?? '')));
            $this->assertNotSame('', trim((string) ($contract['assigned_at'] ?? '')));

            $meta = json_decode((string) ($event->meta_json ?? '{}'), true) ?: [];
            $this->assertSame('PR23_STICKY_BUCKET', (string) ($meta['experiment_key'] ?? ''));
            $this->assertSame($variant, (string) ($meta['variant'] ?? ''));
            $this->assertNotSame('', trim((string) ($meta['version'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['stage'] ?? '')));
            $this->assertNotSame('', trim((string) ($meta['assigned_at'] ?? '')));
            $this->assertIsArray($meta['experiment_exposure'] ?? null);
            $this->assertSame(
                'PR23_STICKY_BUCKET',
                (string) (($meta['experiment_exposure'][0]['experiment_key'] ?? ''))
            );
        }
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
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

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function findLatestEventByAttempt(string $eventCode, string $attemptId): ?object
    {
        $query = DB::table('events')
            ->where('event_code', $eventCode)
            ->where(function ($inner) use ($attemptId): void {
                $inner->where('attempt_id', $attemptId);

                $driver = DB::connection()->getDriverName();
                if ($driver === 'mysql') {
                    $inner->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.attempt_id')) = ?",
                        [$attemptId]
                    );

                    return;
                }

                if ($driver === 'sqlite') {
                    $inner->orWhereRaw(
                        "json_extract(meta_json, '$.attempt_id') = ?",
                        [$attemptId]
                    );

                    return;
                }

                $inner->orWhereRaw('meta_json like ?', ['%"attempt_id":"' . $attemptId . '"%']);
            });

        return $query->orderByDesc('occurred_at')->first();
    }
}

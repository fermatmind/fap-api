<?php

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Models\Attempt;
use App\Models\Result;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MbtiResultTelemetryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_result_and_report_events_include_personalization_meta(): void
    {
        $this->seedScales();
        Config::set('fap_experiments.experiments', []);

        $anonId = 'mbti_phase3a_anon';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);

        $resultResponse = $this->invokeController('result', $attemptId, $anonId);
        $this->assertSame(200, $resultResponse->getStatusCode());

        $reportResponse = $this->invokeController('report', $attemptId, $anonId);
        $this->assertSame(200, $reportResponse->getStatusCode());

        $eventMeta = [];
        foreach (['result_view', 'report_view'] as $eventCode) {
            $event = $this->findLatestEventByAttempt($eventCode, $attemptId);
            $this->assertNotNull($event, 'missing event row: ' . $eventCode);

            $meta = json_decode((string) ($event->meta_json ?? '{}'), true) ?: [];
            $eventMeta[$eventCode] = $meta;
            $this->assertSame('INTJ-A', (string) ($meta['type_code'] ?? ''));
            $this->assertSame('A', (string) ($meta['identity'] ?? ''));
            $this->assertSame('report_phase4a_contract', (string) ($meta['engine_version'] ?? ''));
            $this->assertSame('mbti.personalization.phase4a.v1', (string) ($meta['schema_version'] ?? ''));
            $this->assertSame('phase4a.v1', (string) ($meta['dynamic_sections_version'] ?? ''));
            $this->assertIsArray($meta['axis_bands'] ?? null);
            $this->assertSame('boundary', (string) (($meta['axis_bands']['EI'] ?? '')));
            $this->assertSame('boundary', (string) (($meta['axis_bands']['AT'] ?? '')));
            $this->assertIsArray($meta['boundary_flags'] ?? null);
            $this->assertTrue((bool) (($meta['boundary_flags']['EI'] ?? false)));
            $this->assertTrue((bool) (($meta['boundary_flags']['AT'] ?? false)));
            $this->assertIsArray($meta['variant_keys'] ?? null);
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['overview'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['traits.decision_style'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['growth.stress_recovery'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['variant_keys']['relationships.communication_style'] ?? ''))));
            $this->assertIsArray($meta['scene_fingerprint'] ?? null);
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['work'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['decision'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['stress_recovery'] ?? ''))));
            $this->assertNotSame('', trim((string) (($meta['scene_fingerprint']['communication'] ?? ''))));
        }

        $this->assertSame($eventMeta['report_view']['variant_keys'] ?? null, $eventMeta['result_view']['variant_keys'] ?? null);
        $this->assertSame($eventMeta['report_view']['scene_fingerprint'] ?? null, $eventMeta['result_view']['scene_fingerprint'] ?? null);
        $this->assertSame($eventMeta['report_view']['axis_bands'] ?? null, $eventMeta['result_view']['axis_bands'] ?? null);
        $this->assertSame($eventMeta['report_view']['boundary_flags'] ?? null, $eventMeta['result_view']['boundary_flags'] ?? null);
        $this->assertSame($eventMeta['report_view']['schema_version'] ?? null, $eventMeta['result_view']['schema_version'] ?? null);
        $this->assertSame($eventMeta['report_view']['dynamic_sections_version'] ?? null, $eventMeta['result_view']['dynamic_sections_version'] ?? null);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
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
            'report_engine_version' => 'report_phase4a_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function invokeController(string $method, string $attemptId, string $anonId): \Illuminate\Http\JsonResponse
    {
        $path = "/api/v0.3/attempts/{$attemptId}/" . ($method === 'report' ? 'report' : 'result');
        $request = Request::create($path, 'GET');
        $request->headers->set('X-Anon-Id', $anonId);
        $request->attributes->set('anon_id', $anonId);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::KIND_PUBLIC);

        $this->app->instance('request', $request);
        app(OrgContext::class)->set(0, null, 'public', $anonId, OrgContext::KIND_PUBLIC);

        /** @var AttemptReadController $controller */
        $controller = app(AttemptReadController::class);

        return $controller->{$method}($request, $attemptId);
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

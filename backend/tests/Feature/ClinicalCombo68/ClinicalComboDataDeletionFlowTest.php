<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptDataLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClinicalComboDataDeletionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinical_attempt_purge_revokes_access_and_removes_result_payloads(): void
    {
        [$attemptId, $token, $anonId] = $this->seedAttemptWithResult();

        /** @var AttemptDataLifecycleService $service */
        $service = app(AttemptDataLifecycleService::class);
        $purged = $service->purgeAttempt($attemptId, 0, [
            'reason' => 'user_request',
            'scale_code' => 'CLINICAL_COMBO_68',
        ]);

        $this->assertTrue((bool) ($purged['ok'] ?? false));
        $this->assertSame(0, DB::table('results')->where('attempt_id', $attemptId)->count());

        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/result')->assertStatus(404);

        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/report')->assertStatus(404);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function seedAttemptWithResult(): array
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_cc68_delete';
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

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 68,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => [],
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'CLINICAL_COMBO_68',
                'quality' => ['level' => 'A', 'crisis_alert' => false],
                'scores' => [],
            ],
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        DB::table('attempt_answer_sets')->insert([
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'CLINICAL_COMBO_68',
            'pack_id' => 'CLINICAL_COMBO_68',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1.0_2026',
            'answers_json' => null,
            'answers_hash' => hash('sha256', 'stub'),
            'question_count' => 68,
            'duration_ms' => 1000,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);

        return [$attemptId, $token, $anonId];
    }
}


<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Attempts\AttemptDataLifecycleService;
use App\Services\Storage\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptDataLifecycleArtifactAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_attempt_records_report_and_pdf_residuals_without_deleting_artifacts(): void
    {
        Storage::fake('local');

        $orgId = 808;
        $attemptId = (string) Str::uuid();
        $manifestHash = 'artifact_hash_v1';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => 'anon_artifact_audit',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => [
                'stage' => 'seed',
                'meta' => [
                    'pack_release_manifest_hash' => $manifestHash,
                ],
            ],
            'started_at' => now()->subMinutes(3),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
            ],
            'content_package_version' => 'result-v1',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        /** @var ArtifactStore $artifactStore */
        $artifactStore = app(ArtifactStore::class);
        $reportPath = $artifactStore->reportCanonicalPath('MBTI', $attemptId);
        $pdfPath = $artifactStore->pdfCanonicalPath('MBTI', $attemptId, $manifestHash, 'free');
        Storage::disk('local')->put($reportPath, '{"artifact":"report"}');
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 artifact');

        /** @var AttemptDataLifecycleService $service */
        $service = app(AttemptDataLifecycleService::class);
        $result = $service->purgeAttempt($attemptId, $orgId, [
            'reason' => 'user_request',
            'scale_code' => 'MBTI',
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $audit = (array) ($result['artifact_residual_audit'] ?? []);
        $this->assertSame('residual_report_json_and_pdf_found', (string) ($audit['state'] ?? ''));
        $this->assertSame('remote_state_unknown', (string) ($audit['remote_state'] ?? ''));
        $this->assertSame($reportPath, (string) data_get($audit, 'report.path'));
        $this->assertTrue((bool) data_get($audit, 'report.exists'));
        $this->assertSame($pdfPath, (string) data_get($audit, 'pdf.paths.free'));
        $this->assertTrue((bool) data_get($audit, 'pdf.exists'));

        $requestRow = DB::table('data_lifecycle_requests')
            ->where('request_type', 'attempt_purge')
            ->where('subject_ref', $attemptId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($requestRow);

        $requestResult = json_decode((string) ($requestRow->result_json ?? '{}'), true);
        $this->assertIsArray($requestResult);
        $this->assertSame('residual_report_json_and_pdf_found', (string) data_get($requestResult, 'artifact_residual_audit.state'));
        $this->assertSame('remote_state_unknown', (string) data_get($requestResult, 'artifact_residual_audit.remote_state'));
        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
    }
}

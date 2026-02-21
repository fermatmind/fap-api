<?php

namespace Tests\Feature\V0_3;

use App\Jobs\GenerateReportJob;
use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use App\Services\Content\ContentPacksIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportDirVersionTruthTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_attempt_dir_version_as_single_truth(): void
    {
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3');
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');

        $attemptId = (string) Str::uuid();
        $packId = 'MBTI.cn-mainland.zh-CN.v0.3';
        $dirVersion = 'MBTI-CN-v0.3';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_single_truth',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
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
            'content_package_version' => null,
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
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $job = new GenerateReportJob($attemptId);
        $job->handle(app(\App\Services\Report\ReportComposer::class));

        $reportJob = ReportJob::where('attempt_id', $attemptId)->first();
        $this->assertNotNull($reportJob);

        $report = is_array($reportJob?->report_json) ? $reportJob->report_json : [];
        $this->assertSame($dirVersion, $report['versions']['legacy_dir'] ?? null);
        $this->assertSame($dirVersion, $report['versions']['dir_version'] ?? null);
        $this->assertSame('v0.3', $report['versions']['content_package_version'] ?? null);

        $found = app(ContentPacksIndex::class)->find($packId, $dirVersion);
        $this->assertTrue((bool) ($found['ok'] ?? false));

        $manifestPath = (string) ($found['item']['manifest_path'] ?? '');
        $baseDir = $manifestPath !== '' ? dirname($manifestPath) : '';
        $versionPath = $baseDir !== '' ? $baseDir . DIRECTORY_SEPARATOR . 'version.json' : '';
        $this->assertNotSame('', $versionPath);
        $this->assertTrue(File::isFile($versionPath));

        $versionJson = json_decode(File::get($versionPath), true);
        $this->assertSame($dirVersion, $versionJson['dir_version'] ?? null);
        $this->assertSame(
            $versionJson['content_package_version'] ?? null,
            $report['versions']['content_package_version'] ?? null
        );
    }
}

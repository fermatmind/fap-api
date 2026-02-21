<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportComposer;
use App\Services\Report\ReportAccess;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContentPackCoverageMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const MBTI_TYPES = [
        'INTJ-A', 'INTP-A', 'ENTJ-A', 'ENTP-A',
        'INFJ-A', 'INFP-A', 'ENFJ-A', 'ENFP-A',
        'ISTJ-A', 'ISFJ-A', 'ESTJ-A', 'ESFJ-A',
        'ISTP-A', 'ISFP-A', 'ESTP-A', 'ESFP-A',
    ];

    public function test_mbti_coverage_matrix_for_free_and_full_variants(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $attempt = Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => 'anon_coverage',
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
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        $result = Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => (string) $attempt->id,
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
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        /** @var ReportComposer $composer */
        $composer = app(ReportComposer::class);

        foreach (self::MBTI_TYPES as $typeCode) {
            $result->type_code = $typeCode;
            $result->result_json = ['type_code' => $typeCode];
            $result->save();

            $this->assertCoverage($composer->composeVariant(
                $attempt,
                ReportAccess::VARIANT_FREE,
                [
                    'org_id' => 0,
                    'variant' => ReportAccess::VARIANT_FREE,
                    'report_access_level' => ReportAccess::REPORT_ACCESS_FREE,
                    'modules_allowed' => [ReportAccess::MODULE_CORE_FREE],
                    'modules_preview' => [ReportAccess::MODULE_CORE_FULL, ReportAccess::MODULE_CAREER, ReportAccess::MODULE_RELATIONSHIPS],
                    'persist' => false,
                ],
                $result
            ), $typeCode, ReportAccess::VARIANT_FREE);

            $this->assertCoverage($composer->composeVariant(
                $attempt,
                ReportAccess::VARIANT_FULL,
                [
                    'org_id' => 0,
                    'variant' => ReportAccess::VARIANT_FULL,
                    'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
                    'modules_allowed' => [
                        ReportAccess::MODULE_CORE_FREE,
                        ReportAccess::MODULE_CORE_FULL,
                        ReportAccess::MODULE_CAREER,
                        ReportAccess::MODULE_RELATIONSHIPS,
                    ],
                    'modules_preview' => [ReportAccess::MODULE_CORE_FULL, ReportAccess::MODULE_CAREER, ReportAccess::MODULE_RELATIONSHIPS],
                    'persist' => false,
                ],
                $result
            ), $typeCode, ReportAccess::VARIANT_FULL);
        }
    }

    private function assertCoverage(array $payload, string $typeCode, string $variant): void
    {
        $this->assertTrue((bool) ($payload['ok'] ?? false), "compose failed for {$typeCode}/{$variant}");

        $report = $payload['report'] ?? null;
        $this->assertIsArray($report, "missing report for {$typeCode}/{$variant}");

        $sections = $report['sections'] ?? null;
        $this->assertIsArray($sections, "missing sections for {$typeCode}/{$variant}");

        foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $sections, "missing section {$sectionKey} for {$typeCode}/{$variant}");
            $cards = $sections[$sectionKey]['cards'] ?? null;
            $this->assertIsArray($cards, "missing cards list for {$typeCode}/{$variant}/{$sectionKey}");
            $this->assertGreaterThanOrEqual(1, count($cards), "empty section {$sectionKey} for {$typeCode}/{$variant}");
        }

        $seen = [];
        foreach ($sections as $sectionKey => $sectionNode) {
            foreach ((array) ($sectionNode['cards'] ?? []) as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $id = (string) ($card['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $this->assertArrayNotHasKey($id, $seen, "duplicate card id {$id} for {$typeCode}/{$variant}");
                $seen[$id] = true;

                $this->assertNotSame('', trim((string) ($card['title'] ?? '')), "empty title for {$typeCode}/{$variant}/{$id}");
                $this->assertNotSame('', trim((string) ($card['desc'] ?? '')), "empty desc for {$typeCode}/{$variant}/{$id}");
            }
        }
    }
}

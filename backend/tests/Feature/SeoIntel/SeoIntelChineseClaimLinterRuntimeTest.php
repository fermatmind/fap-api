<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\ClaimLint\ChineseClaimLinter;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class SeoIntelChineseClaimLinterRuntimeTest extends TestCase
{
    #[Test]
    public function service_blocks_forbidden_career_and_salary_claims_without_rewrite(): void
    {
        $report = (new ChineseClaimLinter)->lint([
            [
                'id' => 'career',
                'surface' => 'career_recommendation',
                'text' => '这套 AI 职业规划可以提供精准职业推荐，直接告诉你最适合职业。',
                'is_public' => true,
                'is_indexable' => true,
            ],
            [
                'id' => 'salary',
                'surface' => 'research_report',
                'text' => 'MBTI决定收入，并可给出薪资保证。',
                'is_public' => true,
                'is_indexable' => true,
            ],
        ]);

        $this->assertSame('chinese_claim_linter', $report['runtime']);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('blocked', $report['lint_state']);
        $this->assertSame('P0', $report['severity']);
        $this->assertContains('精准职业推荐', $report['blocked_phrases']);
        $this->assertContains('最适合职业', $report['blocked_phrases']);
        $this->assertContains('MBTI决定收入', $report['blocked_phrases']);
        $this->assertContains('薪资保证', $report['blocked_phrases']);
        $this->assertFalse($report['auto_rewrite_attempted']);
        $this->assertFalse($report['cms_mutation_attempted']);
        $this->assertFalse($report['production_scan_attempted']);
    }

    #[Test]
    public function service_allows_bounded_non_diagnostic_and_snapshot_phrasing(): void
    {
        $report = (new ChineseClaimLinter)->lint([
            [
                'id' => 'career_reference',
                'surface' => 'career_guide',
                'text' => '本页仅提供职业方向参考，结合兴趣信号和工作方式倾向作为探索建议。',
            ],
            [
                'id' => 'non_diagnostic',
                'surface' => 'test_detail',
                'text' => '结果仅供参考，属于自评筛查，非诊断。',
            ],
            [
                'id' => 'snapshot',
                'surface' => 'career_graph',
                'text' => 'This is snapshot-based support and evidence-backed explanation for exploration only.',
            ],
        ]);

        $this->assertSame('success', $report['status']);
        $this->assertSame('safe', $report['lint_state']);
        $this->assertSame('P3', $report['severity']);
        $this->assertTrue($report['bounded_context_detected']);
        $this->assertSame([], $report['blocked_phrases']);
        $this->assertContains('职业方向参考', $report['allowed_bounded_phrases']);
        $this->assertContains('snapshot-based support', $report['allowed_bounded_phrases']);
        $this->assertContains('evidence-backed explanation', $report['allowed_bounded_phrases']);
    }

    #[Test]
    public function service_marks_model_index_prediction_language_for_review(): void
    {
        $report = (new ChineseClaimLinter)->lint([
            [
                'id' => 'model_index',
                'surface' => 'research_report',
                'text' => '报告使用模型化指数展示聚合层面的薪资与离职方向性趋势，不作为个人预测。',
                'is_public' => false,
                'is_indexable' => false,
            ],
        ]);

        $this->assertSame('success', $report['status']);
        $this->assertSame('needs_review', $report['lint_state']);
        $this->assertSame('P2', $report['severity']);
        $this->assertContains('预测', $report['needs_review_phrases']);
        $this->assertContains('模型化指数', $report['allowed_bounded_phrases']);
        $this->assertContains('聚合层面', $report['allowed_bounded_phrases']);
        $this->assertContains('方向性趋势', $report['allowed_bounded_phrases']);
    }

    #[Test]
    public function fixture_command_emits_json_and_never_scans_production_or_mutates_content(): void
    {
        $exitCode = Artisan::call('seo-intel:claim-lint', [
            '--fixture' => true,
            '--json' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('chinese_claim_linter', $payload['runtime']);
        $this->assertTrue($payload['fixture_mode']);
        $this->assertSame('blocked', $payload['lint_state']);
        $this->assertSame('P0', $payload['severity']);
        $this->assertGreaterThanOrEqual(8, $payload['candidate_count']);
        $this->assertFalse($payload['auto_rewrite_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['production_scan_attempted']);
        $this->assertFalse($payload['fap_web_modification_attempted']);
        $this->assertFalse($payload['search_channel_enqueue_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertFalse($payload['seo_intel_write_attempted']);
        $this->assertFalse($payload['scheduler_enabled']);
    }

    #[Test]
    public function command_requires_fixture_and_json_and_exposes_no_production_write_options(): void
    {
        $exitCode = Artisan::call('seo-intel:claim-lint');

        $this->assertSame(Command::FAILURE, $exitCode);
        $output = Artisan::output();

        $this->assertStringContainsString('status=blocked', $output);
        $this->assertStringContainsString('auto_rewrite_attempted=0', $output);
        $this->assertStringContainsString('cms_mutation_attempted=0', $output);
        $this->assertStringContainsString('production_scan_attempted=0', $output);

        Artisan::call('seo-intel:claim-lint --help');
        $help = Artisan::output();

        foreach ([
            '--rewrite',
            '--publish',
            '--write',
            '--scan-production',
            '--submit',
            '--scheduler',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }

        $this->assertStringContainsString('--fixture', $help);
        $this->assertStringContainsString('--json', $help);
    }

    #[Test]
    public function fixture_coverage_matches_required_cases(): void
    {
        $fixture = $this->fixture();
        $ids = array_column($fixture['cases'] ?? [], 'id');

        foreach ([
            'forbidden_career_recommendation_claim',
            'bounded_career_direction_reference',
            'mbti_salary_guarantee_claim',
            'model_index_salary_turnover_bounded',
            'clinical_diagnosis_claim',
            'non_diagnostic_safe_phrasing',
            'big_five_riasec_overclaim',
            'snapshot_based_support_phrasing',
        ] as $id) {
            $this->assertContains($id, $ids);
        }

        foreach (($fixture['cases'] ?? []) as $case) {
            $result = (new ChineseClaimLinter)->lintCandidate($case);

            $this->assertSame($case['expected_lint_state'], $result['lint_state'], (string) $case['id']);
            $this->assertSame($case['expected_severity'], $result['severity'], (string) $case['id']);
            $this->assertFalse($result['auto_rewrite_attempted']);
            $this->assertFalse($result['cms_mutation_attempted']);
            $this->assertFalse($result['production_scan_attempted']);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_runtime_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/chinese-claim-linter-runtime.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->assertSame('chinese-claim-linter-runtime.v1', $artifact['version'] ?? null);
        $this->assertSame('CLAIM-LINT-01B', $artifact['task'] ?? null);
        $this->assertSame('CONTENT-OPS-CLAIM-LINK-OPS-READINESS', $artifact['next_task'] ?? null);
        $this->assertSame('App\\Services\\SeoIntel\\ClaimLint\\ChineseClaimLinter', $artifact['service'] ?? null);
        $this->assertFalse($artifact['safety_flags']['auto_rewrite_enabled'] ?? true);
        $this->assertFalse($artifact['safety_flags']['cms_mutation_enabled'] ?? true);
        $this->assertFalse($artifact['safety_flags']['production_scan_enabled'] ?? true);

        foreach ([
            'does not auto-rewrite content',
            'does not auto-publish',
            'does not mutate cms content',
            'does not scan production content without explicit scope',
            'does not modify fap-web',
            'does not write `seo_intel`',
            'does not enqueue search channel rows',
            'does not submit urls',
            'next task: `content-ops-claim-link-ops-readiness`',
            '"next_task":"content-ops-claim-link-ops-readiness"',
        ] as $required) {
            $this->assertStringContainsString($required, $doc."\n".$artifactJson);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function artisanJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/SeoIntel/claim_lint/chinese_claim_lint_cases.v1.json')), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('docs/seo/generated/chinese-claim-linter-runtime.v1.json')), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}

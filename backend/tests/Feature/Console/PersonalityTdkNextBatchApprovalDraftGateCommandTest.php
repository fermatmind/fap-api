<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonalityTdkNextBatchApprovalDraftGateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_plans_the_three_next_batch_targets_without_writes(): void
    {
        $countsBefore = $this->rowCounts();
        $recommendations = $this->writeJson('recommendations', $this->recommendationsFixture());
        $qa = $this->writeJson('qa', $this->qaFixture());

        $exitCode = Artisan::call('personality:tdk-next-batch-approval-draft-gate', [
            '--recommendations' => $recommendations,
            '--qa' => $qa,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('planned', $summary['status'] ?? null);
        $this->assertSame(3, $summary['candidate_count'] ?? null);
        $this->assertTrue((bool) ($summary['approval_queue_dry_run_ready'] ?? false));
        $this->assertTrue((bool) ($summary['cms_projection_draft_dry_run_ready'] ?? false));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.approval_queue_write', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_draft_write', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('personality-tdk-next-batch-approval-draft-gate.v1', $artifact['schema_version'] ?? null);
        $this->assertSame(['/zh/personality/intp-a', '/zh/personality/esfp-a', '/en/personality/enfj-a'], array_column($artifact['targets'] ?? [], 'path'));
        $this->assertStringContainsString('personality:agent-approval-queue', implode(' ', data_get($artifact, 'future_command_templates.approval_queue_dry_run', [])));
    }

    #[Test]
    public function it_blocks_forbidden_input_fields(): void
    {
        $recommendations = $this->recommendationsFixture();
        $recommendations['raw_url'] = 'https://example.test/private';
        $path = $this->writeJson('recommendations-forbidden', $recommendations);

        $exitCode = Artisan::call('personality:tdk-next-batch-approval-draft-gate', [
            '--recommendations' => $path,
            '--qa' => $this->writeJson('qa', $this->qaFixture()),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
        $this->assertContains('raw_url', data_get($summary, 'forbidden_matches', []));
    }

    #[Test]
    public function generated_contract_documents_personality_tdk_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/personality-tdk-next-batch-approval-draft-gate.v1.json'));

        $this->assertSame('personality-tdk-next-batch-approval-draft-gate.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan personality:tdk-next-batch-approval-draft-gate', $contract['command'] ?? null);
        $this->assertContains('/zh/personality/intp-a', $contract['expected_targets'] ?? []);
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.approval_queue_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.frontend_metadata_edit', true));
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'approval_batches' => DB::table('personality_agent_approval_batches')->count(),
            'approval_items' => DB::table('personality_agent_approval_items')->count(),
            'profile_revisions' => DB::table('personality_profile_revisions')->count(),
            'variant_revisions' => DB::table('personality_profile_variant_revisions')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendationsFixture(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-RECOMMENDATIONS-01',
            'final_decision' => 'PASS_NEXT_BATCH_RECOMMENDATIONS_READY_FOR_QA',
            'recommendations' => [
                $this->recommendation('/zh/personality/intp-a', 'zh-CN', 'INTP'),
                $this->recommendation('/zh/personality/esfp-a', 'zh-CN', 'ESFP'),
                $this->recommendation('/en/personality/enfj-a', 'en', 'ENFJ'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qaFixture(): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-QA-01',
            'final_decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
            'page_results' => [
                $this->qaRow('/zh/personality/intp-a', 'zh-CN'),
                $this->qaRow('/zh/personality/esfp-a', 'zh-CN'),
                $this->qaRow('/en/personality/enfj-a', 'en'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendation(string $path, string $locale, string $mbtiType): array
    {
        return [
            'recommendation_id' => 'personality-agent-next-batch:'.$path,
            'target_url' => 'https://fermatmind.com'.$path,
            'path' => $path,
            'framework' => 'mbti64',
            'locale' => $locale,
            'page_type' => 'variant',
            'mbti_type' => $mbtiType,
            'recommendations' => [
                'title' => ['recommended' => $mbtiType.' title | FermatMind'],
                'description' => ['recommended' => $mbtiType.' public-safe description.'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qaRow(string $path, string $locale): array
    {
        return [
            'target_url' => 'https://fermatmind.com'.$path,
            'path' => $path,
            'framework' => 'mbti64',
            'locale' => $locale,
            'page_type' => 'variant',
            'decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
            'gates' => [
                'schema_validation' => 'pass',
                'trademark_claim_gate' => 'pass',
                'claim_risk_gate' => 'pass',
                'duplicate_template_gate' => 'pass',
                'private_route_gate' => 'pass',
                'result_page_leakage_gate' => 'pass',
                'seo_projection_gate' => 'pass',
                'bilingual_consistency_gate' => 'pass',
            ],
        ];
    }

    private function artifactDir(): string
    {
        $dir = sys_get_temp_dir().'/fm-personality-tdk-next-batch-gate-'.Str::random(12);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $dir = sys_get_temp_dir().'/fm-personality-tdk-next-batch-input-'.Str::random(12);
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$name.'.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, Artisan::output());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path);

        return $decoded;
    }
}

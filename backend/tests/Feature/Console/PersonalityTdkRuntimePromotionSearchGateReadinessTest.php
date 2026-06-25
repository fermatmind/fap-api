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

final class PersonalityTdkRuntimePromotionSearchGateReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_marks_promotion_ready_only_after_runtime_and_promotion_dry_run_evidence_pass(): void
    {
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('personality:tdk-runtime-promotion-search-gate-readiness', [
            '--approval-draft-gate' => $this->writeJson('approval-gate', $this->approvalGateFixture()),
            '--draft-readback' => $this->writeJson('readback', $this->readbackFixture()),
            '--promotion-dry-run' => $this->writeJson('promotion', $this->promotionDryRunFixture()),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('ready_for_separate_promotion_approval', $summary['status'] ?? null);
        $this->assertSame(3, $summary['target_count'] ?? null);
        $this->assertTrue((bool) ($summary['runtime_readback_ready'] ?? false));
        $this->assertTrue((bool) ($summary['promotion_dry_run_ready'] ?? false));
        $this->assertTrue((bool) ($summary['promotion_execute_approval_ready'] ?? false));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_promotion', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('personality-tdk-runtime-promotion-search-gate-readiness.v1', $artifact['schema_version'] ?? null);
        $this->assertArrayHasKey('promotion_execute', data_get($artifact, 'separate_approval_templates', []));
        $this->assertFalse((bool) data_get($artifact, 'gate_statuses.post_promotion_search_gate_ready', true));
    }

    #[Test]
    public function it_requires_review_and_suppresses_approval_phrase_when_followup_evidence_is_missing(): void
    {
        $exitCode = Artisan::call('personality:tdk-runtime-promotion-search-gate-readiness', [
            '--approval-draft-gate' => $this->writeJson('approval-gate', $this->approvalGateFixture()),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('review_required', $summary['status'] ?? null);
        $this->assertFalse((bool) ($summary['promotion_execute_approval_ready'] ?? true));

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame([], data_get($artifact, 'separate_approval_templates', []));
        $this->assertContains('draft_readback_evidence_missing', data_get($artifact, 'review_required_issues', []));
        $this->assertContains('promotion_dry_run_evidence_missing', data_get($artifact, 'review_required_issues', []));
    }

    #[Test]
    public function it_blocks_forbidden_input_fields(): void
    {
        $gate = $this->approvalGateFixture();
        $gate['raw_query'] = 'private';

        $exitCode = Artisan::call('personality:tdk-runtime-promotion-search-gate-readiness', [
            '--approval-draft-gate' => $this->writeJson('approval-gate-forbidden', $gate),
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
        $this->assertContains('raw_query', data_get($summary, 'forbidden_matches', []));
    }

    #[Test]
    public function generated_contract_documents_runtime_promotion_search_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/personality-tdk-runtime-promotion-search-gate-readiness.v1.json'));

        $this->assertSame('personality-tdk-runtime-promotion-search-gate-readiness.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan personality:tdk-runtime-promotion-search-gate-readiness', $contract['command'] ?? null);
        $this->assertContains('/zh/personality/intp-a', $contract['expected_targets'] ?? []);
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_promotion', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_enqueue', true));
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
    private function approvalGateFixture(): array
    {
        return [
            'schema_version' => 'personality-tdk-next-batch-approval-draft-gate.v1',
            'ok' => true,
            'status' => 'planned',
            'targets' => [
                $this->target('/zh/personality/intp-a', 'zh-CN', 'INTP'),
                $this->target('/zh/personality/esfp-a', 'zh-CN', 'ESFP'),
                $this->target('/en/personality/enfj-a', 'en', 'ENFJ'),
            ],
            'gate_statuses' => [
                'approval_queue_dry_run_ready' => true,
                'cms_projection_draft_dry_run_ready' => true,
                'production_approval_queue_write_ready' => false,
                'production_cms_draft_write_ready' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readbackFixture(): array
    {
        return [
            'schema_version' => 'personality-tdk-runtime-readback-qa.v1',
            'ok' => true,
            'status' => 'success',
            'targets' => [
                ['path' => '/zh/personality/intp-a', 'status' => 'success', 'public_runtime_changed' => false],
                ['path' => '/zh/personality/esfp-a', 'status' => 'success', 'public_runtime_changed' => false],
                ['path' => '/en/personality/enfj-a', 'status' => 'success', 'public_runtime_changed' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promotionDryRunFixture(): array
    {
        return [
            'artifact' => 'MBTI64-BACKEND-PROMOTION-CONTRACT-01',
            'ok' => true,
            'status' => 'planned',
            'dry_run' => true,
            'write' => false,
            'promotion_candidates' => [
                ['path' => '/zh/personality/intp-a'],
                ['path' => '/zh/personality/esfp-a'],
                ['path' => '/en/personality/enfj-a'],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function target(string $path, string $locale, string $mbtiType): array
    {
        return [
            'path' => $path,
            'locale' => $locale,
            'framework' => 'mbti64',
            'page_type' => 'variant',
            'mbti_type' => $mbtiType,
        ];
    }

    private function artifactDir(): string
    {
        $dir = sys_get_temp_dir().'/fm-personality-tdk-runtime-promotion-gate-'.Str::random(12);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $dir = sys_get_temp_dir().'/fm-personality-tdk-runtime-promotion-input-'.Str::random(12);
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

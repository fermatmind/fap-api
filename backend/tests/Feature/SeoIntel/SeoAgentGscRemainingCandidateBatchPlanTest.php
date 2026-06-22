<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentGscRemainingCandidateBatchPlanTest extends TestCase
{
    #[Test]
    public function it_plans_the_remaining_six_candidates_and_recommends_a_bounded_next_batch(): void
    {
        $packagePath = $this->writePackage($this->packageCandidates());

        $exitCode = Artisan::call('seo-agent:gsc-remaining-candidate-batch-plan', [
            '--package' => $packagePath,
            '--completed-target' => 'article:41:en',
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(6, $summary['remaining_candidate_count'] ?? null);
        $this->assertContains($summary['recommended_limit'] ?? null, [2, 3]);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('article:41:en', $artifact['completed_target_excluded'] ?? null);
        $this->assertSame(7, $artifact['source_candidate_count'] ?? null);
        $this->assertSame(6, $artifact['remaining_candidate_count'] ?? null);
        $this->assertNotContains('article:41:en', array_column($artifact['remaining_candidates'] ?? [], 'subject_ref'));
        $this->assertContains('article:42:en', data_get($artifact, 'recommendation.recommended_targets', []));
        $this->assertContains(data_get($artifact, 'recommendation.limit'), [2, 3]);
        $this->assertStringContainsString(
            'I explicitly approve production CMS draft write canary for next GSC cohort article batch limit=',
            (string) data_get($artifact, 'recommendation.future_approval_phrase')
        );
        $this->assertTrue((bool) data_get($artifact, 'approval_gate.requires_separate_production_cms_draft_write_approval'));

        $zhCandidate = collect($artifact['remaining_candidates'] ?? [])
            ->firstWhere('subject_ref', 'article:44:zh-CN');
        $this->assertIsArray($zhCandidate);
        $this->assertContains('extra_chinese_tdk_and_faq_review_required', $zhCandidate['review_cautions'] ?? []);
        $this->assertContains('zh_tdk_faq_extra_review', $zhCandidate['risk_flags'] ?? []);

        $encoded = json_encode([$summary, $artifact], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, $forbidden);
        }
        foreach ([
            'database_write',
            'cms_write',
            'cms_publish',
            'search_channel_enqueue',
            'search_channel_submit',
            'indexing_request',
            'scheduler_activation',
            'external_model_api_call',
            'live_gsc_api_call',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function it_returns_review_required_when_the_package_does_not_contain_exactly_six_remaining_candidates(): void
    {
        $packagePath = $this->writePackage(array_slice($this->packageCandidates(), 0, 4));

        $exitCode = Artisan::call('seo-agent:gsc-remaining-candidate-batch-plan', [
            '--package' => $packagePath,
            '--completed-target' => 'article:41:en',
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('review_required', $summary['status'] ?? null);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertContains('remaining_candidate_count_not_expected_6', $artifact['issues'] ?? []);
    }

    #[Test]
    public function it_fails_closed_for_invalid_schema_and_forbidden_fields(): void
    {
        $invalidSchema = $this->writeJson('seo-agent-cms-draft-package-', ['schema_version' => 'wrong']);

        $exitCode = Artisan::call('seo-agent:gsc-remaining-candidate-batch-plan', [
            '--package' => $invalidSchema,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('package_schema_invalid', $summary['issues'] ?? []);

        $forbidden = $this->writePackage([
            [
                ...$this->candidate('article:41:en', 'p1', 10, 'en'),
                'raw_query' => 'blocked',
            ],
        ]);
        $exitCode = Artisan::call('seo-agent:gsc-remaining-candidate-batch-plan', [
            '--package' => $forbidden,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_remaining_candidate_batch_plan_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-remaining-candidate-batch-plan.v1.json'));

        $this->assertSame('seo-agent-gsc-remaining-candidate-batch-plan.v1', $contract['version'] ?? null);
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', $contract['input_schema'] ?? null);
        $this->assertSame(6, data_get($contract, 'planning_policy.expected_remaining_candidate_count'));
        $this->assertFalse((bool) ($contract['mutates_cms'] ?? true));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packageCandidates(): array
    {
        return [
            $this->candidate('article:41:en', 'p1', 31, 'en'),
            $this->candidate('article:42:en', 'p1', 28, 'en'),
            $this->candidate('article:43:en', 'p2', 19, 'en'),
            $this->candidate('article:44:zh-CN', 'p2', 25, 'zh-CN'),
            $this->candidate('article:45:zh-CN', 'p2', 12, 'zh-CN'),
            $this->candidate('article:46:en', 'p3', 18, 'en'),
            $this->candidate('article:47:zh-CN', 'p3', 10, 'zh-CN'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(string $subjectRef, string $severity, int $impressions, string $locale): array
    {
        $slug = str_replace(':', '-', $subjectRef);

        return [
            'source_id' => hash('sha256', $subjectRef),
            'source_family' => 'gsc_cohort_artifact',
            'subject_type' => 'article',
            'target_model' => 'article',
            'subject_ref' => $subjectRef,
            'safe_path' => ($locale === 'en' ? '/en/articles/' : '/zh/articles/').$slug,
            'severity' => $severity,
            'target_fields' => ['seo_title', 'seo_description', 'faq_items'],
            'proposed_seo_title' => $locale === 'en'
                ? 'Careful Career Reflection | FermatMind'
                : '谨慎理解职业选择 | FermatMind',
            'proposed_seo_description' => $locale === 'en'
                ? 'Review personality and career signals without overclaiming outcomes.'
                : '用谨慎方式理解人格与职业信号，避免过度承诺。',
            'proposed_faq_items' => [
                [
                    'question' => $locale === 'en' ? 'Can this predict career outcomes?' : '这能预测职业结果吗？',
                    'answer' => $locale === 'en' ? 'No. It supports reflection only.' : '不能。它只辅助自我反思。',
                ],
            ],
            'proposed_internal_link_actions' => ['Add internal link review target to /en/tests/big-five-personality-test'],
            'proposal_quality' => [
                'source' => 'gsc_cohort_artifact',
                'locale_preserved' => true,
                'slug_generated_copy' => false,
                'needs_human_approval' => true,
            ],
            'metrics' => [
                'impressions' => $impressions,
                'clicks' => 0,
                'ctr_ppm' => 0,
                'average_position' => 3.2,
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function writePackage(array $candidates): string
    {
        return $this->writeJson('seo-agent-cms-draft-package-', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'run_mode' => 'cms_draft_package_dry_run',
            'dry_run' => true,
            'cms_write_allowed' => false,
            'execution_permission' => false,
            'draft_brief_count' => count($candidates),
            'draft_briefs' => $candidates,
            'proposal_count' => count($candidates),
            'proposal_items' => $candidates,
            'claim_gate_required' => true,
            'human_approval_required' => true,
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-remaining-candidate-batch-plan-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = storage_path('framework/testing/'.$prefix.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'raw_url',
            'raw_query',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'content_md',
            'content_html',
            'cms_draft_body',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}

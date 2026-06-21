<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentL5aCandidateReviewTest extends TestCase
{
    #[Test]
    public function command_selects_safest_low_risk_content_page_candidate_from_preflight_chain(): void
    {
        $artifactDir = $this->artifactDir();
        $chain = $this->writePreflightChain($artifactDir, [
            $this->draftBrief([
                'source_family' => 'runtime_seo_qa',
                'subject_type' => 'content_page',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:90:zh-CN',
                'safe_path' => '/zh/help/runtime-risk',
                'severity' => 'p1',
                'gap_codes' => ['canonical_mismatch'],
                'target_fields' => ['canonical_url_or_path'],
            ]),
            $this->draftBrief([
                'source_family' => 'cms_faq_gap',
                'subject_type' => 'content_page',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:91:zh-CN',
                'safe_path' => '/zh/help/faq-gap',
                'severity' => 'p2',
                'gap_codes' => ['missing_faq_items'],
                'target_fields' => ['faq_items'],
            ]),
            $this->draftBrief([
                'source_family' => 'cms_tdk_gap',
                'subject_type' => 'article',
                'target_model' => 'article',
                'subject_ref' => 'article:31:zh-CN',
                'safe_path' => '/zh/articles/article-gap',
                'severity' => 'p1',
            ]),
            $this->draftBrief([
                'source_family' => 'cms_tdk_gap',
                'subject_type' => 'content_page',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:92:zh-CN',
                'safe_path' => '/zh/help/forbidden-claim',
                'severity' => 'p1',
                'proposed_seo_title' => 'Find your perfect match',
            ]),
            $this->draftBrief([
                'source_family' => 'cms_tdk_gap',
                'subject_type' => 'content_page',
                'target_model' => 'content_page',
                'subject_ref' => 'content_page:16:zh-CN',
                'safe_path' => '/zh/help/about',
                'severity' => 'p1',
                'gap_codes' => ['missing_title', 'missing_meta_description'],
                'target_fields' => ['seo_title', 'seo_description'],
            ]),
        ]);

        $exitCode = Artisan::call('seo-agent:l5a-candidate-review', [
            '--preflight-summary' => $chain['summary'],
            '--limit' => 1,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('seo-agent-l5a-candidate-review.v1', $summary['schema_version'] ?? null);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame(1, $summary['selected_count'] ?? null);
        $this->assertSame('content_page:16:zh-CN', data_get($summary, 'selected_candidate.subject_ref'));
        $this->assertSame('/zh/help/about', data_get($summary, 'selected_candidate.safe_path'));
        $this->assertSame('cms_tdk_gap', data_get($summary, 'selected_candidate.source_family'));
        $this->assertSame(['seo_title', 'seo_description'], data_get($summary, 'selected_candidate.target_fields'));
        $this->assertArrayHasKey('target_not_content_page', $summary['rejected_reason_counts'] ?? []);
        $this->assertArrayHasKey('runtime_seo_qa_risk', $summary['rejected_reason_counts'] ?? []);
        $this->assertArrayHasKey('forbidden_claim_detected', $summary['rejected_reason_counts'] ?? []);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('SEO-AGENT-L5A-PREFLIGHT-CANDIDATE-REVIEW-01', $artifact['task'] ?? null);
        $this->assertSame(5, data_get($artifact, 'source_counts.draft_brief_count'));
        $this->assertSame(2, data_get($artifact, 'source_counts.eligible_count'));
        $this->assertSame('content_page:16:zh-CN', data_get($artifact, 'selected_candidate.subject_ref'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.indexnow_live_submit', true));

        $combined = (string) file_get_contents((string) data_get($summary, 'artifact.path'));
        foreach ($this->forbiddenStrings() as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined, $forbidden);
        }
    }

    #[Test]
    public function command_fails_closed_when_no_low_risk_content_page_candidate_exists(): void
    {
        $artifactDir = $this->artifactDir();
        $chain = $this->writePreflightChain($artifactDir, [
            $this->draftBrief([
                'source_family' => 'runtime_seo_qa',
                'subject_ref' => 'content_page:91:zh-CN',
                'gap_codes' => ['noindex_present'],
                'target_fields' => ['is_indexable_or_robots'],
            ]),
            $this->draftBrief([
                'subject_type' => 'article',
                'target_model' => 'article',
                'subject_ref' => 'article:11:zh-CN',
                'safe_path' => '/zh/articles/only-article',
            ]),
        ]);

        $exitCode = Artisan::call('seo-agent:l5a-candidate-review', [
            '--preflight-summary' => $chain['summary'],
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertSame(0, $summary['selected_count'] ?? null);
        $this->assertArrayHasKey('runtime_seo_qa_risk', $summary['rejected_reason_counts'] ?? []);
        $this->assertArrayHasKey('target_not_content_page', $summary['rejected_reason_counts'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_write', true));
    }

    /**
     * @param  list<array<string, mixed>>  $draftBriefs
     * @return array{summary: string, weekly: string, run: string, draft: string}
     */
    private function writePreflightChain(string $dir, array $draftBriefs): array
    {
        $draftPath = $this->writeJson($dir, 'draft-package.json', [
            'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
            'draft_brief_count' => count($draftBriefs),
            'draft_briefs' => $draftBriefs,
            'proposal_items' => $draftBriefs,
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
        $runPath = $this->writeJson($dir, 'run-evidence.json', [
            'schema_version' => 'seo-agent-run-evidence.v1',
            'artifacts' => [
                'cms_draft_package_dry_run' => [
                    'path' => $draftPath,
                    'size' => filesize($draftPath),
                    'sha256' => hash_file('sha256', $draftPath),
                    'schema_version' => 'seo-agent-cms-draft-package-dry-run.v1',
                ],
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
        $weeklyPath = $this->writeJson($dir, 'weekly-readonly.json', [
            'schema_version' => 'seo-agent-weekly-readonly-runner.v1',
            'status' => 'success',
            'run_artifact' => [
                'path' => $runPath,
                'size' => filesize($runPath),
                'sha256' => hash_file('sha256', $runPath),
                'schema_version' => 'seo-agent-run-evidence.v1',
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);
        $summaryPath = $this->writeJson($dir, 'priority-scheduler-summary.json', [
            'schema_version' => 'seo-agent-priority-queue-scheduler.v1',
            'ok' => true,
            'status' => 'success',
            'preflight_only' => true,
            'steps' => [
                'weekly_readonly_runner' => [
                    'status' => 'success',
                    'artifact' => [
                        'path' => $weeklyPath,
                        'size' => filesize($weeklyPath),
                        'sha256' => hash_file('sha256', $weeklyPath),
                        'schema_version' => 'seo-agent-weekly-readonly-runner.v1',
                    ],
                ],
                'rollback_preflight' => [
                    'status' => 'pass',
                ],
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ]);

        return [
            'summary' => $summaryPath,
            'weekly' => $weeklyPath,
            'run' => $runPath,
            'draft' => $draftPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function draftBrief(array $overrides = []): array
    {
        return [
            'source_id' => 'candidate-'.Str::uuid()->toString(),
            'source_family' => 'cms_tdk_gap',
            'subject_type' => 'content_page',
            'subject_ref' => 'content_page:1:zh-CN',
            'target_model' => 'content_page',
            'safe_path' => '/zh/help/default',
            'severity' => 'p1',
            'gap_codes' => ['missing_title'],
            'target_fields' => ['seo_title'],
            'proposed_seo_title' => 'Help | FermatMind',
            'proposed_seo_description' => null,
            'proposed_faq_items' => [],
            'draft_instructions' => [
                'prepare_field_level_proposal_only',
                'do_not_generate_final_body_copy',
            ],
            'claim_gate_required' => true,
            'human_approval_required' => true,
            'execution_permission' => false,
            'blocked_actions' => [
                'cms_write',
                'cms_publish',
                'search_channel_enqueue',
                'search_channel_submit',
                'indexing_request',
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $dir, string $filename, array $payload): string
    {
        $path = rtrim($dir, '/').'/'.$filename;
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-l5a-candidate-review-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @return array<string, bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'cms_publish' => false,
            'search_channel_queue_write' => false,
            'search_channel_submit' => false,
            'indexnow_live_submit' => false,
            'google_indexing_api_call' => false,
            'scheduler_activation' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenStrings(): array
    {
        return [
            'https://fermatmind.com',
            'raw_url',
            'raw_query',
            'full_url',
            'credential_path',
            'client_email',
            'private_key',
            'Bearer ',
            'cookie',
            'content_md',
            'content_html',
            'raw_html',
            'cms_draft_body',
            'Find your perfect match',
        ];
    }
}

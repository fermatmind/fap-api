<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ContentPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentCmsDraftPackageDryRunTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_writes_draft_briefs_without_generating_copy_or_writing_cms(): void
    {
        $this->createRows();
        $artifactDir = $this->artifactDir();
        $verdictPath = $this->writeVerdict([
            $this->candidateVerdict('cms_draft_package_dry_run', true, ['missing_title', 'missing_meta_description']),
            $this->candidateVerdict('defer', false, ['missing_canonical']),
        ]);
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-agent:cms-draft-package-dry-run', [
            '--verdict' => $verdictPath,
            '--artifact-dir' => $artifactDir,
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $countsAfter = $this->rowCounts();
        $package = $this->readJson(data_get($summary, 'artifact.path'));

        $this->assertSame(0, $exitCode);
        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame('success', $summary['status'] ?? null);
        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', $package['schema_version'] ?? null);
        $this->assertTrue((bool) ($package['dry_run'] ?? false));
        $this->assertFalse((bool) ($package['cms_write_allowed'] ?? true));
        $this->assertFalse((bool) ($package['execution_permission'] ?? true));
        $this->assertSame(1, $package['draft_brief_count'] ?? null);

        $brief = $package['draft_briefs'][0] ?? [];
        $this->assertSame('article:1:zh-CN', $brief['subject_ref'] ?? null);
        $this->assertSame('/zh/articles/draft-package-candidate', $brief['safe_path'] ?? null);
        $this->assertSame(['missing_title', 'missing_meta_description'], $brief['gap_codes'] ?? null);
        $this->assertSame(['seo_title', 'seo_description'], $brief['target_fields'] ?? null);
        $this->assertTrue((bool) ($brief['claim_gate_required'] ?? false));
        $this->assertTrue((bool) ($brief['human_approval_required'] ?? false));

        $encoded = json_encode([$summary, $package], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            'queue_worker_started',
        ] as $field) {
            $this->assertFalse((bool) data_get($package, 'negative_guarantees.'.$field, true), $field);
        }
    }

    #[Test]
    public function command_fails_closed_for_invalid_schema_and_forbidden_fields(): void
    {
        $invalidSchema = $this->writeJson(['schema_version' => 'wrong.v1']);

        $exitCode = Artisan::call('seo-agent:cms-draft-package-dry-run', [
            '--verdict' => $invalidSchema,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('verdict_schema_invalid', $summary['issues'] ?? []);

        $forbidden = $this->writeVerdict([
            $this->candidateVerdict('cms_draft_package_dry_run', true, ['missing_title'], ['cms_draft_body' => 'forbidden']),
        ]);

        $exitCode = Artisan::call('seo-agent:cms-draft-package-dry-run', [
            '--verdict' => $forbidden,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $summary['status'] ?? null);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_dry_run_boundaries(): void
    {
        $artifact = $this->readJson(base_path('docs/seo/generated/seo-agent-cms-draft-package-dryrun.v1.json'));

        $this->assertSame('seo-agent-cms-draft-package-dry-run.v1', $artifact['version'] ?? null);
        $this->assertSame('seo-agent-codex-review-verdict.v1', $artifact['input_schema'] ?? null);
        $this->assertTrue((bool) ($artifact['dry_run'] ?? false));
        $this->assertFalse((bool) ($artifact['cms_write_allowed'] ?? true));
        $this->assertFalse((bool) ($artifact['generates_final_copy'] ?? true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_write', true));
    }

    /**
     * @param  list<string>  $gapTypes
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function candidateVerdict(string $recommendedAction, bool $worthOptimizing, array $gapTypes, array $extra = []): array
    {
        return [
            'source_id' => hash('sha256', $recommendedAction.implode(',', $gapTypes)),
            'source_family' => 'cms_tdk_gap',
            'subject_type' => 'article',
            'subject_ref' => 'article:1:zh-CN',
            'safe_path' => '/zh/articles/draft-package-candidate',
            'severity' => $worthOptimizing ? 'p1' : 'p3',
            'gap_types' => $gapTypes,
            'evidence_refs' => array_map(
                static fn (string $gap): array => [
                    'code' => $gap,
                    'field_status' => 'missing',
                ],
                $gapTypes
            ),
            'worth_optimizing' => $worthOptimizing,
            'recommended_action' => $recommendedAction,
            'risk_flags' => [],
            'needs_human_approval' => true,
            'execution_permission' => false,
            ...$extra,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidateVerdicts
     */
    private function writeVerdict(array $candidateVerdicts): string
    {
        return $this->writeJson([
            'schema_version' => 'seo-agent-codex-review-verdict.v1',
            'reviewer' => 'codex',
            'review_mode' => 'deterministic_rules',
            'role' => 'review_only',
            'execution_permission' => false,
            'candidate_count' => count($candidateVerdicts),
            'candidate_verdicts' => $candidateVerdicts,
            'worth_optimizing' => true,
            'recommended_action' => 'cms_draft_package_dry_run',
            'needs_human_approval' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): string
    {
        $path = storage_path('framework/testing/seo-agent-cms-draft-package-verdict-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-cms-draft-package-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function createRows(): void
    {
        Article::query()->create([
            'org_id' => 0,
            'slug' => 'draft-package-db-sentinel',
            'locale' => 'zh-CN',
            'title' => 'Sentinel',
            'excerpt' => 'Sentinel.',
            'content_md' => 'Sentinel markdown body is not read or emitted by this command.',
            'content_html' => '<p>Sentinel HTML body is not read or emitted by this command.</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);

        ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'draft-package-page-sentinel',
            'path' => '/zh/draft-package-page-sentinel',
            'kind' => ContentPage::KIND_COMPANY,
            'page_type' => 'company',
            'title' => 'Sentinel',
            'template' => 'company',
            'animation_profile' => 'none',
            'locale' => 'zh-CN',
            'is_public' => true,
            'is_indexable' => true,
            'schema_enabled' => false,
            'publish_allowed' => false,
            'operator_approval_required' => false,
            'legal_review_required' => false,
            'science_review_required' => false,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'status' => ContentPage::STATUS_PUBLISHED,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->count(),
        ];
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
    private function readJson(?string $path): array
    {
        $this->assertIsString($path);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}

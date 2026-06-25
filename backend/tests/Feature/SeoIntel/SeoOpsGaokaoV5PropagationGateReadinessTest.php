<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoOpsGaokaoV5PropagationGateReadinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_emits_read_only_gate_checklist_without_required_future_artifacts(): void
    {
        $countsBefore = $this->rowCounts();

        $exitCode = Artisan::call('seo-ops:gaokao-v5-propagation-gate-readiness', [
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertSame('review_required', $summary['status'] ?? null);
        $this->assertSame('/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist', data_get($summary, 'target.canonical_path'));
        $this->assertFalse((bool) data_get($summary, 'gate_statuses.publish_dry_run_ready'));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($summary, 'negative_guarantees.search_channel_enqueue', true));
        $this->assertSame($countsBefore, $this->rowCounts());

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('seo-ops-gaokao-v5-propagation-gate-readiness.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('review_required', $artifact['status'] ?? null);
        $this->assertTrue((bool) data_get($artifact, 'approval_boundaries.publish_is_separate_from_url_truth'));
    }

    #[Test]
    public function it_marks_publish_dry_run_ready_when_draft_write_readback_and_preview_pass(): void
    {
        $draftWrite = $this->writeArtifact('draft-write', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'ok' => true,
            'status' => 'success',
        ]);
        $readback = $this->writeArtifact('readback', [
            'schema_version' => 'seo-agent-cms-draft-readback-qa.v1',
            'ok' => true,
            'status' => 'success',
        ]);
        $preview = $this->writeArtifact('preview', [
            'schema_version' => 'seo-agent-article-draft-preview-runtime-qa.v1',
            'ok' => true,
            'status' => 'success',
        ]);

        $exitCode = Artisan::call('seo-ops:gaokao-v5-propagation-gate-readiness', [
            '--draft-write-evidence' => $draftWrite,
            '--readback-qa' => $readback,
            '--preview-qa' => $preview,
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('publish_dry_run_ready', $summary['status'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'gate_statuses.publish_dry_run_ready'));
        $this->assertFalse((bool) data_get($summary, 'gate_statuses.url_truth_ready_after_publish'));

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame(hash_file('sha256', $draftWrite), data_get($artifact, 'artifact_inputs.draft_write_evidence.sha256'));
        $this->assertStringContainsString('production CMS publish canary', data_get($artifact, 'required_evidence_checklist.publish_dry_run.approval_phrase_template'));
    }

    #[Test]
    public function it_blocks_forbidden_payload_fields(): void
    {
        $forbidden = $this->writeArtifact('forbidden', [
            'schema_version' => 'example.v1',
            'ok' => true,
            'status' => 'success',
            'raw_url' => 'https://example.test/private',
        ]);

        $exitCode = Artisan::call('seo-ops:gaokao-v5-propagation-gate-readiness', [
            '--readback-qa' => $forbidden,
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
    public function generated_contract_documents_gate_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-ops-gaokao-v5-propagation-gate-readiness.v1.json'));

        $this->assertSame('seo-ops-gaokao-v5-propagation-gate-readiness.v1', $contract['version'] ?? null);
        $this->assertSame('php artisan seo-ops:gaokao-v5-propagation-gate-readiness', $contract['command'] ?? null);
        $this->assertContains('IndexNow live submit after separate exact approval', $contract['gate_sequence'] ?? []);
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.indexnow_submit', true));
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'articles' => \App\Models\Article::query()->withoutGlobalScopes()->count(),
            'article_revisions' => \App\Models\ArticleRevision::query()->withoutGlobalScopes()->count(),
            'article_translation_revisions' => \App\Models\ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
            'article_seo_meta' => \App\Models\ArticleSeoMeta::query()->withoutGlobalScopes()->count(),
        ];
    }

    private function artifactDir(): string
    {
        $dir = sys_get_temp_dir().'/fm-gaokao-v5-propagation-gate-'.Str::random(12);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeArtifact(string $name, array $payload): string
    {
        $dir = sys_get_temp_dir().'/fm-gaokao-v5-propagation-input-'.Str::random(12);
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

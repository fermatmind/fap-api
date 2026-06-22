<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoAgentGscDraftPublishGateReadinessTest extends TestCase
{
    #[Test]
    public function it_marks_a_draft_publish_ready_only_when_all_required_qa_is_present_and_passing(): void
    {
        $target = 'article:41:en';
        $revisionId = 68;
        $write = $this->writeEvidence($target, $revisionId);
        $readback = $this->artifact('seo-agent-cms-draft-readback-qa.v1', $target, ['status' => 'success', 'mismatch_count' => 0]);
        $claim = $this->artifact('seo-agent-article-draft-claim-risk-qa.v1', $target, ['status' => 'success', 'critical_finding_count' => 0]);
        $preview = $this->artifact('seo-agent-article-draft-preview-runtime-qa.v1', $target, ['status' => 'success', 'ok' => true]);

        $exitCode = Artisan::call('seo-agent:gsc-draft-publish-gate-readiness', [
            '--write-evidence' => $write,
            '--readback-qa' => [$readback],
            '--claim-risk-qa' => [$claim],
            '--preview-runtime-qa' => [$preview],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('publish_ready', $summary['status'] ?? null);
        $this->assertSame(1, $summary['publish_ready_count'] ?? null);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertSame('publish_ready', data_get($artifact, 'draft_verdicts.0.gate_status'));
        $this->assertStringContainsString('I explicitly approve production CMS publish canary for article:41:en revision 68', (string) data_get($artifact, 'draft_verdicts.0.publish_approval_phrase'));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.cms_publish', true));
        $this->assertFalse((bool) data_get($artifact, 'negative_guarantees.indexnow_submit', true));
    }

    #[Test]
    public function it_does_not_emit_a_publish_phrase_when_required_qa_is_missing_or_failing(): void
    {
        $target = 'article:41:en';
        $write = $this->writeEvidence($target, 68);
        $readback = $this->artifact('seo-agent-cms-draft-readback-qa.v1', $target, ['status' => 'success', 'mismatch_count' => 0]);

        $exitCode = Artisan::call('seo-agent:gsc-draft-publish-gate-readiness', [
            '--write-evidence' => $write,
            '--readback-qa' => [$readback],
            '--artifact-dir' => $this->artifactDir(),
            '--json' => true,
        ]);

        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('review_required', $summary['status'] ?? null);

        $artifact = $this->readJson((string) data_get($summary, 'artifact.path'));
        $this->assertNull(data_get($artifact, 'draft_verdicts.0.publish_approval_phrase'));
        $this->assertContains('claim_risk_qa_missing', data_get($artifact, 'draft_verdicts.0.issues', []));
        $this->assertContains('preview_runtime_qa_missing', data_get($artifact, 'draft_verdicts.0.issues', []));
    }

    #[Test]
    public function it_fails_closed_for_invalid_schema_and_forbidden_inputs(): void
    {
        $badWrite = $this->writeJson('bad-write-', ['schema_version' => 'wrong']);

        $exitCode = Artisan::call('seo-agent:gsc-draft-publish-gate-readiness', [
            '--write-evidence' => $badWrite,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('write_evidence_schema_invalid', $summary['issues'] ?? []);

        $forbidden = $this->writeJson('forbidden-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'raw_query' => 'blocked',
        ]);
        $exitCode = Artisan::call('seo-agent:gsc-draft-publish-gate-readiness', [
            '--write-evidence' => $forbidden,
            '--json' => true,
        ]);
        $summary = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, $exitCode);
        $this->assertContains('forbidden_input_field_present', $summary['issues'] ?? []);
    }

    #[Test]
    public function generated_contract_documents_publish_gate_boundaries(): void
    {
        $contract = $this->readJson(base_path('docs/seo/generated/seo-agent-gsc-draft-publish-gate-readiness.v1.json'));

        $this->assertSame('seo-agent-gsc-draft-publish-gate-readiness.v1', $contract['version'] ?? null);
        $this->assertContains('publish_ready', $contract['verdicts'] ?? []);
        $this->assertTrue((bool) data_get($contract, 'approval_policy.emit_publish_phrase_only_when_all_required_qa_present_and_passing'));
        $this->assertFalse((bool) ($contract['publishes_content'] ?? true));
        $this->assertFalse((bool) data_get($contract, 'negative_guarantees.search_channel_submit', true));
    }

    private function writeEvidence(string $target, int $revisionId): string
    {
        return $this->writeJson('seo-agent-controlled-cms-draft-write-', [
            'schema_version' => 'seo-agent-controlled-cms-draft-write.v1',
            'status' => 'success',
            'execute' => true,
            'package_sha256' => hash('sha256', 'package'),
            'affected_refs' => [
                ['target_model' => 'article', 'subject_ref' => $target, 'revision_id' => $revisionId],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function artifact(string $schema, string $target, array $extra): string
    {
        return $this->writeJson('qa-artifact-', [
            'schema_version' => $schema,
            'target' => $target,
            ...$extra,
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/seo-agent-gsc-draft-publish-gate-readiness-'.Str::uuid()->toString());
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
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}

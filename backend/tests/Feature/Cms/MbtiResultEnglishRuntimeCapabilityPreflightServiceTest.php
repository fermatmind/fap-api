<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Services\Cms\MbtiResultEnglishRuntimeCapabilityPreflightService;
use App\Services\ContentPackResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MbtiResultEnglishRuntimeCapabilityPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $compiledRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('content_packs.root', base_path('../content_packages'));
        app()->forgetInstance(ContentPackResolver::class);
        $this->compiledRoot = storage_path('app/content_packs_v2/mbti-result-runtime-capability-preflight');
        File::deleteDirectory($this->compiledRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->compiledRoot);
        parent::tearDown();
    }

    public function test_it_fails_closed_when_the_exact_inactive_authority_is_missing(): void
    {
        $this->expectPreflightFailure('target_authority_contract_mismatch');

        $this->inspect();
    }

    public function test_it_reaches_the_deployed_resolvers_before_rejecting_unsubstituted_tokens(): void
    {
        $this->seedExactInactiveAuthority();

        $this->expectPreflightFailure('runtime_result_token_unresolved');

        $this->inspect();
    }

    public function test_it_fails_closed_when_an_active_pointer_exists(): void
    {
        $this->seedExactInactiveAuthority();
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'MBTI.global.en.default',
            'pack_version' => 'v0.3',
            'release_id' => '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488',
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectPreflightFailure('target_authority_contract_mismatch');

        $this->inspect();
    }

    public function test_the_executor_and_workflow_are_read_only_and_frame_only_validated_stdout_receipts(): void
    {
        $approvalPath = MbtiResultEnglishRuntimeCapabilityPreflightService::defaultApprovalPath();
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        $executor = (string) File::get(base_path('scripts/mbti_result_english_runtime_capability_preflight.php'));
        $service = (string) File::get(base_path('app/Services/Cms/MbtiResultEnglishRuntimeCapabilityPreflightService.php'));
        $workflow = (string) File::get(base_path('../.github/workflows/mbti-result-english-runtime-capability-preflight.yml'));

        self::assertSame(MbtiResultEnglishRuntimeCapabilityPreflightService::APPROVAL_SHA256, hash('sha256', $approvalBytes));
        self::assertSame('runtime_capability_preflight', $approval['gate']);
        self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
        self::assertTrue($approval['permissions']['runtime_projection_readback_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['target_authority_readback_authorized', 'runtime_projection_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }
        self::assertStringContainsString("const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53'", $executor);
        self::assertSame(1, substr_count($executor, 'fwrite(STDOUT'));
        self::assertStringContainsString('mbtiResultRuntimeCapabilityPreflightDiscardOutputBuffers', $executor);
        self::assertStringContainsString('register_shutdown_function', $executor);
        self::assertStringContainsString("mbtiResultRuntimeCapabilityPreflightFail('executor_stdout_contaminated')", $executor);
        self::assertStringContainsString("mbtiResultRuntimeCapabilityPreflightFail('executor_receipt_encode_failed')", $executor);
        self::assertStringNotContainsString('$throwable->getMessage()', $executor);
        self::assertStringContainsString('controlled_read_only_runtime_capability_preflight', $service);
        self::assertStringNotContainsString('payload_json', $service);
        self::assertStringNotContainsString("DB::table('attempts')", $service);
        self::assertStringNotContainsString("DB::table('results')", $service);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('controlled_read_only_runtime_capability_preflight', $workflow);
        self::assertStringContainsString('candidate_receipt_path=', $workflow);
        self::assertStringContainsString('> "$RUN_DIR/executor.stdout" 2> "$RUN_DIR/executor.stderr"', $workflow);
        self::assertStringContainsString('jq -e -s', $workflow);
        self::assertStringContainsString('length == 1', $workflow);
        self::assertStringContainsString('emit_remote_receipt remote_executor_failed remote_executor_failed', $workflow);
        self::assertStringContainsString('emit_remote_receipt remote_executor_stdout_invalid remote_executor_stdout_invalid', $workflow);
        self::assertStringContainsString('emit_failure_receipt remote_transport_or_bootstrap_failed remote_transport_or_bootstrap_failed', $workflow);
        self::assertStringContainsString('cat "$RUN_DIR/executor.stdout"', $workflow);
        self::assertStringContainsString('mv "$candidate_receipt_path" "$receipt_path"', $workflow);
        self::assertStringContainsString("jq -e '.status == \"BLOCKED\"' \"\$receipt_path\"", $workflow);
        self::assertStringContainsString('runner_receipt_contract_failed', $workflow);
        self::assertStringNotContainsString('> "$receipt_path" 2>"$RUNNER_TEMP/mbti-result-runtime-preflight.stderr"', $workflow);
        self::assertStringNotContainsString('$run_dir/receipts/.', $workflow);
        self::assertStringNotContainsString('scp -P "$DEPLOY_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes "$run_dir/receipt', $workflow);
        self::assertStringNotContainsString('php artisan migrate', $workflow);
        self::assertStringNotContainsString('dep deploy', $workflow);
    }

    public function test_the_executor_emits_only_a_single_json_receipt_or_a_safe_stderr_failure(): void
    {
        $success = $this->runExecutorFixture('success');

        self::assertTrue($success->isSuccessful(), $success->getErrorOutput());
        self::assertSame('', $success->getErrorOutput());
        self::assertStringEndsWith(PHP_EOL, $success->getOutput());
        self::assertSame(1, substr_count($success->getOutput(), PHP_EOL));
        self::assertSame([
            'artifact' => 'fixture-receipt',
            'status' => 'PASS',
            'control_plane_sha' => str_repeat('a', 40),
            'active_revision' => '660280d00a57e58bd8bc76608e19de2492c03f53',
        ], json_decode($success->getOutput(), true, 512, JSON_THROW_ON_ERROR));

        foreach ([
            'bootstrap_output' => 'executor_stdout_contaminated',
            'runtime_exception' => 'executor_runtime_failed',
            'invalid_json' => 'executor_receipt_encode_failed',
        ] as $mode => $errorCode) {
            $failure = $this->runExecutorFixture($mode);

            self::assertFalse($failure->isSuccessful(), $mode);
            self::assertSame('', $failure->getOutput(), $mode);
            self::assertSame("mbti_result_runtime_capability_preflight_failed:$errorCode\n", $failure->getErrorOutput(), $mode);
        }
    }

    public function test_the_stdout_provenance_diagnostic_is_read_only_and_does_not_export_raw_probe_output(): void
    {
        $approvalPath = base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/runtime-capability-provenance-diagnostic-approval-2026-08-02.json');
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        $workflow = (string) File::get(base_path('../.github/workflows/mbti-result-english-runtime-capability-provenance-diagnostic.yml'));

        self::assertSame('31681314c75d04c9670e8f013362f609d02e2a053cbbff7977e5b5fa5a41e92b', hash('sha256', $approvalBytes));
        self::assertSame('runtime_capability_provenance_diagnostic', $approval['gate']);
        self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
        self::assertTrue($approval['permissions']['runtime_projection_readback_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['target_authority_readback_authorized', 'runtime_projection_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }

        self::assertStringContainsString('expected_approval_sha', $workflow);
        self::assertStringContainsString('runtime_capability_provenance_diagnostic', $workflow);
        self::assertStringContainsString('run_probe bootstrap', $workflow);
        self::assertStringContainsString('run_probe service', $workflow);
        self::assertStringContainsString('run_probe executor', $workflow);
        self::assertStringContainsString('runtime-capability-preflight-approval-2026-08-02.json', $workflow);
        self::assertStringContainsString('7d20ee867a1a2eb0f69d0d3a4441615690d836f3e8f3a47222b6dfb43f6c5a3b', $workflow);
        self::assertStringContainsString('stdout_bytes', $workflow);
        self::assertStringContainsString('newline_count', $workflow);
        self::assertStringContainsString('json_document_count', $workflow);
        self::assertStringContainsString('stdout_sha256', $workflow);
        self::assertStringContainsString('bootstrap_stdout_observed', $workflow);
        self::assertStringContainsString('runtime_stdout_observed', $workflow);
        self::assertStringContainsString('multiple_json_documents', $workflow);
        self::assertStringContainsString('single_json_contract_mismatch', $workflow);
        self::assertStringContainsString('probe_failed', $workflow);
        self::assertStringContainsString("trap 'rm -rf \"\$RUN_DIR\" \"\$RUN_DIR.tgz\"' EXIT", $workflow);
        self::assertStringContainsString('keys | sort', $workflow);
        self::assertStringNotContainsString('cat "$RUN_DIR/bootstrap.stdout"', $workflow);
        self::assertStringNotContainsString('cat "$RUN_DIR/service.stdout"', $workflow);
        self::assertStringNotContainsString('cat "$RUN_DIR/executor.stdout"', $workflow);
        self::assertStringNotContainsString('scp -P "$DEPLOY_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes "$RUN_DIR/', $workflow);
        self::assertStringNotContainsString('php artisan migrate', $workflow);
        self::assertStringNotContainsString('dep deploy', $workflow);
    }

    private function seedExactInactiveAuthority(): void
    {
        File::ensureDirectoryExists($this->compiledRoot.'/compiled');
        File::put($this->compiledRoot.'/compiled/manifest.json', json_encode([
            'pack_id' => 'MBTI.global.en.default',
            'scale_code' => 'MBTI',
            'region' => 'GLOBAL',
            'locale' => 'en',
            'content_package_version' => 'v0.3',
        ], JSON_THROW_ON_ERROR));
        DB::table('content_pack_releases')->insert([
            'id' => '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488',
            'action' => 'mbti_target_authority_draft_receipt',
            'region' => 'GLOBAL',
            'locale' => 'en',
            'dir_alias' => 'MBTI-GLOBAL-en-v0.3',
            'to_pack_id' => 'MBTI.GLOBAL.EN.DEFAULT',
            'status' => 'success',
            'manifest_hash' => '649a61633a05728618477b97036718c582673c96a82c24d142287991b3d2d0e1',
            'compiled_hash' => '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3',
            'content_hash' => str_repeat('1', 64),
            'pack_version' => 'v0.3',
            'storage_path' => 'content_packs_v2/mbti-result-runtime-capability-preflight',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('content_release_manifests')->insert([
            'content_pack_release_id' => '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488',
            'manifest_hash' => '649a61633a05728618477b97036718c582673c96a82c24d142287991b3d2d0e1',
            'schema_version' => 'mbti.en_result_draft.v1',
            'storage_disk' => 'database',
            'storage_path' => 'content_packs_v2/mbti-result-runtime-capability-preflight',
            'pack_id' => 'MBTI.global.en.default',
            'pack_version' => 'v0.3',
            'compiled_hash' => '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3',
            'content_hash' => str_repeat('1', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function inspect(): array
    {
        return app(MbtiResultEnglishRuntimeCapabilityPreflightService::class)->inspect(
            MbtiResultEnglishRuntimeCapabilityPreflightService::defaultApprovalPath(),
            MbtiResultEnglishRuntimeCapabilityPreflightService::APPROVAL_SHA256,
        );
    }

    private function expectPreflightFailure(string $code): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($code.':');
    }

    private function runExecutorFixture(string $mode): Process
    {
        $fixtureRoot = storage_path('app/private/testing/mbti-result-runtime-capability-executor-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($fixtureRoot.'/vendor');
        File::ensureDirectoryExists($fixtureRoot.'/bootstrap');
        File::put($fixtureRoot.'/artisan', "#!/usr/bin/env php\n");
        File::put($fixtureRoot.'/vendor/autoload.php', '<?php require '.var_export(base_path('vendor/autoload.php'), true).';');
        File::put($fixtureRoot.'/bootstrap/app.php', <<<'PHP'
<?php

$mode = getenv('MBTI_PREFLIGHT_FIXTURE_MODE');
if ($mode === 'bootstrap_output') {
    echo 'fixture-bootstrap-output';
}

return new class($mode) {
    public function __construct(private readonly string|false $mode) {}

    public function make(string $abstract): object
    {
        if ($abstract === \Illuminate\Contracts\Console\Kernel::class) {
            return new class {
                public function bootstrap(): void {}
            };
        }

        return new class($this->mode) {
            public function __construct(private readonly string|false $mode) {}

            public function inspect(string $approvalPath, string $approvalSha256): array
            {
                if ($this->mode === 'runtime_exception') {
                    throw new \RuntimeException('fixture runtime detail must not escape');
                }

                if ($this->mode === 'invalid_json') {
                    return ['value' => INF];
                }

                return ['artifact' => 'fixture-receipt', 'status' => 'PASS'];
            }
        };
    }
};
PHP);

        try {
            $process = new Process([
                PHP_BINARY,
                base_path('scripts/mbti_result_english_runtime_capability_preflight.php'),
                '--backend-root='.$fixtureRoot,
                '--source-backend-root='.base_path(),
                '--control-plane-sha='.str_repeat('a', 40),
                '--active-revision=660280d00a57e58bd8bc76608e19de2492c03f53',
            ], base_path(), ['MBTI_PREFLIGHT_FIXTURE_MODE' => $mode]);
            $process->setTimeout(15);
            $process->run();

            return $process;
        } finally {
            File::deleteDirectory($fixtureRoot);
        }
    }
}

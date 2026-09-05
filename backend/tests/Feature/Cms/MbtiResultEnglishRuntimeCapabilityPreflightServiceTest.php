<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Services\Cms\MbtiResultEnglishRuntimeCapabilityPreflightService;
use App\Services\ContentPackResolver;
use App\Services\Mbti\MbtiResultPersonalizationService;
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

    public function test_it_uses_the_deployed_projection_renderer_without_constructing_its_dependency_graph(): void
    {
        $this->seedExactInactiveAuthority();
        app()->forgetInstance(MbtiResultEnglishRuntimeCapabilityPreflightService::class);
        app()->forgetInstance(MbtiResultPersonalizationService::class);
        app()->bind(MbtiResultPersonalizationService::class, static function (): never {
            throw new \RuntimeException('personalization container construction must not run');
        });

        ob_start();
        try {
            $this->inspect();
            self::fail('The synthetic projection must retain unresolved tokens.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('runtime_result_token_unresolved:', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame('', $output);
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

    public function test_the_executor_is_read_only_and_its_retired_workflow_stays_removed(): void
    {
        $approvalPath = MbtiResultEnglishRuntimeCapabilityPreflightService::defaultApprovalPath();
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        $executor = (string) File::get(base_path('scripts/mbti_result_english_runtime_capability_preflight.php'));
        $service = (string) File::get(base_path('app/Services/Cms/MbtiResultEnglishRuntimeCapabilityPreflightService.php'));
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-result-english-runtime-capability-preflight.yml'));

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
        self::assertStringContainsString('new \\ReflectionClass(MbtiResultPersonalizationService::class)', $service);
        self::assertStringContainsString('newInstanceWithoutConstructor()', $service);
        self::assertStringContainsString('runtime_projection_renderer_unavailable', $service);
        self::assertStringNotContainsString('private readonly MbtiResultPersonalizationService $personalizationService', $service);
        self::assertStringNotContainsString('app()->make(MbtiResultPersonalizationService::class)', $service);
        self::assertStringNotContainsString('fwrite(STDOUT', $service);
        self::assertStringNotContainsString('payload_json', $service);
        self::assertStringNotContainsString("DB::table('attempts')", $service);
        self::assertStringNotContainsString("DB::table('results')", $service);
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

    public function test_the_historical_stdout_diagnostic_keeps_closed_permissions_and_no_workflow(): void
    {
        $approvalPath = base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/runtime-capability-provenance-diagnostic-approval-2026-08-02.json');
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-result-english-runtime-capability-provenance-diagnostic.yml'));

        self::assertSame('31681314c75d04c9670e8f013362f609d02e2a053cbbff7977e5b5fa5a41e92b', hash('sha256', $approvalBytes));
        self::assertSame('runtime_capability_provenance_diagnostic', $approval['gate']);
        self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
        self::assertTrue($approval['permissions']['runtime_projection_readback_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['target_authority_readback_authorized', 'runtime_projection_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }

    }

    public function test_the_historical_phase_diagnostic_keeps_closed_permissions_and_no_workflow(): void
    {
        $approvalPath = base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/runtime-capability-phase-provenance-diagnostic-approval-2026-08-02.json');
        $approvalBytes = (string) File::get($approvalPath);
        $approval = json_decode($approvalBytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertFileDoesNotExist(base_path('../.github/workflows/mbti-result-english-runtime-capability-phase-provenance-diagnostic.yml'));

        self::assertSame('6e266ad254ca445ce4d60524af7deecebb78398cf5e65db34a62919f97aff6f7', hash('sha256', $approvalBytes));
        self::assertSame('runtime_capability_phase_provenance_diagnostic', $approval['gate']);
        self::assertTrue($approval['permissions']['target_authority_readback_authorized']);
        self::assertTrue($approval['permissions']['runtime_projection_readback_authorized']);
        foreach (array_diff(array_keys($approval['permissions']), ['target_authority_readback_authorized', 'runtime_projection_readback_authorized']) as $permission) {
            self::assertFalse($approval['permissions'][$permission], $permission.' must remain closed.');
        }

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

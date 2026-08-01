<?php

declare(strict_types=1);

namespace Tests\Feature\Cms;

use App\Services\Cms\MbtiResultEnglishRuntimeCapabilityPreflightService;
use App\Services\ContentPackResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
}

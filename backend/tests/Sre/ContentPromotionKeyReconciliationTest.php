<?php

declare(strict_types=1);

namespace Tests\Sre;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ContentPromotionKeyReconciliationTest extends TestCase
{
    private string $root;

    private string $bin;

    private const CONTROL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACTIVE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const OLD_KEY = 'old_content_promotion_key_1234567890';

    private const NEW_KEY = 'new_content_promotion_key_0987654321';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/promotion-key-'.bin2hex(random_bytes(6));
        $this->bin = $this->root.'/bin';
        $release = $this->root.'/releases/r1';
        File::ensureDirectoryExists($this->bin);
        File::ensureDirectoryExists($release.'/backend/bootstrap/cache');
        File::ensureDirectoryExists($this->root.'/shared/backend');
        File::ensureDirectoryExists($this->root.'/.dep');
        symlink($release, $this->root.'/current');
        File::put($release.'/REVISION', self::ACTIVE."\n");
        File::put($this->root.'/shared/backend/.env', "APP_ENV=production\nCONTENT_PROMOTION_AUTOMATION_KEY=".self::OLD_KEY."\nUNCHANGED=value\n");
        symlink($this->root.'/shared/backend/.env', $release.'/backend/.env');
        File::put($release.'/backend/bootstrap/cache/config.php', '<?php return '.var_export(['content_promotion' => ['workflow_identity_key' => self::OLD_KEY]], true).';');
        File::put($release.'/backend/artisan', <<<'PHP'
<?php
$target = getenv('APP_CONFIG_CACHE') ?: __DIR__.'/bootstrap/cache/config.php';
$key = getenv('CONTENT_PROMOTION_AUTOMATION_KEY') ?: '';
file_put_contents($target, '<?php return '.var_export(['content_promotion' => ['workflow_identity_key' => $key]], true).';');
PHP);
        File::put($this->bin.'/timeout', "#!/usr/bin/env bash\nshift\nexec \"\$@\"\n");
        chmod($this->bin.'/timeout', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_preflight_is_zero_write_and_apply_changes_only_key_and_config_cache(): void
    {
        $envBefore = hash_file('sha256', $this->root.'/shared/backend/.env');
        $envBytesBefore = File::get($this->root.'/shared/backend/.env');
        $cacheBefore = hash_file('sha256', $this->root.'/current/backend/bootstrap/cache/config.php');
        $preflightResult = $this->executeRunner();
        $preflight = $this->receipt($preflightResult);
        self::assertTrue($preflightResult->successful(), $preflightResult->errorOutput());
        self::assertSame('PASS_RECONCILIATION_REQUIRED', $preflight['status']);
        self::assertTrue($preflight['apply_ready']);
        self::assertSame($envBefore, hash_file('sha256', $this->root.'/shared/backend/.env'));
        self::assertSame($cacheBefore, hash_file('sha256', $this->root.'/current/backend/bootstrap/cache/config.php'));

        $runId = '123456';
        $attempt = '1';
        $phrase = sprintf(
            'I explicitly approve production fap-api content-promotion key reconciliation from preflight run %s attempt %s with control-plane SHA %s active SHA %s environment SHA256 %s config-cache SHA256 %s environment-key SHA256 %s runtime-key SHA256 %s source-key SHA256 %s; write only CONTENT_PROMOTION_AUTOMATION_KEY and rebuild only Laravel config cache, no deploy/symlink/migration/CMS/database/Career/cache-publication/queue/service-restart/publication/indexability/sitemap/llms/search/automatic rollback.',
            $runId, $attempt, self::CONTROL, self::ACTIVE, $preflight['env_sha256'], $preflight['config_cache_sha256'], $preflight['env_key_sha256'], $preflight['runtime_key_sha256'], hash('sha256', self::NEW_KEY),
        );
        $applyResult = $this->executeRunner([
            'MODE' => 'apply',
            'EXPECTED_ENV_SHA256' => $preflight['env_sha256'],
            'EXPECTED_CONFIG_CACHE_SHA256' => $preflight['config_cache_sha256'],
            'EXPECTED_ENV_KEY_SHA256' => $preflight['env_key_sha256'],
            'EXPECTED_RUNTIME_KEY_SHA256' => $preflight['runtime_key_sha256'],
            'PREFLIGHT_RUN_ID' => $runId,
            'PREFLIGHT_RUN_ATTEMPT' => $attempt,
            'AUTHORIZATION_PHRASE' => $phrase,
        ]);
        $apply = $this->receipt($applyResult);
        self::assertTrue($applyResult->successful(), $applyResult->output().$applyResult->errorOutput());
        self::assertSame('PASS_KEY_RECONCILED', $apply['status']);
        self::assertSame(1, $apply['env_setting_write_count']);
        self::assertSame(1, $apply['config_cache_rebuild_count']);
        $envBytesAfter = File::get($this->root.'/shared/backend/.env');
        self::assertSame(str_replace(self::OLD_KEY, self::NEW_KEY, $envBytesBefore), $envBytesAfter);
        self::assertStringNotContainsString(self::OLD_KEY, $envBytesAfter);
        self::assertStringNotContainsString(self::NEW_KEY, json_encode($apply, JSON_THROW_ON_ERROR));
    }

    public function test_scope_and_fail_closed_contracts_are_explicit(): void
    {
        $script = File::get(dirname(__DIR__, 2).'/scripts/deploy/content_promotion_key_reconciliation.sh');
        $workflow = File::get(dirname(__DIR__, 3).'/.github/workflows/content-promotion-key-reconciliation.yml');
        foreach (['ENV_FILE_DRIFT', 'CONFIG_CACHE_DRIFT', 'ENV_KEY_DRIFT', 'RUNTIME_KEY_DRIFT', 'AUTHORIZATION_PHRASE_MISMATCH', 'DEPLOY_LOCK_PRESENT'] as $guard) {
            self::assertStringContainsString($guard, $script);
        }
        self::assertStringContainsString('controlled-operation-gate', $workflow);
        self::assertStringContainsString('Validate immutable preflight receipt', $workflow);
        self::assertStringContainsString('.path == ".github/workflows/content-promotion-key-reconciliation.yml"', $workflow);
        self::assertStringNotContainsString('.name == "Content Promotion Key Reconciliation"', $workflow);
        self::assertStringContainsString('secrets.CONTENT_PROMOTION_AUTOMATION_KEY', $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        $combined = $script."\n".$workflow;
        foreach (['vendor/bin/dep deploy', 'deploy:symlink', 'php artisan migrate', 'queue:restart', 'supervisorctl', 'systemctl'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $combined);
        }
    }

    /** @param array<string, string> $extra */
    private function executeRunner(array $extra = []): ProcessResult
    {
        return Process::env(array_merge([
            'PATH' => $this->bin.':'.getenv('PATH'),
            'MODE' => 'preflight',
            'DEPLOY_PATH' => $this->root,
            'EXPECTED_CONTROL_PLANE_SHA' => self::CONTROL,
            'EXPECTED_ACTIVE_SHA' => self::ACTIVE,
            'EXPECTED_SOURCE_KEY_SHA256' => hash('sha256', self::NEW_KEY),
            'CONTENT_PROMOTION_AUTOMATION_KEY' => self::NEW_KEY,
        ], $extra))->path(dirname(__DIR__, 2))->run(['bash', 'scripts/deploy/content_promotion_key_reconciliation.sh']);
    }

    /** @return array<string, mixed> */
    private function receipt(ProcessResult $result): array
    {
        return json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StagingSitemapCronTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fap-staging-sitemap-'.bin2hex(random_bytes(6));
        $release = $this->directory.'/deploy/releases/1';
        mkdir($release.'/backend/scripts/deploy', 0700, true);
        symlink('releases/1', $this->directory.'/deploy/current');
        file_put_contents($release.'/REVISION', str_repeat('a', 40));
        file_put_contents($release.'/backend/artisan', '<?php');
        file_put_contents($release.'/backend/scripts/deploy/verify_sitemap_source_cache_refresh.sh', '# warmer');
        $this->executable('php', '#!/bin/bash'."\n".'printf "Application environment [%s].\\n" "${FAKE_ENVIRONMENT:-staging}"');
        $this->executable('crontab', <<<'BASH'
#!/bin/bash
set -euo pipefail
if [[ "$1" == -l ]]; then
  [[ "${FAKE_READ_ERROR:-false}" != true ]] || { echo 'permission denied' >&2; exit 1; }
  [[ -f "$FAKE_CRON_FILE" ]] || { echo 'no crontab for fixture' >&2; exit 1; }
  cat "$FAKE_CRON_FILE"
else
  [[ "${FAKE_WRITE_ERROR:-false}" != true ]] || exit 1
  cp "$1" "$FAKE_CRON_FILE"
fi
BASH);
    }

    protected function tearDown(): void
    {
        (new Process(['find', $this->directory, '-depth', '-delete']))->mustRun();
    }

    public function test_install_is_idempotent_and_preserves_unrelated_cron_without_starting_business_scheduler(): void
    {
        $original = "MAILTO=ops@example.test\n3 * * * * /bin/true\n";
        file_put_contents($this->directory.'/cron', $original);
        $first = $this->runInstaller();
        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $installed = (string) file_get_contents($this->directory.'/cron');
        self::assertStringStartsWith($original, $installed);
        self::assertStringContainsString('*/5 * * * * SITEMAP_SOURCE_WARM_PHP_BIN=', $installed);
        self::assertStringContainsString('/deploy/current/backend/artisan', $installed);
        self::assertStringContainsString('/deploy/current/backend/scripts/deploy/verify_sitemap_source_cache_refresh.sh', $installed);
        self::assertStringNotContainsString('schedule:run', $installed);
        self::assertStringNotContainsString('schedule:work', $installed);
        self::assertTrue($this->runInstaller()->isSuccessful());
        self::assertSame($installed, file_get_contents($this->directory.'/cron'));
    }

    public function test_empty_crontab_can_be_initialized(): void
    {
        $process = $this->runInstaller();
        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('interval_seconds=300', $process->getOutput());
    }

    public function test_production_environment_is_rejected_before_writing(): void
    {
        $process = $this->runInstaller(['FAKE_ENVIRONMENT' => 'production']);
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('staging_environment_required', $process->getErrorOutput());
        self::assertFileDoesNotExist($this->directory.'/cron');
    }

    public function test_unreadable_crontab_is_not_replaced_with_an_empty_inventory(): void
    {
        $original = 'preserve me';
        file_put_contents($this->directory.'/cron', $original);
        $process = $this->runInstaller(['FAKE_READ_ERROR' => 'true']);
        self::assertFalse($process->isSuccessful());
        self::assertSame($original, file_get_contents($this->directory.'/cron'));
    }

    public function test_malformed_or_duplicate_owned_blocks_are_preserved_and_rejected(): void
    {
        foreach ([
            "# BEGIN fap-api staging sitemap warmer\nunterminated\n",
            "# END fap-api staging sitemap warmer\n# BEGIN fap-api staging sitemap warmer\n",
            str_repeat("# BEGIN fap-api staging sitemap warmer\n# END fap-api staging sitemap warmer\n", 2),
        ] as $original) {
            file_put_contents($this->directory.'/cron', $original);
            $process = $this->runInstaller();
            self::assertFalse($process->isSuccessful());
            self::assertStringContainsString('managed_block_invalid', $process->getErrorOutput());
            self::assertSame($original, file_get_contents($this->directory.'/cron'));
        }
    }

    public function test_failed_install_is_reported_and_preserves_existing_cron(): void
    {
        file_put_contents($this->directory.'/cron', 'preserve me');
        $process = $this->runInstaller(['FAKE_WRITE_ERROR' => 'true']);
        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('crontab_install_failed', $process->getErrorOutput());
        self::assertSame('preserve me', file_get_contents($this->directory.'/cron'));
    }

    private function executable(string $name, string $source): void
    {
        file_put_contents($this->directory.'/'.$name, $source);
        chmod($this->directory.'/'.$name, 0700);
    }

    private function runInstaller(array $environment = []): Process
    {
        $process = new Process([
            '/bin/bash', dirname(__DIR__, 2).'/scripts/deploy/install_staging_sitemap_cron.sh',
            '--php-bin='.$this->directory.'/php',
            '--crontab='.$this->directory.'/crontab',
            '--deploy-path='.$this->directory.'/deploy',
        ], env: [...$environment, 'FAKE_CRON_FILE' => $this->directory.'/cron']);
        $process->run();

        return $process;
    }
}

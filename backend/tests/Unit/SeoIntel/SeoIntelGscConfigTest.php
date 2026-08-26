<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SeoIntelGscConfigTest extends TestCase
{
    public function test_sync_defaults_on_when_live_readonly_gsc_is_enabled(): void
    {
        $this->assertTrue($this->configuredSyncEnabled(false));
    }

    public function test_explicit_environment_kill_switch_overrides_the_live_default(): void
    {
        $this->assertFalse($this->configuredSyncEnabled('false'));
    }

    private function configuredSyncEnabled(string|false $syncValue): bool
    {
        $environment = getenv();
        unset(
            $environment['SEO_INTEL_GSC_ENABLED'],
            $environment['SEO_INTEL_GSC_LIVE_API_ENABLED'],
            $environment['SEO_INTEL_GSC_SYNC_ENABLED'],
        );
        $environment['SEO_INTEL_GSC_ENABLED'] = 'true';
        $environment['SEO_INTEL_GSC_LIVE_API_ENABLED'] = 'true';
        if ($syncValue !== false) {
            $environment['SEO_INTEL_GSC_SYNC_ENABLED'] = $syncValue;
        }

        $backendRoot = dirname(__DIR__, 3);
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                'require "vendor/autoload.php";'
                    .'$config = require "config/seo_intel.php";'
                    .'echo json_encode($config["gsc_sync_enabled"]);',
            ],
            $backendRoot,
            $environment,
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}

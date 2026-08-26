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
        $backendRoot = dirname(__DIR__, 3);
        $syncEnvironment = $syncValue === false
            ? 'unset($_ENV["SEO_INTEL_GSC_SYNC_ENABLED"], $_SERVER["SEO_INTEL_GSC_SYNC_ENABLED"]);'
                .'putenv("SEO_INTEL_GSC_SYNC_ENABLED");'
            : '$_ENV["SEO_INTEL_GSC_SYNC_ENABLED"] = "false";'
                .'$_SERVER["SEO_INTEL_GSC_SYNC_ENABLED"] = "false";'
                .'putenv("SEO_INTEL_GSC_SYNC_ENABLED=false");';
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                '$_ENV["SEO_INTEL_GSC_ENABLED"] = "true";'
                    .'$_SERVER["SEO_INTEL_GSC_ENABLED"] = "true";'
                    .'putenv("SEO_INTEL_GSC_ENABLED=true");'
                    .'$_ENV["SEO_INTEL_GSC_LIVE_API_ENABLED"] = "true";'
                    .'$_SERVER["SEO_INTEL_GSC_LIVE_API_ENABLED"] = "true";'
                    .'putenv("SEO_INTEL_GSC_LIVE_API_ENABLED=true");'
                    .$syncEnvironment
                    .'require "vendor/autoload.php";'
                    .'$config = require "config/seo_intel.php";'
                    .'echo json_encode($config["gsc_sync_enabled"]);',
            ],
            $backendRoot,
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}

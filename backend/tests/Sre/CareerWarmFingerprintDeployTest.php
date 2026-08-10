<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class CareerWarmFingerprintDeployTest extends TestCase
{
    public function test_deploy_uses_fingerprint_refresh_and_exposes_both_timing_outcomes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $this->assertIsString($source);

        $this->assertStringContainsString(
            'career:warm-public-authority-cache --refresh-if-changed',
            $source,
        );
        $this->assertStringContainsString(
            "task('career:public-authority-cache-verified_unchanged'",
            $source,
        );
        $this->assertStringContainsString(
            "task('career:public-authority-cache-rebuilt'",
            $source,
        );
        $this->assertStringContainsString(
            "invoke('career:public-authority-cache-'.\$match[1])",
            $source,
        );
        $this->assertStringContainsString(
            'career:warm-public-authority-cache --directory-only --json --no-interaction --no-ansi',
            $source,
        );
        $this->assertStringContainsString(
            "after('career:warm-public-authority-cache', 'career:rebuild-directory-after-detail-repair')",
            $source,
        );
        $this->assertStringContainsString(
            "after('seo:warm-sitemap-source-cache', 'guard:career-discoverability-post-sitemap')",
            $source,
        );
        $this->assertStringContainsString('post_sitemap', $source);
    }
}

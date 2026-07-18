<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerDetailStagingCacheRepairWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_staging_only_and_locks_the_exact_three_missing_zh_cn_pointers(): void
    {
        $source = (string) file_get_contents(base_path('../.github/workflows/career-detail-staging-cache-repair.yml'));

        $this->assertStringContainsString('DEPLOY_PATH: "/var/www/fap-api-staging"', $source);
        $this->assertStringNotContainsString('/var/www/fap-api-production', $source);
        $this->assertStringContainsString(
            'TARGET_SLUGS: "library-technicians,purchasing-managers-buyers-and-purchasing-agents,tire-builders"',
            $source,
        );
        $this->assertStringContainsString('TARGET_LOCALE: "zh-CN"', $source);
        $this->assertStringContainsString('--job-detail-only', $source);
        $this->assertStringNotContainsString('--forget-job-detail', $source);
        $this->assertStringNotContainsString('--repair-missing', $source);
    }

    #[Test]
    public function workflow_keeps_verification_read_only_and_repair_revision_bound(): void
    {
        $source = (string) file_get_contents(base_path('../.github/workflows/career-detail-staging-cache-repair.yml'));

        $this->assertStringContainsString('verify_only refuses an operator approval phrase.', $source);
        $this->assertStringContainsString('expected_active_revision must be a lowercase 40-character revision.', $source);
        $this->assertStringContainsString('test ! -e \'$DEPLOY_PATH/.dep/deploy.lock\'', $source);
        $this->assertStringContainsString(
            'cache-only, no CMS/DB/publication/indexability/sitemap/llms/search.',
            $source,
        );
        $this->assertSame(2, substr_count($source, 'career:verify-job-detail-cache-coverage --verify-only'));
        $this->assertSame(1, substr_count($source, 'career:warm-public-authority-cache'));
    }
}

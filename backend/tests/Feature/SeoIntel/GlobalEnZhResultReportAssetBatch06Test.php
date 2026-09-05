<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResultReportAssetBatch06Test extends TestCase
{
    #[Test]
    public function retired_batch_package_cannot_be_reintroduced_as_result_authority(): void
    {
        // The private-result retirement removed the mixed historical prose package.
        $this->assertFileDoesNotExist(base_path('docs/seo/import-packages/global-en-zh-result-report-asset-batch-06.import.v1.json'));
        $summary = json_decode((string) file_get_contents(base_path('docs/seo/generated/global-en-zh-result-report-asset-batch-06.v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('global-en-zh-result-report-asset-batch-06.v1', $summary['schema_version']);
        $this->assertSame(23, $summary['total_items']);
        $this->assertSame(23, $summary['human_review_required_count']);
        $this->assertSame(23, $summary['no_zh_fallback_required_count']);
        foreach (['runtime_active_count', 'sitemap_eligible_count', 'llms_eligible_count', 'search_channel_eligible_count', 'pseo_eligible_count'] as $field) {
            $this->assertSame(0, $summary[$field], $field);
        }
        foreach (['no_cms_mutation', 'no_publish', 'no_deploy', 'no_search_channel_action', 'no_url_submission', 'no_pseo_generation', 'no_frontend_fallback_authority'] as $field) {
            $this->assertTrue($summary[$field], $field);
        }
    }
}

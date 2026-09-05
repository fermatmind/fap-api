<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhResultReportHumanReviewImport06Test extends TestCase
{
    #[Test]
    public function retired_review_packet_cannot_be_used_to_activate_current_result_authority(): void
    {
        $this->assertFileDoesNotExist(base_path('docs/seo/generated/global-en-zh-result-report-human-review-import-06.v1.json'));
        $this->assertFileDoesNotExist(base_path('docs/seo/import-packages/global-en-zh-result-report-asset-batch-06.import.v1.json'));
        $report = (string) file_get_contents(base_path('docs/seo/global-en-zh-result-report-human-review-import-06.md'));
        $this->assertStringContainsString('no item is publish-ready or runtime-activation-ready', $report);
        $this->assertStringContainsString('NO_GO_blocked_authority_export_required', $report);
    }
}

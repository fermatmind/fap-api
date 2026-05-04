<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerValidateReleaseGateCommandTest extends TestCase
{
    #[Test]
    public function it_validates_live_release_gate_read_only_without_mutation(): void
    {
        Http::fake([
            'https://example.test/zh/career/jobs/actuaries' => Http::response($this->html('zh', 'actuaries'), 200),
        ]);

        $exitCode = Artisan::call('career:validate-release-gate', [
            '--slugs' => 'actuaries',
            '--locales' => 'zh',
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('pass', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['sitemap_changed']);
        $this->assertFalse($report['llms_changed']);
        $this->assertSame('pass', $report['items'][0]['Release_Gate_Result']);
        $this->assertTrue($report['items'][0]['Canonical_OK']);
        $this->assertTrue($report['items'][0]['CTA_OK']);
        $this->assertTrue($report['items'][0]['Product_Absent']);
        $this->assertTrue($report['items'][0]['Forbidden_Absent']);
    }

    #[Test]
    public function it_blocks_product_schema_and_forbidden_public_fields(): void
    {
        Http::fake([
            'https://example.test/en/career/jobs/actuaries' => Http::response(
                $this->html('en', 'actuaries', extra: '<script type="application/ld+json">{"@type":"Product"}</script> release_gates'),
                200,
            ),
        ]);

        Artisan::call('career:validate-release-gate', [
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--base-url' => 'https://example.test',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('no_go', $report['decision']);
        $this->assertSame('blocked', $report['items'][0]['Release_Gate_Result']);
        $this->assertFalse($report['items'][0]['Product_Absent']);
        $this->assertFalse($report['items'][0]['Forbidden_Absent']);
        $this->assertContains('release_gate', $report['items'][0]['Forbidden_Found']);
        $this->assertContains('release_gates', $report['items'][0]['Forbidden_Found']);
    }

    private function html(string $locale, string $slug, string $extra = ''): string
    {
        return '<!doctype html><html><head>'
            .'<link rel="canonical" href="https://example.test/'.$locale.'/career/jobs/'.$slug.'">'
            .'<meta name="robots" content="index,follow">'
            .'<script type="application/ld+json">{"@type":"FAQPage"}</script>'
            .'</head><body>'
            .'FAQ <a href="/'.$locale.'/tests/holland-career-interest-test-riasec?target_action=start_riasec_test&entry_surface=career_job_detail&subject_key='.$slug.'">test</a>'
            .$extra
            .'</body></html>';
    }
}

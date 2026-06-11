<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Console\Commands\CareerDisplayAssetPublishGate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerDisplayAssetPublishGateTest extends TestCase
{
    #[Test]
    public function valid_display_asset_payload_passes_publish_gate(): void
    {
        $gate = new CareerDisplayAssetPublishGate;
        $componentOrder = ['hero', 'primary_cta', 'final_cta'];

        $report = $gate->validatePayload(
            'data-scientists',
            $componentOrder,
            $this->pagePayload($componentOrder),
            ['faq_page' => ['zh' => ['mainEntity' => []], 'en' => ['mainEntity' => []]]],
            ['release_gates' => ['sitemap' => false, 'llms' => false]],
        );

        $this->assertSame('pass', $report['decision']);
        $this->assertSame([], $report['errors']);
        $this->assertSame([], $report['module_parity']['zh_missing']);
        $this->assertTrue($report['source_page_type_valid']);
        $this->assertTrue($report['product_schema_absent']);
        $this->assertTrue($report['reviewed_chinese_valid']);
    }

    #[Test]
    public function invalid_display_asset_payload_is_blocked_before_publish(): void
    {
        $gate = new CareerDisplayAssetPublishGate;
        $componentOrder = ['hero', 'primary_cta', 'final_cta', 'source_card'];
        $payload = $this->pagePayload($componentOrder);
        unset($payload['page']['zh']['source_card']);
        $payload['page']['zh']['hero']['h1'] = 'Data Scientists';
        $payload['page']['zh']['hero']['title'] = 'Data Scientists';
        $payload['page']['zh']['primary_cta']['source_page_type'] = 'career_index';

        $report = $gate->validatePayload(
            'data-scientists',
            $componentOrder,
            $payload,
            ['@type' => 'Product', 'name' => 'Data Scientists'],
        );

        $this->assertSame('fail', $report['decision']);
        $this->assertContains('source_card', $report['module_parity']['zh_missing']);
        $this->assertFalse($report['source_page_type_valid']);
        $this->assertFalse($report['product_schema_absent']);
        $this->assertFalse($report['reviewed_chinese_valid']);
        $this->assertStringContainsString('zh page is missing component_order modules', implode(' ', $report['errors']));
        $this->assertStringContainsString('Product schema is forbidden', implode(' ', $report['errors']));
    }

    /**
     * @param  list<string>  $componentOrder
     * @return array<string, mixed>
     */
    private function pagePayload(array $componentOrder): array
    {
        $page = [
            'en' => [],
            'zh' => [],
        ];

        foreach ($componentOrder as $module) {
            $page['en'][$module] = ['body' => 'English '.$module];
            $page['zh'][$module] = ['body' => '中文 '.$module];
        }

        foreach (['en', 'zh'] as $locale) {
            $page[$locale]['primary_cta'] = [
                'source_page_type' => 'career_job_detail',
            ];
            $page[$locale]['final_cta'] = [
                'source_page_type' => 'career_job_detail',
            ];
        }

        $page['en']['hero'] = [
            'h1' => 'Data Scientists',
            'title' => 'Data Scientists',
        ];
        $page['zh']['hero'] = [
            'h1' => '数据科学家',
            'title' => '数据科学家',
        ];

        return ['page' => $page];
    }
}

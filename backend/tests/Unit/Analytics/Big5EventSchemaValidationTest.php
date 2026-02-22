<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Services\Analytics\Big5EventSchema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class Big5EventSchemaValidationTest extends TestCase
{
    public function test_valid_big5_scored_event_passes_validation(): void
    {
        $schema = new Big5EventSchema();

        $validated = $schema->validate('big5_scored', $this->validMeta([
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_prod_all_18-60',
            'quality_level' => 'A',
            'pack_version' => 'v1',
            'norms_version' => '2026Q1_prod_v1',
        ]));

        $this->assertSame('BIG5_OCEAN', $validated['scale_code']);
        $this->assertSame('2026Q1_prod_v1', $validated['norms_version']);
    }

    public function test_missing_required_value_throws_exception(): void
    {
        $schema = new Big5EventSchema();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing BIG5 required meta value: norms_version');

        $schema->validate('big5_scored', $this->validMeta([
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_prod_all_18-60',
            'quality_level' => 'A',
            'pack_version' => 'v1',
            'norms_version' => '',
        ]));
    }

    public function test_unknown_big5_event_throws_exception(): void
    {
        $schema = new Big5EventSchema();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported BIG5 event code');

        $schema->validate('big5_unknown_event', $this->validMeta());
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validMeta(array $overrides = []): array
    {
        return array_merge([
            'scale_code' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'manifest_hash' => 'manifest_abc',
            'norms_version' => '2026Q1_prod_v1',
            'quality_level' => 'A',
            'variant' => 'free',
            'locked' => true,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'norms_status' => 'CALIBRATED',
            'norm_group_id' => 'zh-CN_prod_all_18-60',
            'sections_count' => 6,
            'sku_code' => 'SKU_BIG5_FULL_REPORT_299',
            'offer_code' => 'SKU_BIG5_FULL_REPORT_299',
            'provider' => 'billing',
            'provider_event_id' => 'evt_1',
            'order_no' => 'ord_1',
            'webhook_status' => 'processed',
        ], $overrides);
    }
}

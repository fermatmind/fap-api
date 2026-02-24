<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetsBaseUrlFromCdnMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_iq_raven_questions_return_30_inline_svg_items(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\Pr16IqRavenDemoSeeder']);

        $response = $this->getJson('/api/v0.3/scales/IQ_RAVEN/questions', [
            'X-Region' => 'US',
            'Accept-Language' => 'en-US',
        ]);

        $response->assertStatus(200);

        $doc = $response->json('questions');
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $this->assertCount(30, $items);

        $sectionCounts = [
            'matrix' => 0,
            'odd' => 0,
            'series' => 0,
        ];

        foreach ($items as $item) {
            $sectionCode = (string) ($item['section_code'] ?? '');
            $this->assertArrayHasKey($sectionCode, $sectionCounts);
            $sectionCounts[$sectionCode]++;

            $stemPaths = data_get($item, 'stem.svg.paths', []);
            $this->assertIsArray($stemPaths);
            $this->assertNotEmpty($stemPaths);

            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $expectedOptionCount = $sectionCode === 'series' ? 6 : 5;
            $this->assertCount($expectedOptionCount, $options);

            foreach ($options as $option) {
                $optionPaths = data_get($option, 'svg.paths', []);
                $this->assertIsArray($optionPaths);
                $this->assertNotEmpty($optionPaths);
            }
        }

        $this->assertSame(9, $sectionCounts['matrix']);
        $this->assertSame(10, $sectionCounts['odd']);
        $this->assertSame(11, $sectionCounts['series']);
    }
}

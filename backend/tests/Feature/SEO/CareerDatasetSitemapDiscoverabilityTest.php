<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerDatasetSitemapDiscoverabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_backend_sitemap_generator_includes_dataset_hub_and_method_urls(): void
    {
        config([
            'app.frontend_url' => 'https://www.fermatmind.com',
        ]);

        $payload = app(SitemapGenerator::class)->generate();
        $xml = (string) ($payload['xml'] ?? '');

        $this->assertStringContainsString('https://www.fermatmind.com/datasets/occupations', $xml);
        $this->assertStringContainsString('https://www.fermatmind.com/datasets/occupations/method', $xml);
    }
}

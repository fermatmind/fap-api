<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFivePackIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_pack_structure_and_direction_are_valid(): void
    {
        $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);

        $facetRows = $loader->readCsvWithLines($loader->rawPath('facet_map.csv', 'v1'));
        $questionsRows = $loader->readCsvWithLines($loader->rawPath('questions_big5_bilingual.csv', 'v1'));

        $facetCount = [];
        $domainCount = [];
        foreach ($facetRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $facet = strtoupper((string) ($row['facet_code'] ?? ''));
            $domain = strtoupper((string) ($row['domain_code'] ?? ''));
            $facetCount[$facet] = ($facetCount[$facet] ?? 0) + 1;
            $domainCount[$domain] = ($domainCount[$domain] ?? 0) + 1;
        }

        foreach ($facetCount as $count) {
            $this->assertSame(4, $count);
        }
        foreach ($domainCount as $count) {
            $this->assertSame(24, $count);
        }

        foreach ($questionsRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $direction = (int) ($row['direction'] ?? 0);
            $this->assertContains($direction, [1, -1]);
        }
    }
}

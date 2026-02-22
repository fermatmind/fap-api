<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveNormsCoverageGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_required_norm_groups_have_full_coverage(): void
    {
        $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);
        $rows = $loader->readCsvWithLines($loader->rawPath('norm_stats.csv', 'v1'));

        $coverage = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $group = strtolower(trim((string) ($row['group_id'] ?? '')));
            $level = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $code = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            if ($group === '' || $level === '' || $code === '') {
                continue;
            }

            $coverage[$group][$level][$code] = true;
        }

        $requiredGroups = array_map(
            static fn (string $group): string => strtolower($group),
            (array) config('big5_norms.resolver.required_groups', ['en_johnson_all_18-60', 'zh-CN_prod_all_18-60'])
        );
        foreach ($requiredGroups as $groupKey) {
            $domainCount = count(array_keys((array) ($coverage[$groupKey]['domain'] ?? [])));
            $facetCount = count(array_keys((array) ($coverage[$groupKey]['facet'] ?? [])));
            $this->assertSame(5, $domainCount, "{$groupKey} domain coverage must be 5/5");
            $this->assertSame(30, $facetCount, "{$groupKey} facet coverage must be 30/30");
        }
    }
}

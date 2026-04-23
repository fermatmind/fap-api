<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class FacetGlossaryCoverageTest extends TestCase
{
    public function test_it_covers_all_thirty_big_five_facets_with_user_facing_copy(): void
    {
        $registry = app(RegistryLoader::class)->load();

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys($registry['facet_glossary']));

        $codes = [];
        foreach ($registry['facet_glossary'] as $traitCode => $pack) {
            $this->assertSame($traitCode, $pack['trait_code']);
            $this->assertCount(6, $pack['facets']);

            foreach ($pack['facets'] as $facet) {
                foreach (['facet_code', 'label_zh', 'gloss', 'daily_meaning', 'why_it_matters'] as $field) {
                    $this->assertNotSame('', trim((string) $facet[$field]), "{$facet['facet_code']} missing {$field}");
                }

                $codes[] = $facet['facet_code'];
            }
        }

        $this->assertCount(30, $codes);
        $this->assertCount(30, array_unique($codes));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\RegistryValidator;
use Tests\TestCase;

final class FacetPrecisionRegistryCoverageTest extends TestCase
{
    public function test_it_covers_all_domains_with_valid_precision_rules(): void
    {
        $registry = app(RegistryLoader::class)->load();

        $errors = app(RegistryValidator::class)->validate($registry);
        $this->assertSame([], $errors);

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys($registry['facet_precision']));

        $total = 0;
        foreach ($registry['facet_precision'] as $traitCode => $pack) {
            $this->assertSame($traitCode, $pack['trait_code']);
            $this->assertGreaterThanOrEqual(4, count($pack['rules']));
            $total += count($pack['rules']);

            foreach ($pack['rules'] as $rule) {
                $this->assertSame(['facet_details'], $rule['section_targets'], $rule['rule_id']);
                $this->assertGreaterThanOrEqual(20, $rule['when']['delta_abs_min'], $rule['rule_id']);
                $this->assertTrue($rule['when']['cross_band_required'], $rule['rule_id']);

                foreach (['title', 'body', 'why_it_matters'] as $field) {
                    $this->assertNotSame('', trim((string) $rule['copy'][$field]), "{$rule['rule_id']} missing {$field}");
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $total);
        $this->assertLessThanOrEqual(24, $total);
        $this->assertSame(22, $total);
    }
}

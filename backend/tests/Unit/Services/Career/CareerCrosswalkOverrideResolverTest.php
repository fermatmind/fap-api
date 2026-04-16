<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Operations\CareerCrosswalkOverrideResolver;
use Tests\TestCase;

final class CareerCrosswalkOverrideResolverTest extends TestCase
{
    public function test_it_applies_only_approved_patch_overrides_and_keeps_original_truth_otherwise(): void
    {
        $resolved = app(CareerCrosswalkOverrideResolver::class)->resolve(
            subjects: [
                [
                    'canonical_slug' => 'registered-nurses',
                    'crosswalk_mode' => 'local_heavy_interpretation',
                ],
                [
                    'canonical_slug' => 'software-developers',
                    'crosswalk_mode' => 'family_proxy',
                ],
                [
                    'canonical_slug' => 'data-scientists',
                    'crosswalk_mode' => 'exact',
                ],
            ],
            approvedPatchesBySlug: [
                'registered-nurses' => [
                    'patch_key' => 'patch-rn-v2',
                    'target_kind' => 'occupation',
                    'target_slug' => 'registered-nurses',
                    'crosswalk_mode_override' => 'exact',
                ],
                'software-developers' => [
                    'patch_key' => 'patch-sw-v1',
                    'target_kind' => 'family',
                    'target_slug' => 'software-engineering-family',
                    'crosswalk_mode_override' => 'trust_inheritance',
                ],
            ],
        );

        $this->assertSame('career_crosswalk_override_resolver', $resolved['resolver_kind']);
        $this->assertSame('career.crosswalk.override_resolver.v1', $resolved['resolver_version']);
        $this->assertSame(3, data_get($resolved, 'counts.total'));
        $this->assertSame(2, data_get($resolved, 'counts.override_applied'));
        $this->assertSame(1, data_get($resolved, 'counts.kept_original'));

        $items = collect($resolved['resolved'] ?? [])->keyBy('subject_slug');

        $rn = $items->get('registered-nurses');
        $this->assertIsArray($rn);
        $this->assertSame('local_heavy_interpretation', $rn['original_crosswalk_mode']);
        $this->assertSame('exact', $rn['resolved_crosswalk_mode']);
        $this->assertSame('occupation', $rn['resolved_target_kind']);
        $this->assertTrue((bool) $rn['override_applied']);
        $this->assertSame('patch-rn-v2', $rn['applied_patch_key']);

        $sw = $items->get('software-developers');
        $this->assertIsArray($sw);
        $this->assertSame('family_proxy', $sw['original_crosswalk_mode']);
        $this->assertSame('trust_inheritance', $sw['resolved_crosswalk_mode']);
        $this->assertSame('family', $sw['resolved_target_kind']);
        $this->assertSame('software-engineering-family', $sw['resolved_target_slug']);

        $ds = $items->get('data-scientists');
        $this->assertIsArray($ds);
        $this->assertSame('exact', $ds['resolved_crosswalk_mode']);
        $this->assertFalse((bool) $ds['override_applied']);
        $this->assertNull($ds['applied_patch_key']);
    }
}

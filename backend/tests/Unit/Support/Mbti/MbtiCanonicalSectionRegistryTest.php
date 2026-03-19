<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mbti;

use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use InvalidArgumentException;
use Tests\TestCase;

final class MbtiCanonicalSectionRegistryTest extends TestCase
{
    public function test_registry_exposes_the_pr1_canonical_section_keys_and_variants(): void
    {
        $definitions = MbtiCanonicalSectionRegistry::definitions();

        $this->assertArrayHasKey('letters_intro', $definitions);
        $this->assertArrayHasKey('trait_overview', $definitions);
        $this->assertArrayHasKey('career.preferred_roles', $definitions);
        $this->assertArrayHasKey('career.collaboration_fit', $definitions);
        $this->assertArrayHasKey('career.work_environment', $definitions);
        $this->assertArrayHasKey('career.next_step', $definitions);
        $this->assertArrayHasKey('traits.why_this_type', $definitions);
        $this->assertArrayHasKey('traits.close_call_axes', $definitions);
        $this->assertArrayHasKey('traits.adjacent_type_contrast', $definitions);
        $this->assertArrayHasKey('growth.stability_confidence', $definitions);
        $this->assertArrayHasKey('growth.motivators', $definitions);
        $this->assertArrayHasKey('relationships.rel_risks', $definitions);

        $this->assertSame(
            MbtiCanonicalSectionRegistry::RENDER_VARIANT_TRAIT_DIMENSION_GRID,
            $definitions['trait_overview']['render_variant']
        );
        $this->assertSame(
            MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREFERRED_ROLE_LIST,
            $definitions['career.preferred_roles']['render_variant']
        );
    }

    public function test_premium_teaser_blocks_are_fixed_to_premium_teaser_render_variant(): void
    {
        foreach (MbtiCanonicalSectionRegistry::premiumTeaserKeys() as $sectionKey) {
            $definition = MbtiCanonicalSectionRegistry::definition($sectionKey);

            $this->assertSame(MbtiCanonicalSectionRegistry::BUCKET_PREMIUM_TEASER, $definition['bucket']);
            $this->assertSame(MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER, $definition['render_variant']);
            $this->assertTrue(MbtiCanonicalSectionRegistry::isPremiumTeaser($sectionKey));
        }
    }

    public function test_trait_overview_axis_aliases_are_normalized_explicitly(): void
    {
        $this->assertSame('SN', MbtiCanonicalSectionRegistry::normalizeTraitAxisCode('NS'));
        $this->assertSame('TF', MbtiCanonicalSectionRegistry::normalizeTraitAxisCode('FT'));
        $this->assertSame('AT', MbtiCanonicalSectionRegistry::normalizeTraitAxisCode('AT'));

        $this->expectException(InvalidArgumentException::class);
        MbtiCanonicalSectionRegistry::normalizeTraitAxisCode('XY');
    }
}

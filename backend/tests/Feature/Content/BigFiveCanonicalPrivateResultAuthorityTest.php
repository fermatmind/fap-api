<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePrivateResultCompileService;
use Tests\TestCase;

final class BigFiveCanonicalPrivateResultAuthorityTest extends TestCase
{
    public function test_registry_is_complete_canonical_chinese_private_result_source(): void
    {
        $root = base_path('content_packs/BIG5_OCEAN/v2/registry');
        $this->assertDirectoryExists($root);
        $this->assertDirectoryDoesNotExist(base_path('content_packs/BIG5_OCEAN/v3'));
        $this->assertDirectoryDoesNotExist(base_path('content_packs/BIG5_OCEAN/v4'));

        $compiled = app(BigFivePrivateResultCompileService::class)->compile();
        $coverage = $compiled['manifest']['coverage'];

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], $coverage['traits']);
        $this->assertSame(['low', 'mid', 'high'], $coverage['bands']);
        $this->assertSame(30, $coverage['facet_count']);
        $this->assertSame(5, $coverage['synergy_count']);
        $this->assertSame(
            ['workplace', 'relationships', 'stress_recovery', 'personal_growth'],
            $coverage['action_scenarios']
        );
        $this->assertContains('near_boundary', array_keys(array_filter($coverage)));
        $this->assertSame(['valid', 'low_quality', 'norm_unavailable'], $coverage['quality_states']);
        $this->assertSame(['free', 'full'], $coverage['access_levels']);
        $this->assertSame(['faq', 'lifecycle', 'share', 'pdf', 'print', 'history', 'compare'], $coverage['secondary_surfaces']);

        $assets = $compiled['payload']['assets'];
        $this->assertSame(
            'fap.big5.private_result.secondary_surfaces.v1',
            $assets['surfaces/secondary.json']['schema']
        );
    }
}

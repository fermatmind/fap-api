<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerDetailReadyTargetAuthority;
use PHPUnit\Framework\TestCase;

final class CareerDetailReadyTargetAuthorityTest extends TestCase
{
    public function test_target_authority_separates_1048_from_2786_partition_accounting(): void
    {
        $authority = new CareerDetailReadyTargetAuthority;
        $target = $authority->target();

        $this->assertSame('career_detail_ready_1048_target_authority.v1', $target['schema_version']);
        $this->assertSame('detail_ready_1048', $target['target_key']);
        $this->assertSame(30, $target['current_public_detail_total']);
        $this->assertSame(1048, $target['target_public_total']);
        $this->assertSame(1018, $target['ready_not_currently_public_delta']);
        $this->assertSame(2096, $target['locale_policy']['expected_locale_rows']);
        $this->assertFalse($target['partition_boundary']['is_2786_partition_accounting']);
        $this->assertTrue($target['partition_boundary']['raw_assets_are_not_publication_authority']);
        $this->assertTrue($target['partition_boundary']['do_not_publish_excluded_raw_assets']);
        $this->assertContains('software-developers', $target['manual_hold_policy']['slugs']);
        $this->assertTrue($target['manual_hold_policy']['must_not_force_enable']);
        $this->assertTrue($target['cn_proxy_policy']['preserve_noindex_noncanonical_policy']);
        $this->assertTrue($authority->supportsTarget('detail_ready_1048'));
        $this->assertFalse($authority->supportsTarget('2786'));
    }

    public function test_product_visible_claim_requires_all_1048_runtime_counts(): void
    {
        $authority = new CareerDetailReadyTargetAuthority;

        $claim = $authority->productVisibleClaim([
            'dataset' => ['member_count' => 1048],
            'career_jobs' => ['item_count' => 1048],
            'detail_ready' => ['count' => 1048],
            'product_surface' => ['public_detail_indexable_count' => 1048],
            'found_published' => 2096,
            'release_gate' => ['pass_count' => 2096],
            'sitemap_llms' => ['bad_url_count' => 0],
        ]);

        $this->assertTrue($claim['visible_detail_claim_allowed']);
        $this->assertFalse($claim['partition_accounting_claim_allowed']);
        $this->assertSame('product_visible_detail_ready_1048', $claim['safe_claim_scope']);
    }

    public function test_2786_partition_accounting_does_not_allow_1048_visible_claim(): void
    {
        $authority = new CareerDetailReadyTargetAuthority;

        $claim = $authority->productVisibleClaim([
            'partition_accounting' => [
                'final_public_accounted_total' => 2786,
            ],
        ]);

        $this->assertFalse($claim['visible_detail_claim_allowed']);
        $this->assertFalse($claim['partition_accounting_claim_allowed']);
        $this->assertSame('partition_accounted_not_product_visible', $claim['safe_claim_scope']);
        $this->assertSame(2786, $claim['claimable_counts']['partition_accounting_total']);
    }
}

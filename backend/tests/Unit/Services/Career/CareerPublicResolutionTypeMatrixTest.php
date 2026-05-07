<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use PHPUnit\Framework\TestCase;

final class CareerPublicResolutionTypeMatrixTest extends TestCase
{
    public function test_it_represents_every_public_resolution_type(): void
    {
        $this->assertSame([
            'public_canonical_job',
            'public_alias_redirect',
            'public_family_hub',
            'public_cn_proxy_page',
            'public_nonindex_reference',
            'keep_non_public_with_policy',
            'blocked_until_governance_approval',
        ], CareerPublicResolutionTypeMatrix::allowedTypes());
    }

    public function test_only_public_canonical_job_is_manifest_eligible(): void
    {
        $manifestEligibleTypes = [];
        foreach (CareerPublicResolutionTypeMatrix::matrix() as $type => $policy) {
            if ((bool) ($policy['manifest_eligible'] ?? false)) {
                $manifestEligibleTypes[] = $type;
            }
        }

        $this->assertSame(['public_canonical_job'], $manifestEligibleTypes);
    }

    public function test_public_type_guards_are_separated_by_owner(): void
    {
        $matrix = CareerPublicResolutionTypeMatrix::matrix();

        $this->assertTrue((bool) data_get($matrix, 'public_canonical_job.job_detail_owner'));
        $this->assertTrue((bool) data_get($matrix, 'public_canonical_job.us_canonical_job_allowed'));
        $this->assertFalse((bool) data_get($matrix, 'public_canonical_job.alias_owner'));

        $this->assertTrue((bool) data_get($matrix, 'public_alias_redirect.alias_owner'));
        $this->assertFalse((bool) data_get($matrix, 'public_alias_redirect.job_detail_owner'));
        $this->assertFalse((bool) data_get($matrix, 'public_alias_redirect.us_canonical_job_allowed'));

        $this->assertTrue((bool) data_get($matrix, 'public_family_hub.family_hub_owner'));
        $this->assertFalse((bool) data_get($matrix, 'public_family_hub.job_detail_owner'));
        $this->assertFalse((bool) data_get($matrix, 'public_family_hub.us_canonical_job_allowed'));

        $this->assertTrue((bool) data_get($matrix, 'public_cn_proxy_page.cn_proxy_owner'));
        $this->assertFalse((bool) data_get($matrix, 'public_cn_proxy_page.us_canonical_job_allowed'));
        $this->assertTrue((bool) data_get($matrix, 'public_cn_proxy_page.disclaimer_required'));
        $this->assertTrue((bool) data_get($matrix, 'public_cn_proxy_page.trust_manifest_required'));
    }

    public function test_non_canonical_public_types_are_not_sitemap_or_llms_eligible_by_default(): void
    {
        foreach (CareerPublicResolutionTypeMatrix::matrix() as $type => $policy) {
            if ($type === 'public_canonical_job') {
                continue;
            }

            $this->assertFalse((bool) ($policy['sitemap_eligible_default'] ?? true), $type);
            $this->assertFalse((bool) ($policy['llms_eligible_default'] ?? true), $type);
            $this->assertFalse((bool) ($policy['llms_full_eligible_default'] ?? true), $type);
        }
    }

    public function test_non_public_types_cannot_have_public_urls(): void
    {
        $matrix = CareerPublicResolutionTypeMatrix::matrix();

        $this->assertFalse((bool) data_get($matrix, 'keep_non_public_with_policy.public_url_allowed'));
        $this->assertFalse((bool) data_get($matrix, 'blocked_until_governance_approval.public_url_allowed'));
    }
}

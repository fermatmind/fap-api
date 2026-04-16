<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Services\Career\StructuredData\CareerStructuredDataOutputPolicy;
use Tests\TestCase;

final class CareerDatasetStructuredDataPolicyTest extends TestCase
{
    public function test_dataset_schema_is_only_allowed_for_dataset_hub_route_kind(): void
    {
        $policy = app(CareerStructuredDataOutputPolicy::class);

        $this->assertTrue($policy->allows('career_dataset_hub', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_dataset_method', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_job_detail', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_family_hub', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_recommendation_detail', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_search', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_alias_resolution', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
    }
}

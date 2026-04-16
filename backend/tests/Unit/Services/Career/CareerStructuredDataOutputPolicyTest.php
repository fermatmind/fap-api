<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\StructuredData\CareerStructuredDataOutputPolicy;
use Tests\TestCase;

final class CareerStructuredDataOutputPolicyTest extends TestCase
{
    public function test_it_exposes_a_safe_route_kind_schema_family_matrix(): void
    {
        $policy = app(CareerStructuredDataOutputPolicy::class);

        $this->assertSame(
            [CareerStructuredDataOutputPolicy::SCHEMA_OCCUPATION, CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST],
            $policy->allowedSchemaFamiliesFor('career_job_detail'),
        );
        $this->assertSame(
            [
                CareerStructuredDataOutputPolicy::SCHEMA_COLLECTION_PAGE,
                CareerStructuredDataOutputPolicy::SCHEMA_ITEM_LIST,
                CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST,
            ],
            $policy->allowedSchemaFamiliesFor('career_family_hub'),
        );
        $this->assertSame(
            [CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE, CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST],
            $policy->allowedSchemaFamiliesFor('career_guide_public_detail'),
        );
        $this->assertSame(
            [CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE, CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST],
            $policy->allowedSchemaFamiliesFor('article_public_detail'),
        );
        $this->assertSame(
            [CareerStructuredDataOutputPolicy::SCHEMA_DATASET, CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST],
            $policy->allowedSchemaFamiliesFor('career_dataset_hub'),
        );
        $this->assertSame(
            [CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE, CareerStructuredDataOutputPolicy::SCHEMA_BREADCRUMB_LIST],
            $policy->allowedSchemaFamiliesFor('career_dataset_method'),
        );

        $this->assertFalse($policy->allows('career_job_detail', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_family_hub', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_guide_public_detail', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_recommendation_detail', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_search', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_alias_resolution', CareerStructuredDataOutputPolicy::SCHEMA_DATASET));
        $this->assertFalse($policy->allows('career_search', CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE));
        $this->assertFalse($policy->allows('career_alias_resolution', CareerStructuredDataOutputPolicy::SCHEMA_ARTICLE));
    }
}

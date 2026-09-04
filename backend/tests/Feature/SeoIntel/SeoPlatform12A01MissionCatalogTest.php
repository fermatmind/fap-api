<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform12A01MissionCatalogTest extends TestCase
{
    public function test_generated_foundation_catalog_is_closed_hash_bound_and_runtime_disabled(): void
    {
        $contracts = $this->app->make(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $schema = $contracts->missionCatalogSchema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['dependency_refs']['additionalProperties']);
        $this->assertFalse($schema['$defs']['mission']['additionalProperties']);
        $this->assertFalse($schema['$defs']['mission']['properties']['budgets']['additionalProperties']);
        $this->assertFalse($schema['$defs']['mission']['properties']['failure_policy']['additionalProperties']);
        $this->assertSame([
            'seo.platform12.daily_gsc_core_runtime',
            'seo.platform12.daily_url_truth_reconciliation',
        ], array_column($catalog['missions'], 'mission_id'));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame(
            $this->app->make(SeoRegistryHasher::class)->hashWithout($catalog, 'catalog_hash'),
            $catalog['catalog_hash'],
        );
        $this->assertSame($catalog, $this->app->make(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
    }

    public function test_duplicate_mission_ids_are_rejected(): void
    {
        $catalog = $this->catalogWithMissions([$this->mission(), $this->mission()]);

        $this->expectExceptionMessage('MISSION_CATALOG_DUPLICATE_ID');
        $this->app->make(Platform12MissionCatalogValidator::class)->validate($catalog);
    }

    public function test_invalid_cadence_and_unbounded_attempts_are_rejected(): void
    {
        foreach ([
            ['cadence' => 'cron'],
            ['max_attempts' => PHP_INT_MAX],
        ] as $mutation) {
            $mission = array_replace($this->mission(), $mutation);
            try {
                $this->app->make(Platform12MissionCatalogValidator::class)->validate($this->catalogWithMissions([$mission]));
                $this->fail('Invalid mission was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_budget_and_write_permission_fields_are_rejected(): void
    {
        $missingBudget = $this->mission();
        unset($missingBudget['budgets']['cost_microusd']);
        $writePermission = $this->mission();
        $writePermission['write_permissions'] = ['cms'];

        foreach ([$missingBudget, $writePermission] as $mission) {
            try {
                $this->app->make(Platform12MissionCatalogValidator::class)->validate($this->catalogWithMissions([$mission]));
                $this->fail('Expanded mission was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_role_cms_class_and_url_fields_are_rejected(): void
    {
        foreach (['role_id', 'cms_command', 'service_class', 'url'] as $field) {
            $mission = $this->mission();
            $mission[$field] = 'forbidden';
            try {
                $this->app->make(Platform12MissionCatalogValidator::class)->validate($this->catalogWithMissions([$mission]));
                $this->fail('Imperative mission field was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_catalog_version_change_invalidates_existing_delivery_binding(): void
    {
        $contracts = $this->app->make(Platform12ContractRegistry::class);
        $validator = $this->app->make(Platform12MissionCatalogValidator::class);
        $catalog = $contracts->missionCatalog();
        $delivery = [
            'catalog_id' => $catalog['catalog_id'],
            'catalog_version' => $catalog['catalog_version'],
            'catalog_hash' => $catalog['catalog_hash'],
        ];

        $this->assertTrue($validator->deliveryMatchesCurrentCatalog($delivery));
        $delivery['catalog_version'] = '1.0.1';
        $this->assertFalse($validator->deliveryMatchesCurrentCatalog($delivery));
    }

    /** @param list<array<string, mixed>> $missions @return array<string, mixed> */
    private function catalogWithMissions(array $missions): array
    {
        $catalog = $this->app->make(Platform12ContractRegistry::class)->missionCatalog();
        $catalog['missions'] = $missions;
        $catalog['catalog_hash'] = $this->app->make(SeoRegistryHasher::class)->hashWithout($catalog, 'catalog_hash');

        return $catalog;
    }

    /** @return array<string, mixed> */
    private function mission(): array
    {
        return [
            'mission_id' => 'seo.platform12.fixture_daily_read',
            'cadence' => 'daily',
            'timezone' => 'Asia/Shanghai',
            'natural_slot' => 'daily:ALL:02:00',
            'family' => 'other_public',
            'locale' => 'zh-CN',
            'review_domain' => 'runtime_health',
            'required_evidence' => ['runtime_health', 'deployment_receipt'],
            'eligible_capability' => 'seo.runtime_qa_readback_attribution',
            'priority' => 'normal',
            'timeout_seconds' => 120,
            'max_attempts' => 3,
            'budgets' => [
                'model_calls' => 0,
                'model_input_tokens' => 0,
                'model_output_tokens' => 0,
                'tool_calls' => 0,
                'cost_microusd' => 0,
            ],
            'failure_policy' => [
                'terminal_state' => 'HOLD',
                'retry_strategy' => 'bounded_exponential',
                'initial_backoff_seconds' => 5,
                'max_backoff_seconds' => 60,
            ],
            'output_schema' => [
                'id' => 'seo.runtime_qa_output.v1',
                'version' => '1.0.0',
                'hash' => str_repeat('a', 64),
            ],
        ];
    }
}

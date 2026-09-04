<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12MonthlyFamilyEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12D01MonthlyFamilyTest extends TestCase
{
    public function test_monthly_family_parity_public_set_and_funnel_are_read_only(): void
    {
        $artifact = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($this->evidence());

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame(4, $artifact['authority']['public_url_count']);
        $this->assertSame(4, $artifact['authority']['public_url_denominator']);
        $this->assertCount($artifact['authority']['public_url_denominator'], $artifact['authority']['public_url_refs']);
        $this->assertSame(1, $artifact['authority']['redirect_alias_count']);
        $this->assertSame(0, $artifact['authority']['private_url_count']);
        $this->assertSame('PARITY_READY', $artifact['parity']['state']);
        $this->assertSame(2, $artifact['parity']['paired_count']);
        $this->assertSame('PUBLIC_TOTALS_ONLY', $artifact['public_funnel']['aggregation_level']);
        $this->assertTrue($artifact['artifact_only']);
        $this->assertTrue($artifact['read_only']);
        $this->assertFalse($artifact['execution_allowed']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);
    }

    public function test_authority_and_runtime_observation_remain_separate_with_drift(): void
    {
        $artifact = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($this->evidence());

        $this->assertTrue($artifact['authority_runtime_separated']);
        $this->assertTrue($artifact['runtime_observation']['observation_only']);
        $this->assertSame(1, $artifact['runtime_observation']['missing_from_runtime_count']);
        $this->assertSame(1, $artifact['runtime_observation']['unexpected_runtime_count']);
        $this->assertArrayNotHasKey('authority_revision', $artifact['runtime_observation']);
    }

    public function test_redirect_alias_is_excluded_and_denominator_is_reproducible(): void
    {
        $evidence = $this->evidence();
        $withoutAlias = $evidence;
        array_pop($withoutAlias['authority_inventory']['urls']);
        $reversed = $evidence;
        $reversed['authority_inventory']['urls'] = array_reverse($reversed['authority_inventory']['urls']);

        $base = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($evidence);
        $noAlias = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($withoutAlias);
        $reordered = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($reversed);

        $this->assertFalse($base['redirect_aliases_in_public_set']);
        $this->assertSame($base['authority']['public_url_inventory_hash'], $noAlias['authority']['public_url_inventory_hash']);
        $this->assertSame($base['authority']['public_url_inventory_hash'], $reordered['authority']['public_url_inventory_hash']);
        $this->assertSame($base['authority']['public_url_denominator'], $reordered['authority']['public_url_denominator']);
        $this->assertSame('SORTED_CANONICAL_AUTHORITY_URL_REFS', $base['authority']['denominator_method']);
    }

    public function test_private_url_fails_closed_and_output_count_remains_zero(): void
    {
        $evidence = $this->evidence();
        $evidence['authority_inventory']['urls'][] = $this->url('6', '5', 'career', 'zh-CN', 'PRIVATE');

        $artifact = app(Platform12MonthlyFamilyEvaluator::class)->evaluate($evidence);

        $this->assertSame('MEASUREMENT_HOLD', $artifact['state']);
        $this->assertSame(0, $artifact['authority']['private_url_count']);
        $this->assertNull($artifact['authority']['public_url_count']);
    }

    public function test_catalog_declares_zero_budget_monthly_evaluator_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.monthly_family_maturity_parity_public_url_set');

        $this->assertIsArray($mission);
        $this->assertSame('monthly:01:04:00', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.monthly_family_maturity_parity_public_url_set',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return [
            'evaluated_at' => '2026-10-01T04:00:00Z',
            'authority_inventory' => [
                'authority_revision' => str_repeat('0', 64),
                'urls' => [
                    $this->url('a', 'f', 'career', 'zh-CN'),
                    $this->url('b', 'f', 'career', 'en'),
                    $this->url('c', 'e', 'personality', 'zh-CN'),
                    $this->url('d', 'e', 'personality', 'en'),
                    $this->url('9', '8', 'career', 'zh-CN', 'REDIRECT_ONLY'),
                ],
            ],
            'runtime_observation' => [
                'source_hash' => str_repeat('1', 64),
                'observed_public_refs' => [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), str_repeat('7', 64)],
            ],
            'family_maturity' => [
                ['family' => 'career', 'locale' => 'zh-CN', 'maturity_bp' => 8200],
                ['family' => 'career', 'locale' => 'en', 'maturity_bp' => 7800],
            ],
            'public_funnel' => ['availability' => 'AVAILABLE', 'landing_count' => 400, 'start_count' => 240, 'result_count' => 180],
        ];
    }

    /** @return array<string,mixed> */
    private function url(string $ref, string $parity, string $family, string $locale, string $state = 'CANONICAL'): array
    {
        return [
            'url_ref' => str_repeat($ref, 64),
            'parity_key' => str_repeat($parity, 64),
            'family' => $family,
            'locale' => $locale,
            'identity_state' => $state,
            'canonical_ok' => $state === 'CANONICAL',
            'hreflang_ok' => $state === 'CANONICAL',
            'indexable' => $state === 'CANONICAL',
        ];
    }
}

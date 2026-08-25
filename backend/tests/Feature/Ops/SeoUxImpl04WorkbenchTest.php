<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\SeoOperationsUiState;
use App\Filament\Ops\Support\SeoWorkbenchUiContract;
use Tests\TestCase;

final class SeoUxImpl04WorkbenchTest extends TestCase
{
    public function test_missing_platform_09_contract_withholds_metrics_and_decisions(): void
    {
        $snapshot = SeoWorkbenchUiContract::unavailableSnapshot();

        $this->assertSame(SeoOperationsUiState::MEASUREMENT_HOLD, $snapshot['state']);
        $this->assertSame([], $snapshot['decisions']);
        $this->assertSame(3, SeoWorkbenchUiContract::DEFAULT_DECISION_COUNT);
        $this->assertSame(5, SeoWorkbenchUiContract::MAX_DECISION_COUNT);
        $this->assertSame([null, null, null, null], array_values(array_intersect_key(
            $snapshot['trend'],
            array_flip(['clicks', 'impressions', 'ctr', 'position']),
        )));
        $this->assertSame([null, null, null, null, null, null], array_values($snapshot['health']));
        $this->assertCount(8, $snapshot['required_decision_fields']);

        foreach (array_values($snapshot['health']) as $value) {
            $this->assertSame('—', SeoOperationsUiState::metricValue($value, $snapshot['state']));
        }
    }

    public function test_workbench_does_not_join_partial_models_or_expose_write_controls(): void
    {
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-seo-workbench-workspace.blade.php'));
        $pageClass = (string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php'));

        $this->assertStringContainsString('ops-seo-workbench-workspace', $page);
        $this->assertStringContainsString('data-default-decision-count', $workspace);
        $this->assertStringContainsString('data-max-decision-count', $workspace);
        $this->assertStringContainsString('SeoOperationsUiState::metricValue', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('wire:model', $workspace);
        $this->assertStringNotContainsString('canonical_path', $workspace);
        $this->assertStringNotContainsString('refreshDecisionOverview', $pageClass);
        $this->assertStringNotContainsString('searchChangeSignal', $pageClass);
    }

    public function test_workbench_copy_is_complete_in_both_locales(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.workbench');

            $this->assertIsArray($copy);
            $this->assertSame(['clicks', 'impressions', 'ctr', 'position'], array_keys($copy['trend']['metrics']));
            $this->assertSame(['cause', 'scope', 'evidence', 'impact', 'action'], array_keys($copy['decisions']['columns']));
            $this->assertSame(['p0', 'p1', 'p2', 'runtime_slo', 'latest_crawl', 'release_chain'], array_keys($copy['health']['fields']));
            $this->assertSame(['preview', 'editor', 'diff'], array_keys($copy['inspector']['actions']));
        }
    }
}

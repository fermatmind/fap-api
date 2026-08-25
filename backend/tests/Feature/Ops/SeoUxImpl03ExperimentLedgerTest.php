<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Filament\Ops\Support\SeoExperimentLedgerUiContract;
use App\Filament\Ops\Support\SeoOperationsUiState;
use Tests\TestCase;

final class SeoUxImpl03ExperimentLedgerTest extends TestCase
{
    public function test_automation_adds_ledger_without_creating_another_top_level_workspace(): void
    {
        $this->assertSame(
            ['overview', 'performance', 'technical', 'url-truth', 'content', 'automation'],
            SeoOperationsPage::workspaceKeys(),
        );
        $this->assertSame(['experiments', 'agents', 'scheduler', 'operations'], SeoOperationsPage::automationSectionKeys());

        $source = (string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php'));
        $this->assertStringContainsString("#[Url(as: 'automation-view', history: true)]", $source);
    }

    public function test_missing_platform_08_contract_exposes_no_experiment_row_or_false_zero(): void
    {
        $snapshot = SeoExperimentLedgerUiContract::unavailableSnapshot();

        $this->assertSame(SeoOperationsUiState::PRODUCTION_UNPROVEN, $snapshot['state']);
        $this->assertSame(
            ['planned', 'canary', 'observing', 'kept', 'rolled_back', 'inconclusive'],
            $snapshot['statuses'],
        );
        $this->assertContains('baseline', $snapshot['required_fields']);
        $this->assertContains('observation_window', $snapshot['required_fields']);
        $this->assertContains('public_readback', $snapshot['required_fields']);

        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-experiment-ledger-workspace.blade.php'));
        $this->assertStringNotContainsString('DB::', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('<tbody', $workspace);
    }

    public function test_ledger_copy_is_complete_in_both_locales(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.experiment_ledger');

            $this->assertIsArray($copy);
            $this->assertSame(
                ['planned', 'canary', 'observing', 'kept', 'rolled_back', 'inconclusive'],
                array_keys($copy['statuses']),
            );
            $this->assertCount(11, $copy['fields']);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Filament\Ops\Support\SeoOperationsUiState;
use Tests\TestCase;

final class SeoUxImpl01CouncilShellTest extends TestCase
{
    public function test_council_exposes_only_the_six_canonical_workspaces(): void
    {
        $this->assertSame(
            ['overview', 'performance', 'technical', 'url-truth', 'content', 'automation'],
            SeoOperationsPage::workspaceKeys(),
        );

        $source = (string) file_get_contents(app_path('Filament/Ops/Pages/SeoOperationsPage.php'));
        $view = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));

        $this->assertStringContainsString("#[Url(as: 'workspace', history: true)]", $source);
        $this->assertStringContainsString('ops-seo-council-nav', $view);
        $this->assertStringNotContainsString('ops-seo-workspace-tabs', $view);
    }

    public function test_missing_and_unproven_metrics_never_render_as_zero(): void
    {
        foreach ([
            SeoOperationsUiState::PRODUCTION_UNPROVEN,
            SeoOperationsUiState::EXTERNAL_NOT_CONNECTED,
            SeoOperationsUiState::MEASUREMENT_HOLD,
            SeoOperationsUiState::STALE,
            SeoOperationsUiState::ERROR,
            SeoOperationsUiState::UNAVAILABLE,
        ] as $state) {
            $this->assertSame('—', SeoOperationsUiState::metricValue(0, $state));
            $this->assertTrue(SeoOperationsUiState::blocksExpansion($state));
        }

        $this->assertSame('0', SeoOperationsUiState::metricValue(null, SeoOperationsUiState::VERIFIED_ZERO));
        $this->assertSame('12', SeoOperationsUiState::metricValue(12, SeoOperationsUiState::PRODUCTION_HEALTHY));
    }

    public function test_state_contract_has_complete_bilingual_labels(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            foreach (SeoOperationsUiState::ALL as $state) {
                $this->assertIsString(data_get($translations, 'custom_pages.seo_operations.states.'.$state.'.label'));
                $this->assertIsString(data_get($translations, 'custom_pages.seo_operations.states.'.$state.'.description'));
            }
        }
    }
}

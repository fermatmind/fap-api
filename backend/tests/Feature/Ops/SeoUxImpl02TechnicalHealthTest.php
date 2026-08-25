<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\SeoOperationsUiState;
use App\Filament\Ops\Support\SeoTechnicalHealthUiContract;
use Tests\TestCase;

final class SeoUxImpl02TechnicalHealthTest extends TestCase
{
    public function test_missing_platform_07_contract_withholds_every_metric_and_sample(): void
    {
        $snapshot = SeoTechnicalHealthUiContract::unavailableSnapshot();

        $this->assertSame(SeoOperationsUiState::PRODUCTION_UNPROVEN, $snapshot['state']);
        $this->assertCount(4, $snapshot['trust']);
        $this->assertSame([null, null, null, null], array_column($snapshot['trust'], 'value'));
        $this->assertSame(
            ['http', 'rendered_robots', 'url_truth', 'canonical_sitemap', 'authority_revision'],
            $snapshot['evidence'],
        );

        foreach ($snapshot['trust'] as $item) {
            $this->assertSame('—', SeoOperationsUiState::metricValue($item['value'], $item['state']));
        }
    }

    public function test_technical_workspace_is_cluster_first_and_has_no_url_rows_or_write_action(): void
    {
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-technical-health-workspace.blade.php'));

        $this->assertStringContainsString('ops-technical-health-workspace', $page);
        $this->assertStringContainsString('ops-technical-health__cluster-head', $workspace);
        $this->assertStringContainsString('SeoOperationsUiState::metricValue', $workspace);
        $this->assertStringContainsString("['severity', 'detector', 'family', 'blast_radius', 'observed', 'status']", $workspace);
        $this->assertStringNotContainsString('canonical_path', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('wire:model', $workspace);
    }

    public function test_technical_health_copy_is_complete_in_both_locales(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.technical_health');

            $this->assertIsArray($copy);
            $this->assertSame(
                ['severity', 'detector', 'family', 'blast_radius', 'observed', 'status'],
                array_keys($copy['clusters']['columns']),
            );
            $this->assertCount(5, $copy['evidence']['steps']);
        }
    }
}

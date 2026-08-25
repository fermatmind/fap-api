<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Services\Ops\SeoOperationsReadService;
use ReflectionMethod;
use Tests\TestCase;

final class SeoUxImpl07SchedulerAcceptanceTest extends TestCase
{
    public function test_scheduler_is_internal_and_has_no_manual_or_write_action(): void
    {
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-scheduler-workspace.blade.php'));

        $this->assertSame(['experiments', 'agents', 'scheduler', 'operations'], SeoOperationsPage::automationSectionKeys());
        $this->assertStringContainsString("trigger_mode'] ?? null) === 'scheduled'", $workspace);
        $this->assertStringContainsString('data-read-only-gsc="true"', $workspace);
        $this->assertStringContainsString('data-search-submission-allowed="false"', $workspace);
        $this->assertStringContainsString('MEASUREMENT_HOLD', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('<button', $workspace);
        $this->assertStringNotContainsString('<form', $workspace);
    }

    public function test_composition_layer_recursively_removes_hashes_and_shas_from_livewire_state(): void
    {
        $method = new ReflectionMethod(SeoOperationsReadService::class, 'sanitize');
        $safe = $method->invoke(new SeoOperationsReadService, [
            'application_sha' => str_repeat('a', 40),
            'property_hash' => str_repeat('b', 64),
            'nested' => ['workflow_sha' => str_repeat('c', 40), 'status' => 'success'],
        ]);

        $this->assertSame(['nested' => ['status' => 'success']], $safe);
    }

    public function test_scheduler_copy_is_complete_in_both_locales(): void
    {
        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.scheduler_workspace');
            $this->assertSame(['daily', 'weekly', 'monthly'], array_keys($copy['cadences']));
            $this->assertCount(8, $copy['gate']['fields']);
            $this->assertStringContainsString('scheduled', strtolower($copy['receipts']['description']));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\SeoOperationsPage;
use App\Filament\Ops\Support\SeoAgentCouncilUiContract;
use App\Filament\Ops\Support\SeoOperationsUiState;
use Tests\TestCase;

final class SeoUxImpl06AgentCouncilTest extends TestCase
{
    public function test_missing_platform_11_contract_is_read_only_and_withholds_capabilities_and_run_evidence(): void
    {
        $snapshot = SeoAgentCouncilUiContract::unavailableSnapshot();

        $this->assertSame(SeoOperationsUiState::PRODUCTION_UNPROVEN, $snapshot['state']);
        $this->assertSame('l0_read_only', $snapshot['access_level']);
        $this->assertTrue($snapshot['read_only_gsc']);
        $this->assertFalse($snapshot['search_submission_allowed']);
        $this->assertSame('frozen', $snapshot['registry_metadata']['registry_status']);
        $this->assertSame('fap-api', $snapshot['registry_metadata']['owner_repository']);
        $this->assertSame(9, $snapshot['registry_metadata']['role_count']);
        $this->assertSame(20, $snapshot['registry_metadata']['capability_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['registry_metadata']['registry_hash']);
        $this->assertSame([], $snapshot['capabilities']);
        $this->assertNull($snapshot['policy_decision']);
        $this->assertNull($snapshot['trace']);
        $this->assertNull($snapshot['canary']);
        $this->assertNull($snapshot['circuit_breaker']);
        $this->assertNull($snapshot['rollback']);
    }

    public function test_agent_council_is_an_internal_automation_section_without_chat_or_actions(): void
    {
        $page = (string) file_get_contents(resource_path('views/filament/ops/pages/seo-operations.blade.php'));
        $workspace = (string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php'));

        $this->assertSame(['experiments', 'agents', 'scheduler', 'operations'], SeoOperationsPage::automationSectionKeys());
        $this->assertStringContainsString('ops-agent-council-workspace', $page);
        $this->assertStringContainsString('data-read-only-gsc', $workspace);
        $this->assertStringContainsString('data-search-submission-allowed', $workspace);
        $this->assertStringContainsString('data-registry-status', $workspace);
        $this->assertStringContainsString("['orchestrator', 'policy_gateway', 'safety_review', 'canary', 'circuit_breaker', 'rollback']", (string) file_get_contents(app_path('Filament/Ops/Support/SeoAgentCouncilUiContract.php')));
        $this->assertStringNotContainsString('<form', $workspace);
        $this->assertStringNotContainsString('<input', $workspace);
        $this->assertStringNotContainsString('wire:click', $workspace);
        $this->assertStringNotContainsString('wire:model', $workspace);
        $this->assertStringNotContainsString('chat', strtolower($workspace));
        $this->assertStringNotContainsString('canonical_path', $workspace);
        $this->assertStringNotContainsString('query', strtolower($workspace));
        $this->assertStringNotContainsString('user-agent', strtolower($workspace));
    }

    public function test_agent_council_copy_and_capability_schema_are_complete_in_both_locales(): void
    {
        $snapshot = SeoAgentCouncilUiContract::unavailableSnapshot();

        foreach (['en', 'zh_CN'] as $locale) {
            $translations = require lang_path($locale.'/ops.php');
            $copy = data_get($translations, 'custom_pages.seo_operations.agent_council');

            $this->assertIsArray($copy);
            $this->assertSame($snapshot['capability_fields'], array_keys($copy['capabilities']['fields']));
            $this->assertSame($snapshot['governance_steps'], array_keys($copy['governance']['steps']));
            $this->assertArrayHasKey('privacy_note', $copy);
        }
    }
}

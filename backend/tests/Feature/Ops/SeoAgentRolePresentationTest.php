<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\SeoAgentRolePresentation;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Platform12\Platform12RuntimeControl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SeoAgentRolePresentationTest extends TestCase
{
    public function test_canonical_roles_have_local_icons_bilingual_copy_and_unchanged_authority(): void
    {
        Http::preventStrayRequests();
        $registry = app(SeoRoleCapabilityRegistry::class)->registry();
        $runtime = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();
        $snapshot = app(SeoAgentRolePresentation::class)->snapshot();
        $this->assertTrue($snapshot['available']);
        $this->assertCount(9, $snapshot['roles']);
        $this->assertEqualsCanonicalizing(array_column($registry['roles'], 'role_id'), array_column($snapshot['roles'], 'role_id'));

        foreach (['en', 'zh_CN'] as $locale) {
            app()->setLocale($locale);
            $html = $this->render($snapshot, ['state' => 'ACTIVE_READ_ONLY']);
            $this->assertSame(9, substr_count($html, 'data-role-id='));
            $this->assertSame(9, substr_count($html, '<svg'));
            $this->assertSame(9, substr_count($html, 'data-role-state="dormant_not_authorized"'));
            foreach ($snapshot['roles'] as $role) {
                $this->assertNotNull($role['copy']);
                foreach (['name', 'duty'] as $field) {
                    $key = 'seo-agent-roles.roles.'.$role['copy'].'.'.$field;
                    $this->assertNotSame($key, __($key));
                    $this->assertStringContainsString(e(__($key)), $html);
                }
            }
            foreach (['<button', '<form', 'wire:poll', 'wire:click', 'prompt_ref', 'secret', 'tool_allowlist', 'runtime_version'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $html);
            }
        }
        $this->assertSame($registry, app(SeoRoleCapabilityRegistry::class)->registry());
        $this->assertSame($runtime['version_vector'], app(RuntimeCapabilitySnapshotBuilder::class)->snapshot()['version_vector']);
        Http::assertNothingSent();
    }

    public function test_unknown_role_state_and_removed_roles_do_not_inherit_config_or_running_status(): void
    {
        $snapshot = app(SeoAgentRolePresentation::class)->project([
            ['role_id' => 'new.role', 'classification' => 'active_agent', 'runtime_state' => 'unrecognized', 'prompt' => 'PRIVATE_PROMPT'],
            ['role_id' => 'seo.orchestrator', 'classification' => 'active_agent'],
        ]);
        $this->assertCount(2, $snapshot['roles']);
        $this->assertSame('user-group', $snapshot['roles'][1]['icon']);
        $this->assertNull($snapshot['roles'][1]['copy']);
        $html = $this->render($snapshot, ['state' => 'ACTIVE_READ_ONLY']);
        $this->assertSame(2, substr_count($html, 'data-role-state="unknown"'));
        $this->assertStringContainsString('new.role', $html);
        $this->assertStringNotContainsString('career.content_agent', $html);
        $this->assertStringNotContainsString('PRIVATE_PROMPT', $html);
    }

    public function test_pause_is_global_and_does_not_override_role_state(): void
    {
        app()->setLocale('zh_CN');
        $html = $this->render(app(SeoAgentRolePresentation::class)->snapshot(), ['state' => 'FULL_NIGHTLY_EVIDENCE_HOLD', 'pause_intent' => 'PAUSED']);
        $this->assertStringContainsString('Council 已暂停', $html);
        $this->assertSame(9, substr_count($html, '未授权运行'));
        $this->assertStringNotContainsString('正在运行</', $html);
        foreach ([[], ['state' => 'UNKNOWN'], ['state' => 'SHARED_CACHE_HOLD']] as $runtime) {
            $this->assertSame('unavailable', SeoAgentRolePresentation::globalState($runtime));
        }
        $this->assertSame('disabled', SeoAgentRolePresentation::globalState(['state' => 'DISABLED']));
        $this->assertSame('restricted', SeoAgentRolePresentation::globalState(['state' => 'CATALOG_DRIFT_HOLD']));
    }

    public function test_registry_failure_is_visible_without_fabricated_roles_or_exception_details(): void
    {
        $this->app->bind(SeoRoleCapabilityRegistry::class, fn () => throw new RuntimeException('PRIVATE_CREDENTIAL'));
        $snapshot = app(SeoAgentRolePresentation::class)->snapshot();
        $this->assertFalse($snapshot['available']);
        $html = view('filament.ops.components.ops-agent-council-workspace')->render();
        $this->assertStringContainsString('The role registry is unavailable', $html);
        $this->assertStringContainsString('Status unavailable', $html);
        $this->assertStringNotContainsString('data-role-id=', $html);
        $this->assertStringNotContainsString('PRIVATE_CREDENTIAL', $html);
        $empty = $this->render(app(SeoAgentRolePresentation::class)->project([]), []);
        $this->assertStringContainsString('The registry currently contains no roles.', $empty);
    }

    public function test_duplicate_or_malformed_registry_is_not_rendered_as_a_valid_list(): void
    {
        $presenter = app(SeoAgentRolePresentation::class);
        $this->assertFalse($presenter->project([['role_id' => 'a'], ['role_id' => 'a']])['available']);
        $this->assertFalse($presenter->project([['classification' => 'active_agent']])['available']);
    }

    public function test_runtime_source_failure_preserves_roles_and_reports_unavailable(): void
    {
        $this->app->bind(Platform12RuntimeControl::class, fn () => throw new RuntimeException('PRIVATE_RUNTIME_DETAIL'));
        $html = view('filament.ops.components.ops-agent-council-workspace')->render();
        $this->assertStringContainsString('Status unavailable', $html);
        $this->assertSame(9, substr_count($html, 'data-role-id='));
        $this->assertStringNotContainsString('PRIVATE_RUNTIME_DETAIL', $html);
    }

    private function render(array $snapshot, array $runtime): string
    {
        return view('filament.ops.components.ops-agent-role-overview', compact('snapshot', 'runtime'))->render();
    }
}

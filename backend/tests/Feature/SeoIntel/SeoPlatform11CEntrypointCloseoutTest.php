<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\RetiredSeoAgentCommand;
use App\Filament\Ops\Support\SeoAgentCouncilUiContract;
use App\Http\Middleware\EnsureAdminTotpVerified;
use App\Http\Middleware\EnsureSeoIntelReadAuthorized;
use App\Http\Middleware\OpsAccessControl;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayStatusProjection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11CEntrypointCloseoutTest extends TestCase
{
    public function test_api_has_one_protected_read_only_status_route_and_no_mutation_route(): void
    {
        $route = Route::getRoutes()->getByName('api.v0_5.ops.seo_intel.policy_gateway');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains(EnsureAdminTotpVerified::class, $route->gatherMiddleware());
        $this->assertContains(OpsAccessControl::class, $route->gatherMiddleware());
        $this->assertContains(EnsureSeoIntelReadAuthorized::class, $route->gatherMiddleware());
        $this->getJson('/api/v0.5/ops/seo-intel/policy-gateway')->assertUnauthorized();

        $matching = collect(Route::getRoutes())->filter(static fn ($candidate): bool => $candidate->uri() === 'api/v0.5/ops/seo-intel/policy-gateway');
        $this->assertCount(1, $matching);
        $this->assertSame(['GET', 'HEAD'], $matching->first()->methods());
    }

    public function test_api_and_agent_council_use_the_same_disabled_snapshot_without_controls(): void
    {
        $gateway = app(PolicyGatewayStatusProjection::class)->snapshot();
        $council = SeoAgentCouncilUiContract::unavailableSnapshot();
        $workspace = strtolower((string) file_get_contents(resource_path('views/filament/ops/components/ops-agent-council-workspace.blade.php')));

        $this->assertSame('DEPLOYED_DISABLED', $gateway['state']);
        $this->assertSame('DENY', $gateway['decision']);
        $this->assertSame($gateway['decision'], $council['policy_decision']);
        $this->assertSame($gateway['global_guards'], $council['global_guards']);
        $this->assertSame([], $council['capabilities']);
        $this->assertNull($council['trace']);
        $this->assertNull($council['canary']);
        $this->assertNull($council['circuit_breaker']);
        $this->assertNull($council['rollback']);
        foreach (['<form', '<input', 'wire:click', 'wire:model', '<button'] as $control) {
            $this->assertStringNotContainsString($control, $workspace);
        }
    }

    public function test_closeout_is_the_only_new_cli_entrypoint_and_is_not_scheduled(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('seo:policy-gateway-closeout', $commands);
        $legacy = array_filter($commands, static fn ($command, string $name): bool => str_starts_with($name, 'seo-agent:'), ARRAY_FILTER_USE_BOTH);
        $this->assertCount(35, $legacy);
        foreach ($legacy as $command) {
            $this->assertInstanceOf(RetiredSeoAgentCommand::class, $command);
            $this->assertFalse($command::AGENT_INVOCABLE);
        }

        $scheduleSources = (string) file_get_contents(base_path('bootstrap/app.php'))."\n".(string) file_get_contents(app_path('Console/Kernel.php'));
        $this->assertStringNotContainsString("schedule->command('seo:policy-gateway-closeout", $scheduleSources);
        $this->assertStringNotContainsString('seo-agent:', $scheduleSources);
        foreach (glob(base_path('../.github/workflows/*.yml')) ?: [] as $workflow) {
            $this->assertStringNotContainsString('seo-agent:', (string) file_get_contents($workflow), $workflow);
        }
    }

    public function test_closeout_receipt_is_exact_sha_bound_and_zero_authority(): void
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], dirname(base_path()));
        $process->mustRun();
        $sha = trim($process->getOutput());
        $tester = new CommandTester(Artisan::all()['seo:policy-gateway-closeout']);
        $this->assertSame(0, $tester->execute(['--expected-sha' => $sha, '--json' => true]));
        $receipt = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($sha, $receipt['release_sha']);
        $this->assertSame('DEPLOYED_DISABLED', $receipt['state']);
        foreach (['decision_allow_count', 'admission_bypass', 'execution_bypass', 'manifest_bypass', 'entrypoint_bypass', 'l4_allow_count', 'active_manifest_count', 'trusted_signing_key_count', 'model_calls', 'tool_calls', 'external_calls', 'business_writes', 'cms_writes', 'url_truth_writes', 'search_submissions'] as $field) {
            $this->assertSame(0, $receipt[$field], $field);
        }
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
        foreach (['email', 'phone', 'token', 'account', 'payment', 'user_id', 'raw_query'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower(json_encode($receipt, JSON_THROW_ON_ERROR)));
        }
    }
}

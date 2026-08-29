<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11BProductionCloseoutTest extends TestCase
{
    public function test_closeout_accepts_safe_dependency_hold_with_all_gates_disabled(): void
    {
        $sha = trim((new Process(['git', 'rev-parse', 'HEAD'], base_path('..')))->mustRun()->getOutput());
        $this->assertSame(0, Artisan::call('seo:evidence-boundary-closeout', ['--expected-sha' => $sha, '--json' => true]));
        $output = Artisan::output();
        $receipt = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('seo.evidence_boundary_closeout.v3', $receipt['contract_version']);
        $this->assertSame('DEPENDENCY_HOLD', $receipt['dependency_status']);
        $this->assertFalse($receipt['execution_allowed']);
        $this->assertFalse($receipt['external_fetch_enabled']);
        $this->assertSame(0, $receipt['model_calls']);
        $this->assertSame(0, $receipt['negative_guarantees']['production_evidence_rows_created']);
        $this->assertSame(0, $receipt['negative_guarantees']['agent_write_permissions']);
        $this->assertFalse($receipt['negative_guarantees']['agent_runtime_created']);
        $this->assertSame(0, $receipt['negative_guarantees']['cms_write']);
        $this->assertSame(0, $receipt['negative_guarantees']['search_submission']);
        $this->assertSame(0, $receipt['negative_guarantees']['url_truth_write']);
        $this->assertSame(['total' => 36, 'rejected' => 36, 'bypass' => 0], $receipt['self_checks']['private_route_probes']);
        $this->assertSame(0, $receipt['self_checks']['pii_evasion_probes']['bypass']);
        $this->assertSame(0, $receipt['self_checks']['invalid_context_scope']['ready']);
        $this->assertSame([
            'total' => 52,
            'factory' => ['total' => 19, 'rejected' => 19, 'bypass' => 0],
            'verifier' => ['total' => 27, 'rejected' => 27, 'bypass' => 0],
            'context_builder' => ['total' => 6, 'held' => 6, 'fully_sanitized' => 6, 'bypass' => 0],
        ], $receipt['self_checks']['metadata_privacy_probes']);
        $this->assertNotContains('fail', $receipt['self_checks']['gateway']);
        $this->assertStringNotContainsString('privacy-probe@example.com', $output);
        $this->assertStringNotContainsString('sk-live-privacyprobe12345678', $output);
        $this->assertStringNotContainsString('attempt_id_privacyprobe1234', $output);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['receipt_hash']);
        $firstHash = $receipt['receipt_hash'];
        $this->assertSame(0, Artisan::call('seo:evidence-boundary-closeout', ['--expected-sha' => $sha, '--json' => true]));
        $this->assertSame($firstHash, json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR)['receipt_hash']);
    }

    public function test_exact_sha_ci_and_both_deploy_receipts_are_wired_without_a_new_workflow(): void
    {
        $ci = (string) file_get_contents(base_path('../.github/workflows/ci.yml'));
        $deploy = (string) file_get_contents(base_path('../.github/workflows/deploy.yml'));
        $deployer = (string) file_get_contents(base_path('../deploy.php'));
        $this->assertStringContainsString('seo-agent-evidence-boundary:', $ci);
        $this->assertStringContainsString('seo-agent-evidence-contract-manifest.v2.json', $ci);
        $this->assertStringContainsString('seo:evidence-boundary-closeout --expected-sha="$GITHUB_SHA"', $ci);
        $this->assertStringContainsString('seo.evidence_boundary_closeout.v3', $ci);
        $this->assertStringContainsString("-o seo_agent_evidence_boundary='", $deploy);
        $this->assertStringContainsString('seo-agent-evidence-boundary-staging.json', $deploy);
        $this->assertStringContainsString('seo-agent-evidence-boundary-production.json', $deploy);
        $this->assertStringContainsString('-o IdentitiesOnly=yes -i "$DEPLOY_IDENTITY_FILE_STG"', $deploy);
        $this->assertStringContainsString("task('seo:agent-evidence-boundary-closeout'", $deployer);
        $this->assertStringContainsString('seo.evidence_boundary_closeout.v3', $deployer);
        $this->assertStringContainsString('sudo -n -u www-data -- mkdir -p "$receipt_dir"', $deployer);
        $this->assertStringContainsString('as_receipt_owner ln "$tmp" "$receipt_path"', $deployer);
        $this->assertStringContainsString(
            'file=$q_path/shared/backend/storage/app/release-receipts/seo-agent-evidence-boundary/$q_sha.json; sudo -n -u www-data -- test -f \"\$file\"; sudo -n -u www-data -- test ! -L \"\$file\"; sudo -n -u www-data -- cat \"\$file\"',
            $deploy,
        );
        $this->assertCount(4, glob(base_path('../.github/workflows/*.yml')) ?: []);
    }
}

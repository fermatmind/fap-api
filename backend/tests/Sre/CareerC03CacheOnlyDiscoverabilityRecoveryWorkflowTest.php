<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CareerC03CacheOnlyDiscoverabilityRecoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_binds_the_exact_failed_incident_and_c02_authority_lineage(): void
    {
        $workflow = $this->workflow();

        foreach ([
            "FAILED_DEPLOY_RUN_ID: '31373985399'",
            "C02_REPAIR_RUN_ID: '31372257233'",
            'sha256:f0a85f1a6bd41fbd950e1384826a2f423e2d528430d312b9e825cde06797a704',
            '44951ee59449d0efeeebd179486b6e2db2d05ff265d547105131a6d39e1bf4ec',
            'sha256:ab84f3c2cc2330ba26ca72ffa2f16c07938d4a34ffaa4c943e8f662a93c055c2',
            '993980a3cd345dfc4315f075b78cf7f029bd5c9113b9cc1be0ef5d36bc4bee33',
            'sha256:5326960d5670519d831889688a91d13c1c3f1b937bdcc513618265c4899d7f2b',
            'c2659ace415f9bc91b8052f63c8224d6cc9e43bfde7696287aa288d69dfc0613',
            'sha256:ba1be4f6862851752b1bbfd6a535c7c1ef589e90658d9043f372174fd17ea6eb',
            'f1a30b3c83c8d3f11b83e4b8056074bf724e1f6d37646f93750567b0a60b4cc1',
            '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6',
            'old_incident_artifact_superseded: true',
            'standard_deploy_attempted: true',
            'migration_execution_attempted: true',
            'failed_run_standard_deploy_execution_count: 1',
            'failed_run_migration_execution_count: 1',
            'current_control_migration_count: 0',
            'terminal_failure_task: "guard:public-dns-health"',
            'symlink_changed: false',
            'queue_reloaded: false',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
    }

    #[Test]
    public function workflow_is_latest_main_exact_sha_receipt_bound_and_fail_closed(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'test "$(git rev-parse origin/main)" = "$CONTROL_PLANE_SHA"',
            'expected_incident_closeout_receipt_sha256:',
            'expected_incident_closeout_artifact_digest:',
            'expected_verify_receipt_sha256:',
            'expected_verify_artifact_digest:',
            'operator_approval_phrase:',
            'career-c03-incident_closeout-${INCIDENT_CLOSEOUT_RUN_ID}-${INCIDENT_CLOSEOUT_RUN_ATTEMPT}',
            '.status == "PASS_INCIDENT_CLOSED"',
            '.status == "PASS_RECOVERY_REQUIRED"',
            'test "$OPERATOR_APPROVAL_PHRASE" = "$expected_phrase"',
            'test "$current_pre_state" = "$(jq -r \'.pre_state_sha256\' "$verify")"',
            'php /dev/stdin inspect',
            'php /dev/stdin apply',
            'php /dev/stdin rollback',
            'career_c03_input_boundary_control.sh',
            'bash "$INPUT_BOUNDARY_RUNNER" "$RECEIPT"',
            'INPUT_BOUNDARY_RUNNER_SHA256',
            'input_boundary: {checked:true,passed:true,failed_invariant:null,validator_sha256:$input_boundary_runner}',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
    }

    #[Test]
    public function verify_and_apply_contracts_preserve_exact_career_and_non_career_sets(): void
    {
        $workflow = $this->workflow();
        $readback = $this->publicReadbackRunner();

        foreach ([
            'PASS_C03_REVERIFIED_NO_APPLY_REQUIRED',
            'PASS_RECOVERY_REQUIRED',
            'PASS_C03_RECOVERED',
            'HOLD_NON_RECOVERABLE_DRIFT',
            'HOLD_APPLY_ROLLED_BACK',
            'HOLD_ROLLBACK_INCOMPLETE',
            'authority_inventory: $inspect.authority_inventory',
            'published_cohort: $inspect.authority',
            'detail_coverage: $inspect.detail_coverage',
            'target_set_sha256: $inspect.target_set_sha256',
            'cache_key_manifest: $inspect.cache_key_manifest',
            'cache_key_manifest_sha256: $inspect.cache_key_manifest_sha256',
            'revalidation_path_manifest: $inspect.revalidation_path_manifest',
            'revalidation_path_manifest_sha256: $inspect.revalidation_path_manifest_sha256',
            'non_career_url_set_sha256',
            'HOLD_PUBLIC_READBACK_FAILED',
            'PUBLIC_READBACK_RUNNER_SHA256',
            'public_recovered_incomplete_transfer_count',
            'public_other_transport_failure_count',
            'public_non_200_count',
            'surface_diagnostics: $public.surface_diagnostics',
            'shared_surface_readback: $public.shared_surface_readback',
            '.shared_surface_readback',
            '.surface_diagnostics |= with_entries',
            "'https://fermatmind.com/sitemap.xml'",
            "'https://fermatmind.com/llms.txt'",
            "'https://fermatmind.com/llms-full.txt'",
            'career_link_publication_gate: "CLOSED"',
            'automatic_retry_allowed: false',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }

        $controlPlane = $workflow."\n".$readback;
        self::assertStringNotContainsString('1046', $controlPlane);
        self::assertStringNotContainsString('2092', $controlPlane);
        self::assertStringNotContainsString('uses: ./.github/workflows/deploy-production.yml', $controlPlane);
        self::assertStringNotContainsString('gh workflow run deploy-production.yml', $controlPlane);
        self::assertStringNotContainsString('php artisan migrate', $controlPlane);
        self::assertStringNotContainsString('queue:restart', $controlPlane);
        self::assertStringNotContainsString('supervisorctl', $controlPlane);
        self::assertStringNotContainsString('indexnow', strtolower($controlPlane));
        self::assertStringNotContainsString('googleapis', strtolower($controlPlane));

        foreach ([
            'jobs_en jobs_zh directory_en directory_zh sitemap_source sitemap llms llms_full',
            '--write-out $\'%{http_code}\\t%{time_total}\'',
            'for attempt in 1 2 3; do',
            'total_elapsed_ms=$((total_elapsed_ms + elapsed_ms))',
            'total_elapsed_ms=$((total_elapsed_ms + 2000))',
            'shared-surface-status.tsv',
            'PUBLIC_SHARED_TRANSPORT_INCOMPLETE',
            'PUBLIC_SHARED_TRANSPORT_TIMEOUT',
            'PUBLIC_SHARED_HTTP_5XX',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
        self::assertStringNotContainsString('%{num_retries}', $workflow);

        foreach ([
            'for round in 1 2; do',
            'xargs -0 -P 2 -n 1',
            "--proto '=https'",
            '--request GET',
            '--max-redirs 0',
            '--connect-timeout 5',
            '--max-time 20',
            'sleep 1',
        ] as $contract) {
            self::assertStringContainsString($contract, $readback);
        }

        self::assertStringNotContainsString('--location', $readback);
        self::assertStringNotContainsString('--request POST', $readback);
        self::assertStringNotContainsString('--data', $readback);
    }

    #[Test]
    public function bounded_readback_recovers_curl_18_once_and_does_not_retry_http_500(): void
    {
        $recovered = $this->runPublicReadback('recover18');
        self::assertSame(0, $recovered['exit']);
        self::assertSame(3, $recovered['curl_count']);
        self::assertSame([
            ['1', 'https://fermatmind.com/en/career/jobs/example-job', '200', '0', '2', '18'],
            ['2', 'https://fermatmind.com/en/career/jobs/example-job', '200', '0', '1', '0'],
        ], $recovered['rows']);

        $httpFailure = $this->runPublicReadback('http500');
        self::assertSame(0, $httpFailure['exit']);
        self::assertSame(2, $httpFailure['curl_count']);
        self::assertSame('500', $httpFailure['rows'][0][2]);
        self::assertSame('1', $httpFailure['rows'][0][4]);
        self::assertSame('0', $httpFailure['rows'][0][5]);
    }

    #[Test]
    public function bounded_readback_records_terminal_curl_18_after_one_retry_per_round(): void
    {
        $result = $this->runPublicReadback('persistent18');

        self::assertSame(0, $result['exit']);
        self::assertSame(4, $result['curl_count']);
        self::assertCount(2, $result['rows']);
        foreach ($result['rows'] as $row) {
            self::assertSame('18', $row[3]);
            self::assertSame('2', $row[4]);
            self::assertSame('18', $row[5]);
        }
    }

    #[Test]
    public function bounded_readback_rejects_duplicate_private_malformed_and_cross_locale_targets_before_curl(): void
    {
        foreach ([
            [
                'https://fermatmind.com/en/career/jobs/example-job',
                'https://fermatmind.com/en/career/jobs/example-job',
            ],
            ['https://fermatmind.com/en/results/private'],
            ['http://fermatmind.com/en/career/jobs/example-job'],
            ['https://fermatmind.com/zh-CN/career/jobs/example-job'],
        ] as $urls) {
            $result = $this->runPublicReadback('success', $urls);

            self::assertSame(2, $result['exit']);
            self::assertSame(0, $result['curl_count']);
            self::assertSame([], $result['rows']);
        }
    }

    #[Test]
    public function workflow_artifact_is_always_uploaded_and_only_final_pass_states_close_c03(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString('if: always()', $workflow);
        self::assertStringContainsString(
            'name: career-c03-${{ inputs.mode }}-${{ github.run_id }}-${{ github.run_attempt }}',
            $workflow,
        );
        self::assertStringContainsString('retention-days: 30', $workflow);
        self::assertStringContainsString(
            'expected_re=\'^(PASS_C03_REVERIFIED_NO_APPLY_REQUIRED|PASS_RECOVERY_REQUIRED)$\'',
            $workflow,
        );
        self::assertSame(1, substr_count($workflow, 'status: "PASS_C03_RECOVERED"'));
        self::assertSame(1, substr_count($workflow, 'status=PASS_C03_REVERIFIED_NO_APPLY_REQUIRED'));
    }

    #[Test]
    public function input_boundary_validator_reports_every_exact_invariant_without_retaining_input_values(): void
    {
        $sha = str_repeat('a', 64);
        $digest = 'sha256:'.str_repeat('b', 64);
        $empty = [
            'INCIDENT_CLOSEOUT_RUN_ID' => '',
            'INCIDENT_CLOSEOUT_RUN_ATTEMPT' => '',
            'EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256' => '',
            'EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST' => '',
            'VERIFY_RUN_ID' => '',
            'VERIFY_RUN_ATTEMPT' => '',
            'EXPECTED_VERIFY_RECEIPT_SHA256' => '',
            'EXPECTED_VERIFY_ARTIFACT_DIGEST' => '',
            'OPERATOR_APPROVAL_PHRASE' => '',
        ];
        $valid = [
            'incident_closeout' => $empty,
            'verify' => [
                ...$empty,
                'INCIDENT_CLOSEOUT_RUN_ID' => '123',
                'INCIDENT_CLOSEOUT_RUN_ATTEMPT' => '1',
                'EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256' => $sha,
                'EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST' => $digest,
            ],
            'apply' => [
                ...$empty,
                'INCIDENT_CLOSEOUT_RUN_ID' => '123',
                'INCIDENT_CLOSEOUT_RUN_ATTEMPT' => '1',
                'EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256' => $sha,
                'EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST' => $digest,
                'VERIFY_RUN_ID' => '456',
                'VERIFY_RUN_ATTEMPT' => '1',
                'EXPECTED_VERIFY_RECEIPT_SHA256' => $sha,
                'EXPECTED_VERIFY_ARTIFACT_DIGEST' => $digest,
                'OPERATOR_APPROVAL_PHRASE' => 'sensitive-approval-value',
            ],
        ];

        foreach ($valid as $mode => $environment) {
            $result = $this->runInputBoundary($mode, $environment);
            self::assertSame(0, $result['exit']);
            self::assertSame([
                'checked' => true,
                'passed' => true,
                'failed_invariant' => null,
                'validator_sha256' => str_repeat('c', 64),
            ], $result['receipt']['input_boundary']);
            self::assertStringNotContainsString('sensitive-approval-value', json_encode($result['receipt'], JSON_THROW_ON_ERROR));
        }

        $invalid = [];
        foreach ($empty as $field => $_value) {
            $environment = $valid['incident_closeout'];
            $environment[$field] = 'sensitive-unexpected-value';
            $invalid[] = ['incident_closeout', $environment, 'incident_closeout.'.strtolower($field).'.must_be_empty'];
        }
        foreach ([
            'INCIDENT_CLOSEOUT_RUN_ID' => ['0', 'verify.incident_closeout_run_id.required_numeric'],
            'INCIDENT_CLOSEOUT_RUN_ATTEMPT' => ['0', 'verify.incident_closeout_run_attempt.required_numeric'],
            'EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256' => ['invalid', 'verify.expected_incident_closeout_receipt_sha256.required_sha256'],
            'EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST' => ['invalid', 'verify.expected_incident_closeout_artifact_digest.required_digest'],
            'VERIFY_RUN_ID' => ['sensitive-unexpected-value', 'verify.verify_run_id.must_be_empty'],
            'VERIFY_RUN_ATTEMPT' => ['sensitive-unexpected-value', 'verify.verify_run_attempt.must_be_empty'],
            'EXPECTED_VERIFY_RECEIPT_SHA256' => ['sensitive-unexpected-value', 'verify.expected_verify_receipt_sha256.must_be_empty'],
            'EXPECTED_VERIFY_ARTIFACT_DIGEST' => ['sensitive-unexpected-value', 'verify.expected_verify_artifact_digest.must_be_empty'],
            'OPERATOR_APPROVAL_PHRASE' => ['sensitive-unexpected-value', 'verify.operator_approval_phrase.must_be_empty'],
        ] as $field => [$value, $invariant]) {
            $environment = $valid['verify'];
            $environment[$field] = $value;
            $invalid[] = ['verify', $environment, $invariant];
        }
        foreach ([
            'INCIDENT_CLOSEOUT_RUN_ID' => ['0', 'apply.incident_closeout_run_id.required_numeric'],
            'INCIDENT_CLOSEOUT_RUN_ATTEMPT' => ['0', 'apply.incident_closeout_run_attempt.required_numeric'],
            'EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256' => ['invalid', 'apply.expected_incident_closeout_receipt_sha256.required_sha256'],
            'EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST' => ['invalid', 'apply.expected_incident_closeout_artifact_digest.required_digest'],
            'VERIFY_RUN_ID' => ['0', 'apply.verify_run_id.required_numeric'],
            'VERIFY_RUN_ATTEMPT' => ['0', 'apply.verify_run_attempt.required_numeric'],
            'EXPECTED_VERIFY_RECEIPT_SHA256' => ['invalid', 'apply.expected_verify_receipt_sha256.required_sha256'],
            'EXPECTED_VERIFY_ARTIFACT_DIGEST' => ['invalid', 'apply.expected_verify_artifact_digest.required_digest'],
            'OPERATOR_APPROVAL_PHRASE' => ['', 'apply.operator_approval_phrase.required_nonempty'],
        ] as $field => [$value, $invariant]) {
            $environment = $valid['apply'];
            $environment[$field] = $value;
            $invalid[] = ['apply', $environment, $invariant];
        }

        foreach ($invalid as [$mode, $environment, $invariant]) {
            $result = $this->runInputBoundary($mode, $environment);
            self::assertSame(1, $result['exit'], $invariant);
            self::assertSame('HOLD_CONTROL_INCOMPLETE', $result['receipt']['status']);
            self::assertSame('INPUT_BOUNDARY_INVARIANT_FAILED', $result['receipt']['safe_failure_code']);
            self::assertSame($invariant, $result['receipt']['input_boundary']['failed_invariant']);
            self::assertFalse($result['receipt']['input_boundary']['passed']);
            self::assertFalse($result['receipt']['automatic_retry_allowed']);
            self::assertSame('CLOSED', $result['receipt']['career_link_publication_gate']);
            $encoded = json_encode($result['receipt'], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('sensitive-', $encoded);
            self::assertStringNotContainsString('sensitive-', $result['stderr']);
        }

        $multiple = $valid['verify'];
        $multiple['INCIDENT_CLOSEOUT_RUN_ID'] = '0';
        $multiple['INCIDENT_CLOSEOUT_RUN_ATTEMPT'] = '0';
        $result = $this->runInputBoundary('verify', $multiple);
        self::assertSame('verify.incident_closeout_run_id.required_numeric', $result['receipt']['input_boundary']['failed_invariant']);
    }

    private function workflow(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/career-c03-cache-only-discoverability-recovery.yml',
        );
    }

    private function publicReadbackRunner(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2).'/scripts/operations/career_c03_bounded_public_readback.sh',
        );
    }

    /** @return array{exit:int,receipt:array<string,mixed>,stderr:string} */
    private function runInputBoundary(string $mode, array $inputEnvironment): array
    {
        $directory = sys_get_temp_dir().'/career-c03-input-boundary-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $receipt = $directory.'/receipt.json';
        file_put_contents($receipt, json_encode([
            'contract_version' => 'career.c03.cache_only_discoverability_recovery.v1',
            'mode' => $mode,
            'status' => 'HOLD_CONTROL_INCOMPLETE',
            'cache_write_count' => 0,
            'database_write_count' => 0,
            'publication_write_count' => 0,
            'indexability_write_count' => 0,
            'deploy_count' => 0,
            'migration_count' => 0,
            'career_link_publication_gate' => 'CLOSED',
            'automatic_retry_allowed' => false,
        ], JSON_THROW_ON_ERROR));
        $environment = array_merge($_ENV, $inputEnvironment, [
            'MODE' => $mode,
            'INPUT_BOUNDARY_RUNNER_SHA256' => str_repeat('c', 64),
        ]);
        $process = proc_open(
            ['bash', dirname(__DIR__, 2).'/scripts/operations/career_c03_input_boundary_control.sh', $receipt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $decoded = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);
        unlink($receipt);
        rmdir($directory);

        return ['exit' => $exit, 'receipt' => $decoded, 'stderr' => $stderr];
    }

    /**
     * @param  list<string>|null  $urls
     * @return array{exit: int, curl_count: int, rows: list<list<string>>}
     */
    private function runPublicReadback(string $mode, ?array $urls = null): array
    {
        $directory = sys_get_temp_dir().'/career-c03-readback-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $inspection = $directory.'/inspection.json';
        $output = $directory.'/detail-status.tsv';
        $counter = $directory.'/curl-count';
        $fakeCurl = $directory.'/curl';
        file_put_contents($inspection, json_encode([
            'expected_urls' => $urls ?? ['https://fermatmind.com/en/career/jobs/example-job'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($fakeCurl, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
count=0
if [ -f "$FAKE_CURL_COUNT_FILE" ]; then
  count="$(<"$FAKE_CURL_COUNT_FILE")"
fi
count=$((count + 1))
printf '%s' "$count" > "$FAKE_CURL_COUNT_FILE"
case "$FAKE_CURL_MODE" in
  recover18)
    printf '200'
    if [ "$count" -eq 1 ]; then exit 18; fi
    ;;
  persistent18)
    printf '000'
    exit 18
    ;;
  http500)
    printf '500'
    ;;
  success)
    printf '200'
    ;;
  *)
    exit 99
    ;;
esac
BASH);
        chmod($fakeCurl, 0700);

        $environment = array_merge($_ENV, [
            'PATH' => $directory.PATH_SEPARATOR.(string) getenv('PATH'),
            'FAKE_CURL_COUNT_FILE' => $counter,
            'FAKE_CURL_MODE' => $mode,
        ]);
        $process = proc_open(
            ['bash', dirname(__DIR__, 2).'/scripts/operations/career_c03_bounded_public_readback.sh', $inspection, $output],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $rows = [];
        if (is_file($output)) {
            foreach (file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $rows[] = explode("\t", $line);
            }
        }
        $curlCount = is_file($counter) ? (int) file_get_contents($counter) : 0;

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);

        return ['exit' => $exit, 'curl_count' => $curlCount, 'rows' => $rows];
    }
}

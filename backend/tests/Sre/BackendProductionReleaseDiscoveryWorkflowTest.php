<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackendProductionReleaseDiscoveryWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_main_bound_production_protected_and_read_only(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'workflow_dispatch:',
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'cancel-in-progress: false',
            'contents: read',
            'actions: read',
            'test "$GITHUB_REF" = "refs/heads/main"',
            'I explicitly approve read-only backend production release identity discovery.',
            'ref: ${{ github.sha }}',
            'persist-credentials: false',
            'test "$control_plane_sha" = "$GITHUB_SHA"',
            'test "$control_plane_sha" = "$(git rev-parse origin/main)"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function remote_step_reads_only_bounded_managed_release_identity(): void
    {
        $source = $this->workflowSource();
        $remote = $this->between(
            $source,
            '      - name: Read managed production release identities',
            '      - name: Bind inactive releases to exact staging evidence',
        );

        foreach ([
            'test ! -e "$DEPLOY_PATH/.dep/deploy.lock"',
            'current_release="$(readlink -f "$DEPLOY_PATH/current")"',
            'test "${#release_names[@]}" -le 50',
            'test -f "$current_release/REVISION"',
            'if [ ! -f "$release_path/REVISION" ]; then',
            'reason: "revision_missing"',
            'reason: "revision_invalid"',
            'release_anomalies: $release_anomalies',
            'backend-production-release-remote-read.v1',
            'deploy_lock_present: false',
            '2>/dev/null',
            'PRODUCTION_RELEASE_DISCOVERY_REMOTE_READ_FAILED',
        ] as $contract) {
            $this->assertStringContainsString($contract, $remote);
        }

        foreach ([
            'sudo ',
            'php artisan',
            'deploy:symlink',
            'migrate',
            'queue:',
            'supervisorctl',
            'rm ',
            'mv ',
            'cp ',
            'touch ',
            'chmod ',
            'chown ',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $remote);
        }
    }

    #[Test]
    public function candidates_require_main_reachability_and_exact_successful_staging(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'git merge-base --is-ancestor "$revision" origin/main',
            'gh api --paginate --slurp',
            "'[.[].workflow_runs[]] | {workflow_runs: .}'",
            '.name == "Deploy Application"',
            '.path == ".github/workflows/deploy.yml"',
            '.head_branch == "main"',
            '.head_sha == $revision',
            'Deploy checks (staging)',
            'Deploy (staging)',
            'staging_evidence_valid: $staging_evidence_valid',
            'eligible: $eligible',
            'max_by(.staging_run_id)',
            'NO_ELIGIBLE_INACTIVE_CANDIDATE',
            'BLOCKED_RELEASE_ANOMALIES',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function artifact_is_sanitized_and_attests_zero_writes(): void
    {
        $source = $this->workflowSource();

        foreach ([
            'backend-production-release-discovery.v1',
            'candidate_activation: false',
            'cache_write: false',
            'queue_dispatch: false',
            'database_write: false',
            'cms_write: false',
            'migration: false',
            'process_restart: false',
            'remote_file_write: false',
            'raw_log_read: false',
            'search_submit: false',
            'writes_committed: false',
            'release_anomalies_absent: ($release_anomalies | length == 0)',
            'Release identity anomalies: ${anomaly_count}',
            'backend-production-release-discovery-${{ github.run_id }}',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        foreach ([
            'DEPLOY_HOST:',
            'DEPLOY_PORT:',
            'DEPLOY_USER:',
            'DEPLOY_PATH:',
            'SSH_KNOWN_HOSTS:',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $this->artifactContract($source));
        }
    }

    private function workflowSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-production-release-discovery.yml',
        );
    }

    private function artifactContract(string $source): string
    {
        return $this->between(
            $source,
            "          jq -n \\\n            --arg status \"\$status\"",
            ' > artifacts/backend-production-release-discovery.json',
        );
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, (int) $startPosition);
        $this->assertNotFalse($endPosition);

        return substr(
            $source,
            (int) $startPosition,
            (int) $endPosition - (int) $startPosition,
        );
    }
}

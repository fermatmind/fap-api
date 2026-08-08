<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackendGreenfieldCurrentBaselineWorkflowTest extends TestCase
{
    #[Test]
    public function workflow_is_latest_main_bound_production_protected_and_select_only(): void
    {
        $source = $this->source();

        foreach ([
            'environment: production',
            'group: deploy-${{ github.repository }}-production',
            'cancel-in-progress: false',
            'contents: read',
            'test "$GITHUB_REF" = "refs/heads/main"',
            'test "$(git rev-parse origin/main)" = "$EXPECTED_CONTROL_PLANE_SHA"',
            'I explicitly approve SELECT-only production Greenfield current-baseline',
            'retention-days: 3',
            'persist-credentials: false',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function remote_source_step_streams_stdin_and_never_creates_or_mutates_remote_files(): void
    {
        $remote = $this->between(
            $this->source(),
            '      - name: Stream source snapshot through stdin without remote files',
            '      - name: Build and verify deterministic package',
        );

        foreach ([
            'GreenfieldBaselineSourceScript)->render()',
            'EXPECTED_ACTIVE_REVISION=$q_active_revision php',
            '> "$RUNNER_TEMP/greenfield/source.jsonl"',
            'pipeline_status=("${PIPESTATUS[@]}")',
            'producer_status="${pipeline_status[0]}"',
            'remote_status="${pipeline_status[1]}"',
            'GREENFIELD_REMOTE_SOURCE_STREAM_FAILED',
            'GREENFIELD_SOURCE_EXPORT_FAILED_STAGE_[A-Z0-9_]+',
            '.writes_committed == false',
        ] as $contract) {
            $this->assertStringContainsString($contract, $remote);
        }

        foreach ([
            'sudo ', 'php artisan', 'supervisorctl', 'systemctl', 'service ', 'redis-cli',
            'rm ', 'mv ', 'cp ', 'touch ', 'chmod ', 'chown ', 'tee ',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $remote);
        }

        foreach (['cat "$RUNNER_TEMP/greenfield/source.stderr"', 'getMessage()', 'set -x'] as $secretRisk) {
            $this->assertStringNotContainsString($secretRisk, $remote);
        }
    }

    #[Test]
    public function workflow_enforces_frozen_counts_projection_and_zero_write_receipt(): void
    {
        $source = $this->source();

        foreach ([
            '.dataset_counts.articles == 129',
            '.dataset_counts.content_pages == 56',
            '.dataset_counts.personality_public_content_assets == 220',
            '.dataset_counts.occupations == 1046',
            '.dataset_counts.career_job_ai_impact_assets == 2092',
            '.dataset_counts.media_assets == 111',
            '.dataset_counts.media_variants == 660',
            '.media.entry_count == 771',
            '.media.downloaded == (env.MODE == "export")',
            '.career_projection.tracked_slug_count == 342',
            '.career_projection.public_slug_count == 30',
            '.career_projection.state_counts.blocked == 622',
            '.career_projection.state_counts.quarantined == 2',
            'source_database_write: false',
            'source_remote_file_write: false',
            'database_import: false',
            'deployment: false',
            'service_restart: false',
            'dns_change: false',
            'queue_action: false',
            'redis_action: false',
            'writes_committed: false',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }

    #[Test]
    public function artifact_contract_never_exposes_topology_or_credentials(): void
    {
        $receipt = $this->between(
            $this->source(),
            '          jq -n \\',
            ' > artifacts/backend-greenfield-current-baseline-receipt.json',
        );

        foreach ([
            'DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER', 'DEPLOY_PATH', 'SSH_KNOWN_HOSTS',
            'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'url:', 'path:',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $receipt);
        }
    }

    private function source(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/.github/workflows/backend-greenfield-current-baseline.yml',
        );
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $this->assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, (int) $startPosition);
        $this->assertNotFalse($endPosition);

        return substr($source, (int) $startPosition, (int) $endPosition - (int) $startPosition);
    }
}

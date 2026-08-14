<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;

final class Career1046DisplayAssetReplacementControlTest extends TestCase
{
    public function test_html_validator_enforces_rendered_modules_seo_cta_and_no_raw_markdown(): void
    {
        $root = dirname(__DIR__, 3);
        $validator = $root.'/backend/scripts/operations/career_1046_display_asset_html_validate.py';
        $fixture = tempnam(sys_get_temp_dir(), 'career-html-');
        self::assertIsString($fixture);
        $testIds = [
            'career-display-surface', 'career-display-hero', 'definition-block',
            'responsibilities-block', 'career-snapshot-primary', 'career-display-faq',
            'career-ai-description-block', 'career-path-block', 'career-display-cta',
        ];
        $modules = implode('', array_map(
            static fn (string $id): string => '<section data-testid="'.$id.'">Rendered</section>',
            $testIds,
        ));
        $head = '<link rel="canonical" href="https://fermatmind.com/en/career/jobs/actuaries">'
            .'<link rel="alternate" hreflang="en" href="https://fermatmind.com/en/career/jobs/actuaries">'
            .'<link rel="alternate" hreflang="zh-CN" href="https://fermatmind.com/zh/career/jobs/actuaries">'
            .'<meta name="robots" content="index, follow">';
        $cta = '<a data-entry-surface="career_job_detail" data-source-page-type="career_job_detail" '
            .'data-target-action="start_riasec_test" data-test-slug="holland-career-interest-test-riasec">Start</a>';

        try {
            file_put_contents($fixture, '<html><head>'.$head.'</head><body>'.$modules.$cta.'<h3>Rendered heading</h3><blockquote>Quote</blockquote></body></html>');
            exec('python3 '.escapeshellarg($validator).' '.escapeshellarg($fixture).' actuaries en en 2>&1', $output, $status);
            self::assertSame(0, $status, implode("\n", $output));
            self::assertSame(['pass'], $output);

            file_put_contents($fixture, '<html><head>'.$head.'</head><body>'.$modules.$cta.'<p>## **raw**</p></body></html>');
            $output = [];
            exec('python3 '.escapeshellarg($validator).' '.escapeshellarg($fixture).' actuaries en en 2>&1', $output, $status);
            self::assertSame(1, $status);
            self::assertSame(['web_raw_markdown'], $output);
        } finally {
            @unlink($fixture);
        }
    }

    public function test_the_standalone_runner_emits_a_sanitized_receipt_before_composer_is_loaded(): void
    {
        $runner = dirname(__DIR__, 3).'/backend/scripts/operations/career_1046_display_asset_replacement.php';
        $output = [];
        $status = 0;

        exec(
            'CAREER_DISPLAY_REPLACEMENT_BACKEND_ROOT= CAREER_DISPLAY_REPLACEMENT_EXECUTE= '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner).' 2>&1',
            $output,
            $status,
        );

        self::assertSame(1, $status);
        $receipt = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('career.1046.display_asset_replacement.v2', $receipt['contract_version']);
        self::assertSame('execute', $receipt['mode']);
        self::assertSame('FAIL_DISPLAY_ASSET_REPLACEMENT', $receipt['status']);
        self::assertSame('EXECUTION_CONTRACT_INVALID', $receipt['safe_error_code']);
        self::assertTrue($receipt['production_write_execution']);
        self::assertSame('ambiguous', $receipt['write_commit_state']);
        self::assertFalse($receipt['writes_committed']);
        self::assertFalse($receipt['automatic_retry_allowed']);
    }

    public function test_the_control_plane_is_one_protected_execute_with_internal_plan_and_full_readback(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = (string) file_get_contents($root.'/.github/workflows/career-1046-display-asset-replacement.yml');
        $runner = (string) file_get_contents($root.'/backend/scripts/operations/career_1046_display_asset_replacement.php');
        $service = (string) file_get_contents($root.'/backend/app/Domain/Career/Display/Career1046DisplayAssetReplacement.php');
        $cache = (string) file_get_contents($root.'/backend/app/Services/Career/PublicCareerAuthorityResponseCache.php');
        $validator = (string) file_get_contents($root.'/backend/scripts/operations/career_1046_display_asset_live_validate.sh');

        self::assertStringContainsString('mode=execute', $workflow);
        self::assertStringContainsString('Elect display-asset operation owner before Environment access', $workflow);
        self::assertLessThan(strpos($workflow, 'environment: production'), strpos($workflow, 'name: Elect display-asset operation owner'));
        self::assertStringContainsString('git merge-base --is-ancestor "$CONTROL" origin/main', $workflow);
        self::assertStringContainsString('REVISION', $workflow);
        self::assertStringContainsString('Execute exact production replacement without mutation retry', $workflow);
        self::assertStringContainsString('Validate all 2092 public pages', $workflow);
        self::assertStringContainsString('career.workbuddy_1046_display_asset_package.v2', $workflow);
        self::assertStringNotContainsString('operator_approval_phrase', $workflow);
        self::assertStringNotContainsString('expected_preflight_receipt_sha256', $workflow);
        self::assertStringNotContainsString('expected_preflight_state_sha256', $workflow);
        self::assertStringNotContainsString('options: [preflight, apply]', $workflow);
        self::assertStringNotContainsString('run_remote_once', $workflow);

        self::assertStringContainsString('$replacement->execute(', $runner);
        self::assertStringContainsString("'idempotent_noop'", $runner);
        self::assertStringContainsString("'automatic_retry_allowed' => false", $runner);
        self::assertStringContainsString("'task_4b_through_7b_executed' => false", $runner);
        self::assertStringContainsString("'sitemap_or_llms_release_executed' => false", $runner);
        self::assertStringContainsString("'search_channel_executed' => false", $runner);

        self::assertStringContainsString('public function execute(', $service);
        self::assertStringNotContainsString('public function preflight(', $service);
        self::assertStringNotContainsString('public function apply(', $service);
        self::assertStringContainsString('if ($plan[\'state\'] === \'applied\')', $service);
        self::assertStringContainsString('APPLIED_REPLACEMENT_STATE_HASH_MISMATCH', $service);
        self::assertStringContainsString('DB::transaction', $service);
        self::assertStringContainsString('->lockForUpdate()', $service);
        self::assertStringContainsString('assertActiveCacheReadback', $service);
        self::assertStringContainsString('restorePreparedJobDetailExposurePointers', $service);
        self::assertStringContainsString('restoreDatabaseRows', $service);
        self::assertStringContainsString('AI_NUMERIC_RATING_RESIDUE', $service);
        self::assertStringContainsString("'replacement_lineage'", $service);
        self::assertStringContainsString("'workbook_sha256'", $service);
        self::assertStringContainsString("'mapper_version'", $service);
        self::assertStringContainsString("'validator_version'", $service);

        self::assertStringContainsString('rollback_snapshots', $cache);
        self::assertStringContainsString('restorePreparedJobDetailExposurePointers', $cache);
        self::assertStringContainsString('jobDetailVersionPayloadKey', $cache);

        self::assertStringNotContainsString('--retry-all-errors', $validator);
        self::assertStringContainsString('api_ai_aggregate_hash', $validator);
        self::assertStringContainsString('category_counts', $validator);
        self::assertStringContainsString('samples:', $validator);
        self::assertStringNotContainsString('Task 3A', $service);
        self::assertStringNotContainsString('IndexNow', $service);
    }
}

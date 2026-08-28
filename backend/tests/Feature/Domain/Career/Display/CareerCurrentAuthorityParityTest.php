<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityParity;
use RuntimeException;
use Tests\TestCase;

final class CareerCurrentAuthorityParityTest extends TestCase
{
    public function test_it_runs_the_fixed_slice_then_all_2092_pages_without_authority_writes(): void
    {
        $receipt = app(CareerCurrentAuthorityParity::class)->run(
            base_path(),
            CareerCurrentAuthorityParity::MODE_PACKAGE,
            'none',
            str_repeat('a', 40),
        );

        self::assertSame(CareerCurrentAuthorityParity::PACKAGE_SCAN_CONTRACT_VERSION, $receipt['contract_version']);
        self::assertSame(CareerCurrentAuthorityParity::MODE_PACKAGE, $receipt['mode']);
        self::assertSame('pass', $receipt['status']);
        self::assertSame(4, $receipt['architecture_slice']['counts']['locale_pages']);
        self::assertSame(2, $receipt['architecture_slice']['content_states']['enhanced']);
        self::assertSame(2, $receipt['architecture_slice']['content_states']['legacy']);
        self::assertSame(1046, $receipt['full_scan']['counts']['slugs']);
        self::assertSame(2092, $receipt['full_scan']['counts']['locale_pages']);
        self::assertSame(2092, $receipt['full_scan']['counts']['encoded']);
        self::assertSame(2092, $receipt['full_scan']['counts']['decoded']);
        self::assertGreaterThan(0, $receipt['full_scan']['bytes']['max_single_key']);
        self::assertSame(
            $receipt['full_scan']['bytes']['candidate_total']
                + $receipt['full_scan']['bytes']['active_total']
                + $receipt['full_scan']['bytes']['lkg_total'],
            $receipt['full_scan']['bytes']['worst_state_amplification'],
        );
        self::assertLessThan(
            CareerCurrentAuthorityParity::LOCKED_CAREER_BUDGET_BYTES,
            $receipt['full_scan']['bytes']['worst_state_amplification'],
        );
        self::assertSame([
            'database_write_count' => 0,
            'cache_write_count' => 0,
            'discoverability_write_count' => 0,
            'search_write_count' => 0,
        ], $receipt['write_counts']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $receipt['receipt_digest']);
    }

    public function test_it_is_deterministic_for_the_same_sha_package_compiler_and_codec(): void
    {
        $parity = app(CareerCurrentAuthorityParity::class);
        $first = $parity->run(base_path(), CareerCurrentAuthorityParity::MODE_PACKAGE, 'none', str_repeat('b', 40));
        $second = $parity->run(base_path(), CareerCurrentAuthorityParity::MODE_PACKAGE, 'none', str_repeat('b', 40));

        self::assertSame($first['receipt_digest'], $second['receipt_digest']);
        self::assertSame($first['full_scan']['aggregate_hashes'], $second['full_scan']['aggregate_hashes']);
        self::assertSame($first['full_scan']['bytes'], $second['full_scan']['bytes']);
    }

    public function test_deploy_runs_real_production_parity_before_mutations_and_symlink_without_staging_scan(): void
    {
        $root = dirname(__DIR__, 6);
        $deploy = (string) file_get_contents($root.'/deploy.php');
        $workflow = (string) file_get_contents($root.'/.github/workflows/deploy.yml');
        $task = 'career:current-authority-production-preactivation-parity';

        self::assertStringContainsString("after('artisan:config:cache', '".$task."')", $deploy);
        self::assertStringContainsString("after('".$task."', 'guard:sitemap-authority')", $deploy);
        self::assertLessThan(
            strpos($deploy, "after('guard:no-pending-migrations', 'artisan:migrate-seo-intel')"),
            strpos($deploy, "after('artisan:config:cache', '".$task."')"),
        );
        self::assertStringContainsString("-o career_current_parity_required='", $workflow);
        self::assertStringNotContainsString('Run staging zero-write 2092-page Career parity', $workflow);
        self::assertStringContainsString('career.current_authority_parity.v2', $workflow);
        self::assertStringContainsString('CAREER_CURRENT_PUBLISH_PRODUCTION_PARITY_RECEIPT_DIGEST', $workflow);
        self::assertStringNotContainsString('CAREER_CURRENT_PUBLISH_STAGING_PARITY_RECEIPT_DIGEST', $workflow);
    }

    public function test_it_fails_closed_when_serialized_or_observed_redis_capacity_exceeds_the_budget(): void
    {
        foreach ([[101, 0], [0, 101]] as [$worstStateBytes, $redisMemoryUsageBytes]) {
            try {
                CareerCurrentAuthorityParity::assertCapacityWithinBudget(
                    $worstStateBytes,
                    $redisMemoryUsageBytes,
                    100,
                );
                self::fail('Expected the Career parity capacity guard to fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('CAREER_PARITY_REDIS_BUDGET_EXCEEDED', $exception->getMessage());
            }
        }
    }

    public function test_production_mode_cannot_run_without_readonly_redis(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CAREER_PARITY_MODE_REDIS_MISMATCH');

        app(CareerCurrentAuthorityParity::class)->run(
            base_path(),
            CareerCurrentAuthorityParity::MODE_PRODUCTION_PREACTIVATION,
            'none',
            str_repeat('a', 40),
            str_repeat('b', 40),
        );
    }
}

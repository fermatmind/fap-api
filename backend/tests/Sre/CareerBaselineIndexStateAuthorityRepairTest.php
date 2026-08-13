<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\CareerBaselineIndexStateAuthorityRepair;
use FermatMind\Operations\CareerBaselineIndexStateAuthorityRepairFailure;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2).'/scripts/operations/career_baseline_index_state_authority_repair.php';

final class CareerBaselineIndexStateAuthorityRepairTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    /** @var list<string> */
    private array $baseline;

    /** @var list<string> */
    private array $delta;

    /** @var list<string> */
    private array $target;

    protected function setUp(): void
    {
        parent::setUp();
        $bytes = file_get_contents(dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json');
        self::assertIsString($bytes);
        self::assertSame(CareerBaselineIndexStateAuthorityRepair::MANIFEST_SHA256, hash('sha256', $bytes));
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $this->manifest = $manifest;
        $this->baseline = $this->slugs($manifest['baseline_slugs']);
        $this->delta = $this->slugs($manifest['delta_slugs']);
        $this->target = $this->slugs([...$this->baseline, ...$this->delta]);
    }

    public function test_missing_baseline_states_produce_exact_bounded_repair_set(): void
    {
        $analysis = CareerBaselineIndexStateAuthorityRepair::analyze(
            $this->manifest,
            $this->occupations(),
            [],
        );

        self::assertSame(30, $analysis['baseline']['repair_target_count']);
        self::assertSame(CareerBaselineIndexStateAuthorityRepair::BASELINE_SET_SHA256, $analysis['baseline']['repair_target_set_sha256']);
        self::assertSame(30, $analysis['baseline']['missing_count']);
        self::assertSame(0, $analysis['baseline']['matching_count']);
        self::assertSame(1016, $analysis['delta']['latest_state_missing_count']);
        self::assertSame(0, $analysis['delta']['latest_state_tie_count']);
    }

    public function test_locale_row_identity_hash_preserves_canonical_locale_case(): void
    {
        $rows = [];
        foreach ($this->baseline as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh-CN';
        }

        self::assertSame(
            CareerBaselineIndexStateAuthorityRepair::BASELINE_LOCALE_ROW_SET_SHA256,
            CareerBaselineIndexStateAuthorityRepair::identitySetHash($rows),
        );
        self::assertNotSame(
            CareerBaselineIndexStateAuthorityRepair::BASELINE_LOCALE_ROW_SET_SHA256,
            CareerBaselineIndexStateAuthorityRepair::setHash($rows),
        );
    }

    public function test_exact_baseline_is_already_repaired_and_delta_snapshot_is_unchanged(): void
    {
        $analysis = CareerBaselineIndexStateAuthorityRepair::analyze(
            $this->manifest,
            $this->occupations(),
            $this->baselineStates(),
        );

        self::assertSame(30, $analysis['baseline']['preserved_count']);
        self::assertSame(30, $analysis['baseline']['matching_count']);
        self::assertSame(CareerBaselineIndexStateAuthorityRepair::BASELINE_SET_SHA256, $analysis['baseline']['matching_set_sha256']);
        self::assertSame(0, $analysis['baseline']['repair_target_count']);
        self::assertSame(CareerBaselineIndexStateAuthorityRepair::EMPTY_SET_SHA256, $analysis['baseline']['repair_target_set_sha256']);
        self::assertSame(0, $analysis['delta']['matching_count']);
        self::assertSame(1016, $analysis['delta']['missing_or_mismatching_count']);
    }

    public function test_each_semantic_drift_is_counted_and_hashed_without_exposing_slugs(): void
    {
        $states = $this->baselineStates();
        $states[0]['index_state'] = 'noindex';
        $states[1]['index_eligible'] = false;
        $states[2]['canonical_path'] = '/career/jobs/wrong';
        $states[3]['canonical_target'] = '/career/jobs/redirect';
        $states[4]['reason_codes'] = ['unrelated_reason'];

        $analysis = CareerBaselineIndexStateAuthorityRepair::analyze($this->manifest, $this->occupations(), $states);

        self::assertSame(5, $analysis['baseline']['repair_target_count']);
        foreach ([
            'state_mismatch_count',
            'eligibility_mismatch_count',
            'canonical_path_mismatch_count',
            'canonical_target_mismatch_count',
            'promotion_reason_mismatch_count',
        ] as $field) {
            self::assertSame(1, $analysis['baseline'][$field]);
        }
        foreach ($analysis['baseline'] as $field => $value) {
            if (str_ends_with((string) $field, '_set_sha256')) {
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $value);
            }
        }
    }

    public function test_duplicate_occupation_and_latest_timestamp_tie_fail_closed(): void
    {
        $occupations = $this->occupations();
        $occupations[] = $occupations[0];
        try {
            CareerBaselineIndexStateAuthorityRepair::analyze($this->manifest, $occupations, []);
            self::fail('Duplicate occupation must fail closed.');
        } catch (CareerBaselineIndexStateAuthorityRepairFailure $failure) {
            self::assertSame('OCCUPATION_IDENTITY_INVALID_OR_DUPLICATE', $failure->safeCode);
        }

        $states = $this->baselineStates();
        $states[] = [...$states[0], 'id' => 'baseline-state-tie'];
        $analysis = CareerBaselineIndexStateAuthorityRepair::analyze($this->manifest, $this->occupations(), $states);
        self::assertSame(1, $analysis['baseline']['latest_state_tie_count']);
    }

    public function test_bound_json_refuses_symlink_hardlink_and_path_escape(): void
    {
        $root = sys_get_temp_dir().'/career-baseline-bound-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $bytes = "{\"ok\":true}\n";
        $file = $root.'/authority.json';
        file_put_contents($file, $bytes);
        $method = new ReflectionMethod(CareerBaselineIndexStateAuthorityRepair::class, 'readExactJson');
        self::assertSame(['ok' => true], $method->invoke(null, $root, $file, hash('sha256', $bytes), 'TEST'));

        $outside = $root.'-outside.json';
        file_put_contents($outside, $bytes);
        $symlink = $root.'/symlink.json';
        symlink($outside, $symlink);
        $this->expectBoundFailure($method, $root, $symlink, hash('sha256', $bytes));
        unlink($symlink);

        $hardlink = $root.'/hardlink.json';
        link($file, $hardlink);
        $this->expectBoundFailure($method, $root, $hardlink, hash('sha256', $bytes));
        unlink($hardlink);
        $this->expectBoundFailure($method, $root, $outside, hash('sha256', $bytes));

        unlink($file);
        unlink($outside);
        rmdir($root);
    }

    public function test_workflow_is_receipt_bound_append_only_and_deploy_ignored(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = (string) file_get_contents($root.'/.github/workflows/career-baseline-index-state-authority-repair.yml');
        $runner = (string) file_get_contents($root.'/backend/scripts/operations/career_baseline_index_state_authority_repair.php');
        $deploy = (string) file_get_contents($root.'/.github/workflows/deploy.yml');

        self::assertStringContainsString('options: [preflight, apply, readback]', $workflow);
        self::assertStringContainsString('uses: ./.github/actions/controlled-operation-gate', $workflow);
        self::assertLessThan(strpos($workflow, 'environment: production'), strpos($workflow, 'operation_gate:'));
        self::assertStringContainsString('test "$(( $(date -u +%s) - $(date -u -d "$completed" +%s) ))" -le 21600', $workflow);
        self::assertStringContainsString('PREWRITE_AUTHORITY_NOT_EXACT', $workflow);
        self::assertStringContainsString('writes_committed == false', $workflow);
        self::assertStringContainsString('writes_committed = null', $workflow);
        self::assertStringContainsString('IndexState::query()->create([', $runner);
        self::assertStringContainsString('DB::transaction(', $runner);
        self::assertStringContainsString('->lockForUpdate()', $runner);
        foreach (['->update(', '->delete(', 'DB::statement(', 'DB::unprepared(', 'Artisan::call(', 'file_put_contents(', 'rename('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $runner);
        }
        foreach ([
            '.github/workflows/career-baseline-index-state-authority-repair.yml',
            'backend/scripts/operations/career_baseline_index_state_authority_repair.php',
            'backend/tests/Sre/CareerBaselineIndexStateAuthorityRepairTest.php',
            'docs/operations/career-baseline-index-state-authority-repair.md',
        ] as $ignored) {
            self::assertStringContainsString('- "'.$ignored.'"', $deploy);
        }
    }

    /** @return list<array{id:string,canonical_slug:string}> */
    private function occupations(): array
    {
        return array_map(
            static fn (string $slug, int $index): array => [
                'id' => 'occupation-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'canonical_slug' => $slug,
            ],
            $this->target,
            array_keys($this->target),
        );
    }

    /** @return list<array<string,mixed>> */
    private function baselineStates(): array
    {
        $occupationIndex = array_flip($this->target);

        return array_map(static fn (string $slug, int $index): array => [
            'id' => 'baseline-state-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'occupation_id' => 'occupation-'.str_pad((string) $occupationIndex[$slug], 4, '0', STR_PAD_LEFT),
            'index_state' => 'indexed',
            'index_eligible' => true,
            'canonical_path' => '/career/jobs/'.$slug,
            'canonical_target' => '',
            'reason_codes' => ['canonical_rollout_batch_promotion'],
            'changed_at' => '2026-08-12T00:00:00.000000+00:00',
            'created_at' => '2026-08-12T00:00:00.000000+00:00',
        ], $this->baseline, array_keys($this->baseline));
    }

    /** @return list<string> */
    private function slugs(mixed $values): array
    {
        self::assertIsArray($values);
        $result = array_values(array_unique(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $values,
        )));
        sort($result, SORT_STRING);

        return $result;
    }

    private function expectBoundFailure(ReflectionMethod $method, string $root, string $path, string $sha): void
    {
        try {
            $method->invoke(null, $root, $path, $sha, 'TEST');
            self::fail('Unsafe path must fail closed.');
        } catch (CareerBaselineIndexStateAuthorityRepairFailure $failure) {
            self::assertSame('TEST_PATH_INVALID', $failure->safeCode);
        }
    }
}

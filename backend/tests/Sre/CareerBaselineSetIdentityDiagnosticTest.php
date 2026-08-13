<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Operations\CareerBaselineSetIdentityDiagnostic;
use FermatMind\Operations\CareerBaselineSetIdentityDiagnosticFailure;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2).'/scripts/operations/career_baseline_set_identity_diagnostic.php';

final class CareerBaselineSetIdentityDiagnosticTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $manifest;

    /** @var list<string> */
    private array $baseline;

    /** @var list<string> */
    private array $delta;

    /** @var list<string> */
    private array $authority;

    protected function setUp(): void
    {
        parent::setUp();
        $bytes = file_get_contents(dirname(__DIR__, 2).'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json');
        self::assertIsString($bytes);
        self::assertSame(CareerBaselineSetIdentityDiagnostic::MANIFEST_SHA256, hash('sha256', $bytes));
        $this->manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $this->baseline = $this->slugs($this->manifest['baseline_slugs']);
        $this->delta = $this->slugs($this->manifest['delta_slugs']);
        $this->authority = $this->slugs([...$this->baseline, ...array_slice($this->delta, 0, 312)]);
        self::assertCount(342, $this->authority);
    }

    public function test_exact_sets_classify_as_exact(): void
    {
        $projection = $this->projection($this->authority, $this->baseline);
        $result = CareerBaselineSetIdentityDiagnostic::analyzeSetRelations(
            $this->manifest,
            $projection,
            $projection,
            $projection,
            $this->delta,
        );

        self::assertSame('CONTROL_CALCULATION_ERROR', $result['primary_classification']);
        self::assertTrue($result['findings']['legacy_locale_hash_normalization_error']);
        self::assertFalse($result['findings']['raw_loader_set_mismatch']);
        self::assertSame(30, $result['actual_published']['count']);
        self::assertSame(60, $result['actual_published_locale_rows']['count']);
        self::assertSame(1016, $result['receipt_to_actual_delta']['covered']['count']);
        self::assertSame(0, $result['actual_only']['count']);
        self::assertSame(0, $result['manifest_only']['count']);
        self::assertSame(CareerBaselineSetIdentityDiagnostic::V1_BASELINE_LOCALE_ROW_SET_SHA256, $result['actual_published_locale_rows']['set_sha256']);
        self::assertSame(CareerBaselineSetIdentityDiagnostic::TARGET_LOCALE_ROW_SET_SHA256, $result['target_locale_rows']['set_sha256']);
    }

    public function test_valid_pointer_partition_drift_and_receipt_mismatch_are_both_reported(): void
    {
        $actual = $this->baseline;
        array_shift($actual);
        $actual[] = $this->delta[0];
        $actual = $this->slugs($actual);
        $projection = $this->projection($this->authority, $actual);
        $result = CareerBaselineSetIdentityDiagnostic::analyzeSetRelations(
            $this->manifest,
            $projection,
            $projection,
            $projection,
            $this->delta,
        );

        self::assertSame('MANIFEST_BASELINE_PARTITION_STALE', $result['primary_classification']);
        self::assertTrue($result['findings']['manifest_baseline_partition_stale']);
        self::assertTrue($result['findings']['receipt_delta_partition_mismatch']);
        self::assertSame(1, $result['actual_only']['count']);
        self::assertSame(1, $result['manifest_only']['count']);
        self::assertSame(1, $result['receipt_to_actual_delta']['missing']['count']);
        self::assertSame(1, $result['receipt_to_actual_delta']['outside']['count']);
        self::assertSame(1, $result['receipt_to_actual_delta']['baseline_overlap']['count']);
    }

    public function test_raw_loader_drift_has_highest_priority(): void
    {
        $raw = $this->projection($this->authority, $this->baseline);
        $loader = $raw;
        foreach ($loader['items'] as &$item) {
            if ($item['runtime_publish_state'] === 'published') {
                $item['runtime_publish_state'] = 'blocked';
                break;
            }
        }
        unset($item);
        $result = CareerBaselineSetIdentityDiagnostic::analyzeSetRelations(
            $this->manifest,
            $raw,
            $loader,
            $raw,
            $this->delta,
        );

        self::assertSame('CONTROL_CALCULATION_ERROR', $result['primary_classification']);
        self::assertTrue($result['findings']['control_calculation_error']);
        self::assertTrue($result['findings']['raw_loader_set_mismatch']);
    }

    public function test_outside_target_or_forbidden_published_slug_is_invalid(): void
    {
        $authority = $this->authority;
        $published = $this->baseline;
        $removed = array_shift($published);
        self::assertIsString($removed);
        $published[] = 'software-developers';
        $authority = array_values(array_diff($authority, [$removed]));
        $authority[] = 'software-developers';
        $projection = $this->projection($this->slugs($authority), $this->slugs($published));
        $result = CareerBaselineSetIdentityDiagnostic::analyzeSetRelations(
            $this->manifest,
            $projection,
            $projection,
            $projection,
            $this->delta,
        );

        self::assertSame('PROJECTION_PUBLISHED_SET_INVALID', $result['primary_classification']);
        self::assertSame(1, $result['actual_to_target']['outside']['count']);
        self::assertSame(1, $result['actual_to_target']['forbidden']['count']);
    }

    public function test_database_classification_is_sanitized_and_exact(): void
    {
        $scope = ['alpha-role', 'beta-role', 'gamma-role'];
        $occupations = [
            ['id' => '1', 'canonical_slug' => 'alpha-role'],
            ['id' => '2', 'canonical_slug' => 'beta-role'],
            ['id' => '3', 'canonical_slug' => 'gamma-role'],
            ['id' => '4', 'canonical_slug' => 'outside-sibling-role'],
        ];
        $states = [
            $this->state('a', '1', 'alpha-role'),
            $this->state('b', '2', 'beta-role', eligible: false),
            $this->state('d', '4', 'outside-sibling-role'),
        ];
        $result = CareerBaselineSetIdentityDiagnostic::analyzeDatabase($scope, $occupations, $states);

        self::assertSame(1, $result['matching']['count']);
        self::assertSame(1, $result['mismatching']['count']);
        self::assertSame(1, $result['missing']['count']);
        self::assertSame(1, $result['eligibility_mismatch']['count']);
        self::assertArrayNotHasKey('slugs', $result['matching']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $result['current_state_sha256']);
    }

    public function test_bound_json_refuses_symlink_hardlink_and_escape(): void
    {
        $root = sys_get_temp_dir().'/career-set-diagnostic-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $bytes = "{\"ok\":true}\n";
        $file = $root.'/authority.json';
        file_put_contents($file, $bytes);
        $method = new ReflectionMethod(CareerBaselineSetIdentityDiagnostic::class, 'readExactJson');
        self::assertSame(['ok' => true], $method->invoke(null, $root, $file, hash('sha256', $bytes), 'TEST'));

        $outside = $root.'-outside.json';
        file_put_contents($outside, $bytes);
        $symlink = $root.'/symlink.json';
        symlink($outside, $symlink);
        $this->expectPathFailure($method, $root, $symlink, hash('sha256', $bytes));
        unlink($symlink);
        $hardlink = $root.'/hardlink.json';
        link($file, $hardlink);
        $this->expectPathFailure($method, $root, $hardlink, hash('sha256', $bytes));
        unlink($hardlink);
        $this->expectPathFailure($method, $root, $outside, hash('sha256', $bytes));
        unlink($file);
        unlink($outside);
        rmdir($root);
    }

    public function test_workflow_is_zero_write_sanitized_and_deploy_ignored(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = (string) file_get_contents($root.'/.github/workflows/career-baseline-set-identity-diagnostic.yml');
        $runner = (string) file_get_contents($root.'/backend/scripts/operations/career_baseline_set_identity_diagnostic.php');
        $deploy = (string) file_get_contents($root.'/.github/workflows/deploy.yml');

        self::assertStringContainsString('uses: ./.github/actions/controlled-operation-gate', $workflow);
        self::assertLessThan(strpos($workflow, 'environment: production'), strpos($workflow, 'operation_gate:'));
        self::assertStringContainsString('CareerVerifiedRolloutBatchSlugAuthority::class', $runner);
        self::assertStringContainsString('CareerGenerationAuthorityLoader::class', $runner);
        foreach (['CONTROL_CALCULATION_ERROR', 'PROJECTION_PUBLISHED_SET_INVALID', 'MANIFEST_BASELINE_PARTITION_STALE', 'RECEIPT_DELTA_PARTITION_MISMATCH'] as $classification) {
            self::assertStringContainsString($classification, $runner);
        }
        self::assertStringContainsString('.findings.projection_published_set_invalid == true', $workflow);
        self::assertStringNotContainsString('.primary_classification == "PROJECTION_PUBLISHED_SET_INVALID"', $workflow);
        foreach (['DB::transaction(', '->create(', '->update(', '->delete(', 'file_put_contents(', 'rename(', 'curl -k', '--insecure'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $runner.$workflow);
        }
        foreach ([
            '.github/workflows/career-baseline-set-identity-diagnostic.yml',
            'backend/scripts/operations/career_baseline_set_identity_diagnostic.php',
            'backend/tests/Sre/CareerBaselineSetIdentityDiagnosticTest.php',
            'docs/operations/career-baseline-set-identity-diagnostic.md',
        ] as $ignored) {
            self::assertStringContainsString('- "'.$ignored.'"', $deploy);
        }
    }

    /** @return array<string,mixed> */
    private function projection(array $authority, array $published): array
    {
        $items = [];
        foreach ($authority as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $isPublished = in_array($slug, $published, true);
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => $isPublished ? 'public_canonical_job' : 'blocked_until_governance_approval',
                    'runtime_publish_state' => $isPublished ? 'published' : 'blocked',
                    'release_gate_pass' => $isPublished,
                ];
            }
        }

        return [
            'projection_kind' => 'career_runtime_publish_projection',
            'projection_version' => 'career.runtime_publish_projection.v1',
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => $items,
        ];
    }

    /** @return array<string,mixed> */
    private function state(string $id, string $occupationId, string $slug, bool $eligible = true): array
    {
        return [
            'id' => $id,
            'occupation_id' => $occupationId,
            'index_state' => 'indexed',
            'index_eligible' => $eligible,
            'canonical_path' => '/career/jobs/'.$slug,
            'canonical_target' => '',
            'reason_codes' => ['canonical_rollout_batch_promotion'],
            'changed_at' => '2026-08-13T00:00:00.000000+00:00',
            'created_at' => '2026-08-13T00:00:00.000000+00:00',
        ];
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

    private function expectPathFailure(ReflectionMethod $method, string $root, string $path, string $sha): void
    {
        try {
            $method->invoke(null, $root, $path, $sha, 'TEST');
            self::fail('Unsafe path must fail closed.');
        } catch (CareerBaselineSetIdentityDiagnosticFailure $failure) {
            self::assertSame('TEST_PATH_INVALID', $failure->safeCode);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerCanonicalEligibilityStatus;
use App\Domain\Career\Audit\CareerPublicResolutionPlanIssue;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use PHPUnit\Framework\TestCase;

final class CareerPublicResolutionPlanResolverTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                unlink($path);
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_missing_plan_file_returns_blocked_issue(): void
    {
        $result = CareerPublicResolutionPlanResolver::fromPath($this->tempPath('missing.json'), 2786);

        $this->assertSame(CareerCanonicalEligibilityStatus::BLOCKED, $result->status);
        $this->assertSame(CareerPublicResolutionPlanIssue::PLAN_FILE_MISSING, $result->issues[0]->reason);
        $this->assertSame(2786, $result->expectedRows);
        $this->assertSame(0, $result->foundRows());
    }

    public function test_invalid_json_returns_plan_json_invalid(): void
    {
        $path = $this->writeRaw('{"rows": [');

        $result = CareerPublicResolutionPlanResolver::fromPath($path);

        $this->assertSame(CareerCanonicalEligibilityStatus::FAIL, $result->status);
        $this->assertSame(CareerPublicResolutionPlanIssue::PLAN_JSON_INVALID, $result->issues[0]->reason);
    }

    public function test_unsupported_shape_returns_shape_or_rows_issue(): void
    {
        $path = $this->writePlan(['workbook' => ['rows' => 2786]]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path);

        $this->assertSame(CareerCanonicalEligibilityStatus::FAIL, $result->status);
        $this->assertContains($result->issues[0]->reason, [
            CareerPublicResolutionPlanIssue::UNSUPPORTED_PLAN_SHAPE,
            CareerPublicResolutionPlanIssue::PLAN_ROWS_MISSING,
        ]);
    }

    public function test_valid_rows_shape_parses_into_normalized_rows(): void
    {
        $path = $this->writePlan([
            'workbook' => ['rows' => 2],
            'rows' => [
                $this->row(2, 'Actuaries', 'upload_candidate', [
                    'title' => ['en' => 'Actuaries', 'zh-CN' => 'Jing suan shi'],
                    'source_code' => '15-2011.00',
                    'family' => 'math',
                    'batch_id' => 'batch-001',
                    'locales' => ['en', 'zh-CN'],
                ]),
                $this->row(3, 'cn-proxy-sample', 'CN_proxy_hold'),
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(2, $result->foundRows());
        $this->assertSame('actuaries', $result->rows()[0]->canonicalSlug);
        $this->assertSame('upload_candidate', $result->rows()[0]->publicResolutionState);
        $this->assertSame('Actuaries', $result->rows()[0]->titleEn);
        $this->assertSame('Jing suan shi', $result->rows()[0]->titleZh);
        $this->assertSame(['en', 'zh-CN'], $result->rows()[0]->locales);
    }

    public function test_duplicate_canonical_slug_produces_duplicate_issue(): void
    {
        $path = $this->writePlan([
            'rows' => [
                $this->row(2, 'actuaries', 'upload_candidate'),
                $this->row(3, 'actuaries', 'CN_proxy_hold'),
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path);

        $this->assertSame(CareerCanonicalEligibilityStatus::FAIL, $result->status);
        $this->assertSame(CareerPublicResolutionPlanIssue::CANONICAL_SLUG_DUPLICATE, $result->issues[0]->reason);
        $this->assertSame('actuaries', $result->issues[0]->canonicalSlug);
    }

    public function test_missing_canonical_slug_produces_missing_slug_issue(): void
    {
        $path = $this->writePlan([
            'rows' => [
                [
                    'row_number' => 2,
                    'status' => 'upload_candidate',
                ],
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path);

        $this->assertSame(CareerCanonicalEligibilityStatus::FAIL, $result->status);
        $this->assertSame(CareerPublicResolutionPlanIssue::CANONICAL_SLUG_MISSING, $result->issues[0]->reason);
        $this->assertSame(['row_number' => 2, 'status' => 'upload_candidate'], $result->rows()[0]->raw);
    }

    public function test_expected_row_count_mismatch_produces_issue(): void
    {
        $path = $this->writePlan(['rows' => [$this->row(2, 'actuaries', 'upload_candidate')]]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::FAIL, $result->status);
        $this->assertSame(CareerPublicResolutionPlanIssue::EXPECTED_ROW_COUNT_MISMATCH, $result->issues[0]->reason);
        $this->assertSame(['expected_row_count_mismatch' => 1], $result->byReason());
    }

    public function test_expected_row_count_pass_works(): void
    {
        $path = $this->writePlan([
            'rows' => [
                $this->row(2, 'actuaries', 'upload_candidate'),
                $this->row(3, 'cn-proxy-sample', 'CN_proxy_hold'),
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 2);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame(2, $result->expectedRows);
        $this->assertSame([], $result->issues);
    }

    public function test_raw_row_is_preserved(): void
    {
        $raw = $this->row(2, 'actuaries', 'upload_candidate', ['custom_field' => ['nested' => true]]);
        $path = $this->writePlan(['rows' => [$raw]]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 1);

        $this->assertSame($raw, $result->rows()[0]->raw);
        $this->assertSame(['nested' => true], $result->rows()[0]->raw['custom_field']);
    }

    public function test_resolver_does_not_hit_db(): void
    {
        $path = $this->writePlan(['rows' => [$this->row(2, 'actuaries', 'upload_candidate')]]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 1);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
    }

    public function test_result_to_array_is_stable(): void
    {
        $path = $this->writePlan(['rows' => [$this->row(2, 'actuaries', 'upload_candidate')]]);
        $result = CareerPublicResolutionPlanResolver::fromPath($path, 1)->toArray();
        $result['source_path'] = '__PATH__';
        $result['checksum'] = '__CHECKSUM__';

        $this->assertSame(
            <<<'JSON'
{
    "status": "pass",
    "expected_rows": 1,
    "found_rows": 1,
    "source_path": "__PATH__",
    "checksum": "__CHECKSUM__",
    "by_reason": [],
    "rows": [
        {
            "row_number": 2,
            "canonical_slug": "actuaries",
            "public_resolution_state": "upload_candidate",
            "canonical_public_type": null,
            "rollout_state": null,
            "projection_state": null,
            "index_state_hint": null,
            "title_en": null,
            "title_zh": null,
            "source_code": null,
            "family": null,
            "batch_id": null,
            "locales": [],
            "raw": {
                "row_number": 2,
                "slug": "actuaries",
                "status": "upload_candidate",
                "canonical_slug": "actuaries",
                "hold_reason": null,
                "import_eligible": true
            }
        }
    ],
    "issues": []
}
JSON,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_by_reason_counts_issues_correctly(): void
    {
        $path = $this->writePlan([
            'rows' => [
                $this->row(2, 'actuaries', 'upload_candidate'),
                $this->row(3, 'actuaries', 'CN_proxy_hold'),
                ['row_number' => 4, 'status' => 'manual_hold'],
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 2786);

        $this->assertSame([
            'canonical_slug_duplicate' => 1,
            'canonical_slug_missing' => 1,
            'expected_row_count_mismatch' => 1,
        ], $result->byReason());
    }

    public function test_2786_expected_count_can_be_represented_without_actual_2786_fixture(): void
    {
        $path = $this->writePlan(['rows' => [$this->row(2, 'actuaries', 'upload_candidate')]]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 2786);

        $this->assertSame(2786, $result->expectedRows);
        $this->assertSame(1, $result->foundRows());
        $this->assertSame(CareerPublicResolutionPlanIssue::EXPECTED_ROW_COUNT_MISMATCH, $result->issues[0]->reason);
    }

    public function test_small_synthetic_fixture_with_public_non_public_split_validates(): void
    {
        $path = $this->writePlan([
            'workbook' => [
                'path' => '/tmp/career_full_upload_repaired.xlsx',
                'sha256' => 'fixture-workbook-sha',
                'sheet' => 'Career_Assets_v4_1',
                'rows' => 4,
            ],
            'rows' => [
                $this->row(2, 'canonical-0001', 'already_imported_validated', ['canonical_public_type' => 'public_canonical_job']),
                $this->row(3, 'canonical-0002', 'upload_candidate', ['canonical_public_type' => 'public_canonical_job']),
                $this->row(4, 'cn-proxy-0001', 'CN_proxy_hold', ['canonical_public_type' => 'blocked_until_governance_approval']),
                $this->row(5, 'manual-hold-0001', 'manual_hold', ['canonical_public_type' => 'keep_non_public_with_policy']),
            ],
        ]);

        $result = CareerPublicResolutionPlanResolver::fromPath($path, 4);

        $this->assertSame(CareerCanonicalEligibilityStatus::PASS, $result->status);
        $this->assertSame([
            'public_canonical_job',
            'public_canonical_job',
            'blocked_until_governance_approval',
            'keep_non_public_with_policy',
        ], array_map(
            static fn ($row): ?string => $row->canonicalPublicType,
            $result->rows()
        ));
    }

    private function tempPath(string $name): string
    {
        $dir = sys_get_temp_dir().'/career-public-resolution-plan-resolver-'.str_replace('.', '', uniqid('', true));
        mkdir($dir);
        $this->paths[] = $dir;

        return $dir.'/'.$name;
    }

    private function writeRaw(string $contents): string
    {
        $path = $this->tempPath('plan.json');
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writePlan(array $payload): string
    {
        return $this->writeRaw((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $rowNumber, string $slug, string $status, array $overrides = []): array
    {
        return array_merge([
            'row_number' => $rowNumber,
            'slug' => $slug,
            'status' => $status,
            'canonical_slug' => $slug,
            'hold_reason' => str_ends_with($status, '_hold') ? $status : null,
            'import_eligible' => $status === 'upload_candidate',
        ], $overrides);
    }
}

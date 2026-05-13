<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Audit\CareerSurfaceContextArtifactIssue;
use App\Domain\Career\Audit\CareerSurfaceContextArtifactReader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerExportCanonicalEligibilitySurfaceContextCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:export-canonical-eligibility-surface-context', Artisan::all());
    }

    public function test_command_requires_public_resolution_plan(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--output' => $this->tempPath('surface-context'),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['public_resolution_plan_missing' => 1], $payload['by_reason']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['live_crawl_performed']);
    }

    public function test_command_requires_output_path(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => $this->writePlanner(['actuaries']),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['output_path_missing' => 1], $payload['by_reason']);
    }

    public function test_planner_only_mode_emits_unverified_slug_locale_rows(): void
    {
        $planPath = $this->writePlanner(['actuaries', 'software-developers']);
        $output = $this->tempPath('surface-context');

        $exitCode = Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => $planPath,
            '--output' => $output,
            '--locales' => 'en,zh',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('materialized', $payload['status']);
        $this->assertSame(2, $payload['expected_slugs']);
        $this->assertSame(4, $payload['expected_rows']);
        $this->assertSame(4, $payload['written_rows']);
        $this->assertSame(0, $payload['verified_rows']);
        $this->assertSame(4, $payload['unverified_rows']);
        $this->assertSame([
            CareerSurfaceContextArtifactIssue::SURFACE_ARTIFACT_MISSING => 4,
            CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED => 4,
        ], $payload['by_reason']);

        $artifact = CareerSurfaceContextArtifactReader::fromPath($output);
        $this->assertSame([
            CareerSurfaceContextArtifactIssue::SURFACE_ARTIFACT_MISSING => 4,
            CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED => 4,
        ], $artifact->byReason());
        $this->assertSame(['actuaries|en', 'actuaries|zh', 'software-developers|en', 'software-developers|zh'], array_keys($artifact->rowsByKey()));
        $this->assertFalse($artifact->rowsByKey()['actuaries|en']->surfaceVerified);
        $this->assertSame('planner_only', $artifact->rowsByKey()['actuaries|en']->surfaceMode);
        $this->assertSame('/en/career/jobs/actuaries', $artifact->rowsByKey()['actuaries|en']->apiCanonicalPath);
    }

    public function test_api_artifact_mode_marks_rows_verified_when_evidence_exists(): void
    {
        $planPath = $this->writePlanner(['actuaries']);
        $apiArtifact = $this->writeJson('api-surface', [
            'items' => [
                [
                    'canonical_slug' => 'actuaries',
                    'locale' => 'en',
                    'api_canonical_path' => '/en/career/jobs/actuaries',
                    'api_indexable' => true,
                ],
            ],
        ]);
        $output = $this->tempPath('surface-context');

        Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => $planPath,
            '--output' => $output,
            '--locales' => 'en',
            '--api-artifact' => $apiArtifact,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $artifact = CareerSurfaceContextArtifactReader::fromPath($output);

        $this->assertSame(1, $payload['verified_rows']);
        $this->assertSame(0, $payload['unverified_rows']);
        $this->assertSame([], $artifact->byReason());
        $this->assertTrue($artifact->rowsByKey()['actuaries|en']->surfaceVerified);
    }

    public function test_exported_artifact_can_be_consumed_by_canonical_eligibility_audit_without_fake_pass(): void
    {
        $planPath = $this->writePlanner(['actuaries']);
        $output = $this->tempPath('surface-context');

        Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => $planPath,
            '--output' => $output,
            '--locales' => 'en',
            '--json' => true,
        ]);

        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--public-resolution-plan' => $planPath,
            '--surface-context' => $output,
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('supplied', $payload['context_summary']['surface_context']);
        $this->assertArrayNotHasKey('surface_context_missing', $payload['by_reason']);
        $this->assertSame('blocked', data_get($payload, 'rows.0.surface_status.status'));
        $this->assertContains(CareerSurfaceContextArtifactIssue::SURFACE_UNVERIFIED, data_get($payload, 'rows.0.surface_status.reasons'));
        $this->assertContains(CareerSurfaceContextArtifactIssue::SURFACE_ARTIFACT_MISSING, data_get($payload, 'rows.0.surface_status.reasons'));
    }

    public function test_duplicate_slug_locale_detection_is_preserved_by_reader(): void
    {
        $artifact = CareerSurfaceContextArtifactReader::fromPath($this->writeJson('surface-duplicate', [
            'schema_version' => 'career_surface_context.v1',
            'rows' => [
                ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries', 'api_indexable' => true],
                ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries', 'api_indexable' => true],
            ],
        ]));

        $this->assertSame(['surface_context_slug_locale_duplicate' => 1], $artifact->byReason());
    }

    public function test_malformed_planner_path_reports_structured_failure(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => sys_get_temp_dir().'/missing-surface-plan-'.bin2hex(random_bytes(4)).'.json',
            '--output' => $this->tempPath('surface-context'),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['plan_file_missing' => 1], $payload['by_reason']);
        $this->assertSame('blocked', $payload['plan_validation']['status']);
    }

    public function test_summary_json_shape_is_stable(): void
    {
        Artisan::call('career:export-canonical-eligibility-surface-context', [
            '--public-resolution-plan' => $this->writePlanner(['actuaries']),
            '--output' => $this->tempPath('surface-context'),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'status',
            'read_only',
            'writes_database',
            'live_crawl_performed',
            'public_resolution_plan',
            'output_path',
            'expected_slugs',
            'expected_rows',
            'written_rows',
            'verified_rows',
            'unverified_rows',
            'duplicate_input_slugs',
            'duplicate_input_slug_values',
            'duplicate_artifact_rows',
            'duplicate_artifact_row_keys',
            'by_reason',
            'artifacts',
        ], array_keys($payload));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writePlanner(array $slugs): string
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = [
                'row_number' => $index + 2,
                'canonical_slug' => $slug,
                'status' => 'ready_for_pilot',
                'title_en' => Str::headline($slug),
                'title_zh' => '职业'.$index,
                'locales' => ['en', 'zh'],
            ];
        }

        return $this->writeJson('planner', [
            'schema_version' => 'career_public_resolution_plan.v1',
            'workbook' => ['rows' => count($rows)],
            'rows' => $rows,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $prefix, array $payload): string
    {
        $path = $this->tempPath($prefix);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function tempPath(string $prefix): string
    {
        return sys_get_temp_dir().'/career-surface-context-export-'.$prefix.'-'.bin2hex(random_bytes(4)).'.json';
    }
}

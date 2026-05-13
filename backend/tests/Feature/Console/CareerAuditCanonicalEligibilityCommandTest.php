<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerAuditCanonicalEligibilityCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:audit-canonical-eligibility', Artisan::all());
    }

    public function test_slugs_mode_returns_read_only_json_schema(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('slugs', $payload['scope']);
        $this->assertSame(1, $payload['audited_occupations']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame('actuaries', data_get($payload, 'rows.0.slug'));
        $this->assertSame('unverified', data_get($payload, 'rows.0.runtime_status.status'));
        $this->assertArrayNotHasKey('validator_context_missing', $payload['by_reason']);
        $this->assertArrayHasKey('runtime_projection_context_missing', $payload['by_reason']);
        $this->assertArrayHasKey('runtime_truth_context_missing', $payload['by_reason']);
    }

    public function test_missing_public_resolution_plan_reports_structured_error(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['public_resolution_plan_missing' => 1], $payload['by_reason']);
        $this->assertTrue($payload['read_only']);
    }

    public function test_include_live_html_without_base_url_reports_unverified_context_issue(): void
    {
        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--include-surfaces' => true,
            '--include-live-html' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unverified', data_get($payload, 'rows.0.surface_status.status'));
        $this->assertContains('validator_context_missing', data_get($payload, 'rows.0.surface_status.reasons'));
        $this->assertSame(1, $payload['by_reason']['validator_context_missing']);
        $this->assertContains('surface_live_html_context_missing', array_column($payload['sidecars'], 'sidecar_id'));
    }

    public function test_valid_public_resolution_plan_runs_layer_specific_audit_reasons(): void
    {
        $planPath = $this->writePlanner([
            ['row_number' => 2, 'canonical_slug' => 'actuaries', 'status' => 'ready_for_pilot', 'title_en' => 'Actuaries', 'title_zh' => '精算师'],
            ['row_number' => 3, 'canonical_slug' => 'actors', 'status' => 'ready_for_pilot', 'title_en' => 'Actors', 'title_zh' => '演员'],
        ]);

        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--public-resolution-plan' => $planPath,
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame(2, $payload['expected_occupations']);
        $this->assertSame('career_baselines', data_get($payload, 'rows.0.baseline_status.source'));
        $this->assertArrayNotHasKey('validator_context_missing', $payload['by_reason']);
        $this->assertArrayHasKey('runtime_projection_context_missing', $payload['by_reason']);
        $this->assertNotSame(['validator_context_missing' => count($payload['rows'])], $payload['by_reason']);
    }

    public function test_projection_truth_artifacts_drive_runtime_layer_status(): void
    {
        $projectionPath = $this->writeJsonArtifact('projection', [
            'items' => [
                ['slug' => 'actuaries', 'locale' => 'en', 'runtime_publish_state' => 'published', 'canonical_public_type' => 'public_canonical_job'],
            ],
        ]);
        $truthPath = $this->writeJsonArtifact('truth', [
            'items' => [
                ['slug' => 'actuaries', 'locale' => 'en', 'state' => 'published', 'canonical_public_type' => 'public_canonical_job'],
            ],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--projection' => $projectionPath,
            '--truth' => $truthPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('pass', data_get($payload, 'rows.0.runtime_status.status'));
        $this->assertArrayNotHasKey('runtime_projection_context_missing', $payload['by_reason']);
        $this->assertArrayNotHasKey('runtime_truth_context_missing', $payload['by_reason']);
    }

    public function test_command_writes_output_json_when_requested(): void
    {
        $outputPath = sys_get_temp_dir().'/career-audit-command-output-'.bin2hex(random_bytes(4)).'.json';

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
            '--output' => $outputPath,
        ]);

        $this->assertFileExists($outputPath);
        $payload = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_validator_context_missing_all_rows_regression_is_prevented(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries,actors',
            '--locales' => 'en,zh',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(4, $payload['rows']);
        $this->assertNotSame(['validator_context_missing' => 4], $payload['by_reason']);
        $this->assertArrayNotHasKey('validator_context_missing', $payload['by_reason']);
        $this->assertArrayHasKey('runtime_projection_context_missing', $payload['by_reason']);
    }

    public function test_json_schema_is_stable(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'status',
            'scope',
            'expected_occupations',
            'audited_occupations',
            'eligible_count',
            'blocked_count',
            'by_reason',
            'rows',
            'sidecars',
            'read_only',
            'writes_database',
            'audit_command',
        ], array_keys($payload));
        $this->assertSame([
            'slug',
            'locale',
            'source_scope',
            'entity_status',
            'baseline_status',
            'index_status',
            'runtime_status',
            'seo_geo_status',
            'surface_status',
            'safety_status',
            'overall_status',
            'severity',
            'reasons',
            'evidence',
            'sidecars',
        ], array_keys($payload['rows'][0]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writePlanner(array $rows): string
    {
        return $this->writeJsonArtifact('plan', [
            'workbook' => ['rows' => count($rows)],
            'rows' => $rows,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJsonArtifact(string $prefix, array $payload): string
    {
        $path = sys_get_temp_dir().'/career-audit-command-'.$prefix.'-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }
}

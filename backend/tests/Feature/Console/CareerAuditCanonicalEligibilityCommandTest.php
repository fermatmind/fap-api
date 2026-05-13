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
        $this->assertSame('missing', $payload['context_summary']['runtime_projection_context']);
        $this->assertSame('missing', $payload['context_summary']['runtime_truth_context']);
        $this->assertSame('provide_read_only_context_bundle', $payload['context_summary']['required_next_action']);
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
        $this->assertFalse($payload['context_summary']['planner_supplied']);
        $this->assertContains('public_resolution_plan', array_column($payload['run_context']['next_required_inputs'], 'context_id'));
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
        $this->assertSame('missing', $payload['context_summary']['live_html_context']);
        $this->assertContains('live_html_context', array_column($payload['run_context']['unverified_contexts'], 'context_id'));
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
        $this->assertTrue($payload['context_summary']['planner_supplied']);
        $this->assertSame('public_resolution_plan_json', $payload['run_context']['planner']['source_type']);
        $this->assertSame(2, $payload['run_context']['planner']['found_rows']);
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
        $this->assertSame('supplied', $payload['context_summary']['runtime_projection_context']);
        $this->assertSame('supplied', $payload['context_summary']['runtime_truth_context']);
    }

    public function test_entity_and_index_context_artifacts_mark_contexts_supplied(): void
    {
        $planPath = $this->writePlanner([
            ['row_number' => 2, 'canonical_slug' => 'actuaries', 'status' => 'ready_for_pilot', 'title_en' => 'Actuaries', 'title_zh' => '精算师'],
        ]);
        $entityPath = $this->writeEntityContext([
            ['canonical_slug' => 'actuaries', 'occupation_exists' => true, 'occupation_id' => 123, 'missing_entity_fields' => []],
        ]);
        $indexPath = $this->writeIndexContext([
            ['canonical_slug' => 'actuaries', 'latest_index_state' => 'indexed', 'public_facing_state' => 'indexed', 'index_eligible' => true],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--public-resolution-plan' => $planPath,
            '--entity-context' => $entityPath,
            '--index-state-context' => $indexPath,
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('supplied', $payload['context_summary']['entity_db_context']);
        $this->assertSame('supplied', $payload['context_summary']['index_state_context']);
        $this->assertSame($entityPath, $payload['run_context']['entity']['entity_context_path']);
        $this->assertSame($indexPath, $payload['run_context']['index']['index_state_context_path']);
        $this->assertArrayNotHasKey('entity_db_context_missing', $payload['by_reason']);
        $this->assertArrayNotHasKey('index_state_context_missing', $payload['by_reason']);
        $this->assertSame('pass', data_get($payload, 'rows.0.entity_status.status'));
        $this->assertSame('pass', data_get($payload, 'rows.0.index_status.status'));
        $this->assertSame('entity_context_artifact', data_get($payload, 'rows.0.entity_status.source'));
        $this->assertSame('index_state_context_artifact', data_get($payload, 'rows.0.index_status.source'));
    }

    public function test_missing_entity_context_file_reports_structured_artifact_issue(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--entity-context' => sys_get_temp_dir().'/missing-entity-context-'.bin2hex(random_bytes(4)).'.json',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('entity_context_file_missing', $payload['by_reason']);
        $this->assertSame('missing', $payload['context_summary']['entity_db_context']);
        $this->assertContains('entity_context_artifact_issue', array_column($payload['sidecars'], 'sidecar_id'));
    }

    public function test_malformed_entity_context_json_reports_structured_artifact_issue(): void
    {
        $path = sys_get_temp_dir().'/career-audit-command-bad-entity-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, '{bad json');

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--entity-context' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('entity_context_json_invalid', $payload['by_reason']);
        $this->assertSame('missing', $payload['context_summary']['entity_db_context']);
    }

    public function test_duplicate_entity_context_slug_reports_structured_artifact_issue(): void
    {
        $entityPath = $this->writeEntityContext([
            ['canonical_slug' => 'actuaries', 'occupation_exists' => true, 'occupation_id' => 123],
            ['canonical_slug' => 'actuaries', 'occupation_exists' => true, 'occupation_id' => 456],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--entity-context' => $entityPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('entity_context_slug_duplicate', $payload['by_reason']);
        $this->assertSame('supplied', $payload['context_summary']['entity_db_context']);
        $this->assertContains('entity_context_slug_duplicate', data_get($payload, 'rows.0.entity_status.reasons'));
    }

    public function test_missing_index_context_file_reports_structured_artifact_issue(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--index-state-context' => sys_get_temp_dir().'/missing-index-context-'.bin2hex(random_bytes(4)).'.json',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('index_context_file_missing', $payload['by_reason']);
        $this->assertSame('missing', $payload['context_summary']['index_state_context']);
        $this->assertContains('index_context_artifact_issue', array_column($payload['sidecars'], 'sidecar_id'));
    }

    public function test_malformed_index_context_json_reports_structured_artifact_issue(): void
    {
        $path = sys_get_temp_dir().'/career-audit-command-bad-index-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, '{bad json');

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--index-state-context' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('index_context_json_invalid', $payload['by_reason']);
        $this->assertSame('missing', $payload['context_summary']['index_state_context']);
    }

    public function test_duplicate_index_context_slug_reports_structured_artifact_issue(): void
    {
        $indexPath = $this->writeIndexContext([
            ['canonical_slug' => 'actuaries', 'latest_index_state' => 'indexed', 'public_facing_state' => 'indexed', 'index_eligible' => true],
            ['canonical_slug' => 'actuaries', 'latest_index_state' => 'indexed', 'public_facing_state' => 'indexed', 'index_eligible' => true],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--index-state-context' => $indexPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('index_context_slug_duplicate', $payload['by_reason']);
        $this->assertSame('supplied', $payload['context_summary']['index_state_context']);
        $this->assertContains('index_context_slug_duplicate', data_get($payload, 'rows.0.index_status.reasons'));
    }

    public function test_surface_context_artifact_marks_context_supplied(): void
    {
        $surfacePath = $this->writeSurfaceContext([
            ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actuaries', 'api_indexable' => true],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--surface-context' => $surfacePath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('supplied', $payload['context_summary']['surface_context']);
        $this->assertSame($surfacePath, $payload['run_context']['surface']['surface_context_path']);
        $this->assertArrayNotHasKey('surface_context_missing', $payload['by_reason']);
        $this->assertSame('pass', data_get($payload, 'rows.0.surface_status.status'));
        $this->assertSame('surface_context_artifact', data_get($payload, 'rows.0.surface_status.source'));
    }

    public function test_missing_surface_context_file_reports_structured_artifact_issue(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--surface-context' => sys_get_temp_dir().'/missing-surface-context-'.bin2hex(random_bytes(4)).'.json',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('surface_context_file_missing', $payload['by_reason']);
        $this->assertSame('missing', $payload['context_summary']['surface_context']);
        $this->assertContains('surface_context_artifact_issue', array_column($payload['sidecars'], 'sidecar_id'));
    }

    public function test_surface_context_artifact_canonical_mismatch_remains_blocked(): void
    {
        $surfacePath = $this->writeSurfaceContext([
            ['canonical_slug' => 'actuaries', 'locale' => 'en', 'api_canonical_path' => '/en/career/jobs/actors', 'api_indexable' => true],
        ]);

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--surface-context' => $surfacePath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('supplied', $payload['context_summary']['surface_context']);
        $this->assertArrayHasKey('api_canonical_not_self', $payload['by_reason']);
        $this->assertSame('blocked', data_get($payload, 'rows.0.surface_status.status'));
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

    public function test_command_output_includes_run_context_and_context_summary(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('context_summary', $payload);
        $this->assertArrayHasKey('run_context', $payload);
        $this->assertSame([
            'planner_supplied',
            'entity_db_context',
            'index_state_context',
            'runtime_projection_context',
            'runtime_truth_context',
            'surface_context',
            'live_html_context',
            'required_next_action',
        ], array_keys($payload['context_summary']));
        $this->assertSame([
            'planner',
            'entity',
            'index',
            'runtime',
            'surface',
            'static_sources',
            'missing_contexts',
            'unverified_contexts',
            'approval_gates',
            'next_required_inputs',
            'suggested_rerun_modes',
        ], array_keys($payload['run_context']));
    }

    public function test_missing_contexts_are_aggregated_as_context_requirements(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries,actors',
            '--locales' => 'en,zh',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $missingContextIds = array_column($payload['run_context']['missing_contexts'], 'context_id');
        $runtimeProjection = collect($payload['run_context']['missing_contexts'])->firstWhere('context_id', 'runtime_projection_context');
        $runtimeTruth = collect($payload['run_context']['missing_contexts'])->firstWhere('context_id', 'runtime_truth_context');
        $surface = collect($payload['run_context']['missing_contexts'])->firstWhere('context_id', 'surface_context');

        $this->assertContains('runtime_projection_context', $missingContextIds);
        $this->assertContains('runtime_truth_context', $missingContextIds);
        $this->assertContains('surface_context', $missingContextIds);
        $this->assertSame(4, data_get($runtimeProjection, 'evidence.0.row_reason_count'));
        $this->assertSame(4, data_get($runtimeTruth, 'evidence.0.row_reason_count'));
        $this->assertSame(4, data_get($surface, 'evidence.0.row_reason_count'));
        $this->assertSame('provide_read_only_context_bundle', $payload['context_summary']['required_next_action']);
    }

    public function test_approval_gates_are_emitted_for_read_only_db_and_runtime_artifacts(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $gateIds = array_column($payload['run_context']['approval_gates'], 'gate_id');

        $this->assertContains('production_readonly_db_context', $gateIds);
        $this->assertContains('production_runtime_projection_export', $gateIds);
        $this->assertContains('production_truth_export', $gateIds);
        $this->assertContains('live_html_crawl', $gateIds);
        $this->assertContains('db_backfill_apply', $gateIds);
        $this->assertContains('index_state_apply', $gateIds);
        $this->assertContains('rollout_apply', $gateIds);
    }

    public function test_context_output_writes_stable_json(): void
    {
        $contextPath = sys_get_temp_dir().'/career-audit-command-context-'.bin2hex(random_bytes(4)).'.json';

        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
            '--context-output' => $contextPath,
        ]);
        $payload = json_decode((string) file_get_contents($contextPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFileExists($contextPath);
        $this->assertSame([
            'context_summary',
            'run_context',
            'read_only',
            'writes_database',
            'audit_command',
        ], array_keys($payload));
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
        $this->assertContains('full_readonly_context', $payload['run_context']['suggested_rerun_modes']);
    }

    public function test_output_recommends_context_bundle_not_rollout_apply(): void
    {
        Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'slugs',
            '--slugs' => 'actuaries',
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('provide_read_only_context_bundle', $payload['context_summary']['required_next_action']);
        $this->assertArrayNotHasKey('rollout_allowed', $payload);
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
            'context_summary',
            'run_context',
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
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeEntityContext(array $rows): string
    {
        return $this->writeJsonArtifact('entity-context', [
            'schema_version' => 'career_entity_context.v1',
            'source' => ['type' => 'read_only_db_export', 'environment' => 'local'],
            'rows' => $rows,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeIndexContext(array $rows): string
    {
        return $this->writeJsonArtifact('index-context', [
            'schema_version' => 'career_index_state_context.v1',
            'source' => ['type' => 'read_only_db_export', 'environment' => 'local'],
            'rows' => $rows,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeSurfaceContext(array $rows): string
    {
        return $this->writeJsonArtifact('surface-context', [
            'schema_version' => 'career_surface_context.v1',
            'source' => ['type' => 'read_only_surface_export', 'environment' => 'local'],
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Audit\CareerEntityContextArtifactReader;
use App\Domain\Career\Audit\CareerIndexStateContextArtifactReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerExportCanonicalEligibilityDbContextCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:export-canonical-eligibility-db-context', Artisan::all());
    }

    public function test_command_requires_public_resolution_plan(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-db-context', [
            '--entity-output' => $this->tempPath('entity'),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['public_resolution_plan_missing' => 1], $payload['by_reason']);
        $this->assertTrue($payload['read_only']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_command_requires_at_least_one_output_path(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-db-context', [
            '--public-resolution-plan' => $this->writePlanner(['actuaries']),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['output_path_missing' => 1], $payload['by_reason']);
    }

    public function test_command_emits_entity_and_index_context_artifacts_for_all_planner_slugs(): void
    {
        $actuariesId = $this->insertOccupation('actuaries', 'Actuaries', '精算师');
        $this->insertIndexState($actuariesId, 'noindex', false, '2026-05-12 00:00:00');
        $latestIndexStateId = $this->insertIndexState($actuariesId, 'indexed', true, '2026-05-13 00:00:00', ['manual_review_passed']);
        $planPath = $this->writePlanner(['actuaries', 'missing-career']);
        $entityOutput = $this->tempPath('entity-context');
        $indexOutput = $this->tempPath('index-context');

        $occupationCountBefore = DB::table('occupations')->count();
        $indexStateCountBefore = DB::table('index_states')->count();
        $exitCode = Artisan::call('career:export-canonical-eligibility-db-context', [
            '--public-resolution-plan' => $planPath,
            '--entity-output' => $entityOutput,
            '--index-state-output' => $indexOutput,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('materialized', $payload['status']);
        $this->assertSame(2, $payload['expected_slugs']);
        $this->assertSame(2, $payload['entity']['entity_rows_written']);
        $this->assertSame(1, $payload['entity']['occupations_found']);
        $this->assertSame(1, $payload['entity']['occupations_missing']);
        $this->assertSame(2, $payload['index_state']['index_rows_written']);
        $this->assertSame(1, $payload['index_state']['latest_index_state_found']);
        $this->assertSame(['indexed' => 1], $payload['index_state']['observed_states']);
        $this->assertSame($occupationCountBefore, DB::table('occupations')->count());
        $this->assertSame($indexStateCountBefore, DB::table('index_states')->count());

        $entityArtifact = CareerEntityContextArtifactReader::fromPath($entityOutput);
        $indexArtifact = CareerIndexStateContextArtifactReader::fromPath($indexOutput);

        $this->assertSame([], $entityArtifact->byReason());
        $this->assertSame([], $indexArtifact->byReason());
        $this->assertSame(['actuaries', 'missing-career'], array_keys($entityArtifact->rowsBySlug()));
        $this->assertSame(['actuaries', 'missing-career'], array_keys($indexArtifact->rowsBySlug()));
        $this->assertTrue($entityArtifact->rowsBySlug()['actuaries']->occupationExists);
        $this->assertFalse($entityArtifact->rowsBySlug()['missing-career']->occupationExists);
        $this->assertSame('indexed', $indexArtifact->rowsBySlug()['actuaries']->latestIndexState);
        $this->assertSame('indexable', $indexArtifact->rowsBySlug()['actuaries']->publicFacingState);
        $this->assertTrue($indexArtifact->rowsBySlug()['actuaries']->indexEligible);
        $this->assertSame(['manual_review_passed'], $indexArtifact->rowsBySlug()['actuaries']->reasonCodes);
        $this->assertSame($latestIndexStateId, $indexArtifact->rowsBySlug()['actuaries']->evidence['index_state_id']);
        $this->assertNull($indexArtifact->rowsBySlug()['missing-career']->latestIndexState);
    }

    public function test_exported_artifacts_can_be_consumed_by_canonical_eligibility_audit(): void
    {
        $occupationId = $this->insertOccupation('actuaries', 'Actuaries', '精算师');
        $this->insertIndexState($occupationId, 'indexed', true, '2026-05-13 00:00:00');
        $planPath = $this->writePlanner(['actuaries']);
        $entityOutput = $this->tempPath('entity-context');
        $indexOutput = $this->tempPath('index-context');

        Artisan::call('career:export-canonical-eligibility-db-context', [
            '--public-resolution-plan' => $planPath,
            '--entity-output' => $entityOutput,
            '--index-state-output' => $indexOutput,
            '--json' => true,
        ]);

        $exitCode = Artisan::call('career:audit-canonical-eligibility', [
            '--scope' => 'all',
            '--public-resolution-plan' => $planPath,
            '--entity-context' => $entityOutput,
            '--index-state-context' => $indexOutput,
            '--locales' => 'en',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('supplied', $payload['context_summary']['entity_db_context']);
        $this->assertSame('supplied', $payload['context_summary']['index_state_context']);
        $this->assertArrayNotHasKey('entity_db_context_missing', $payload['by_reason']);
        $this->assertArrayNotHasKey('index_state_context_missing', $payload['by_reason']);
        $this->assertSame('pass', data_get($payload, 'rows.0.entity_status.status'));
        $this->assertSame('pass', data_get($payload, 'rows.0.index_status.status'));
    }

    public function test_malformed_planner_path_reports_structured_failure(): void
    {
        $exitCode = Artisan::call('career:export-canonical-eligibility-db-context', [
            '--public-resolution-plan' => sys_get_temp_dir().'/missing-plan-'.bin2hex(random_bytes(4)).'.json',
            '--entity-output' => $this->tempPath('entity-context'),
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
        $planPath = $this->writePlanner(['actuaries']);
        $entityOutput = $this->tempPath('entity-context');

        Artisan::call('career:export-canonical-eligibility-db-context', [
            '--public-resolution-plan' => $planPath,
            '--entity-output' => $entityOutput,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'status',
            'read_only',
            'writes_database',
            'public_resolution_plan',
            'expected_slugs',
            'duplicate_input_slugs',
            'duplicate_input_slug_values',
            'artifacts',
            'entity',
            'index_state',
        ], array_keys($payload));
        $this->assertSame([
            'expected_slugs',
            'entity_rows_written',
            'occupations_found',
            'occupations_missing',
            'duplicate_input_slugs',
            'duplicate_entity_slugs',
            'duplicate_entity_slug_values',
            'output_path',
        ], array_keys($payload['entity']));
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

        $path = $this->tempPath('planner');
        file_put_contents($path, json_encode([
            'schema_version' => 'career_public_resolution_plan.v1',
            'workbook' => ['rows' => count($rows)],
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    private function insertOccupation(string $slug, string $titleEn, string $titleZh): string
    {
        $now = now()->toDateTimeString();
        $familyId = (string) Str::uuid();
        $occupationId = (string) Str::uuid();

        DB::table('occupation_families')->insert([
            'id' => $familyId,
            'canonical_slug' => $slug.'-family',
            'title_en' => $titleEn.' Family',
            'title_zh' => $titleZh.'族',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('occupations')->insert([
            'id' => $occupationId,
            'family_id' => $familyId,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'occupation',
            'truth_market' => 'global',
            'display_market' => 'global',
            'crosswalk_mode' => 'canonical',
            'canonical_title_en' => $titleEn,
            'canonical_title_zh' => $titleZh,
            'search_h1_zh' => $titleZh,
            'structural_stability' => null,
            'task_prototype_signature' => null,
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('occupation_crosswalks')->insert([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupationId,
            'source_system' => 'O_NET',
            'source_code' => '15-0000.00',
            'source_title' => $titleEn,
            'mapping_type' => 'canonical',
            'confidence_score' => null,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $occupationId;
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function insertIndexState(string $occupationId, string $state, bool $indexEligible, string $changedAt, array $reasonCodes = []): string
    {
        $now = now()->toDateTimeString();
        $id = (string) Str::uuid();

        DB::table('index_states')->insert([
            'id' => $id,
            'occupation_id' => $occupationId,
            'index_state' => $state,
            'index_eligible' => $indexEligible,
            'canonical_path' => '/career/'.$occupationId,
            'canonical_target' => null,
            'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
            'changed_at' => $changedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function tempPath(string $prefix): string
    {
        return sys_get_temp_dir().'/career-export-db-context-'.$prefix.'-'.bin2hex(random_bytes(4)).'.json';
    }
}

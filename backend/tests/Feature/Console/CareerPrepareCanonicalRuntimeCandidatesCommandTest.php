<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\IndexStateValue;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerPrepareCanonicalRuntimeCandidatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:prepare-canonical-runtime-candidates', Artisan::all());
    }

    public function test_missing_plan_or_slug_artifact_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['exactly_one_of_plan_or_slug_artifact_required' => 1], $payload['by_reason']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_missing_source_file_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--slug-artifact' => sys_get_temp_dir().'/missing-runtime-candidate-slugs.json',
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['runtime_candidate_prep_artifact_missing' => 1], $payload['by_reason']);
    }

    public function test_invalid_json_blocks(): void
    {
        $path = $this->tempPath('invalid');
        file_put_contents($path, '{not json');

        $exitCode = $this->callCommand([
            '--slug-artifact' => $path,
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['runtime_candidate_prep_artifact_json_invalid' => 1], $payload['by_reason']);
    }

    public function test_empty_slug_list_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--slug-artifact' => $this->writeSlugArtifact([]),
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['slug_list_empty' => 1], $payload['by_reason']);
    }

    public function test_duplicate_slugs_block(): void
    {
        $exitCode = $this->callCommand([
            '--slug-artifact' => $this->writeSlugArtifact(['actuaries', 'actuaries'], countOverride: 2),
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['duplicate_slug_actuaries' => 1], $payload['by_reason']);
    }

    public function test_dry_run_does_not_write_and_emits_sha_and_approval_phrase(): void
    {
        $this->createOccupation('actuaries');
        $artifact = $this->writeSlugArtifact(['actuaries']);
        $before = IndexState::query()->count();

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--dry-run' => true,
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame($before, IndexState::query()->count());
        $this->assertSame(hash_file('sha256', $artifact), $payload['artifact_sha256']);
        $this->assertSame(1, $payload['slug_count']);
        $this->assertSame(2, $payload['expected_locale_rows']);
        $this->assertSame(1, $payload['planned_write_count']);
        $this->assertStringContainsString('I explicitly approve Career 80 delta runtime candidate preparation apply', $payload['approval_phrase_template']);
    }

    public function test_apply_requires_artifact_sha_and_expected_slug_count(): void
    {
        $this->createOccupation('actuaries');
        $artifact = $this->writeSlugArtifact(['actuaries']);

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--apply' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['dry_run']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame(1, $payload['by_reason']['confirm_artifact_sha256_missing']);
        $this->assertSame(1, $payload['by_reason']['expect_slug_count_missing']);
        $this->assertSame(0, IndexState::query()->count());
    }

    public function test_apply_rejects_sha_mismatch_count_mismatch_and_slug_count_above_max(): void
    {
        $this->createOccupation('actuaries');
        $this->createOccupation('actors');
        $artifact = $this->writeSlugArtifact(['actuaries', 'actors']);

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--apply' => true,
            '--confirm-artifact-sha256' => str_repeat('0', 64),
            '--expect-slug-count' => 1,
            '--max-slugs' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $payload['by_reason']['artifact_sha256_mismatch']);
        $this->assertSame(1, $payload['by_reason']['expect_slug_count_mismatch']);
        $this->assertSame(1, $payload['by_reason']['slug_count_exceeds_max_slugs']);
        $this->assertSame(0, IndexState::query()->count());
    }

    public function test_progressive_dry_run_requires_max_slugs_to_match_delta_count(): void
    {
        $artifact = $this->writePlanArtifact($this->slugs('delta', 220), target: 'career_80_to_300_delta', targetTotal: 300);

        $exitCode = $this->callCommand([
            '--plan' => $artifact,
            '--dry-run' => true,
            '--expect-slug-count' => 220,
            '--max-slugs' => 300,
            '--target-total' => 300,
            '--cohort' => 'career_80_to_300_delta',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $payload['by_reason']['progressive_max_slugs_must_match_delta_count']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame(0, IndexState::query()->count());
    }

    public function test_progressive_dry_run_allows_exact_220_guard_without_writes(): void
    {
        $slugs = $this->slugs('delta', 220);
        foreach ($slugs as $slug) {
            $this->createOccupation($slug);
        }
        $artifact = $this->writePlanArtifact($slugs, target: 'career_80_to_300_delta', targetTotal: 300);
        $before = IndexState::query()->count();

        $exitCode = $this->callCommand([
            '--plan' => $artifact,
            '--dry-run' => true,
            '--expect-slug-count' => 220,
            '--max-slugs' => 220,
            '--target-total' => 300,
            '--cohort' => 'career_80_to_300_delta',
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('planned', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['writes_database']);
        $this->assertSame(220, $payload['slug_count']);
        $this->assertSame(440, $payload['expected_locale_rows']);
        $this->assertSame(220, $payload['planned_write_count']);
        $this->assertSame('career_80_to_300_delta_runtime_candidate_preparation', $payload['preparation_source']);
        $this->assertSame($before, IndexState::query()->count());
    }

    public function test_progressive_target_guard_blocks_mismatch(): void
    {
        $artifact = $this->writePlanArtifact($this->slugs('delta', 220), target: 'career_80_to_300_delta', targetTotal: 300);

        $exitCode = $this->callCommand([
            '--plan' => $artifact,
            '--dry-run' => true,
            '--expect-slug-count' => 220,
            '--max-slugs' => 220,
            '--target-total' => 800,
            '--cohort' => 'career_80_to_300_delta',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $payload['by_reason']['target_public_total_mismatch']);
        $this->assertFalse($payload['writes_database']);
    }

    public function test_apply_writes_only_explicit_slugs_and_verifies_candidate_rows(): void
    {
        $actuaries = $this->createOccupation('actuaries');
        $actors = $this->createOccupation('actors');
        $unlisted = $this->createOccupation('unlisted-career');
        $this->createIndexState($actuaries, IndexStateValue::NOINDEX, false, ['legacy_noindex'], now()->subDay());
        $this->createIndexState($unlisted, IndexStateValue::NOINDEX, false, ['legacy_noindex'], now()->subDay());
        $artifact = $this->writeSlugArtifact(['actuaries', 'actors']);
        $sha = hash_file('sha256', $artifact);
        $occupationCountBefore = Occupation::query()->count();

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--apply' => true,
            '--confirm-artifact-sha256' => $sha,
            '--expect-slug-count' => 2,
            '--max-slugs' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('applied', $payload['status']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['writes_database']);
        $this->assertTrue($payload['write_verified']);
        $this->assertSame(2, $payload['created_count']);
        $this->assertSame(2, $payload['verified_count']);
        $this->assertSame($occupationCountBefore, Occupation::query()->count());

        $this->assertLatestPreparedCandidate('actuaries', $sha);
        $this->assertLatestPreparedCandidate('actors', $sha);
        $this->assertSame(IndexStateValue::NOINDEX, $unlisted->indexStates()->latest('changed_at')->firstOrFail()->index_state);
    }

    public function test_missing_occupation_blocks_apply_before_any_write(): void
    {
        $this->createOccupation('actuaries');
        $artifact = $this->writeSlugArtifact(['actuaries', 'missing-career']);
        $before = IndexState::query()->count();

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--apply' => true,
            '--confirm-artifact-sha256' => hash_file('sha256', $artifact),
            '--expect-slug-count' => 2,
            '--max-slugs' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['occupation_missing' => 1], $payload['by_reason']);
        $this->assertSame($before, IndexState::query()->count());
        $this->assertSame(['missing-career'], $payload['missing_occupations']);
    }

    public function test_plan_artifact_input_is_supported_and_json_shape_is_stable(): void
    {
        $this->createOccupation('actuaries');
        $plan = $this->writePlanArtifact(['actuaries']);

        $exitCode = $this->callCommand([
            '--plan' => $plan,
            '--dry-run' => true,
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame([
            'status',
            'mode',
            'dry_run',
            'writes_database',
            'write_verified',
            'target_runtime_state',
            'target_index_state',
            'batch_id',
            'reason',
            'source_artifact',
            'preparation_source',
            'source_kind',
            'artifact_schema_version',
            'artifact_sha256',
            'slug_count',
            'locales',
            'expected_locale_rows',
            'max_slugs',
            'expect_slug_count',
            'slugs',
            'missing_occupations',
            'existing_latest_states',
            'planned_writes',
            'planned_write_count',
            'blockers',
            'by_reason',
            'approval_phrase_template',
            'non_goals',
        ], array_keys($payload));
        $this->assertSame('plan', $payload['source_kind']);
        $this->assertSame('career_80_delta_runtime_candidate_preparation', $payload['preparation_source']);
        $this->assertSame('published_candidate', $payload['target_runtime_state']);
        $this->assertSame(IndexStateValue::PROMOTION_CANDIDATE, $payload['target_index_state']);
        $this->assertContains('no_rollout_apply', $payload['non_goals']);
        $this->assertContains('no_backfill', $payload['non_goals']);
        $this->assertContains('no_promotion_to_published', $payload['non_goals']);
    }

    public function test_command_writes_output_file(): void
    {
        $this->createOccupation('actuaries');
        $output = $this->tempPath('output');

        $exitCode = $this->callCommand([
            '--slug-artifact' => $this->writeSlugArtifact(['actuaries']),
            '--dry-run' => true,
            '--output' => $output,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($output);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:prepare-canonical-runtime-candidates', array_merge([
            '--batch-id' => 'career_80_delta_candidate_prep_001',
            '--reason' => 'reviewed runtime candidate prep',
            '--json' => true,
        ], $options));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writeSlugArtifact(array $slugs, ?int $countOverride = null): string
    {
        return $this->writeJson('runtime-candidate-slugs', [
            'schema_version' => 'career_80_delta_runtime_candidate_prep_slugs.v1',
            'count' => $countOverride ?? count($slugs),
            'slugs' => $slugs,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function writePlanArtifact(array $slugs, string $target = 'career_80_delta', int $targetTotal = 80): string
    {
        $rows = [];
        foreach ($slugs as $slug) {
            foreach (['en', 'zh'] as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'runtime_publish_state' => 'published_candidate',
                    'projection_state' => 'published_candidate',
                ];
            }
        }

        return $this->writeJson('runtime-candidate-plan', [
            'schema_version' => 'career_runtime_candidate_prep_plan.v1',
            'status' => 'planned',
            'target' => $target,
            'target_public_total' => $targetTotal,
            'delta_slug_count' => count($slugs),
            'locales' => ['en', 'zh'],
            'expected_locale_rows' => count($slugs) * 2,
            'planned_candidate_rows' => $rows,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $name, array $payload): string
    {
        $path = $this->tempPath($name);
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-prepare-runtime-candidates-'.Str::uuid().'-'.$name.'.json';
    }

    private function createOccupation(string $slug): Occupation
    {
        /** @var Occupation $occupation */
        $occupation = Occupation::query()->create([
            'family_id' => $this->occupationFamily()->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'canonical_rollout_batch',
            'canonical_title_en' => Str::title(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => '职业',
            'search_h1_zh' => '职业',
        ]);

        return $occupation;
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function createIndexState(Occupation $occupation, string $state, bool $eligible, array $reasonCodes = [], mixed $changedAt = null): IndexState
    {
        /** @var IndexState $indexState */
        $indexState = IndexState::query()->create([
            'occupation_id' => $occupation->id,
            'index_state' => $state,
            'index_eligible' => $eligible,
            'canonical_path' => '/career/jobs/'.$occupation->canonical_slug,
            'canonical_target' => null,
            'reason_codes' => $reasonCodes,
            'changed_at' => $changedAt ?? now(),
        ]);

        return $indexState;
    }

    private function assertLatestPreparedCandidate(string $slug, string $sha): void
    {
        /** @var Occupation $occupation */
        $occupation = Occupation::query()->where('canonical_slug', $slug)->firstOrFail();
        /** @var IndexState $latest */
        $latest = $occupation->indexStates()
            ->orderByDesc('changed_at')
            ->orderByDesc('created_at')
            ->firstOrFail();

        $this->assertSame(IndexStateValue::PROMOTION_CANDIDATE, $latest->index_state);
        $this->assertTrue($latest->index_eligible);
        $this->assertSame(IndexStateValue::TRUST_LIMITED, IndexStateValue::publicFacing($latest->index_state, (bool) $latest->index_eligible));
        $this->assertContains('career_80_delta_runtime_candidate_preparation', $latest->reason_codes);
        $this->assertContains('prepare_published_candidate_runtime_rows', $latest->reason_codes);
        $this->assertContains('batch_id:career_80_delta_candidate_prep_001', $latest->reason_codes);
        $this->assertContains('reason:reviewed_runtime_candidate_prep', $latest->reason_codes);
        $this->assertContains('artifact_sha256:'.$sha, $latest->reason_codes);
        $this->assertContains('target_runtime_state:published_candidate', $latest->reason_codes);
        $this->assertContains('target_index_state:promotion_candidate', $latest->reason_codes);
    }

    private function occupationFamily(): OccupationFamily
    {
        /** @var OccupationFamily $family */
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'runtime-candidate-prep-family'],
            [
                'title_en' => 'Runtime Candidate Prep Family',
                'title_zh' => 'Runtime Candidate Prep Family',
            ]
        );

        return $family;
    }
}

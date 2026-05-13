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

final class CareerRemediateCanonicalIndexStateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('career:remediate-canonical-index-state', Artisan::all());
    }

    public function test_missing_slug_artifact_blocks(): void
    {
        $exitCode = $this->callCommand([
            '--slug-artifact' => sys_get_temp_dir().'/missing-index-state-slugs.json',
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['slug_artifact_missing' => 1], $payload['by_reason']);
        $this->assertFalse($payload['writes_database']);
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
        $this->assertSame(['slug_artifact_json_invalid' => 1], $payload['by_reason']);
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

    public function test_dry_run_does_not_write_and_emits_artifact_sha_and_approval_phrase(): void
    {
        $occupation = $this->createOccupation('actuaries');
        $this->createIndexState($occupation, IndexStateValue::NOINDEX, false);
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
        $this->assertFalse($payload['writes_database']);
        $this->assertSame($before, IndexState::query()->count());
        $this->assertSame(hash_file('sha256', $artifact), $payload['artifact_sha256']);
        $this->assertSame(1, $payload['planned_write_count']);
        $this->assertStringContainsString('I explicitly approve Career 2786 minimum index_state remediation apply', $payload['approval_phrase_template']);
    }

    public function test_apply_requires_artifact_sha_and_expected_slug_count(): void
    {
        $this->createOccupation('actuaries');
        $artifact = $this->writeSlugArtifact(['actuaries']);

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--apply' => true,
            '--reason' => 'reviewed minimum index remediation',
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
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
            '--reason' => 'reviewed minimum index remediation',
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

    public function test_apply_writes_only_explicit_slugs_and_verifies_latest_index_state(): void
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
            '--reason' => 'reviewed minimum index remediation',
            '--confirm-artifact-sha256' => $sha,
            '--expect-slug-count' => 2,
            '--max-slugs' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('applied', $payload['status']);
        $this->assertTrue($payload['writes_database']);
        $this->assertTrue($payload['write_verified']);
        $this->assertSame(2, $payload['created_count']);
        $this->assertSame(2, $payload['verified_count']);
        $this->assertSame($occupationCountBefore, Occupation::query()->count());

        $this->assertLatestIndexed('actuaries', $sha);
        $this->assertLatestIndexed('actors', $sha);
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
            '--reason' => 'reviewed minimum index remediation',
            '--confirm-artifact-sha256' => hash_file('sha256', $artifact),
            '--expect-slug-count' => 2,
            '--max-slugs' => 2,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(['occupation_missing' => 1], $payload['by_reason']);
        $this->assertSame($before, IndexState::query()->count());
        $this->assertSame(['missing-career'], $payload['missing_occupations']);
    }

    public function test_json_output_is_stable_and_target_state_can_be_indexable(): void
    {
        $this->createOccupation('actuaries');
        $artifact = $this->writeSlugArtifact(['actuaries']);

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--target-state' => IndexStateValue::INDEXABLE,
            '--dry-run' => true,
            '--expect-slug-count' => 1,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame([
            'status',
            'mode',
            'writes_database',
            'write_verified',
            'target_state',
            'batch_id',
            'reason',
            'slug_artifact',
            'artifact_schema_version',
            'artifact_sha256',
            'slug_count',
            'max_slugs',
            'expect_slug_count',
            'slugs',
            'missing_occupations',
            'existing_latest_index_states',
            'planned_writes',
            'planned_write_count',
            'blockers',
            'by_reason',
            'approval_phrase_template',
            'read_only',
        ], array_keys($payload));
        $this->assertSame(IndexStateValue::INDEXABLE, $payload['target_state']);
    }

    public function test_rejects_non_indexed_like_target_state(): void
    {
        $artifact = $this->writeSlugArtifact(['actuaries']);

        $exitCode = $this->callCommand([
            '--slug-artifact' => $artifact,
            '--target-state' => IndexStateValue::NOINDEX,
            '--dry-run' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame(['target_state_not_indexed_like' => 1], $payload['by_reason']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function callCommand(array $options): int
    {
        return Artisan::call('career:remediate-canonical-index-state', array_merge([
            '--batch-id' => 'career_2786_minimum_80_index_state',
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
     * @param  list<string>  $slugs
     */
    private function writeSlugArtifact(array $slugs, ?int $countOverride = null): string
    {
        $path = $this->tempPath('index-state-slugs');
        file_put_contents($path, json_encode([
            'schema_version' => 'career_minimum_index_state_remediation.v1',
            'source' => [
                'audit_artifact' => '/tmp/career_2786_canonical_eligibility_audit_run1f.json',
                'purpose' => 'minimum_80_candidate_unlock',
            ],
            'target' => [
                'current_near_eligible_count' => 29,
                'needed_additional_count' => count($slugs),
                'expected_near_eligible_after_plan' => 29 + count($slugs),
            ],
            'count' => $countOverride ?? count($slugs),
            'slugs' => $slugs,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().'/career-remediate-index-state-'.Str::uuid().'-'.$name.'.json';
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

    private function assertLatestIndexed(string $slug, string $sha): void
    {
        /** @var Occupation $occupation */
        $occupation = Occupation::query()->where('canonical_slug', $slug)->firstOrFail();
        /** @var IndexState $latest */
        $latest = $occupation->indexStates()
            ->orderByDesc('changed_at')
            ->orderByDesc('created_at')
            ->firstOrFail();

        $this->assertSame(IndexStateValue::INDEXED, $latest->index_state);
        $this->assertTrue($latest->index_eligible);
        $this->assertContains('career_2786_minimum_index_state_remediation', $latest->reason_codes);
        $this->assertContains('batch_id:career_2786_minimum_80_index_state', $latest->reason_codes);
        $this->assertContains('reason:reviewed_minimum_index_remediation', $latest->reason_codes);
        $this->assertContains('artifact_sha256:'.$sha, $latest->reason_codes);
    }

    private function occupationFamily(): OccupationFamily
    {
        /** @var OccupationFamily $family */
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'index-remediation-family'],
            [
                'title_en' => 'Index Remediation Family',
                'title_zh' => 'Index Remediation Family',
            ]
        );

        return $family;
    }
}

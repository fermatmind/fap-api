<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use App\Domain\Career\Display\Career1046DisplayAssetReplacementFailure;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class Career1046OccupationCrosswalkTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_twenty_four_crosswalks_write_read_back_replay_and_compensate(): void
    {
        $rows = $this->baseRows();
        $occupations = $this->createOccupations($rows);
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $planMethod = new ReflectionMethod($service, 'occupationCrosswalkPlan');
        $insertMethod = new ReflectionMethod($service, 'insertOccupationCrosswalks');
        $deleteMethod = new ReflectionMethod($service, 'deleteOccupationCrosswalksForCompensation');
        $packageSha256 = $this->missingPackageSha256();

        $initial = $planMethod->invoke($service, $occupations, $rows, true, $packageSha256);
        self::assertCount(24, $initial['inserts']);
        self::assertCount(24, $initial['expected']);

        $inserted = DB::transaction(static fn (): int => $insertMethod->invoke($service, $initial['inserts']));
        self::assertSame(24, $inserted);
        self::assertSame(24, OccupationCrosswalk::query()->count());

        $appliedOccupations = Occupation::query()
            ->whereIn('canonical_slug', array_keys($rows))
            ->with('crosswalks')
            ->get()
            ->keyBy('canonical_slug')
            ->all();
        $applied = $planMethod->invoke($service, $appliedOccupations, $rows, false, $packageSha256);
        self::assertSame([], $applied['inserts']);
        self::assertSame($initial['expected'], $applied['expected']);

        DB::transaction(static fn () => $deleteMethod->invoke($service, $initial['inserts'], $initial['expected']));
        self::assertSame(0, OccupationCrosswalk::query()->count());
    }

    public function test_crosswalk_drift_during_the_transaction_rolls_back_every_new_row(): void
    {
        $rows = $this->baseRows();
        $occupations = $this->createOccupations($rows);
        $service = (new ReflectionClass(Career1046DisplayAssetReplacement::class))->newInstanceWithoutConstructor();
        $planMethod = new ReflectionMethod($service, 'occupationCrosswalkPlan');
        $insertMethod = new ReflectionMethod($service, 'insertOccupationCrosswalks');
        $initial = $planMethod->invoke($service, $occupations, $rows, true, $this->missingPackageSha256());
        $conflict = $initial['inserts'][1];

        OccupationCrosswalk::query()->create(array_replace($conflict, [
            'id' => '00000000-0000-4000-8000-000000000099',
        ]));

        try {
            DB::transaction(static fn (): int => $insertMethod->invoke($service, $initial['inserts']));
            self::fail('Expected crosswalk drift to fail closed.');
        } catch (Career1046DisplayAssetReplacementFailure $failure) {
            self::assertSame('DATABASE_CROSSWALK_TARGET_STATE_DRIFT', $failure->safeCode);
        }

        self::assertSame(1, OccupationCrosswalk::query()->count());
        self::assertDatabaseHas('occupation_crosswalks', ['id' => '00000000-0000-4000-8000-000000000099']);
    }

    /** @return array<string, array<string, mixed>> */
    private function baseRows(): array
    {
        $rows = [];
        $path = dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl';
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[$row['slug']] = $row;
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    private function missingPackageSha256(): string
    {
        return (string) hash_file('sha256', dirname(__DIR__, 5).'/content_assets/career/missing-12-display-v1/assets.jsonl');
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, Occupation>
     */
    private function createOccupations(array $rows): array
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'career-1046-crosswalk-test',
            'title_en' => 'Career 1046 crosswalk test',
            'title_zh' => 'Career 1046 crosswalk test',
        ]);
        $occupations = [];
        foreach ($rows as $slug => $row) {
            $title = $row['asset_payload']['page_payload_json']['page']['en']['hero']['title'];
            $occupation = Occupation::query()->create([
                'family_id' => $family->id,
                'canonical_slug' => $slug,
                'entity_level' => 'market_child',
                'truth_market' => 'US',
                'display_market' => 'CN',
                'crosswalk_mode' => 'direct_match',
                'canonical_title_en' => $title,
                'canonical_title_zh' => $title,
                'search_h1_zh' => $title,
            ]);
            $occupation->setRelation('crosswalks', collect());
            $occupations[$slug] = $occupation;
        }

        return $occupations;
    }
}

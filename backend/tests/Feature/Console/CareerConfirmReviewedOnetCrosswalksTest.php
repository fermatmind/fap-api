<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerConfirmReviewedOnetCrosswalksTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_is_atomic_idempotent_and_excludes_held_rows(): void
    {
        $a = $this->occupation('first-occupation', '15-2051.00');
        $b = $this->occupation('second-occupation', '13-2011.00');
        $held = $this->occupation('held-occupation', '29-1141.00');
        $rows = [];
        $entries = [];
        foreach ([$a, $b, $held] as $item) {
            $slug = $item->occupation->canonical_slug;
            $rows[] = [
                'slug' => $slug, 'onet' => $item->source_code, 'soc' => substr($item->source_code, 0, 7),
                'status' => $item->is($held) ? 'hold' : 'review_pass', 'reasons' => [],
                'official_core_task_ids' => [1, 2], 'bilingual_task_counts' => [3, 3],
                'title_evidence' => 'official_title', 'vector' => [1, 2, 3, 4, 5, 6],
            ];
            $entries[$slug] = ['en' => [], 'zh-CN' => []];
        }
        for ($i = count($rows); $i < 618; $i++) {
            $slug = 'held-'.$i;
            $rows[] = ['slug' => $slug, 'status' => 'hold'];
            $entries[$slug] = ['en' => [], 'zh-CN' => []];
        }
        $manifestHash = str_repeat('a', 64);
        $this->mock(CareerContentV3CanonicalReader::class, function ($reader) use ($manifestHash, $entries): void {
            $reader->shouldReceive('authority')->andReturn(['manifest' => ['aggregate_sha256' => $manifestHash], 'entries' => $entries]);
            $reader->shouldReceive('hasPublicBody')->andReturn(true);
        });
        $file = tempnam(sys_get_temp_dir(), 'career-mapping-');
        try {
            $bytes = json_encode(['career_manifest_sha256' => $manifestHash, 'review_pass' => 2, 'rows' => $rows], JSON_THROW_ON_ERROR);
            file_put_contents($file, $bytes);
            $args = ['--report' => $file, '--sha256' => hash('sha256', $bytes)];
            $run = fn (array $options = []): int => Artisan::call('career:confirm-reviewed-onet-crosswalks', $options + $args);

            $this->assertSame(1, $run(['--sha256' => str_repeat('b', 64)]));
            $this->assertSame(0, $run());
            $this->assertSame('directory_candidate', $a->fresh()->mapping_type);

            $b->update(['source_code' => '13-2021.00']);
            $this->assertSame(1, $run(['--apply' => true]));
            $this->assertSame('directory_candidate', $a->fresh()->mapping_type);

            $b->update(['source_code' => '13-2011.00']);
            $this->assertSame(0, $run(['--apply' => true]));
            $this->assertSame('direct_match', $a->fresh()->mapping_type);
            $this->assertSame('direct_match', $b->fresh()->mapping_type);
            $this->assertSame('directory_candidate', $held->fresh()->mapping_type);
            $this->assertSame(0, $run(['--apply' => true]));
            $this->assertSame([], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['changed_or_planned']);
        } finally {
            unlink($file);
        }
    }

    public function test_batch_three_binds_the_live_body_and_confirms_only_matching_rows(): void
    {
        $path = database_path('data/career_onet_crosswalk_review_batch3_20261111.json');
        $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $rows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['status'] === 'review_pass'));
        $eligible = $this->occupation($rows[0]['slug'], $rows[0]['onet']);
        $changed = $this->occupation($rows[1]['slug'], $rows[1]['onet']);
        $changed->update(['confidence_score' => 0.4]);
        $args = ['--report' => $path, '--sha256' => hash_file('sha256', $path)];

        $this->assertSame(0, Artisan::call('career:confirm-reviewed-onet-crosswalks', $args));
        $receipt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$rows[0]['slug']], $receipt['changed_or_planned']);
        $this->assertSame('live_mapping_status_differs', $receipt['held'][$rows[1]['slug']]);
        $this->assertSame('directory_candidate', $eligible->fresh()->mapping_type);

        $this->assertSame(0, Artisan::call('career:confirm-reviewed-onet-crosswalks', $args + ['--apply' => true]));
        $this->assertSame('direct_match', $eligible->fresh()->mapping_type);
        $this->assertSame('directory_candidate', $changed->fresh()->mapping_type);

        $this->assertSame(0, Artisan::call('career:confirm-reviewed-onet-crosswalks', $args + ['--apply' => true]));
        $receipt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $receipt['changed_or_planned']);
        $this->assertSame([$rows[0]['slug']], $receipt['already_confirmed_slugs']);
    }

    private function occupation(string $slug, string $code): OccupationCrosswalk
    {
        $family = OccupationFamily::query()->firstOrCreate(['canonical_slug' => 'mapping-family'], ['title_en' => 'Family', 'title_zh' => '职业族']);
        $job = Occupation::query()->create([
            'family_id' => $family->id, 'canonical_slug' => $slug, 'entity_level' => 'market_child',
            'truth_market' => 'US', 'display_market' => 'CN', 'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => $slug, 'canonical_title_zh' => $slug, 'search_h1_zh' => $slug,
        ]);
        $job->crosswalks()->create([
            'source_system' => 'us_soc', 'source_code' => substr($code, 0, 7),
            'source_title' => $slug, 'mapping_type' => 'direct_match', 'confidence_score' => 1.0,
        ]);

        return $job->crosswalks()->create([
            'source_system' => 'onet_soc_2019', 'source_code' => $code,
            'source_title' => $slug, 'mapping_type' => 'directory_candidate', 'confidence_score' => 0.5,
        ]);
    }
}

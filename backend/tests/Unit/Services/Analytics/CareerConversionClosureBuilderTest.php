<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\CareerConversionClosureBuilder;
use App\Support\SchemaBaseline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerConversionClosureBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function batch_projection_matches_single_slug_results_with_one_read_per_source(): void
    {
        $this->seedEvent('alpha', 'career_job_detail_cta_click', 'job_slug');
        $this->seedEvent('alpha', 'career_support_link_click', 'job_slug');
        $this->seedEvent('alpha', 'career_shortlist_add', 'job_slug');
        $this->seedEvent('alpha', 'career_feedback_submit', 'recommendation_type');
        $this->seedEvent('beta', 'career_job_detail_cta_click', 'job_slug');

        DB::table('career_shortlist_items')->insert([
            'id' => (string) Str::uuid(),
            'visitor_key' => 'visitor-alpha',
            'subject_kind' => 'job_slug',
            'subject_slug' => 'alpha',
            'source_page_type' => 'career_job_detail',
            'created_at' => now(),
        ]);
        DB::table('career_feedback_records')->insert([
            'id' => (string) Str::uuid(),
            'subject_kind' => 'recommendation_type',
            'subject_slug' => 'alpha',
            'created_at' => now(),
        ]);

        $builder = app(CareerConversionClosureBuilder::class);
        $single = [
            'alpha' => $builder->buildForSubjectSlug('alpha'),
            'beta' => $builder->buildForSubjectSlug('beta'),
        ];
        foreach (['events', 'career_shortlist_items', 'career_feedback_records'] as $table) {
            SchemaBaseline::hasTable($table);
        }

        $sourceReads = [
            'events' => 0,
            'career_shortlist_items' => 0,
            'career_feedback_records' => 0,
        ];
        DB::listen(function (QueryExecuted $query) use (&$sourceReads): void {
            $sql = strtolower($query->sql);
            foreach (array_keys($sourceReads) as $table) {
                if (str_contains($sql, 'from "'.$table.'"') || str_contains($sql, 'from `'.$table.'`')) {
                    $sourceReads[$table]++;
                }
            }
        });

        $batch = $builder->buildForSubjectSlugs([' BETA ', 'alpha', 'alpha']);

        $this->assertSame($single, $batch);
        $this->assertSame([
            'events' => 1,
            'career_shortlist_items' => 1,
            'career_feedback_records' => 1,
        ], $sourceReads);
        $this->assertTrue((bool) data_get($batch, 'alpha.readiness.closure_ready'));
        $this->assertFalse((bool) data_get($batch, 'beta.readiness.closure_ready'));
    }

    private function seedEvent(string $slug, string $eventName, string $subjectKind): void
    {
        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => $eventName,
            'event_name' => $eventName,
            'scale_code' => 'CAREER',
            'meta_json' => json_encode([
                'subject_kind' => $subjectKind,
                'subject_key' => $slug,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Services\Cms\MbtiTraitExplanations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MbtiTraitExplanationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_09_12_180000_create_mbti_trait_catalogs_table.php');
        $migration->up();
    }

    private function publish(): MbtiTraitExplanations
    {
        $catalog = app(MbtiTraitExplanations::class);
        $catalog->publish($catalog->package()['hash'], true);

        return $catalog;
    }

    public function test_all_axes_poles_and_integer_boundaries_have_one_unique_pair(): void
    {
        $catalog = $this->publish();
        $response = $this->getJson('/api/v0.5/personality/mbti/trait-explanations?locale=zh-CN');
        $response->assertOk()->assertJsonPath('schema', MbtiTraitExplanations::SCHEMA)->assertJsonPath('revision', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertCount(32, $response->json('overviews'));
        $this->assertSame($catalog->package()['document']['overviews'], $response->json('overviews'));
        foreach ($response->json('overviews') as $overview) {
            $this->assertCount(2, $overview['paragraphs']);
        }
        $entries = $response->json('entries');
        $this->assertSame($catalog->package()['document']['entries'], $entries);
        $this->assertCount(110, array_unique([...array_column($entries, 'a'), ...array_column($entries, 'b')]));
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            foreach (str_split($axis) as $pole) {
                foreach (range(50, 100) as $pct) {
                    $matches = array_filter($entries, fn ($e) => $e['axis_code'] === $axis
                        && $e['pole'] === ($pct === 50 ? 'balanced' : $pole) && $e['min'] <= $pct && $e['max'] >= $pct);
                    $this->assertCount(1, $matches, $axis.' '.$pole.' '.$pct);
                }
            }
        }
    }

    public function test_dry_run_idempotency_and_conflicting_editorial_state(): void
    {
        $catalog = app(MbtiTraitExplanations::class);
        $hash = $catalog->package()['hash'];
        $catalog->publish($hash, false);
        $this->assertSame(0, DB::table('mbti_trait_catalogs')->count());
        $this->assertSame(1, $catalog->publish($hash, true)['created']);
        $this->assertSame(0, $catalog->publish($hash, true)['created']);
        DB::table('mbti_trait_catalogs')->update(['status' => 'draft']);
        $this->expectException(\RuntimeException::class);
        $catalog->publish($hash, true);
    }

    public function test_missing_draft_future_and_corrupt_database_never_use_package_fallback(): void
    {
        $path = '/api/v0.5/personality/mbti/trait-explanations?locale=zh-CN';
        $this->getJson($path)->assertNotFound();
        $this->publish();
        DB::table('mbti_trait_catalogs')->update(['status' => 'draft']);
        $this->getJson($path)->assertNotFound();
        DB::table('mbti_trait_catalogs')->update(['status' => 'published', 'published_at' => now()->addDay()]);
        $this->getJson($path)->assertNotFound();
        DB::table('mbti_trait_catalogs')->update(['published_at' => now(), 'content_hash' => str_repeat('0', 64)]);
        $this->getJson($path)->assertStatus(503)->assertJsonPath('error_code', 'MBTI_TRAIT_CONTENT_UNAVAILABLE');
    }

    public function test_locale_and_tenant_are_explicit_and_never_silently_fall_back(): void
    {
        $this->publish();
        foreach (['', '?locale=en', '?locale=zh', '?locale=zh-CN&org_id=1'] as $query) {
            $this->getJson('/api/v0.5/personality/mbti/trait-explanations'.$query)->assertStatus(422);
        }
    }

    public function test_invalid_inventory_and_gap_are_rejected(): void
    {
        $catalog = app(MbtiTraitExplanations::class);
        $document = $catalog->package()['document'];
        $document['entries'][1]['min'] = 52;
        $this->expectException(\RuntimeException::class);
        $catalog->validate($document);
    }

    public function test_malformed_overview_identity_is_rejected_as_content_error(): void
    {
        $catalog = app(MbtiTraitExplanations::class);
        $document = $catalog->package()['document'];
        $document['overviews'][0]['full_code'] = ['INTP-A'];
        $this->expectException(\RuntimeException::class);
        $catalog->validate($document);
    }

    public function test_publish_rejects_a_mismatched_hash(): void
    {
        $catalog = app(MbtiTraitExplanations::class);
        $this->artisan('personality:publish-trait-content', ['--expected-hash' => str_repeat('0', 64), '--write' => true])->assertFailed();
        $this->assertSame(0, DB::table('mbti_trait_catalogs')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Services\Cms\CareerInterestPracticeRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CareerInterestPracticeReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['review_governance.mode' => 'solo_owner', 'review_governance.solo_owner_admin_user_id' => 1,
            'ops.content_release_observability.hmac_revalidation_url' => 'https://frontend.example.test/api/content-release/revalidate',
            'ops.content_release_observability.hmac_revalidation_secret' => 'fixture-only-secret']);
        Http::preventStrayRequests();
        Http::fake(['frontend.example.test/*' => fn ($request) => Http::response(['ok' => true,
            'revalidated_paths' => $request->data()['cache_signal']['paths'], 'rejected_paths' => [],
            'invalidated_tags' => ['article-detail:zh-CN:riasec-holland-career-interest-test-explained', 'article-seo:zh-CN:riasec-holland-career-interest-test-explained']])]);
        AdminUser::query()->create(['name' => 'Fixture owner', 'email' => 'owner@example.test', 'password' => 'fixture-password', 'is_active' => 1]);
    }

    public function test_both_bodies_publish_with_native_revisions_and_preserve_metadata_and_history(): void
    {
        $this->seedBaseline();
        $beforeSeo = DB::table('article_seo_meta')->get()->toJson();
        $searchFingerprint = app(\App\Services\Cms\ArticleMaterialDecisionService::class)->currentSearchFingerprint(\App\Models\Article::withoutGlobalScopes()->findOrFail(40));
        $beforeGuide = (array) DB::table('career_guides')->find(12);
        $old = (array) DB::table('article_translation_revisions')->find(774);
        $service = app(CareerInterestPracticeRelease::class);
        $this->assertSame(['status' => 'ready', 'changed' => 0], $service->publish(true));
        $this->assertSame(['status' => 'published', 'changed' => 2], $service->publish());
        $this->assertSame($beforeSeo, DB::table('article_seo_meta')->get()->toJson());
        $this->assertSame($searchFingerprint, app(\App\Services\Cms\ArticleMaterialDecisionService::class)->currentSearchFingerprint(\App\Models\Article::withoutGlobalScopes()->findOrFail(40)));
        $this->assertSame(1, DB::table('career_guide_revisions')->count());
        $this->assertSame(2, DB::table('article_translation_revisions')->count());
        $after = (array) DB::table('article_translation_revisions')->find(774);
        unset($old['revision_status'], $old['updated_at'], $after['revision_status'], $after['updated_at']);
        $this->assertSame($old, $after);
        $afterGuide = (array) DB::table('career_guides')->find(12);
        unset($beforeGuide['body_md'], $beforeGuide['updated_at'], $afterGuide['body_md'], $afterGuide['updated_at']);
        $this->assertSame($beforeGuide, $afterGuide);
        $this->assertSame(['status' => 'unchanged', 'changed' => 0], $service->publish());
        $this->assertSame(2, DB::table('article_translation_revisions')->count());
        $this->getJson('/api/v0.5/articles/riasec-holland-career-interest-test-explained?locale=zh-CN')->assertOk()
            ->assertJsonPath('article.content_md', file_get_contents(base_path(CareerInterestPracticeRelease::ROOT.'/article.md')));
        $this->getJson('/api/v0.5/career-guides/how-to-find-right-career-direction?locale=zh-CN')->assertOk()
            ->assertJsonPath('guide.body_md', file_get_contents(base_path(CareerInterestPracticeRelease::ROOT.'/guide.md')));
    }

    public function test_staging_baseline_also_uses_the_same_candidate(): void
    {
        $this->seedBaseline('staging');
        $this->app->detectEnvironment(fn () => 'staging');
        $this->assertSame(['status' => 'published', 'changed' => 2], app(CareerInterestPracticeRelease::class)->publish());
    }

    public function test_foreign_draft_and_body_drift_fail_without_writes(): void
    {
        $this->seedBaseline();
        DB::table('articles')->where('id', 40)->update(['working_revision_id' => 9999]);
        $this->assertRejected();
        DB::table('articles')->where('id', 40)->update(['working_revision_id' => 774]);
        DB::table('career_guides')->where('id', 12)->update(['body_md' => 'Concurrent owner edit']);
        $this->assertRejected();
    }

    public function test_audit_failure_rolls_back_both_bodies_and_native_history(): void
    {
        $this->seedBaseline();
        $this->mock(\App\Services\Audit\AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('audit failed'));
        $this->assertRejected();
    }

    public function test_guide_save_failure_rolls_back_the_already_promoted_article(): void
    {
        $this->seedBaseline();
        \Illuminate\Support\Facades\Event::listen('eloquent.saving: App\\Models\\CareerGuide', function (): void {
            throw new RuntimeException('guide save failed after article promotion');
        });
        $this->assertRejected();
    }

    private function assertRejected(): void
    {
        $before = $this->state();
        try {
            app(CareerInterestPracticeRelease::class)->publish();
            $this->fail('Must reject drift.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->state());
        }
    }

    private function state(): string
    {
        return json_encode(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), [
            'articles', 'article_translation_revisions', 'article_seo_meta', 'career_guides', 'career_guide_revisions',
            'editorial_reviews', 'review_attestations', 'content_material_decisions',
        ]), JSON_THROW_ON_ERROR);
    }

    private function seedBaseline(string $environment = 'prod'): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/career_interest_practice_baseline.json')), true)[$environment];
        foreach ($fixture as $table => $row) {
            $row = array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
            foreach ($row as $column => &$value) {
                if ($value !== null && str_ends_with($column, '_at') && is_string($value)) {
                    $value = \Illuminate\Support\Carbon::parse($value)->format(DB::connection()->getQueryGrammar()->getDateFormat());
                }
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            unset($value);
            DB::table($table)->insert($row);
        }
    }
}

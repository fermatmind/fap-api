<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\LandingSurface;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\TraditionalArticlePublication;
use App\Services\ContentPromotion\TraditionalLandingSurfacePublication;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TraditionalLandingSurfacePublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_cohort_creates_public_variants_and_retains_safe_drafts_after_rollback(): void
    {
        $rows = $this->rows();
        $native = app(TraditionalLandingSurfacePublication::class);
        foreach ($rows as $row) {
            $this->seedSource($row);
        }

        DB::transaction(function () use ($rows, $native): void {
            foreach ($rows as $row) {
                $before = $native->read($row, true);
                self::assertTrue($before['missing']);
                self::assertTrue($native->publish($row, $before));
                $after = $native->read($row, true);
                self::assertSame($before['source'], $after['source']);
                self::assertFalse($native->publish($row, $after));

                $key = $row['identity']['slug'];
                $indexable = in_array($key, ['home', 'tests'], true);
                $this->getJson('/api/v0.5/landing-surfaces/'.$key.'?locale=zh-TW')
                    ->assertOk()->assertJsonPath('surface.payload_json.hero.title', '探索職業方向')
                    ->assertJsonPath('surface.is_indexable', $indexable);

                $native->restore($row, $before, $after);
                $draft = $native->read($row, true);
                self::assertTrue($draft['retained_draft']);
                self::assertFalse($draft['target']['is_indexable']);
                self::assertSame($after['target']['id'], $draft['target']['id']);
                $this->getJson('/api/v0.5/landing-surfaces/'.$key.'?locale=zh-TW')->assertNotFound();

                self::assertTrue($native->publish($row, $draft));
                $republished = $native->read($row, true)['target'];
                self::assertSame($after['target']['id'], $republished['id']);
                self::assertSame($indexable, $republished['is_indexable']);
            }
        });

        self::assertSame(8, LandingSurface::query()->withoutGlobalScopes()
            ->whereIn('surface_key', TraditionalLandingSurfacePublication::SURFACE_KEYS)->count());
    }

    public function test_blog_directory_uses_the_exact_article_consumer_and_can_be_rolled_back(): void
    {
        $base = base_path('content_assets/public_content_53/');
        $bytes = file_get_contents($base.'traditional-article-batch.json');
        $review = file_get_contents($base.'traditional-article-batch-review.json');
        $native = app(TraditionalLandingSurfacePublication::class);
        $staging = TraditionalArticlePublication::batch($bytes, $review, 'staging')['candidates'][3];
        $production = TraditionalArticlePublication::batch($bytes, $review, 'production')['candidates'][3];
        $url = '/api/v0.5/articles?org_id=0&locale=zh-TW&include_blog=1';
        $this->getJson($url)->assertOk()->assertJsonPath('blog_v1.configuration_state', 'unconfigured');
        $this->getJson('/api/v0.5/landing-surfaces/articles_index?locale=zh-TW')->assertNotFound();

        DB::transaction(function () use ($native, $staging, $url): void {
            $before = $native->read($staging, true);
            self::assertNull($before['source']);
            self::assertTrue($native->publish($staging, $before));
            $after = $native->read($staging, true);
            $this->getJson($url)->assertOk()->assertJsonPath('blog_v1.configuration_state', 'published')
                ->assertJsonPath('blog_v1.title', '費馬部落格')->assertJsonPath('blog_v1.is_indexable', false)
                ->assertJsonCount(0, 'blog_v1.featured_items');
            $native->restore($staging, $before, $after);
            $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'unconfigured');
        });

        $this->seedSource($production);
        DB::transaction(function () use ($native, $production, $url): void {
            $before = $native->read($production, true);
            self::assertTrue($native->publish($production, $before));
            self::assertSame($before['source'], $native->read($production, true)['source']);
            $this->getJson($url)->assertJsonPath('blog_v1.title', '費馬部落格');
        });
    }

    public function test_frozen_public_copy_matches_the_source_projection_but_preserves_raw_source_blocks(): void
    {
        $row = $this->rows()[0];
        $this->seedSource($row);
        $source = LandingSurface::query()->withoutGlobalScopes()
            ->where(['locale' => 'zh-CN', 'surface_key' => 'home'])->firstOrFail();
        $source->blocks()->firstOrFail()->update([
            'payload_json' => ['body' => '完整15题', 'items' => [['label' => '原始区块项目']]],
        ]);

        $public = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN')
            ->assertOk()->assertJsonPath('surface.page_blocks.0.payload_json.items', []);
        $copy = array_intersect_key($public->json('surface'), $row['source_snapshot']);
        $row['source_snapshot'] = $copy;
        $row['snapshot']['page_blocks'][0]['payload_json']['items'] = [];
        $native = app(TraditionalLandingSurfacePublication::class);

        DB::transaction(function () use ($row, $native): void {
            $before = $native->read($row, true);
            self::assertTrue($native->publish($row, $before));
            self::assertSame($before['source'], $native->read($row, true)['source']);
        });
        self::assertSame([['label' => '原始区块项目']], $source->blocks()->firstOrFail()->payload_json['items']);
        $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-TW')
            ->assertOk()->assertJsonPath('surface.page_blocks.0.payload_json.items', []);
    }

    public function test_late_source_drift_rolls_back_earlier_creations(): void
    {
        $rows = $this->rows();
        foreach ($rows as $row) {
            $this->seedSource($row);
        }
        LandingSurface::query()->withoutGlobalScopes()
            ->where(['locale' => 'zh-CN', 'surface_key' => 'tests_category_career'])
            ->update(['title' => '来源已更新']);

        $native = app(TraditionalLandingSurfacePublication::class);
        try {
            DB::transaction(function () use ($native, $rows): void {
                foreach ($rows as $row) {
                    $native->publish($row, $native->read($row, true));
                }
            });
            self::fail('A stale source must abort the cohort.');
        } catch (DomainException $error) {
            self::assertSame('public_content_53_traditional_landing_source_drift', $error->getMessage());
        }
        self::assertSame(0, LandingSurface::query()->withoutGlobalScopes()->where('locale', 'zh-TW')->count());
    }

    public function test_pilot_indexability_requires_an_indexable_source(): void
    {
        $row = $this->rows()[0];
        $this->seedSource($row);
        LandingSurface::query()->withoutGlobalScopes()
            ->where(['locale' => 'zh-CN', 'surface_key' => 'home'])
            ->update(['is_indexable' => false]);

        $native = app(TraditionalLandingSurfacePublication::class);
        $this->expectExceptionMessage('public_content_53_traditional_landing_source_drift');
        DB::transaction(fn () => $native->publish($row, $native->read($row, true)));
    }

    public function test_review_binding_does_not_authorize_link_changes(): void
    {
        $this->expectExceptionMessage('public_content_53_traditional_landing_protected_field_changed');
        $this->rows(changeLink: true);
    }

    private function seedSource(array $row): void
    {
        $copy = $row['source_snapshot'];
        $blocks = $copy['page_blocks'];
        unset($copy['page_blocks']);
        $source = LandingSurface::query()->withoutGlobalScopes()->create([
            ...$copy, 'org_id' => 0, 'locale' => 'zh-CN', 'surface_key' => $row['identity']['slug'],
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
        ]);
        $source->blocks()->createMany($blocks);
    }

    private function rows(bool $changeLink = false): array
    {
        $schema = TraditionalLandingSurfacePublication::SCHEMA;
        $package = ['schema' => $schema, 'environments' => []];
        $proof = ['schema' => $schema.'.review', 'verdict' => 'PASS', 'environment_sha256' => []];
        foreach (['staging', 'production'] as $environment) {
            foreach (TraditionalLandingSurfacePublication::SURFACE_KEYS as $key) {
                $source = [
                    'title' => '探索职业方向', 'description' => '完整15题',
                    'schema_version' => match ($key) {
                        'home' => 'home.v1',
                        'tests' => 'tests.v1',
                        default => 'tests-category.v1',
                    },
                    'payload_json' => [
                        'hero' => ['title' => '探索职业方向', 'primaryHref' => '/zh/tests'],
                        'seo' => ['title' => '免费测评'],
                    ],
                    'page_blocks' => [[
                        'block_key' => 'hero', 'block_type' => 'json', 'title' => '入口',
                        'payload_json' => ['body' => '完整15题'], 'sort_order' => 0, 'is_enabled' => true,
                    ]],
                ];
                $target = $source;
                $target['title'] = '探索職業方向';
                $target['description'] = '完整 15 題';
                $target['payload_json']['hero']['title'] = $target['title'];
                if ($changeLink) {
                    $target['payload_json']['hero']['primaryHref'] = '/zh-TW/tests';
                }
                $row = ['source_snapshot' => $source, 'snapshot' => $target];
                $package['environments'][$environment][$key] = $row;
                $proof['environment_sha256'][$environment][$key] = hash('sha256', PromotionContextFactory::canonicalJson($row));
            }
        }
        $bytes = json_encode($package, JSON_THROW_ON_ERROR);
        $proof['candidate_sha256'] = hash('sha256', $bytes);

        return TraditionalLandingSurfacePublication::package($bytes, json_encode($proof, JSON_THROW_ON_ERROR), 'production')['candidates'];
    }
}

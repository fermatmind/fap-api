<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\LandingSurface;
use App\Services\ContentPromotion\PromotionContextFactory;
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
                $this->getJson('/api/v0.5/landing-surfaces/'.$key.'?locale=zh-TW')
                    ->assertOk()->assertJsonPath('surface.payload_json.hero.title', '探索職業方向')
                    ->assertJsonPath('surface.is_indexable', false);

                $native->restore($row, $before, $after);
                $draft = $native->read($row, true);
                self::assertTrue($draft['retained_draft']);
                self::assertSame($after['target']['id'], $draft['target']['id']);
                $this->getJson('/api/v0.5/landing-surfaces/'.$key.'?locale=zh-TW')->assertNotFound();

                self::assertTrue($native->publish($row, $draft));
                self::assertSame($after['target']['id'], $native->read($row, true)['target']['id']);
            }
        });

        self::assertSame(8, LandingSurface::query()->withoutGlobalScopes()
            ->whereIn('surface_key', TraditionalLandingSurfacePublication::SURFACE_KEYS)->count());
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

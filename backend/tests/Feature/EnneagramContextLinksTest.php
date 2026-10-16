<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistryWriter;
use App\Support\CacheKeys;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class EnneagramContextLinksTest extends TestCase
{
    private const CONNECTION = 'enneagram_context_links_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'enneagram_context_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::CONNECTION => $connection]);
        DB::setDefaultConnection(self::CONNECTION);
        Schema::clearResolvedInstance('db.schema');
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            Schema::create($table, function (Blueprint $schema): void {
                $schema->integer('org_id');
                $schema->string('code');
                $schema->text('content_i18n_json');
                $schema->string('identity')->default('preserve');
            });
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                Schema::connection(self::CONNECTION)->dropIfExists($table);
            }
        } finally {
            if ($this->originalConnection !== null) {
                DB::setDefaultConnection($this->originalConnection);
                DB::purge(self::CONNECTION);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    private function package(): array
    {
        return json_decode(file_get_contents(database_path('data/assessment_enneagram_context_links_20261016.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function content(bool $published = false): array
    {
        $content = ['fr' => ['title' => 'Preserve another locale']];
        foreach ($this->package()['locales'] as $locale => $updates) {
            $content[$locale] = [
                'landing_copy' => 'Preserve introduction',
                'why_choose' => ['items' => [['id' => 'existing-card', 'link' => ['href' => '#existing', 'label' => 'Existing navigation']]]],
                'faq' => [['id' => 'unrelated-faq', 'a' => 'Preserve answer']],
            ];
            foreach ($updates as $update) {
                $item = ['id' => $update['id'], 'q' => 'Preserve question', 'a' => 'Preserve answer and scoring boundaries'];
                $links = $update['id'] === 'faq-enneagram-interpretation'
                    ? [['href' => '#faq-enneagram-calculation', 'label' => $locale === 'zh' ? '查看两种题本的计分说明' : 'Read the scoring explanation for both forms']]
                    : [];
                if ($published) {
                    $links[] = $update['link'];
                }
                if ($links !== []) {
                    $item[$update['field']] = $links;
                }
                $content[$locale]['faq'][] = $item;
            }
        }

        return $content;
    }

    private function seedContent(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ([[0, 'ENNEAGRAM'], [7, 'ENNEAGRAM'], [0, 'RIASEC']] as [$org, $code]) {
                DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode($this->content())]);
            }
        }
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_10_16_120000_link_enneagram_landing_context_articles.php'))->up();
    }

    private function assertPublishedScope(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertSame('preserve', $row->identity);
                $this->assertSame(
                    $this->content($row->org_id === 0 && $row->code === 'ENNEAGRAM'),
                    json_decode($row->content_i18n_json, true),
                );
            }
        }
    }

    public function test_bilingual_links_preserve_existing_content_and_close_native_caches_idempotently(): void
    {
        $this->seedContent();
        $cache = app(PublicScaleCatalogCache::class);
        $generation = $cache->generation(0);
        $key = CacheKeys::scaleRegistryByCode(0, 'ENNEAGRAM');
        Cache::put($key, 'stale');
        Cache::put($key.':generation='.$generation, 'stale');

        $this->publish();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertPublishedScope();
        $this->assertNull(Cache::get($key));
        $this->assertNull(Cache::get($key.':generation='.$generation));
        $this->assertGreaterThan($generation, $cache->generation(0));

        $this->publish();
        $this->assertPublishedScope();
        (require database_path('migrations/2026_10_16_120000_link_enneagram_landing_context_articles.php'))->down();
        $this->assertPublishedScope();

        foreach ($this->package()['locales'] as $locale => $updates) {
            $this->assertSame(['faq-enneagram-interpretation', 'faq-enneagram-retest'], array_column($updates, 'id'));
            foreach ($updates as $update) {
                $this->assertSame('related_links', $update['field']);
                $this->assertStringStartsWith('/'.$locale.'/articles/', $update['link']['href']);
            }
        }
    }

    public function test_english_conflict_in_second_registry_rolls_back_both_tables_without_invalidating_cache(): void
    {
        $this->seedContent();
        $content = $this->content();
        $content['en']['faq'][1]['related_links'] = [['href' => '/en/articles/enneagram-personality-test-explained', 'label' => 'Later owner edit']];
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        $this->instance(ScaleRegistryWriter::class, Mockery::mock(ScaleRegistryWriter::class)->shouldNotReceive('invalidatePublicContentProjection')->getMock());

        try {
            $this->publish();
            $this->fail('Expected reviewed baseline conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }
        $this->assertSame($this->content(), json_decode(DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
        $this->assertSame($content, json_decode(DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
    }

    public function test_existing_non_target_links_are_retained_in_both_registries_and_languages(): void
    {
        $this->seedContent();
        $custom = ['href' => '#existing-context', 'label' => 'Keep environment-specific navigation'];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = $this->content();
            foreach (['zh', 'en'] as $locale) {
                $content[$locale]['faq'][1]['related_links'] = [$custom];
                // JSON object key order is not an editorial conflict.
                $link = $this->package()['locales'][$locale][1]['link'];
                $content[$locale]['faq'][2]['related_links'] = [['label' => $link['label'], 'href' => $link['href'], 'preserve' => 'extra metadata']];
            }
            DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        }
        $this->publish();
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true);
            foreach (['zh', 'en'] as $locale) {
                $this->assertSame([$custom, $this->package()['locales'][$locale][0]['link']], $content[$locale]['faq'][1]['related_links']);
                $this->assertCount(1, $content[$locale]['faq'][2]['related_links']);
            }
        }
    }

    public function test_absent_null_and_empty_lists_receive_only_the_new_context_link(): void
    {
        $this->seedContent();
        foreach (['absent', 'null', 'empty'] as $variant) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = $this->content();
                foreach (['zh', 'en'] as $locale) {
                    foreach ([1, 2] as $index) {
                        if ($variant === 'absent') {
                            unset($content[$locale]['faq'][$index]['related_links']);
                        } else {
                            $content[$locale]['faq'][$index]['related_links'] = $variant === 'null' ? null : [];
                        }
                    }
                }
                DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
            }
            $this->publish();
            $this->publish();
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true);
                foreach (['zh', 'en'] as $locale) {
                    foreach ($this->package()['locales'][$locale] as $offset => $update) {
                        $this->assertSame([$update['link']], $content[$locale]['faq'][$offset + 1]['related_links']);
                    }
                }
            }
        }
    }

    public function test_malformed_existing_links_roll_back_without_cache_invalidation(): void
    {
        $this->seedContent();
        $this->instance(ScaleRegistryWriter::class, Mockery::mock(ScaleRegistryWriter::class)->shouldNotReceive('invalidatePublicContentProjection')->getMock());
        foreach (['malformed', (object) [], ['href' => '/not-a-list'], [['href' => '/missing-label']], [['href' => '', 'label' => 'Empty URL']]] as $malformed) {
            $content = $this->content();
            $content['en']['faq'][1]['related_links'] = $malformed;
            DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
            try {
                $this->publish();
                $this->fail('Expected malformed link rejection.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('malformed; refusing to overwrite', $exception->getMessage());
            }
            $this->assertSame($this->content(), json_decode(DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
            $this->assertSame(json_decode(json_encode($content), true), json_decode(DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
        }
    }

    public function test_preserves_json_object_shapes_and_original_indices_with_unidentified_faqs(): void
    {
        $this->seedContent();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(json_encode($this->content()));
            $content->fr->settings = (object) [];
            foreach (['zh', 'en'] as $locale) {
                array_unshift($content->{$locale}->faq, (object) ['q' => 'Unidentified existing question', 'meta' => (object) []]);
                $content->{$locale}->faq[2]->related_links[0]->meta = (object) [];
            }
            DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        }
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $raw = DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json');
            $content = json_decode($raw);
            $this->assertInstanceOf(\stdClass::class, $content->fr->settings);
            foreach (['zh', 'en'] as $locale) {
                $this->assertSame('Unidentified existing question', $content->{$locale}->faq[0]->q);
                $this->assertObjectNotHasProperty('related_links', $content->{$locale}->faq[0]);
                $this->assertInstanceOf(\stdClass::class, $content->{$locale}->faq[0]->meta);
                $this->assertInstanceOf(\stdClass::class, $content->{$locale}->faq[2]->related_links[0]->meta);
                $this->assertEquals((object) $this->package()['locales'][$locale][0]['link'], $content->{$locale}->faq[2]->related_links[1]);
                $this->assertEquals((object) $this->package()['locales'][$locale][1]['link'], $content->{$locale}->faq[3]->related_links[0]);
            }
            $this->publish();
            $this->assertSame($raw, DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'));
        }
    }

    public function test_missing_or_duplicated_target_id_rolls_back_both_registries(): void
    {
        $this->seedContent();
        $this->instance(ScaleRegistryWriter::class, Mockery::mock(ScaleRegistryWriter::class)->shouldNotReceive('invalidatePublicContentProjection')->getMock());
        foreach (['missing', 'duplicate'] as $variant) {
            $content = $this->content();
            if ($variant === 'missing') {
                unset($content['en']['faq'][1]['id']);
            } else {
                $content['en']['faq'][] = $content['en']['faq'][1];
            }
            DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
            try {
                $this->publish();
                $this->fail('Expected unique target rejection.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('missing or duplicated; refusing to overwrite', $exception->getMessage());
            }
            $this->assertSame($this->content(), json_decode(DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
            $this->assertSame($content, json_decode(DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
        }
    }

    public function test_cache_failure_keeps_committed_links_and_idempotent_reentry_closes_the_cache(): void
    {
        $this->seedContent();
        $writer = Mockery::mock(ScaleRegistryWriter::class);
        $writer->shouldReceive('invalidatePublicContentProjection')->once()
            ->with(0, 'ENNEAGRAM', ['enneagram-personality-test-nine-types'])
            ->andReturnUsing(function (): void {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertPublishedScope();
                throw new \RuntimeException('Cache transport unavailable');
            });
        $this->instance(ScaleRegistryWriter::class, $writer);

        try {
            $this->publish();
            $this->fail('Expected partial cache closeout.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('committed; PARTIAL_CACHE_CLOSEOUT', $exception->getMessage());
        }
        $this->assertPublishedScope();
        app()->forgetInstance(ScaleRegistryWriter::class);
        $this->publish();
        $this->assertPublishedScope();
    }

    public function test_deploy_reseeding_preserves_both_locales_published_faq_links_and_answers(): void
    {
        $writer = Mockery::mock(ScaleRegistryWriter::class);
        $writer->shouldReceive('upsertScale')->andReturnUsing(function (array $attributes) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                DB::table($table)->updateOrInsert(
                    ['org_id' => 0, 'code' => $attributes['code']],
                    ['content_i18n_json' => json_encode($attributes['content_i18n_json'])],
                );
            }

            return new \App\Models\ScaleRegistry;
        });
        $seeder = new \Database\Seeders\ScaleRegistrySeeder;
        $seed = new \ReflectionMethod($seeder, 'upsertAssessmentPreservingFaq');
        $attributes = ['code' => 'ENNEAGRAM', 'content_i18n_json' => ['en' => [], 'zh' => []]];
        $seed->invoke($seeder, $writer, $attributes);
        $this->publish();
        $published = [];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $published[$table] = json_decode(DB::table($table)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true);
        }

        foreach (range(1, 2) as $attempt) {
            $seed->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $actual = json_decode(DB::table($table)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true);
                foreach (['zh', 'en'] as $locale) {
                    $this->assertSame($published[$table][$locale]['faq'], $actual[$locale]['faq']);
                    foreach ($this->package()['locales'][$locale] as $update) {
                        $item = collect($actual[$locale]['faq'])->firstWhere('id', $update['id']);
                        $this->assertContains($update['link'], $item['related_links']);
                    }
                }
            }
        }
    }

    public function test_both_current_hubs_expose_the_context_links_without_changing_identity_or_indexability(): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            $segment = $locale === 'zh-CN' ? 'zh' : 'en';
            $payload = $this->getJson('/api/v0.5/personality-content-assets/enneagram/hub/enneagram?locale='.$locale.'&org_id=0')
                ->assertOk()->json('personality_public_content_asset_v1');
            $this->assertSame('/'.$segment.'/personality/enneagram', $payload['canonical_path']);
            $this->assertTrue($payload['index_eligible']);
            $this->assertSame('/'.$segment.'/personality/enneagram', $payload['hreflang'][$locale]);
            $sections = array_column($payload['sections'], null, 'key');
            $this->assertStringContainsString('/'.$segment.'/articles/enneagram-personality-test-explained', $sections['understanding_motivation']['body_md']);
            $this->assertStringContainsString('/'.$segment.'/articles/enneagram-workplace-friction-core-motivations', $sections['compare_next']['body_md']);
        }
    }
}

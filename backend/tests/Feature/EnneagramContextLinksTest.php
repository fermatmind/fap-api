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
                $value = $published ? $update['value'] : $update['expected'];
                if ($value !== null) {
                    $item[$update['field']] = $value;
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
                $this->assertSame($update['expected'] ?? [], array_slice($update['value'], 0, -1));
                $this->assertStringStartsWith('/'.$locale.'/articles/', $update['value'][array_key_last($update['value'])]['href']);
            }
        }
    }

    public function test_english_conflict_in_second_registry_rolls_back_both_tables_without_invalidating_cache(): void
    {
        $this->seedContent();
        $content = $this->content();
        $content['en']['faq'][1]['related_links'] = [['href' => '/later-revision', 'label' => 'Later owner edit']];
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
        // Existing production FAQ revisions can differ from fresh-install seed defaults.
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true);
            foreach ($this->package()['locales'] as $locale => $updates) {
                foreach ($updates as $update) {
                    $index = array_search($update['id'], array_column($content[$locale]['faq'], 'id'), true);
                    if ($update['expected'] === null) {
                        unset($content[$locale]['faq'][$index]['related_links']);
                    } else {
                        $content[$locale]['faq'][$index]['related_links'] = array_map(
                            fn (array $link): array => ['label' => $link['label'], 'href' => $link['href']],
                            $update['expected'],
                        );
                    }
                }
            }
            DB::table($table)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        }
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
                        $this->assertSame($update['value'], $item['related_links']);
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

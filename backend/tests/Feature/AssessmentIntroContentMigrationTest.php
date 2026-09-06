<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentIntroContentMigrationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_intro_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_intro_fixture_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::FIXTURE_CONNECTION => $connection]);
        DB::setDefaultConnection(self::FIXTURE_CONNECTION);
        Schema::clearResolvedInstance('db.schema');

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            Schema::create($table, function (Blueprint $schema): void {
                $schema->integer('org_id');
                $schema->string('code');
                $schema->text('content_i18n_json');
            });
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->originalConnection !== null) {
                foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                    Schema::connection(self::FIXTURE_CONNECTION)->dropIfExists($table);
                }
            }
        } finally {
            if ($this->originalConnection !== null) {
                DB::setDefaultConnection($this->originalConnection);
                DB::purge(self::FIXTURE_CONNECTION);
                Schema::clearResolvedInstance('db.schema');
            }
            parent::tearDown();
        }
    }

    private function package(): array
    {
        return json_decode(file_get_contents(database_path('data/assessment_intro_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR)['scales'];
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_06_150000_publish_assessment_intro_zh.php'))->up();
    }

    public function test_publication_preserves_faq_english_other_scales_and_tenants(): void
    {
        $baseline = ['zh' => ['faq' => [['q' => 'Keep', 'a' => 'Answer']], 'landing_copy' => 'Keep'], 'en' => ['why_choose' => ['title' => 'Keep']]];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ([...array_keys($this->package()), 'MBTI', 'RIASEC'] as $code) {
                foreach ([0, 1] as $org) {
                    DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode($baseline)]);
                }
            }
        }
        $this->publish();
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $expected = $baseline;
                if ($row->org_id === 0 && isset($this->package()[$row->code])) {
                    $expected['zh'] = array_merge($expected['zh'], $this->package()[$row->code]);
                }
                $this->assertEquals($expected, json_decode($row->content_i18n_json, true));
            }
        }
    }

    public function test_existing_editorial_conflict_rolls_back_all_rows(): void
    {
        DB::table('scales_registry')->insert(['org_id' => 0, 'code' => 'BIG5_OCEAN', 'content_i18n_json' => '{"zh":{}}']);
        DB::table('scales_registry_v2')->insert(['org_id' => 0, 'code' => 'EQ_60', 'content_i18n_json' => '{"zh":{"why_choose":{"title":"Owner revision"}}}']);
        try {
            $this->publish();
            $this->fail('Expected conflict');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('refusing to overwrite', $error->getMessage());
        }
        $this->assertSame('{"zh":{}}', DB::table('scales_registry')->value('content_i18n_json'));
    }

    public function test_reviewed_package_links_resolve_to_published_faq_anchors(): void
    {
        $faq = json_decode(file_get_contents(database_path('data/assessment_faq_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR)['scales'];
        $this->assertSame(['BIG5_OCEAN', 'ENNEAGRAM', 'IQ_RAVEN', 'EQ_60'], array_keys($this->package()));
        foreach ($this->package() as $code => $fields) {
            $this->assertCount(5, $fields['why_choose']['items']);
            foreach ($fields['why_choose']['items'] as $item) {
                $this->assertStringContainsString("\n\n", $item['body']);
                if (str_starts_with($item['link']['href'] ?? '', '#')) {
                    $this->assertContains(substr($item['link']['href'], 1), array_column($faq[$code]['faq'], 'id'));
                }
            }
        }
    }

    public function test_seeder_preserves_later_cms_intro_revisions(): void
    {
        $writer = \Mockery::mock(\App\Services\Scale\ScaleRegistryWriter::class);
        $writer->shouldReceive('upsertScale')->andReturnUsing(function (array $attributes) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                DB::table($table)->updateOrInsert(['org_id' => 0, 'code' => $attributes['code']], ['content_i18n_json' => json_encode($attributes['content_i18n_json'])]);
            }

            return new \App\Models\ScaleRegistry;
        });
        $method = new \ReflectionMethod(\Database\Seeders\ScaleRegistrySeeder::class, 'upsertAssessmentPreservingFaq');
        $seeder = new \Database\Seeders\ScaleRegistrySeeder;
        foreach ($this->package() as $code => $fields) {
            $attributes = ['code' => $code, 'content_i18n_json' => ['zh' => []]];
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                $latest = json_decode(file_get_contents(database_path('data/assessment_methods_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($latest['scales'][$code]['why_choose'], $content['zh']['why_choose']);
                if (isset($fields['version_comparison'])) {
                    $this->assertSame($fields['version_comparison'], $content['zh']['version_comparison']);
                }
                $content['zh']['why_choose']['title'] = 'Owner revision';
                DB::table($table)->where('code', $code)->update(['content_i18n_json' => json_encode($content)]);
            }
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame('Owner revision', $content['zh']['why_choose']['title']);
            }
        }
    }
}

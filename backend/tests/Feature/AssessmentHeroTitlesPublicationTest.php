<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentHeroTitlesPublicationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_hero_titles_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_hero_titles_fixture_'.bin2hex(random_bytes(6)).'_';
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
        Schema::create('landing_surfaces', function (Blueprint $schema): void {
            $schema->integer('org_id');
            $schema->string('locale');
            $schema->string('surface_key');
            $schema->text('payload_json');
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->originalConnection !== null) {
                foreach (['scales_registry', 'scales_registry_v2', 'landing_surfaces'] as $table) {
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

    private function fixture(): array
    {
        $scales = json_decode(file_get_contents(database_path('data/assessment_hero_titles_zh_20260907.json')), true, 512, JSON_THROW_ON_ERROR)['scales'];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($scales as $code => $entry) {
                foreach ([0, 1] as $org) {
                    DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode([
                        'en' => ['title' => 'English untouched'],
                        'zh' => ['landing_entry' => ['title' => $entry['expected_title'], 'labels' => ['full' => 'Keep CTA']], 'faq' => ['Keep FAQ']],
                    ])]);
                }
            }
        }
        foreach ($scales as $entry) {
            if ($entry['expected_surface_title'] === null) {
                continue;
            }
            foreach ([[0, 'zh-CN'], [0, 'en'], [1, 'zh-CN']] as [$org, $locale]) {
                DB::table('landing_surfaces')->insert(['org_id' => $org, 'locale' => $locale, 'surface_key' => $entry['surface_key'], 'payload_json' => json_encode([
                    'h1_or_hero_title' => $entry['expected_surface_title'], 'seo_title' => 'Keep SEO', 'hero_copy' => 'Keep source description',
                ])]);
            }
        }

        return $scales;
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_07_120000_publish_assessment_hero_titles_zh.php');
    }

    public function test_titles_preserve_ctas_seo_other_locales_and_tenants_and_are_idempotent(): void
    {
        $scales = $this->fixture();
        $this->migration()->up();
        $before = DB::table('scales_registry')->get()->toJson();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame($before, DB::table('scales_registry')->get()->toJson());
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $entry = $scales[$row->code];
                $this->assertSame([
                    'en' => ['title' => 'English untouched'],
                    'zh' => ['landing_entry' => ['title' => $row->org_id == 0 ? $entry['title'] : $entry['expected_title'], 'labels' => ['full' => 'Keep CTA']], 'faq' => ['Keep FAQ']],
                ], json_decode($row->content_i18n_json, true));
            }
        }
        foreach (DB::table('landing_surfaces')->get() as $row) {
            $entry = array_values(array_filter($scales, fn ($entry) => $entry['surface_key'] === $row->surface_key))[0];
            $this->assertSame([
                'h1_or_hero_title' => $row->org_id == 0 && $row->locale === 'zh-CN' ? $entry['title'] : $entry['expected_surface_title'],
                'seo_title' => 'Keep SEO', 'hero_copy' => 'Keep source description',
            ], json_decode($row->payload_json, true));
        }
    }

    public function test_cms_conflict_rolls_back_registry_and_surface_changes_atomically(): void
    {
        $this->fixture();
        DB::table('landing_surfaces')->where('org_id', 0)->where('locale', 'zh-CN')->where('surface_key', 'test_detail_holland_career_interest_test_riasec')->update(['payload_json' => json_encode(['h1_or_hero_title' => 'Later owner revision'])]);
        $before = DB::table('scales_registry')->get()->toJson();
        $surfaces = DB::table('landing_surfaces')->get()->toJson();
        try {
            $this->migration()->up();
            $this->fail('Conflict expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
        }
        $this->assertSame($before, DB::table('scales_registry')->get()->toJson());
        $this->assertSame($surfaces, DB::table('landing_surfaces')->get()->toJson());
    }

    public function test_registry_conflict_is_not_overwritten(): void
    {
        $this->fixture();
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'MBTI')->update(['content_i18n_json' => json_encode(['zh' => ['landing_entry' => ['title' => 'Later owner revision']]])]);
        $before = DB::table('scales_registry')->get()->toJson();
        try {
            $this->migration()->up();
            $this->fail('Conflict expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
        }
        $this->assertSame($before, DB::table('scales_registry')->get()->toJson());
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentLandingContentSeoPublicationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_landing_content_seo_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_landing_content_seo_'.bin2hex(random_bytes(6)).'_';
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
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                Schema::connection(self::FIXTURE_CONNECTION)->dropIfExists($table);
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
        return json_decode(file_get_contents(database_path('data/assessment_landing_content_seo_20260917.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(): array
    {
        return [
            'zh' => [
                'why_choose' => ['items' => [['id' => 'riasec-scoring', 'title' => '保留方法', 'body' => '保留正文']]],
                'faq' => [['id' => 'faq-riasec-model', 'q' => '保留问题', 'a' => '保留回答']],
                'unrelated' => '保留中文字段',
            ],
            'en' => [
                'why_choose' => ['items' => [['id' => 'riasec-scoring', 'title' => 'Keep method', 'body' => 'Keep body']]],
                'faq' => [['id' => 'faq-riasec-model', 'q' => 'Keep question', 'a' => 'Keep answer']],
                'unrelated' => 'Keep English field',
            ],
            'fr' => ['title' => 'Conserver'],
        ];
    }

    private function seedRows(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ([0, 9] as $org) {
                DB::table($table)->insert([
                    'org_id' => $org,
                    'code' => 'RIASEC',
                    'content_i18n_json' => json_encode($this->baseline(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_17_120000_publish_assessment_landing_content_seo.php'))->up();
    }

    public function test_adds_only_reviewed_bilingual_version_content_and_is_idempotent(): void
    {
        $this->seedRows();
        $this->publish();
        $first = DB::table('scales_registry')->where('org_id', 0)->value('content_i18n_json');
        $this->publish();
        $this->assertSame($first, DB::table('scales_registry')->where('org_id', 0)->value('content_i18n_json'));

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', 'RIASEC')->value('content_i18n_json'), true);
            foreach ($this->package()['scales']['RIASEC']['locales'] as $locale => $localizedPackage) {
                $this->assertSame($localizedPackage['version_comparison'], $content[$locale]['version_comparison']);
                $this->assertSame($localizedPackage['version_item'], collect($content[$locale]['why_choose']['items'])->firstWhere('id', 'versions'));
                $this->assertCount(2, $content[$locale]['why_choose']['items']);
            }
            $this->assertSame($this->baseline()['fr'], $content['fr']);
            $this->assertSame($this->baseline(), json_decode(DB::table($table)->where('org_id', 9)->value('content_i18n_json'), true));
        }
    }

    public function test_conflict_rolls_back_both_registry_tables(): void
    {
        $this->seedRows();
        $content = $this->baseline();
        $content['en']['version_comparison'] = ['later' => 'owner revision'];
        DB::table('scales_registry_v2')->where('org_id', 0)->update(['content_i18n_json' => json_encode($content)]);
        $before = DB::table('scales_registry')->where('org_id', 0)->value('content_i18n_json');

        try {
            $this->publish();
            $this->fail('Expected drift rejection.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('refusing to overwrite', $error->getMessage());
        }

        $this->assertSame($before, DB::table('scales_registry')->where('org_id', 0)->value('content_i18n_json'));
    }

    public function test_fresh_install_seeder_reads_the_same_package(): void
    {
        $source = file_get_contents(database_path('seeders/ScaleRegistrySeeder.php'));
        $this->assertStringContainsString('assessment_landing_content_seo_20260917.json', $source);
        $this->assertStringContainsString('$this->applyLandingContentSeo($attributes);', $source);
    }
}

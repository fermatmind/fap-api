<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentEnglishLandingPublicationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_english_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_english_fixture_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/assessment_landing_en_20260907.json')), true, 512, JSON_THROW_ON_ERROR)['scales'];
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_07_160000_publish_assessment_landing_en.php'))->up();
    }

    private function seedBaseline(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                foreach ([0, 9] as $org) {
                    DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode([
                        'zh' => $entry['source_zh'],
                        'en' => array_filter($entry['expected_en'], fn ($value) => $value !== null) + ['unrelated' => 'Preserve'],
                        'fr' => ['title' => 'Preserve'],
                    ])]);
                }
            }
        }
    }

    public function test_publishes_complete_translation_without_changing_source_tenants_or_other_fields(): void
    {
        $this->seedBaseline();
        $otherTenant = DB::table('scales_registry')->where('org_id', 9)->pluck('content_i18n_json', 'code')->all();
        $this->publish();
        $first = DB::table('scales_registry')->where('org_id', 0)->pluck('content_i18n_json', 'code')->all();
        $this->publish();
        $this->assertSame($first, DB::table('scales_registry')->where('org_id', 0)->pluck('content_i18n_json', 'code')->all());
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->assertSame($otherTenant, DB::table($table)->where('org_id', 9)->pluck('content_i18n_json', 'code')->all());
            foreach ($this->package() as $code => $entry) {
                $value = json_decode(DB::table($table)->where('org_id', 0)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame($entry['source_zh'], $value['zh']);
                $this->assertSame('Preserve', $value['en']['unrelated']);
                $this->assertSame(['title' => 'Preserve'], $value['fr']);
                foreach ($entry['content'] as $key => $translated) {
                    $this->assertSame($translated, $value['en'][$key]);
                }
                $en = $entry['content'];
                $this->assertSame(array_column($entry['source_zh']['faq'], 'id'), array_column($en['faq'], 'id'));
                $this->assertSame(array_column($entry['source_zh']['why_choose']['items'], 'id'), array_column($en['why_choose']['items'], 'id'));
                $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', json_encode($en, JSON_UNESCAPED_UNICODE));
                $this->assertStringNotContainsString('/zh/', json_encode($en, JSON_UNESCAPED_SLASHES));
                foreach ($en['faq'] as $faq) {
                    $this->assertNotEmpty($faq['q']);
                    $this->assertGreaterThan(100, strlen($faq['a']));
                }
            }
        }
    }

    public function test_refuses_changed_english_and_rolls_back_all_scales(): void
    {
        $this->assertDriftRejected('en');
    }

    public function test_refuses_changed_chinese_source_and_rolls_back_all_scales(): void
    {
        $this->assertDriftRejected('zh');
    }

    private function assertDriftRejected(string $locale): void
    {
        $this->seedBaseline();
        $row = DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'RIASEC');
        $content = json_decode($row->value('content_i18n_json'), true);
        $content[$locale]['faq'] = [['q' => 'New owner revision', 'a' => 'Keep']];
        $row->update(['content_i18n_json' => json_encode($content)]);
        $before = DB::table('scales_registry')->pluck('content_i18n_json')->all();
        try {
            $this->publish();
            $this->fail('Expected a drift rejection');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('refusing', $error->getMessage());
        }
        $this->assertSame($before, DB::table('scales_registry')->pluck('content_i18n_json')->all());
        $this->assertSame($content, json_decode(DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'RIASEC')->value('content_i18n_json'), true));
    }

    public function test_new_records_receive_english_and_reseeding_preserves_published_revisions(): void
    {
        $writer = \Mockery::mock(\App\Services\Scale\ScaleRegistryWriter::class);
        $writer->shouldReceive('upsertScale')->andReturnUsing(function (array $attributes) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                DB::table($table)->updateOrInsert(['org_id' => 0, 'code' => $attributes['code']], ['content_i18n_json' => json_encode($attributes['content_i18n_json'])]);
            }

            return new \App\Models\ScaleRegistry;
        });
        $seeder = new \Database\Seeders\ScaleRegistrySeeder;
        foreach ($this->package() as $code => $entry) {
            $method = new \ReflectionMethod($seeder, $code === 'MBTI' ? 'upsertMbtiPreservingPublishedContent' : 'upsertAssessmentPreservingFaq');
            $attributes = ['code' => $code, 'content_i18n_json' => ['en' => [], 'zh' => []]];
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $row = DB::table($table)->where('org_id', 0)->where('code', $code);
                $value = json_decode($row->value('content_i18n_json'), true);
                $this->assertSame($entry['content'], $value['en']);
                $value['en']['why_choose']['title'] = 'Later English revision';
                $value['en']['faq'][0]['a'] = 'Later FAQ revision';
                $row->update(['content_i18n_json' => json_encode($value)]);
            }
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $value = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame('Later English revision', $value['en']['why_choose']['title']);
                $this->assertSame('Later FAQ revision', $value['en']['faq'][0]['a']);
            }
        }
    }
}

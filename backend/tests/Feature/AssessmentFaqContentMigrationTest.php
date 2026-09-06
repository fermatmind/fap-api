<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentFaqContentMigrationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_faq_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_faq_fixture_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/assessment_faq_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR)['scales'];
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_06_140000_publish_assessment_faq_zh.php'))->up();
    }

    private function insert(string $table, string $code, array $faq, int $org = 0): void
    {
        DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode([
            'en' => ['faq' => [['q' => 'English', 'a' => 'Preserved']]],
            'zh' => ['faq' => $faq, 'landing_copy' => 'Preserved'],
        ])]);
    }

    public function test_publishes_exact_five_page_package_without_changing_other_content_or_tenants(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                $this->insert($table, $code, $entry['expected_faq']);
                $this->insert($table, $code, [], 1);
            }
            $this->insert($table, 'MBTI', []);
        }
        $this->publish();
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame($entry['faq'], $content['zh']['faq']);
                $this->assertCount(9, $content['zh']['faq']);
                $this->assertSame('Preserved', $content['zh']['landing_copy']);
                $this->assertSame('English', $content['en']['faq'][0]['q']);
                $other = json_decode(DB::table($table)->where('org_id', 1)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame([], $other['zh']['faq']);
            }
            $other = json_decode(DB::table($table)->where('code', 'MBTI')->value('content_i18n_json'), true);
            $this->assertSame([], $other['zh']['faq']);
        }
    }

    public function test_concurrent_edit_rolls_back_the_entire_publication(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                $this->insert($table, $code, $table === 'scales_registry_v2' && $code === 'EQ_60' ? [['q' => 'Owner edit', 'a' => 'Keep']] : $entry['expected_faq']);
            }
        }
        try {
            $this->publish();
            $this->fail('Expected baseline conflict');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }
        $first = json_decode(DB::table('scales_registry')->where('code', 'BIG5_OCEAN')->value('content_i18n_json'), true);
        $this->assertEquals($this->package()['BIG5_OCEAN']['expected_faq'], $first['zh']['faq']);
    }

    public function test_missing_content_fails_closed_and_empty_registry_is_safe(): void
    {
        $this->publish();
        $this->assertSame(0, DB::table('scales_registry')->count());
        $this->insert('scales_registry', 'EQ_60', []);
        $this->expectException(\RuntimeException::class);
        $this->publish();
    }

    public function test_exact_fresh_install_records_are_upgraded_without_broad_empty_content_fallback(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                if (isset($entry['expected_initial_zh'])) {
                    DB::table($table)->insert(['org_id' => 0, 'code' => $code, 'content_i18n_json' => json_encode(['zh' => $entry['expected_initial_zh']])]);
                }
            }
        }
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package() as $code => $entry) {
                if (isset($entry['expected_initial_zh'])) {
                    $content = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                    $this->assertSame($entry['faq'], $content['zh']['faq']);
                    unset($content['zh']['faq']);
                    $this->assertSame($entry['expected_initial_zh'], $content['zh']);
                }
            }
        }
    }

    public function test_package_has_unique_anchors_paragraphs_and_source_links(): void
    {
        $ids = [];
        foreach ($this->package() as $entry) {
            $referenceCount = 0;
            foreach ($entry['faq'] as $faq) {
                $this->assertMatchesRegularExpression('/^faq-[a-z0-9-]+$/', $faq['id']);
                $this->assertNotContains($faq['id'], $ids);
                $ids[] = $faq['id'];
                $this->assertStringContainsString("\n\n", $faq['a']);
                foreach ($faq['references'] ?? [] as $link) {
                    $this->assertSame('https', parse_url($link['href'], PHP_URL_SCHEME));
                    $referenceCount++;
                }
            }
            $this->assertGreaterThanOrEqual(2, $referenceCount);
        }
        $this->assertCount(45, $ids);
    }

    public function test_seeder_uses_reviewed_faq_for_new_rows_and_preserves_published_edits(): void
    {
        $writer = \Mockery::mock(\App\Services\Scale\ScaleRegistryWriter::class);
        $writer->shouldReceive('upsertScale')->andReturnUsing(function (array $attributes) {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                DB::table($table)->updateOrInsert(['org_id' => 0, 'code' => $attributes['code']], ['content_i18n_json' => json_encode($attributes['content_i18n_json'])]);
            }

            return new \App\Models\ScaleRegistry;
        });
        $seeder = new \Database\Seeders\ScaleRegistrySeeder;
        $method = new \ReflectionMethod($seeder, 'upsertAssessmentPreservingFaq');
        foreach ($this->package() as $code => $entry) {
            $attributes = ['code' => $code, 'content_i18n_json' => ['zh' => ['landing_copy' => 'Default']]];
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                $latest = json_decode(file_get_contents(database_path('data/assessment_methods_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($latest['scales'][$code]['faq'], $content['zh']['faq']);
                $content['zh']['faq'][0]['a'] = 'Later CMS revision';
                DB::table($table)->where('code', $code)->update(['content_i18n_json' => json_encode($content)]);
            }
            $method->invoke($seeder, $writer, $attributes);
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                $content = json_decode(DB::table($table)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame('Later CMS revision', $content['zh']['faq'][0]['a']);
            }
        }
    }
}

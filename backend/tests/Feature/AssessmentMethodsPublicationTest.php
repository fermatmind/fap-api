<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssessmentMethodsPublicationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'assessment_methods_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'assessment_methods_fixture_'.bin2hex(random_bytes(6)).'_';
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

    private function package(string $name): array
    {
        return json_decode(file_get_contents(database_path('data/'.$name)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(string $code): array
    {
        if ($code === 'MBTI') {
            return $this->package('mbti_landing_zh_20260905.json')['content'];
        }

        return array_merge($this->package('assessment_intro_zh_20260906.json')['scales'][$code] ?? [], ['faq' => $this->package('assessment_faq_zh_20260906.json')['scales'][$code]['faq']]);
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_06_160000_publish_assessment_methods_zh.php'))->up();
    }

    public function test_exact_six_page_update_preserves_other_fields_and_locales(): void
    {
        $scales = $this->package('assessment_methods_zh_20260906.json')['scales'];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($scales as $code => $fields) {
                foreach ([0, 1] as $org) {
                    DB::table($table)->insert(['org_id' => $org, 'code' => $code, 'content_i18n_json' => json_encode(['en' => ['title' => 'Keep'], 'zh' => array_merge($this->baseline($code), ['landing_copy' => 'Keep'])])]);
                }
            }
        }
        $this->publish();
        $this->publish();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $actual = json_decode($row->content_i18n_json, true);
                $expected = array_merge($this->baseline($row->code), ['landing_copy' => 'Keep'], $row->org_id === 0 ? $scales[$row->code] : []);
                $this->assertEquals($expected, $actual['zh']);
                $this->assertSame(['title' => 'Keep'], $actual['en']);
            }
        }
    }

    public function test_conflict_rolls_back_all_six_pages(): void
    {
        $scales = $this->package('assessment_methods_zh_20260906.json')['scales'];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($scales as $code => $fields) {
                $base = $this->baseline($code);
                if ($table === 'scales_registry_v2' && $code === 'MBTI') {
                    $base['faq'][0]['a'] = 'Later owner revision';
                }
                DB::table($table)->insert(['org_id' => 0, 'code' => $code, 'content_i18n_json' => json_encode(['zh' => $base])]);
            }
        }
        try {
            $this->publish();
            $this->fail('Conflict expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
        }
        $actual = json_decode(DB::table('scales_registry')->where('code', 'EQ_60')->value('content_i18n_json'), true);
        $this->assertEquals($this->baseline('EQ_60'), $actual['zh']);
    }

    public function test_public_methods_keep_unique_anchors_and_complete_references(): void
    {
        $package = $this->package('assessment_methods_zh_20260906.json');
        $this->assertCount(6, $package['scales']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $package['scoring_source_commit']);
        foreach ($package['scoring_sources'] as $source) {
            $this->assertFileExists(base_path($source));
        }
        foreach ($package['scales'] as $fields) {
            $ids = array_column($fields['faq'], 'id');
            $this->assertCount(count($ids), array_unique($ids));
            foreach ($fields['why_choose']['items'] as $item) {
                if (str_starts_with($item['link']['href'] ?? '', '#faq-')) {
                    $this->assertContains(substr($item['link']['href'], 1), $ids);
                }
            }
        }
    }
}

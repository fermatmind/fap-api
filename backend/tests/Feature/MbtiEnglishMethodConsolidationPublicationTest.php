<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MbtiEnglishMethodConsolidationPublicationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'mbti_english_method_consolidation_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'mbti_english_method_consolidation_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/assessment_mbti_method_consolidation_en_20260920.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(): array
    {
        return json_decode(file_get_contents(database_path('data/assessment_landing_en_20260907.json')), true, 512, JSON_THROW_ON_ERROR)['scales']['MBTI']['content'];
    }

    private function insert(string $table, array $en): void
    {
        DB::table($table)->insert([
            'org_id' => 0,
            'code' => 'MBTI',
            'content_i18n_json' => json_encode([
                'en' => array_merge($en, ['unrelated' => 'Keep']),
                'zh' => ['why_choose' => ['intro' => '保留中文']],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_20_100000_consolidate_mbti_method_content_en.php'))->up();
    }

    private function applyPackage(array $content): array
    {
        foreach ($this->package()['updates'] as $update) {
            $collection = data_get($content, $update['collection']);
            $index = array_search($update['id'], array_column($collection, 'id'), true);
            $collection[$index][$update['field']] = $update['value'];
            data_set($content, $update['collection'], $collection);
        }

        return $content;
    }

    public function test_updates_only_exact_fields_in_both_registries_and_is_idempotent(): void
    {
        $package = $this->package();
        $this->assertSame('assessment.exact-field-copy.v2', $package['schema_version']);
        $this->assertSame('MBTI', $package['scale_code']);
        $this->assertSame('en', $package['locale']);
        $this->assertCount(7, $package['updates']);

        $before = $this->baseline();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->insert($table, $before);
        }

        $this->publish();
        $this->publish();

        $expected = $this->applyPackage(array_merge($before, ['unrelated' => 'Keep']));
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['why_choose' => ['intro' => '保留中文']], $content['zh']);
            $this->assertSame($expected, $content['en']);

            $validity = collect($content['en']['faq'])->firstWhere('id', 'faq-validity');
            $this->assertCount(2, $validity['references']);
            $this->assertSame(['#method-and-evidence', '/en/reliability-validity'], array_column($validity['related_links'], 'href'));
        }
    }

    public function test_rejects_cross_registry_mixed_state_without_writing(): void
    {
        $before = $this->baseline();
        $this->insert('scales_registry', $before);
        $this->insert('scales_registry_v2', $this->applyPackage($before));

        try {
            $this->publish();
            $this->fail('Expected mixed registry state rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('mixed state', $exception->getMessage());
        }

        $actual = json_decode(DB::table('scales_registry')->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($before['why_choose'], $actual['en']['why_choose']);
    }

    public function test_rejects_partial_or_unknown_target_state_without_writing(): void
    {
        $before = $this->baseline();
        $partial = $before;
        $partial['why_choose']['items'][0]['title'] = $this->package()['updates'][0]['value'];
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->insert($table, $partial);
        }

        try {
            $this->publish();
            $this->fail('Expected partial state rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('mixed or changed since review', $exception->getMessage());
        }

        $actual = json_decode(DB::table('scales_registry')->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($partial['why_choose'], $actual['en']['why_choose']);
    }

    public function test_transaction_rolls_back_both_registries_when_the_second_update_fails(): void
    {
        $before = $this->baseline();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->insert($table, $before);
        }

        $prefix = DB::connection()->getTablePrefix();
        DB::unprepared(sprintf(
            "CREATE TRIGGER %smbti_english_update_failure BEFORE UPDATE ON %sscales_registry_v2 BEGIN SELECT RAISE(ABORT, 'forced update failure'); END",
            $prefix,
            $prefix,
        ));

        try {
            $this->publish();
            $this->fail('Expected the second registry update to fail.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('forced update failure', $exception->getMessage());
        }

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $actual = json_decode(DB::table($table)->value('content_i18n_json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($before['why_choose'], $actual['en']['why_choose']);
            $this->assertSame($before['faq'], $actual['en']['faq']);
        }
    }

    public function test_rejects_incomplete_registry_rows_and_seeder_uses_the_same_package(): void
    {
        $this->insert('scales_registry', $this->baseline());

        try {
            $this->publish();
            $this->fail('Expected incomplete registry row rejection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('row set is incomplete', $exception->getMessage());
        }

        $source = file_get_contents(database_path('seeders/ScaleRegistrySeeder.php'));
        $this->assertStringContainsString('assessment_mbti_method_consolidation_en_20260920.json', $source);
    }
}

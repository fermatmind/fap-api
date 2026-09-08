<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MbtiLandingCopyRefinementTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'mbti_landing_copy_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'mbti_landing_copy_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/assessment_mbti_copy_zh_20260908.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(): array
    {
        $base = json_decode(file_get_contents(database_path('data/mbti_landing_zh_20260905.json')), true, 512, JSON_THROW_ON_ERROR)['content'];
        $methods = json_decode(file_get_contents(database_path('data/assessment_methods_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR)['scales']['MBTI'];

        return array_replace($base, $methods);
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_08_090000_refine_mbti_landing_copy_zh.php'))->up();
    }

    private function insert(string $table, int $org, array $zh): void
    {
        DB::table($table)->insert([
            'org_id' => $org,
            'code' => 'MBTI',
            'content_i18n_json' => json_encode([
                'en' => ['why_choose' => ['intro' => 'Keep English']],
                'zh' => array_merge($zh, ['unrelated' => 'Keep']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function test_updates_only_reviewed_fields_and_is_idempotent(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->insert($table, 0, $this->baseline());
            $this->insert($table, 1, $this->baseline());
        }

        $this->publish();
        $this->publish();

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->value('content_i18n_json'), true);
            $this->assertSame('Keep English', $content['en']['why_choose']['intro']);
            $this->assertSame('Keep', $content['zh']['unrelated']);
            $this->assertSame(array_column($this->baseline()['faq'], 'id'), array_column($content['zh']['faq'], 'id'));

            foreach ($this->package()['updates'] as $update) {
                if (isset($update['path'])) {
                    $this->assertSame($update['value'], data_get($content['zh'], $update['path']));

                    continue;
                }
                $item = collect(data_get($content['zh'], $update['collection']))->firstWhere('id', $update['id']);
                $this->assertSame($update['value'], $item[$update['field']]);
            }

            $tenant = json_decode(DB::table($table)->where('org_id', 1)->value('content_i18n_json'), true);
            $this->assertSame($this->baseline(), array_diff_key($tenant['zh'], ['unrelated' => true]));
        }
    }

    public function test_conflict_rolls_back_the_whole_batch(): void
    {
        $this->insert('scales_registry', 0, $this->baseline());
        $conflict = $this->baseline();
        $conflict['faq'][0]['a'] = 'Later owner revision';
        $this->insert('scales_registry_v2', 0, $conflict);

        try {
            $this->publish();
            $this->fail('Expected baseline conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }

        $content = json_decode(DB::table('scales_registry')->value('content_i18n_json'), true);
        $this->assertSame($this->baseline()['why_choose']['intro'], $content['zh']['why_choose']['intro']);
    }

    public function test_fresh_install_seeder_reads_the_same_exact_field_package(): void
    {
        $source = file_get_contents(database_path('seeders/ScaleRegistrySeeder.php'));

        $this->assertStringContainsString('assessment_mbti_copy_zh_20260908.json', $source);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RiasecEnneagramLandingCopyRefinementTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'riasec_enneagram_copy_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'riasec_enneagram_copy_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/assessment_riasec_enneagram_copy_zh_20260908.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function baseline(string $code): array
    {
        return json_decode(file_get_contents(database_path('data/assessment_methods_zh_20260906.json')), true, 512, JSON_THROW_ON_ERROR)['scales'][$code];
    }

    private function insert(string $table, int $org, string $code, array $zh): void
    {
        DB::table($table)->insert([
            'org_id' => $org,
            'code' => $code,
            'content_i18n_json' => json_encode([
                'en' => ['why_choose' => ['intro' => 'Keep English']],
                'zh' => array_merge($zh, ['unrelated' => 'Keep']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function publish(): void
    {
        (require database_path('migrations/2026_09_08_100000_refine_riasec_enneagram_landing_copy_zh.php'))->up();
    }

    public function test_updates_only_reviewed_fields_and_is_idempotent(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (array_keys($this->package()['scales']) as $code) {
                $this->insert($table, 0, $code, $this->baseline($code));
                $this->insert($table, 7, $code, $this->baseline($code));
            }
        }

        $this->publish();
        $this->publish();

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach ($this->package()['scales'] as $code => $scale) {
                $content = json_decode(DB::table($table)->where('org_id', 0)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame('Keep English', $content['en']['why_choose']['intro']);
                $this->assertSame('Keep', $content['zh']['unrelated']);
                $this->assertSame(array_column($this->baseline($code)['faq'], 'id'), array_column($content['zh']['faq'], 'id'));
                $this->assertCount(count($this->baseline($code)['faq']), $content['zh']['faq']);
                foreach ($this->baseline($code)['faq'] as $index => $baselineFaq) {
                    $this->assertSame($baselineFaq['q'], $content['zh']['faq'][$index]['q']);
                    $this->assertSame($baselineFaq['references'] ?? null, $content['zh']['faq'][$index]['references'] ?? null);
                    $this->assertSame($baselineFaq['related_links'] ?? null, $content['zh']['faq'][$index]['related_links'] ?? null);
                }

                foreach ($scale['updates'] as $update) {
                    if (isset($update['path'])) {
                        $this->assertSame($update['value'], data_get($content['zh'], $update['path']));
                    } else {
                        $item = collect(data_get($content['zh'], $update['collection']))->firstWhere('id', $update['id']);
                        $this->assertSame($update['value'], $item[$update['field']]);
                    }
                }

                $tenant = json_decode(DB::table($table)->where('org_id', 7)->where('code', $code)->value('content_i18n_json'), true);
                $this->assertSame($this->baseline($code), array_diff_key($tenant['zh'], ['unrelated' => true]));
            }
        }
    }

    public function test_one_conflict_rolls_back_both_scales_and_tables(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (array_keys($this->package()['scales']) as $code) {
                $baseline = $this->baseline($code);
                if ($table === 'scales_registry_v2' && $code === 'ENNEAGRAM') {
                    $baseline['why_choose']['intro'] = 'Later owner revision';
                }
                $this->insert($table, 0, $code, $baseline);
            }
        }

        try {
            $this->publish();
            $this->fail('Expected baseline conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }

        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('code', 'RIASEC')->value('content_i18n_json'), true);
            $this->assertSame($this->baseline('RIASEC')['why_choose']['intro'], $content['zh']['why_choose']['intro']);
        }
    }

    public function test_fresh_install_seeder_reads_the_same_package(): void
    {
        $source = file_get_contents(database_path('seeders/ScaleRegistrySeeder.php'));
        $this->assertStringContainsString('assessment_riasec_enneagram_copy_zh_20260908.json', $source);
    }
}

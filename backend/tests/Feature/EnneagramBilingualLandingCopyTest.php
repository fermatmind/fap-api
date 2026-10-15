<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnneagramBilingualLandingCopyTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'enneagram_bilingual_landing_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'enneagram_bilingual_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::FIXTURE_CONNECTION => $connection]);
        DB::setDefaultConnection(self::FIXTURE_CONNECTION);
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
        return json_decode(file_get_contents(database_path('data/assessment_enneagram_bilingual_20261015.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function content(): array
    {
        $content = ['fr' => ['title' => 'Preserve another locale']];
        foreach ($this->package()['locales'] as $locale => $updates) {
            $content[$locale] = ['landing_copy' => 'Preserve introduction', 'faq' => [], 'why_choose' => ['items' => []]];
            foreach ($updates as $update) {
                $collection = data_get($content[$locale], $update['collection']);
                $index = array_search($update['id'], array_column($collection, 'id'), true);
                if ($index === false) {
                    $collection[] = ['id' => $update['id'], 'untouched' => 'Preserve item'];
                    $index = array_key_last($collection);
                }
                if ($update['expected'] !== null) {
                    $collection[$index][$update['field']] = $update['expected'];
                }
                data_set($content[$locale], $update['collection'], $collection);
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
        (require database_path('migrations/2026_10_15_130000_refine_enneagram_bilingual_landing_copy.php'))->up();
    }

    public function test_bilingual_copy_is_atomic_idempotent_and_preserves_other_content(): void
    {
        $this->seedContent();
        $this->publish();
        $this->publish();
        $expected = $this->content();
        foreach ($this->package()['locales'] as $locale => $updates) {
            foreach ($updates as $update) {
                $collection = data_get($expected[$locale], $update['collection']);
                $index = array_search($update['id'], array_column($collection, 'id'), true);
                $collection[$index][$update['field']] = $update['value'];
                data_set($expected[$locale], $update['collection'], $collection);
            }
        }
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertSame('preserve', $row->identity);
                $this->assertSame(
                    $row->org_id === 0 && $row->code === 'ENNEAGRAM' ? $expected : $this->content(),
                    json_decode($row->content_i18n_json, true)
                );
            }
        }
        (require database_path('migrations/2026_10_15_130000_refine_enneagram_bilingual_landing_copy.php'))->down();
        $this->assertSame($expected, json_decode(DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
    }

    public function test_a_later_english_revision_rolls_back_both_locales_and_tables(): void
    {
        $this->seedContent();
        $content = $this->content();
        $content['en']['why_choose']['items'][0]['body'] = 'Later owner revision';
        DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        try {
            $this->publish();
            $this->fail('Expected a reviewed baseline conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }
        $this->assertSame($this->content(), json_decode(DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
        $this->assertSame($content, json_decode(DB::table('scales_registry_v2')->where('org_id', 0)->where('code', 'ENNEAGRAM')->value('content_i18n_json'), true));
    }

    public function test_a_missing_item_cannot_be_silently_created(): void
    {
        $this->seedContent();
        $content = $this->content();
        $content['en']['why_choose']['items'] = [];
        DB::table('scales_registry')->where('org_id', 0)->where('code', 'ENNEAGRAM')->update(['content_i18n_json' => json_encode($content)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is missing');
        $this->publish();
    }
}

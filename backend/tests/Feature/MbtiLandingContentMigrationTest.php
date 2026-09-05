<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MbtiLandingContentMigrationTest extends TestCase
{
    private const FIXTURE_CONNECTION = 'mbti_landing_content_fixture';

    private ?string $originalConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'mbti_landing_fixture_'.bin2hex(random_bytes(6)).'_';
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
        return json_decode(file_get_contents(database_path('data/mbti_landing_zh_20260905.json')), true);
    }

    private function migrateContent(): void
    {
        (require database_path('migrations/2026_09_05_120000_publish_mbti_landing_zh_content.php'))->up();
    }

    private function seedContent(string $table, array $faq, int $org = 0): void
    {
        DB::table($table)->insert(['org_id' => $org, 'code' => 'MBTI', 'content_i18n_json' => json_encode([
            'en' => ['faq' => [['q' => 'English', 'a' => 'Preserved']]],
            'zh' => ['faq' => $faq, 'landing_copy' => 'Preserved'],
        ])]);
    }

    public function test_publishes_nine_questions_preserving_other_fields_and_tenants_and_is_idempotent(): void
    {
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $this->seedContent($table, $this->package()['expected_faq']);
            $this->seedContent($table, [], 1);
        }
        $this->migrateContent();
        $this->migrateContent();
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            $content = json_decode(DB::table($table)->where('org_id', 0)->value('content_i18n_json'), true);
            $this->assertCount(9, $content['zh']['faq']);
            $this->assertCount(9, array_unique(array_column($content['zh']['faq'], 'id')));
            $this->assertSame('Preserved', $content['zh']['landing_copy']);
            $this->assertSame('English', $content['en']['faq'][0]['q']);
            $this->assertCount(5, $content['zh']['version_comparison']['rows']);
            $other = json_decode(DB::table($table)->where('org_id', 1)->value('content_i18n_json'), true);
            $this->assertSame([], $other['zh']['faq']);
        }
    }

    public function test_concurrent_edit_aborts_both_registry_updates(): void
    {
        $this->seedContent('scales_registry', $this->package()['expected_faq']);
        $this->seedContent('scales_registry_v2', [['q' => 'Edited', 'a' => 'By owner']]);
        try {
            $this->migrateContent();
            $this->fail('Expected baseline conflict');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }
        $content = json_decode(DB::table('scales_registry')->value('content_i18n_json'), true);
        $this->assertArrayNotHasKey('why_choose', $content['zh']);
    }

    public function test_accepts_exact_fresh_install_content(): void
    {
        DB::table('scales_registry')->insert(['org_id' => 0, 'code' => 'MBTI', 'content_i18n_json' => json_encode(['zh' => $this->package()['expected_initial_zh']])]);
        $this->migrateContent();
        $content = json_decode(DB::table('scales_registry')->value('content_i18n_json'), true);
        $this->assertCount(9, $content['zh']['faq']);
    }

    public function test_empty_registry_is_compatible_with_fresh_migrations(): void
    {
        $this->migrateContent();
        $this->assertSame(0, DB::table('scales_registry')->count());
    }
}

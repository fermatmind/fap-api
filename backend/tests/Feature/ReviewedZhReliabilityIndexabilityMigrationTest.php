<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ReviewedZhReliabilityIndexabilityMigrationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-21 12:00:00'));
        $this->originalConnection = DB::getDefaultConnection();
        $connection = DB::connection()->getConfig();
        $connection['prefix'] = 'zh_reliability_index_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.zh_reliability_index_fixture' => $connection]);
        DB::setDefaultConnection('zh_reliability_index_fixture');
        Schema::clearResolvedInstance('db.schema');
        Schema::create('content_pages', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('org_id');
            foreach (['slug', 'path', 'canonical_path', 'locale', 'page_type', 'status', 'review_state', 'claim_gate_status'] as $field) {
                $t->string($field);
            }
            foreach (['title', 'summary', 'seo_title', 'meta_description', 'content_md', 'faq_items', 'forbidden_claims'] as $field) {
                $t->text($field)->nullable();
            }
            foreach (['is_public', 'is_indexable', 'publish_allowed', 'operator_approval_required', 'legal_review_required', 'science_review_required', 'schema_enabled', 'faq_schema_eligible'] as $field) {
                $t->boolean($field)->default(false);
            }
            foreach (['published_revision_id', 'working_revision_id'] as $field) {
                $t->integer($field)->nullable();
            }
            foreach (['published_at', 'operator_approved_at', 'schema_eligibility_reviewed_at', 'updated_at'] as $field) {
                $t->timestamp($field)->nullable();
            }
        });
        Schema::create('cms_translation_revisions', function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->integer('org_id');
            $t->integer('content_id');
            foreach (['content_type', 'locale', 'revision_status'] as $field) {
                $t->string($field);
            }
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cms_translation_revisions');
        Schema::dropIfExists('content_pages');
        DB::purge('zh_reliability_index_fixture');
        DB::setDefaultConnection($this->originalConnection);
        Schema::clearResolvedInstance('db.schema');
        $this->travelBack();
        parent::tearDown();
    }

    public function test_only_reviewed_zh_page_flag_changes_and_api_cache_is_refreshed(): void
    {
        $this->seedPage();
        $this->seedPage(['id' => 8, 'locale' => 'en']);
        $this->seedPage(['id' => 9, 'org_id' => 1]);
        $this->seedPage(['id' => 10, 'slug' => 'science']);
        $before = DB::table('content_pages')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $revisions = DB::table('cms_translation_revisions')->get()->toArray();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once();
        $this->getJson('/api/v0.5/content-pages/reliability-validity?locale=zh-CN&org_id=0')
            ->assertOk()->assertJsonPath('page.is_indexable', false);
        $this->migration()->up();
        $this->migration()->up();
        $this->migration()->down();
        $expected = $before;
        $expected[0]['is_indexable'] = 1;
        $this->assertSame($expected, DB::table('content_pages')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertEquals($revisions, DB::table('cms_translation_revisions')->get()->toArray());
        $this->getJson('/api/v0.5/content-pages/reliability-validity?locale=zh-CN&org_id=0')
            ->assertOk()->assertJsonPath('page.is_indexable', true);
    }

    #[DataProvider('unsafeStates')]
    public function test_unreviewed_or_changed_page_is_rejected(array $changes): void
    {
        $this->seedPage($changes);
        $this->mock(AuditLogger::class)->shouldNotReceive('log');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the reviewed current published version');
        $this->migration()->up();
    }

    public static function unsafeStates(): array
    {
        return array_map(fn ($changes) => [$changes], [
            'unpublished' => ['status' => 'draft'],
            'private' => ['is_public' => false],
            'review pending' => ['review_state' => 'changes_requested'],
            'legal hold' => ['legal_review_required' => true],
            'science hold' => ['science_review_required' => true],
            'claims failed' => ['claim_gate_status' => 'failed'],
            'new draft' => ['working_revision_id' => 99],
            'missing revision' => ['working_revision_id' => 99, 'published_revision_id' => 99],
            'changed copy' => ['content_md' => 'Unreviewed replacement'],
            'different canonical' => ['canonical_path' => '/science'],
            'future publication' => ['published_at' => '2026-10-22 12:00:00'],
        ]);
    }

    public function test_audit_failure_rolls_back_the_flag_and_keeps_cached_projection(): void
    {
        $this->seedPage();
        Cache::put('content_page:v1:0:reliability-validity:zh-CN', 'unchanged');
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit failed'));
        try {
            $this->migration()->up();
            $this->fail('Expected atomic rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('audit failed', $error->getMessage());
        }
        $this->assertSame(0, DB::table('content_pages')->value('is_indexable'));
        $this->assertSame('unchanged', Cache::get('content_page:v1:0:reliability-validity:zh-CN'));
    }

    public function test_missing_page_is_not_created(): void
    {
        $this->mock(AuditLogger::class)->shouldNotReceive('log');
        $this->migration()->up();
        $this->assertSame(0, DB::table('content_pages')->count());
    }

    public function test_unapproved_revision_is_rejected_without_mutation(): void
    {
        $this->seedPage();
        DB::table('cms_translation_revisions')->where('id', 17)->update(['approved_at' => null]);
        $this->mock(AuditLogger::class)->shouldNotReceive('log');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not the reviewed current published version');
        $this->migration()->up();
    }

    public function test_ambiguous_identity_is_rejected_without_mutation(): void
    {
        $this->seedPage();
        $this->seedPage(['id' => 8]);
        $this->mock(AuditLogger::class)->shouldNotReceive('log');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity is ambiguous');
        $this->migration()->up();
    }

    private function seedPage(array $changes = []): void
    {
        $package = json_decode(file_get_contents(base_path('content_assets/public_content_53/seo-alignment.json')), true);
        $row = array_values(array_filter($package['candidates'], fn ($row) => $row['page_id'] === 'PAGE-63'))[0];
        $copy = $row['snapshot'];
        $copy['faq_items'] = json_encode($copy['faq_items'], JSON_THROW_ON_ERROR);
        DB::table('content_pages')->insert(array_replace($copy, [
            'id' => 7, 'org_id' => 0, 'slug' => 'reliability-validity', 'path' => '/reliability-validity',
            'canonical_path' => '/reliability-validity', 'locale' => 'zh-CN', 'page_type' => 'methodology',
            'status' => 'published', 'review_state' => 'approved', 'claim_gate_status' => 'passed',
            'is_public' => true, 'is_indexable' => false, 'publish_allowed' => true,
            'operator_approval_required' => true, 'operator_approved_at' => '2026-06-09',
            'published_at' => '2026-06-09', 'updated_at' => '2026-10-21', 'forbidden_claims' => '[]',
            'published_revision_id' => 17, 'working_revision_id' => 17,
        ], $changes));
        if (! DB::table('cms_translation_revisions')->where('id', 17)->exists()) {
            DB::table('cms_translation_revisions')->insert(['id' => 17, 'org_id' => 0, 'content_id' => 7,
                'content_type' => 'content_page', 'locale' => 'zh-CN', 'revision_status' => 'published',
                'approved_at' => '2026-10-21', 'published_at' => '2026-10-21']);
        }
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_21_220000_activate_reviewed_zh_reliability_indexability.php');
    }
}

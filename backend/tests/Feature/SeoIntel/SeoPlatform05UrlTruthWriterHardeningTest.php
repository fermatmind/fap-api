<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform05UrlTruthWriterHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function expand_migration_keeps_history_and_selects_one_deterministic_current_binding(): void
    {
        $this->prepareLegacySchema();
        $now = Carbon::parse('2026-08-24T00:00:00Z');
        $hash = hash('sha256', 'https://fermatmind.com/en/articles/example');

        DB::connection('seo_intel')->table('seo_urls')->insert($this->urlRow($hash, $now));
        DB::connection('seo_intel')->table('seo_url_entities')->insert([
            $this->entityRow($hash, 'published_approved', $now->copy()->subDay(), $now->copy()->subDay()),
            $this->entityRow($hash, 'published_approved', $now, $now),
            $this->entityRow($hash, 'superseded_canonical', $now->copy()->addDay(), $now->copy()->addDay()),
        ]);

        $migration = require dirname(__DIR__, 3).'/database/migrations/seo_intel/2026_08_25_020000_expand_url_truth_current_bindings.php';
        $migration->up();

        $rows = DB::connection('seo_intel')->table('seo_url_entities')->orderBy('id')->get();
        $current = $rows->where('binding_status', 'current');

        $this->assertCount(3, $rows);
        $this->assertCount(1, $current);
        $this->assertSame(2, (int) $current->first()->id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $current->first()->current_binding_key);
        $this->assertNull($rows[0]->current_binding_key);
        $this->assertSame('superseded_duplicate', $rows[0]->binding_status);
        $this->assertSame('superseded_duplicate', $rows[2]->binding_status);
        $this->assertSame(2, (int) $rows[0]->superseded_by_id);
        $this->assertSame($hash, DB::connection('seo_intel')->table('seo_urls')->value('canonical_revision'));
    }

    #[Test]
    public function writer_preserves_first_seen_and_retires_old_canonical_without_deleting_history(): void
    {
        $this->prepareLegacySchema();
        $migration = require dirname(__DIR__, 3).'/database/migrations/seo_intel/2026_08_25_020000_expand_url_truth_current_bindings.php';
        $migration->up();
        $writer = new UrlTruthInventoryRecordWriter;
        $old = $this->record('https://fermatmind.com/en/articles/old');

        Carbon::setTestNow('2026-08-24T01:00:00Z');
        $writer->write([$old]);
        $first = DB::connection('seo_intel')->table('seo_urls')->first();

        Carbon::setTestNow('2026-08-24T02:00:00Z');
        $writer->write([$old]);
        $rerun = DB::connection('seo_intel')->table('seo_urls')->first();

        $this->assertSame((string) $first->first_seen_at, (string) $rerun->first_seen_at);
        $this->assertSame((string) $first->created_at, (string) $rerun->created_at);
        $this->assertNotSame((string) $first->last_seen_at, (string) $rerun->last_seen_at);

        Carbon::setTestNow('2026-08-24T03:00:00Z');
        $writer->write([$this->record('https://fermatmind.com/en/articles/new')]);

        $urls = DB::connection('seo_intel')->table('seo_urls')->orderBy('id')->get();
        $bindings = DB::connection('seo_intel')->table('seo_url_entities')->orderBy('id')->get();

        $this->assertCount(2, $urls);
        $this->assertCount(2, $bindings);
        $this->assertSame('superseded_canonical', $urls[0]->indexability_state);
        $this->assertSame('indexable', $urls[1]->indexability_state);
        $this->assertSame('superseded_canonical', $bindings[0]->binding_status);
        $this->assertNull($bindings[0]->current_binding_key);
        $this->assertSame((int) $bindings[1]->id, (int) $bindings[0]->superseded_by_id);
        $this->assertSame('current', $bindings[1]->binding_status);
        $this->assertNotNull($bindings[1]->current_binding_key);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->whereNotNull('current_binding_key')->count());
        $this->assertSame('career', $bindings[1]->page_family);
        $this->assertSame(hash('sha256', 'authority_revision|'.str_repeat('a', 64)), $bindings[1]->authority_revision);
        $this->assertSame(str_repeat('b', 64), $bindings[1]->canonical_revision);
    }

    private function prepareLegacySchema(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255)->nullable();
            $table->string('cluster', 64)->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source', 64)->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });

        Schema::connection('seo_intel')->create('seo_url_entities', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug', 255);
            $table->string('entity_source', 64);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
        });
    }

    /** @return array<string, mixed> */
    private function urlRow(string $hash, Carbon $now): array
    {
        return [
            'canonical_url_hash' => $hash,
            'canonical_url' => 'https://fermatmind.com/en/articles/example',
            'locale' => 'en',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => 'example',
            'cluster' => 'articles',
            'source_authority' => 'cms_article',
            'indexability_state' => 'indexable',
            'lastmod_at' => $now,
            'lastmod_source' => 'cms_article',
            'is_private_flow' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'metadata_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array<string, mixed> */
    private function entityRow(string $hash, string $status, Carbon $sourceUpdatedAt, Carbon $updatedAt): array
    {
        return [
            'canonical_url_hash' => $hash,
            'locale' => 'en',
            'page_entity_type' => 'article',
            'entity_id_or_slug' => 'example',
            'entity_source' => 'cms_article',
            'authority_status' => $status,
            'source_updated_at' => $sourceUpdatedAt,
            'attributes_json' => '{}',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function record(string $canonicalUrl): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: $canonicalUrl,
            locale: 'en',
            pageEntityType: 'career_job',
            entityIdOrSlug: 'example',
            sourceAuthority: 'career_runtime_publish_projection',
            entitySource: 'career_directory_authority',
            authorityStatus: 'published_approved',
            metadata: [
                'authority_revision' => str_repeat('a', 64),
                'canonical_revision' => str_repeat('b', 64),
            ],
        );
    }
}

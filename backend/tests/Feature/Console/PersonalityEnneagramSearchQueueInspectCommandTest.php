<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonalityEnneagramSearchQueueInspectCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'seo_intel.search_channel_queue.write_enabled' => false,
        ]);
        DB::purge('seo_intel');
        $this->createSeoIntelTables();
    }

    #[Test]
    public function it_inspects_exactly_116_public_enneagram_assets_without_writes(): void
    {
        $this->seedTargetSet();
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-inspect', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('GO_FOR_SEPARATE_SEARCH_QUEUE_AUTHORIZATION', $payload['decision'] ?? null);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame(116, $payload['target_count'] ?? null);
        $this->assertSame(116, data_get($payload, 'summary.candidate_count'));
        $this->assertSame(116, data_get($payload, 'summary.eligible_count'));
        $this->assertSame(0, data_get($payload, 'summary.blocked_count'));
        $this->assertSame(116, data_get($payload, 'summary.planned_queue_count'));
        $this->assertSame(0, data_get($payload, 'summary.active_duplicate_count'));
        $this->assertSame([], $payload['issues'] ?? []);
        $this->assertCount(116, $payload['targets'] ?? []);
        $this->assertFalse((bool) data_get($payload, 'negative_guarantees.database_write', true));
        $this->assertFalse((bool) data_get($payload, 'negative_guarantees.queue_write', true));
        $this->assertFalse((bool) data_get($payload, 'negative_guarantees.search_submit', true));
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function it_reports_active_duplicate_without_enqueue_or_submission(): void
    {
        $paths = $this->seedTargetSet();
        $first = $paths[0];
        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'canonical_url' => 'https://fermatmind.com'.$first['path'],
            'locale' => $first['locale'],
            'page_entity_type' => 'personality_profile_variant',
            'channel' => 'indexnow',
            'approval_state' => 'pending',
            'execution_state' => 'dry_run_ready',
            'url_hash' => hash('sha256', 'https://fermatmind.com'.$first['path']),
            'content_hash' => hash('sha256', $first['path']),
            'lastmod' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-inspect', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('NO_GO_SEARCH_RELEASE', $payload['decision'] ?? null);
        $this->assertSame(1, data_get($payload, 'summary.active_duplicate_count'));
        $this->assertSame(115, data_get($payload, 'summary.planned_queue_count'));
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function it_fails_closed_when_the_canonical_target_set_is_not_unique(): void
    {
        $this->seedTargetSet();
        $duplicatePath = '/en/personality/enneagram';
        $asset = PersonalityPublicContentAsset::query()
            ->where('locale', 'en')
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_CENTER)
            ->firstOrFail();
        $asset->update(['canonical_json' => ['path' => $duplicatePath]]);
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-inspect', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('NO_GO_SEARCH_RELEASE', $payload['decision'] ?? null);
        $this->assertContains('enneagram_canonical_path_set_mismatch', $payload['issues'] ?? []);
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function it_requires_explicit_dry_run_and_does_not_query_or_write_queue_state(): void
    {
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-inspect', ['--json' => true]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('NO_GO_SAFETY_VIOLATION', $payload['decision'] ?? null);
        $this->assertContains('dry_run_required', $payload['issues'] ?? []);
        $this->assertSame($before, $this->queueCounts());
    }

    /** @return list<array{locale:string,path:string}> */
    private function seedTargetSet(): array
    {
        $paths = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $prefix = $locale === 'en' ? '/en' : '/zh';
            $this->seedAsset($locale, PersonalityPublicContentAsset::ENTITY_HUB, 'enneagram', $prefix.'/personality/enneagram');
            foreach (['gut', 'heart', 'head'] as $center) {
                $this->seedAsset($locale, PersonalityPublicContentAsset::ENTITY_CENTER, $center, $prefix.'/personality/enneagram/centers/'.$center);
            }
            for ($type = 1; $type <= 9; $type++) {
                $this->seedAsset($locale, PersonalityPublicContentAsset::ENTITY_CORE_TYPE, 'type-'.$type, $prefix.'/personality/enneagram/type-'.$type);
            }
            foreach ([
                '1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6',
                '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1',
            ] as $wing) {
                $this->seedAsset($locale, PersonalityPublicContentAsset::ENTITY_WING, $wing, $prefix.'/personality/enneagram/wings/'.$wing);
            }
            foreach (range(1, 9) as $type) {
                foreach (['self-preservation', 'social', 'one-to-one'] as $instinct) {
                    $this->seedAsset($locale, PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE, 'type-'.$type.'-'.$instinct, $prefix.'/personality/enneagram/type-'.$type.'/instincts/'.$instinct);
                }
            }
        }

        foreach (PersonalityPublicContentAsset::query()->orderBy('locale')->orderBy('entity_type')->orderBy('entity_key')->get() as $asset) {
            $path = (string) data_get($asset->canonical_json, 'path');
            $paths[] = ['locale' => (string) $asset->locale, 'path' => $path];
            $canonicalUrl = 'https://fermatmind.com'.$path;
            DB::connection('seo_intel')->table('seo_urls')->insert([
                'canonical_url_hash' => hash('sha256', $canonicalUrl),
                'canonical_url' => $canonicalUrl,
                'locale' => $asset->locale,
                'page_entity_type' => 'personality_profile_variant',
                'source_authority' => 'backend_public_surface',
                'indexability_state' => 'indexable',
                'lastmod_at' => now()->subHour(),
                'metadata_json' => json_encode(['claim_safe' => true, 'publication_state' => 'published', 'source_table' => 'personality_public_content_assets'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $paths;
    }

    private function seedAsset(string $locale, string $entityType, string $key, string $path): void
    {
        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => $entityType,
            'entity_key' => $key,
            'slug' => ltrim($path, '/'),
            'locale' => $locale,
            'title' => $key,
            'summary' => 'Public reflective content.',
            'content_sections_json' => [],
            'seo_json' => [],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => $path],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => [],
            'schema_json' => [],
            'method_boundary_json' => ['summary' => 'Not diagnosis.'],
            'evidence_notes_json' => [['source_id' => 'test-source']],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'published_no_llms',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-enneagram-package',
            'source_hash' => hash('sha256', $locale.'|'.$entityType.'|'.$key),
            'published_at' => now(),
        ]);
    }

    private function createSeoIntelTables(): void
    {
        Schema::connection('seo_intel')->create('seo_urls', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_items', function ($table): void {
            $table->id();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('channel', 64);
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->char('url_hash', 64);
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->timestamps();
        });
        foreach (['seo_search_channel_queue_batches', 'seo_search_channel_queue_events', 'seo_indexnow_submissions'] as $name) {
            Schema::connection('seo_intel')->create($name, function ($table): void {
                $table->id();
                $table->timestamps();
            });
        }
    }

    /** @return array<string, int> */
    private function queueCounts(): array
    {
        return [
            'items' => DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count(),
            'batches' => DB::connection('seo_intel')->table('seo_search_channel_queue_batches')->count(),
            'events' => DB::connection('seo_intel')->table('seo_search_channel_queue_events')->count(),
            'submissions' => DB::connection('seo_intel')->table('seo_indexnow_submissions')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

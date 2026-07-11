<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SeoIntel\SearchChannelQueue\SearchChannelQueuePlanner;
use App\Services\SeoIntel\UrlTruthHandoffArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnneagramUrlTruthHandoffTest extends TestCase
{
    use RefreshDatabase;

    private string $artifactPath;

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
            'seo_intel.enabled' => false,
            'seo_intel.write_enabled' => false,
            'seo_intel.search_channel_queue.write_enabled' => false,
        ]);
        DB::purge('seo_intel');
        $this->createSeoIntelTables();
        $this->artifactPath = sys_get_temp_dir().'/enneagram-url-truth-handoff-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->artifactPath)) {
            unlink($this->artifactPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_exports_exactly_116_assets_and_idempotently_imports_only_url_truth_records(): void
    {
        $this->seedTargetSet();

        [$exportExit, $export] = $this->runExport();
        $this->assertSame(0, $exportExit, Artisan::output());
        $this->assertSame('success', $export['status'] ?? null);
        $this->assertSame(116, $export['planned_url_count'] ?? null);
        $this->assertFalse((bool) ($export['writes_committed'] ?? true));
        $this->assertSame(0, $this->seoUrlCount());

        $sha256 = (string) ($export['artifact_sha256'] ?? '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sha256);
        $artifact = json_decode((string) file_get_contents($this->artifactPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(116, $artifact['candidate_count'] ?? null);
        $this->assertCount(116, $artifact['candidates'] ?? []);

        [$dryRunExit, $dryRun] = $this->runImport();
        $this->assertSame(0, $dryRunExit, Artisan::output());
        $this->assertSame('success', $dryRun['status'] ?? null);
        $this->assertSame('import_dry_run', $dryRun['mode'] ?? null);
        $this->assertFalse((bool) ($dryRun['writes_committed'] ?? true));
        $this->assertSame(0, $this->seoUrlCount());

        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        [$writeExit, $write] = $this->runImport(write: true, sha256: $sha256);
        $this->assertSame(0, $writeExit, Artisan::output());
        $this->assertSame('success', $write['status'] ?? null);
        $this->assertSame(116, $write['written_records'] ?? null);
        $this->assertSame(116, $this->seoUrlCount());
        $this->assertSame(116, $this->seoEntityCount());
        $this->assertSame(0, $this->queueItemCount());

        [$repeatExit, $repeat] = $this->runImport(write: true, sha256: $sha256);
        $this->assertSame(0, $repeatExit, Artisan::output());
        $this->assertSame(116, $repeat['written_records'] ?? null);
        $this->assertSame(116, $this->seoUrlCount());
        $this->assertSame(116, $this->seoEntityCount());
        $this->assertSame(0, $this->queueItemCount());

        $plan = app(SearchChannelQueuePlanner::class)->plan(
            channel: 'indexnow',
            pageType: UrlTruthHandoffArtifact::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE,
            limit: 116,
        );
        $this->assertSame(116, $plan['candidate_count'] ?? null);
        $this->assertSame(116, $plan['eligible_count'] ?? null);
        $this->assertSame(0, $plan['blocked_count'] ?? null);
        $this->assertSame(116, $plan['planned_queue_count'] ?? null);
        $this->assertFalse((bool) ($plan['duplicate_detected'] ?? true));
    }

    #[Test]
    public function it_requires_the_exact_116_limit_for_enneagram_url_truth(): void
    {
        $this->seedTargetSet();

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $this->artifactPath,
            '--dry-run' => true,
            '--limit' => 115,
            '--page-type' => UrlTruthHandoffArtifact::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('enneagram_exact_116_limit_required', $payload['issues'] ?? []);
        $this->assertFalse(is_file($this->artifactPath));
        $this->assertSame(0, $this->seoUrlCount());
    }

    #[Test]
    public function it_fails_closed_for_noindex_assets_and_duplicate_canonicals(): void
    {
        $this->seedTargetSet();
        PersonalityPublicContentAsset::query()
            ->where('locale', 'en')
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_CENTER)
            ->firstOrFail()
            ->update(['index_eligible' => false]);

        [$noindexExit, $noindex] = $this->runExport();
        $this->assertSame(1, $noindexExit);
        $this->assertContains('enneagram_exact_candidate_count_mismatch', $noindex['issues'] ?? []);
        $this->assertSame(0, $this->seoUrlCount());

        if (is_file($this->artifactPath)) {
            unlink($this->artifactPath);
        }
        PersonalityPublicContentAsset::query()
            ->where('locale', 'en')
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_CENTER)
            ->firstOrFail()
            ->update(['index_eligible' => true, 'canonical_json' => ['path' => '/en/personality/enneagram']]);

        [$duplicateExit, $duplicate] = $this->runExport();
        $this->assertSame(1, $duplicateExit);
        $this->assertContains('enneagram_exact_candidate_count_mismatch', $duplicate['issues'] ?? []);
        $this->assertSame(0, $this->seoUrlCount());
    }

    #[Test]
    public function it_fails_closed_for_private_routes_and_invalid_source_hashes(): void
    {
        $this->seedTargetSet();
        PersonalityPublicContentAsset::query()
            ->where('locale', 'en')
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_CENTER)
            ->firstOrFail()
            ->update(['canonical_json' => ['path' => '/en/results/private-result']]);
        PersonalityPublicContentAsset::query()
            ->where('locale', 'zh-CN')
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_CENTER)
            ->firstOrFail()
            ->update(['source_hash' => 'invalid']);

        [$exitCode, $payload] = $this->runExport();
        $this->assertSame(1, $exitCode);
        $issues = implode('|', $payload['issues'] ?? []);
        $this->assertStringContainsString('candidate_route_not_enneagram_personality_public_content_asset', $issues);
        $this->assertStringContainsString('enneagram_candidate_source_hash_invalid', $issues);
        $this->assertSame(0, $this->seoUrlCount());
        $this->assertSame(0, $this->queueItemCount());
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function runExport(): array
    {
        $exitCode = Artisan::call('seo-intel:url-truth-handoff', [
            '--export' => $this->artifactPath,
            '--dry-run' => true,
            '--limit' => 116,
            '--page-type' => UrlTruthHandoffArtifact::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE,
            '--json' => true,
        ]);

        return [$exitCode, $this->payload()];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function runImport(bool $write = false, ?string $sha256 = null): array
    {
        $arguments = [
            '--import' => $this->artifactPath,
            '--limit' => 116,
            '--page-type' => UrlTruthHandoffArtifact::ENNEAGRAM_PERSONALITY_PUBLIC_CONTENT_ASSET_PAGE_ENTITY_TYPE,
            '--json' => true,
        ];

        if ($write) {
            $arguments['--write'] = true;
            $arguments['--confirm-artifact-sha256'] = $sha256;
        } else {
            $arguments['--dry-run'] = true;
        }

        $exitCode = Artisan::call('seo-intel:url-truth-handoff', $arguments);

        return [$exitCode, $this->payload()];
    }

    private function seedTargetSet(): void
    {
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
    }

    private function seedAsset(string $locale, string $entityType, string $entityKey, string $path): void
    {
        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => ltrim($path, '/'),
            'locale' => $locale,
            'title' => $entityKey,
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
            'source_hash' => hash('sha256', $locale.'|'.$entityType.'|'.$entityKey),
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
            $table->string('entity_id_or_slug')->nullable();
            $table->string('cluster')->nullable();
            $table->string('source_authority', 64);
            $table->string('indexability_state', 64);
            $table->timestamp('lastmod_at')->nullable();
            $table->string('lastmod_source')->nullable();
            $table->boolean('is_private_flow')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale']);
        });
        Schema::connection('seo_intel')->create('seo_url_entities', function ($table): void {
            $table->id();
            $table->char('canonical_url_hash', 64);
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_id_or_slug');
            $table->string('entity_source', 128);
            $table->string('authority_status', 64);
            $table->timestamp('source_updated_at')->nullable();
            $table->json('attributes_json')->nullable();
            $table->timestamps();
            $table->unique(['canonical_url_hash', 'locale', 'page_entity_type', 'entity_id_or_slug']);
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
    }

    private function seoUrlCount(): int
    {
        return DB::connection('seo_intel')->table('seo_urls')->count();
    }

    private function seoEntityCount(): int
    {
        return DB::connection('seo_intel')->table('seo_url_entities')->count();
    }

    private function queueItemCount(): int
    {
        return DB::connection('seo_intel')->table('seo_search_channel_queue_items')->count();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}

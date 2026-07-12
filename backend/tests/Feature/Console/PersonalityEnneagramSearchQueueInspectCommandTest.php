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

    private const ARTIFACT_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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

    #[Test]
    public function it_dry_runs_the_artifact_bound_116_target_enqueue_without_writes(): void
    {
        $this->seedTargetSet();
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-enqueue', [
            '--dry-run' => true,
            '--artifact-sha256' => self::ARTIFACT_SHA,
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('dry_run_ready', $payload['status'] ?? null);
        $this->assertSame(116, data_get($payload, 'summary.candidate_count'));
        $this->assertSame(116, data_get($payload, 'summary.planned_queue_count'));
        $this->assertSame($before, $this->queueCounts());
        $this->assertArrayNotHasKey('targets', $payload);
    }

    #[Test]
    public function it_requires_exact_artifact_confirmation_and_operator_approval_before_write(): void
    {
        $this->seedTargetSet();
        $before = $this->queueCounts();

        $exitCode = Artisan::call('personality:enneagram-search-queue-enqueue', [
            '--write' => true,
            '--artifact-sha256' => self::ARTIFACT_SHA,
            '--confirm-artifact-sha256' => str_repeat('b', 64),
            '--operator-approved' => 'wrong',
            '--json' => true,
        ]);
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('artifact_sha256_confirmation_mismatch', $payload['issues'] ?? []);
        $this->assertContains('operator_approval_mismatch', $payload['issues'] ?? []);
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function it_fails_closed_before_write_when_the_url_truth_set_is_not_exactly_116(): void
    {
        $this->seedTargetSet();
        DB::connection('seo_intel')->table('seo_urls')->limit(1)->delete();
        $before = $this->queueCounts();

        $exitCode = $this->callEnqueueWrite();
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('candidate_count_mismatch', $payload['issues'] ?? []);
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function it_writes_exactly_116_pending_queue_rows_without_submission_and_replays_idempotently(): void
    {
        $this->seedTargetSet();

        $firstExitCode = $this->callEnqueueWrite();
        $first = $this->payload();
        $firstCounts = $this->queueCounts();

        $this->assertSame(0, $firstExitCode, Artisan::output());
        $this->assertSame('enqueued', $first['status'] ?? null);
        $this->assertSame(116, data_get($first, 'summary.written_item_count'));
        $this->assertSame(['items' => 116, 'batches' => 1, 'events' => 117, 'submissions' => 0], $firstCounts);
        $this->assertSame(116, DB::connection('seo_intel')->table('seo_search_channel_queue_items')->where('approval_state', 'pending')->where('execution_state', 'dry_run_ready')->count());

        $secondExitCode = $this->callEnqueueWrite();
        $second = $this->payload();

        $this->assertSame(0, $secondExitCode, Artisan::output());
        $this->assertSame('already_enqueued', $second['status'] ?? null);
        $this->assertSame(0, data_get($second, 'summary.written_item_count'));
        $this->assertSame($firstCounts, $this->queueCounts());

        $dryRunExitCode = Artisan::call('personality:enneagram-search-queue-enqueue', [
            '--dry-run' => true,
            '--artifact-sha256' => self::ARTIFACT_SHA,
            '--json' => true,
        ]);
        $dryRun = $this->payload();
        $this->assertSame(0, $dryRunExitCode, Artisan::output());
        $this->assertSame('already_enqueued', $dryRun['status'] ?? null);
        $this->assertSame(116, data_get($dryRun, 'summary.active_queue_item_count'));
        $this->assertSame($firstCounts, $this->queueCounts());
    }

    #[Test]
    public function it_rejects_a_partial_active_duplicate_set_instead_of_writing_the_remainder(): void
    {
        $paths = $this->seedTargetSet();
        $first = $paths[0];
        $this->seedActiveQueueItem($first['locale'], $first['path']);
        $before = $this->queueCounts();

        $exitCode = $this->callEnqueueWrite();
        $payload = $this->payload();

        $this->assertSame(1, $exitCode);
        $this->assertContains('active_duplicate_present', $payload['issues'] ?? []);
        $this->assertSame($before, $this->queueCounts());
    }

    #[Test]
    public function production_workflow_requires_deployed_sha_and_dry_run_before_bounded_enqueue(): void
    {
        $workflow = (string) file_get_contents(base_path('../.github/workflows/enneagram-search-queue-production-enqueue.yml'));

        $this->assertStringContainsString('test "$deployed_sha" = "$RELEASE_SHA"', $workflow);
        $this->assertStringContainsString('Approve ENNEAGRAM Search Queue enqueue for SHA ${RELEASE_SHA} artifact ${ARTIFACT_SHA256}', $workflow);
        $dryRunPosition = strpos($workflow, '--dry-run --artifact-sha256="$ARTIFACT_SHA256"');
        $writePosition = strpos($workflow, '--write --artifact-sha256="$ARTIFACT_SHA256"');
        $this->assertIsInt($dryRunPosition);
        $this->assertIsInt($writePosition);
        $this->assertLessThan($writePosition, $dryRunPosition);
        $this->assertStringContainsString('.summary.active_duplicate_count == 116', $workflow);
        $this->assertStringNotContainsString('indexnow:submit', $workflow);
        $this->assertStringNotContainsString('seo:warm-sitemap-source-cache', $workflow);
        $this->assertStringNotContainsString('deploy-production', $workflow);
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
                'page_entity_type' => 'personality_public_content_asset',
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
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->text('canonical_url');
            $table->string('locale', 16);
            $table->string('page_entity_type', 64);
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 255)->nullable();
            $table->string('source_authority', 64)->nullable();
            $table->string('source_table', 128)->nullable();
            $table->string('channel', 64);
            $table->string('eligibility_state', 64)->nullable();
            $table->string('approval_state', 64);
            $table->string('execution_state', 64);
            $table->string('indexability_state', 64)->nullable();
            $table->string('claim_boundary_state', 64)->nullable();
            $table->boolean('private_flow')->default(false);
            $table->json('reason_codes')->nullable();
            $table->char('url_hash', 64);
            $table->char('content_hash', 64)->nullable();
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('lastmod')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_batches', function ($table): void {
            $table->id();
            $table->string('channel', 64);
            $table->string('status', 64);
            $table->unsignedInteger('item_count');
            $table->json('dry_run_report')->nullable();
            $table->text('approval_note')->nullable();
            $table->string('created_by', 128)->nullable();
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('seo_intel')->create('seo_search_channel_queue_events', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('queue_item_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('event_type', 96);
            $table->json('event_payload')->nullable();
            $table->string('actor_type', 64);
            $table->string('actor_id', 128)->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('seo_intel')->create('seo_indexnow_submissions', function ($table): void {
            $table->id();
            $table->timestamps();
        });
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

    private function callEnqueueWrite(): int
    {
        return Artisan::call('personality:enneagram-search-queue-enqueue', [
            '--write' => true,
            '--artifact-sha256' => self::ARTIFACT_SHA,
            '--confirm-artifact-sha256' => self::ARTIFACT_SHA,
            '--operator-approved' => 'ENNEAGRAM-SEARCH-QUEUE-ENQUEUE-01:'.self::ARTIFACT_SHA,
            '--json' => true,
        ]);
    }

    private function seedActiveQueueItem(string $locale, string $path): void
    {
        $canonicalUrl = 'https://fermatmind.com'.$path;
        DB::connection('seo_intel')->table('seo_search_channel_queue_items')->insert([
            'canonical_url' => $canonicalUrl,
            'locale' => $locale,
            'page_entity_type' => 'personality_public_content_asset',
            'channel' => 'indexnow',
            'approval_state' => 'pending',
            'execution_state' => 'dry_run_ready',
            'url_hash' => hash('sha256', $canonicalUrl),
            'content_hash' => hash('sha256', $path),
            'lastmod' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

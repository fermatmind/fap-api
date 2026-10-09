<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\Adapters\IqPublicScalePromotionAdapter;
use App\Services\ContentPromotion\IqPublicEntryPackage;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistryWriter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class IqPublicScalePromotionAdapterTest extends TestCase
{
    use RefreshDatabase;

    private array $receiptFiles = [];

    private array $receiptDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->receiptFiles as $file) {
            unlink($file);
        }
        foreach ($this->receiptDirectories as $directory) {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_publication_updates_both_sources_and_preserves_every_other_field(): void
    {
        $this->seedEntry();
        $before = $this->databaseState();
        $generation = app(PublicScaleCatalogCache::class)->generation(0);
        $reader = app(\App\Services\Scale\ScaleRegistry::class);
        $reader->getByCode('IQ_INTELLIGENCE_QUOTIENT', 0);
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $preflight = $adapter->preflight($context);
        self::assertSame($before, $this->databaseState());
        $this->previous($context, 'content_promotion_preflight_receipt', $preflight);
        $draft = $adapter->draftImport($context);
        self::assertSame(0, $draft['written_count']);
        self::assertSame(0, $draft['published_count']);
        self::assertSame($before, $this->databaseState());
        self::assertSame($draft['rollback_reference'], $adapter->draftImport($context)['rollback_reference']);
        $this->previous($context, 'cms_draft_import_receipt', $draft);
        $draftReceipt = config('content_promotion.execution.previous_receipt');
        $published = $adapter->publish($context);
        self::assertSame(2, $published['written_count']);
        self::assertSame(2, $published['published_count']);
        $this->previous($context, 'cms_publication_receipt', $published);
        self::assertSame(2, $adapter->liveQa($context)['published_count']);
        config(['content_promotion.execution.previous_receipt' => $draftReceipt]);
        $alias = $reader->getByCode('IQ_INTELLIGENCE_QUOTIENT', 0);
        foreach (app(IqPublicEntryPackage::class)->read(base_path(), IqPublicEntryPackage::SHA256) as $row) {
            foreach ($row['patch'] as $leaf => $expected) {
                self::assertSame($expected, $alias['content_i18n_json'][$row['key']][$leaf]);
            }
        }
        self::assertGreaterThan($generation, app(PublicScaleCatalogCache::class)->generation(0));
        self::assertSame($this->protectedState($before), $this->protectedState($this->databaseState()));
        $replayed = $adapter->publish($context);
        self::assertSame(0, $replayed['written_count']);
        self::assertSame($published['rollback_reference'], $replayed['rollback_reference']);
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before, $this->databaseState());
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before, $this->databaseState());
        $this->expectExceptionMessage('iq_public_scale_published_content_drift');
        $adapter->publish($context);
    }

    public function test_publication_and_rollback_refresh_prewarmed_bilingual_http_lookup(): void
    {
        $this->seedEntry();
        $before = [];
        foreach (['zh-CN' => 'zh', 'en' => 'en'] as $locale => $key) {
            $url = '/api/v0.3/scales/lookup?slug='.IqPublicEntryPackage::SLUG.'&locale='.$locale;
            $response = $this->getJson($url)->assertOk()->assertJsonPath('ok', true);
            $before[$locale] = $response->json('content_i18n_json.'.$key);
            self::assertSame($before[$locale], $this->getJson($url)->assertOk()->json('content_i18n_json.'.$key));
        }
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        foreach (app(IqPublicEntryPackage::class)->read(base_path(), IqPublicEntryPackage::SHA256) as $row) {
            $response = $this->getJson('/api/v0.3/scales/lookup?slug='.IqPublicEntryPackage::SLUG.'&locale='.$row['identity']['locale'])->assertOk();
            foreach ($row['patch'] as $leaf => $expected) {
                self::assertSame($expected, $response->json('content_i18n_json.'.$row['key'].'.'.$leaf));
            }
        }
        $adapter->rollback($context, $published['rollback_reference']);
        foreach (['zh-CN' => 'zh', 'en' => 'en'] as $locale => $key) {
            self::assertSame($before[$locale], $this->getJson('/api/v0.3/scales/lookup?slug='.IqPublicEntryPackage::SLUG.'&locale='.$locale)->assertOk()->json('content_i18n_json.'.$key));
        }
    }

    public function test_third_code_alias_refreshes_after_publication_and_rollback(): void
    {
        $this->seedEntry();
        DB::table('scale_code_aliases')->insert([
            'scale_uid' => '55555555-5555-4555-8555-555555555555', 'alias_code' => 'IQ_HISTORICAL_PUBLIC',
            'alias_type' => 'legacy', 'is_primary' => false,
        ]);
        $reader = app(\App\Services\Scale\ScaleRegistry::class);
        $before = $reader->getByCode('IQ_HISTORICAL_PUBLIC', 0);
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        $actual = $reader->getByCode('IQ_HISTORICAL_PUBLIC', 0);
        foreach (app(IqPublicEntryPackage::class)->read(base_path(), IqPublicEntryPackage::SHA256) as $row) {
            foreach ($row['patch'] as $leaf => $expected) {
                self::assertSame($expected, $actual['content_i18n_json'][$row['key']][$leaf]);
            }
        }
        $adapter->rollback($context, $published['rollback_reference']);
        self::assertSame($before['content_i18n_json'], $reader->getByCode('IQ_HISTORICAL_PUBLIC', 0)['content_i18n_json']);
    }

    public function test_late_prepublication_slug_fill_cannot_poison_the_new_http_generation(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $fired = false;
        DB::listen(function ($event) use (&$fired, $adapter, $context): void {
            if (! $fired && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "scales_registry_v2"')) {
                // The query already has its old result; publication interleaves
                // before that result is returned and cached by the reader.
                $fired = true;
                $adapter->publish($context);
            }
        });
        $reader = app(\App\Services\Scale\ScaleRegistry::class);
        $delayed = $reader->lookupBySlug(IqPublicEntryPackage::SLUG, 0, true);
        self::assertTrue($fired);
        self::assertSame('Original v2 entry', $delayed['content_i18n_json']['en']['landing_copy']);
        $expected = app(IqPublicEntryPackage::class)->read(base_path(), IqPublicEntryPackage::SHA256)[1]['patch']['landing_copy'];
        $this->getJson('/api/v0.3/scales/lookup?slug='.IqPublicEntryPackage::SLUG.'&locale=en')->assertOk()->assertJsonPath('content_i18n_json.en.landing_copy', $expected);
    }

    public function test_late_prerollback_slug_fill_cannot_poison_the_restored_http_generation(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        $fired = false;
        DB::listen(function ($event) use (&$fired, $adapter, $context, $published): void {
            if (! $fired && str_starts_with($event->sql, 'select') && str_contains($event->sql, 'from "scales_registry_v2"')) {
                $fired = true;
                $adapter->rollback($context, $published['rollback_reference']);
            }
        });
        $reader = app(\App\Services\Scale\ScaleRegistry::class);
        $delayed = $reader->lookupBySlug(IqPublicEntryPackage::SLUG, 0, true);
        self::assertTrue($fired);
        self::assertNotSame('Original v2 entry', $delayed['content_i18n_json']['en']['landing_copy']);
        $this->getJson('/api/v0.3/scales/lookup?slug='.IqPublicEntryPackage::SLUG.'&locale=en')->assertOk()->assertJsonPath('content_i18n_json.en.landing_copy', 'Original v2 entry');
    }

    public function test_preflight_to_import_drift_never_writes_public_content(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->update(['is_indexable' => false]);
        $before = $this->databaseState();
        try {
            $adapter->draftImport($context);
            self::fail('Drift must fail closed.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_preflight_state_drift', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
    }

    public function test_import_to_publication_drift_keeps_the_operator_change(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        DB::table('scales_registry')->where('code', IqPublicEntryPackage::CODE)->update(['default_locale' => 'zh-CN']);
        $before = $this->databaseState();
        try {
            $adapter->publish($context);
            self::fail('Drift must fail closed.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_draft_state_drift', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
    }

    public function test_second_table_write_failure_rolls_back_the_first_table(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $before = $this->databaseState();
        DB::statement("CREATE TRIGGER iq_test_reject_legacy BEFORE UPDATE ON scales_registry BEGIN SELECT RAISE(ABORT, 'iq_test_legacy_failure'); END");
        try {
            $adapter->publish($context);
            self::fail('Second table failure must fail the transaction.');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('iq_test_legacy_failure', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
        self::assertSame(0, DB::table('content_release_snapshots')->where('pack_id', 'iq-public-scale')->where('reason', 'content_promotion_before_publication')->count());
    }

    public function test_cache_failure_restores_content_and_closes_the_restored_projection(): void
    {
        $this->seedEntry();
        $before = $this->databaseState();
        $realWriter = app(ScaleRegistryWriter::class);
        $writer = Mockery::mock(ScaleRegistryWriter::class);
        $calls = 0;
        $writer->shouldReceive('invalidatePublicContentProjection')->twice()->andReturnUsing(function ($org, $code, $slugs) use (&$calls, $realWriter): void {
            if (++$calls === 1) {
                throw new RuntimeException('iq_test_cache_failure');
            }
            $realWriter->invalidatePublicContentProjection($org, $code, $slugs);
        });
        $this->app->instance(ScaleRegistryWriter::class, $writer);
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $context = $this->context();
        $this->prepare($adapter, $context);
        try {
            $adapter->publish($context);
            self::fail('Cache failure must not return a success result.');
        } catch (RuntimeException $error) {
            self::assertSame('iq_test_cache_failure', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
        self::assertSame(2, $calls);
    }

    public function test_cache_failure_during_replay_keeps_the_already_published_content(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $adapter->publish($context);
        $published = $this->databaseState();
        $writer = Mockery::mock(ScaleRegistryWriter::class);
        $writer->shouldReceive('invalidatePublicContentProjection')->once()->andThrow(new RuntimeException('iq_test_replay_cache_failure'));
        $this->app->instance(ScaleRegistryWriter::class, $writer);
        try {
            app(IqPublicScalePromotionAdapter::class)->publish($context);
            self::fail('Replay cache failure must not report success.');
        } catch (RuntimeException $error) {
            self::assertSame('iq_test_replay_cache_failure', $error->getMessage());
        }
        self::assertSame($published, $this->databaseState());
    }

    public function test_rollback_preserves_new_unrelated_copy_and_a_later_public_hold(): void
    {
        $this->seedEntry();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $context = $this->context();
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        $content = json_decode(DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->value('content_i18n_json'), true);
        $content['fr']['note'] = 'Later operator copy';
        DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->update(['content_i18n_json' => json_encode($content), 'is_public' => false]);
        $adapter->rollback($context, $published['rollback_reference']);
        $restored = DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->first();
        self::assertFalse((bool) $restored->is_public);
        self::assertSame('Later operator copy', json_decode($restored->content_i18n_json, true)['fr']['note']);
        self::assertSame('Original v2 entry', json_decode($restored->content_i18n_json, true)['en']['landing_copy']);
    }

    public function test_rollback_rejects_a_later_edit_to_owned_copy_atomically(): void
    {
        $this->seedEntry();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $context = $this->context();
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        $content = json_decode(DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->value('content_i18n_json'), true);
        $content['en']['landing_copy'] = 'Later operator owned copy';
        DB::table('scales_registry_v2')->where('code', IqPublicEntryPackage::CODE)->update(['content_i18n_json' => json_encode($content)]);
        $before = $this->databaseState();
        try {
            $adapter->rollback($context, $published['rollback_reference']);
            self::fail('Concurrent owned content must be preserved.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_rollback_concurrent_content', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
    }

    public function test_tampered_previous_receipt_never_creates_a_journal(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $file = config('content_promotion.execution.previous_receipt');
        $receipt = json_decode(file_get_contents($file), true);
        $receipt['target_state_sha256'] = str_repeat('f', 64);
        file_put_contents($file, json_encode($receipt));
        $before = $this->databaseState();
        try {
            $adapter->draftImport($context);
            self::fail('Tampered receipt must fail.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_previous_receipt_binding_invalid', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
        self::assertSame(0, ContentReleaseSnapshot::query()->where('pack_id', 'iq-public-scale')->count());
    }

    public function test_receipt_from_another_workflow_is_rejected_even_with_a_valid_digest(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $file = config('content_promotion.execution.previous_receipt');
        $receipt = json_decode(file_get_contents($file), true);
        unset($receipt['receipt_content_sha256']);
        $receipt['workflow_run_id'] = '13';
        $receipt['receipt_content_sha256'] = hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($receipt));
        file_put_contents($file, json_encode($receipt));
        $this->expectExceptionMessage('iq_public_scale_previous_receipt_binding_invalid');
        $adapter->draftImport($context);
    }

    public function test_rollback_rejects_a_snapshot_from_another_executor(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $published = $adapter->publish($context);
        $snapshot = ContentReleaseSnapshot::query()->where('reason', 'content_promotion_before_publication')->where('pack_id', 'iq-public-scale')->firstOrFail();
        $meta = $snapshot->meta_json;
        $meta['executor_release_sha256'] = str_repeat('f', 64);
        $snapshot->update(['meta_json' => $meta]);
        $before = $this->databaseState();
        try {
            $adapter->rollback($context, $published['rollback_reference']);
            self::fail('Snapshot from another executor must fail.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_snapshot_execution_invalid', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
    }

    public function test_signed_registered_command_runs_all_four_real_phases(): void
    {
        $this->seedEntry();
        $key = str_repeat('test-key', 8);
        $source = str_repeat('a', 40);
        $policy = hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson(config('content_promotion.release_policy')));
        config(['content_promotion.workflow_identity_key' => $key]);
        foreach ([
            'source_commit' => $source, 'workflow_run_id' => '12',
            'workflow_run_attempt' => 1, 'expected_row_count' => 2,
            'executor_release_sha256' => str_repeat('b', 64), 'release_policy_sha256' => $policy,
            'workflow_signature' => hash_hmac('sha256', implode('|', [
                'content-promotion-v2', $source, '12', '1', 'W6', IqPublicScalePromotionAdapter::SUBSCOPE,
                IqPublicEntryPackage::SHA256, $policy, '2',
            ]), $key),
        ] as $field => $value) {
            config(['content_promotion.execution.'.$field => $value]);
        }
        $directory = $this->receiptDirectory();
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = $directory.'/'.$phase.'.json';
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $exit = \Illuminate\Support\Facades\Artisan::call('content:promote-exact-package', [
                '--package' => IqPublicEntryPackage::PACKAGE,
                '--expected-package-sha256' => IqPublicEntryPackage::SHA256,
                '--lane' => 'W6', '--subscope' => IqPublicScalePromotionAdapter::SUBSCOPE,
                '--phase' => $phase, '--receipt' => $path, '--json' => true,
            ], $output);
            $response = json_decode(trim($output->fetch()), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit, json_encode($response));
            $receipt = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('w6_iq_public_scale_v2', $receipt['adapter']);
            self::assertSame($source, $receipt['source_commit']);
            self::assertSame(2, $receipt['readback_count']);
            self::assertSame(hash_file('sha256', $path), $response['receipt_sha256']);
            self::assertSame($previous === '' ? null : hash_file('sha256', $previous), $receipt['previous_receipt_sha256']);
            $previous = $path;
        }
    }

    public function test_registered_service_preserves_the_exact_four_phase_receipt_chain(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $directory = $this->receiptDirectory();
        $previous = '';
        $previousHash = null;
        foreach (['preflight', 'draft-import', 'publish', 'live-qa'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $path = $directory.'/'.$phase.'.json';
            $result = app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, $phase, $path);
            self::assertSame('w6_iq_public_scale_v2', $result['receipt']['adapter']);
            self::assertSame(2, $result['receipt']['readback_count']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['receipt']['target_state_sha256']);
            self::assertSame($previousHash, $result['receipt']['previous_receipt_sha256']);
            self::assertSame(in_array($phase, ['publish', 'live-qa'], true) ? 2 : 0, $result['receipt']['published_count']);
            $previousHash = hash_file('sha256', $path);
            self::assertSame($previousHash, $result['receipt_sha256']);
            $previous = $path;
        }
    }

    public function test_publication_receipt_write_failure_restores_the_exact_previous_content(): void
    {
        $this->seedEntry();
        $before = $this->databaseState();
        $context = $this->context();
        $this->prepare(app(IqPublicScalePromotionAdapter::class), $context);
        $path = $this->receiptDirectory().'/occupied.json';
        file_put_contents($path, 'must remain immutable');
        try {
            app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, 'publish', $path);
            self::fail('Immutable receipt destination must reject publication.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
        self::assertSame('must remain immutable', file_get_contents($path));
    }

    public function test_live_qa_receipt_write_failure_restores_the_exact_previous_content(): void
    {
        $this->seedEntry();
        $before = $this->databaseState();
        $context = $this->context();
        $directory = $this->receiptDirectory();
        $previous = '';
        foreach (['preflight', 'draft-import', 'publish'] as $phase) {
            config(['content_promotion.execution.previous_receipt' => $previous]);
            $previous = $directory.'/'.$phase.'.json';
            app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, $phase, $previous);
        }
        config(['content_promotion.execution.previous_receipt' => $previous]);
        $path = $directory.'/occupied.json';
        file_put_contents($path, 'immutable live QA receipt');
        try {
            app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, 'live-qa', $path);
            self::fail('Immutable live QA receipt destination must reject acceptance.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_receipt_failed_rollback_succeeded', $error->getMessage());
        }
        self::assertSame($before, $this->databaseState());
        self::assertSame('immutable live QA receipt', file_get_contents($path));
    }

    public function test_replay_receipt_failure_does_not_restore_an_old_successful_publication(): void
    {
        $this->seedEntry();
        $context = $this->context();
        $adapter = app(IqPublicScalePromotionAdapter::class);
        $this->prepare($adapter, $context);
        $adapter->publish($context);
        $published = $this->databaseState();
        $path = $this->receiptDirectory().'/occupied.json';
        file_put_contents($path, 'immutable');
        try {
            app(\App\Services\ContentPromotion\ExactPackagePromotionService::class)->execute($context, 'publish', $path);
            self::fail('Receipt failure must not return success.');
        } catch (DomainException $error) {
            self::assertSame('iq_public_scale_receipt_failed_without_new_write', $error->getMessage());
        }
        self::assertSame($published, $this->databaseState());
    }

    private function receiptDirectory(): string
    {
        $directory = sys_get_temp_dir().'/iq-scale-service-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->receiptDirectories[] = $directory;

        return $directory;
    }

    private function seedEntry(): void
    {
        config(['content_packs.public_scale_cache_store' => 'array']);
        app(ScaleRegistryWriter::class)->upsertScale([
            'org_id' => 0, 'code' => IqPublicEntryPackage::CODE, 'primary_slug' => IqPublicEntryPackage::SLUG,
            'slugs_json' => [IqPublicEntryPackage::SLUG, 'historical-iq-entry'], 'driver_type' => 'iq_raven',
            'default_locale' => 'en', 'is_public' => true, 'is_active' => true, 'is_indexable' => true,
            'seo_i18n_json' => ['en' => ['title' => 'Original SEO']],
            'content_i18n_json' => ['en' => ['landing_copy' => 'Original v2 entry', 'highlight' => ['title' => 'Keep this highlight']], 'zh' => ['landing_copy' => '原中文入口'], 'fr' => ['note' => 'Original French control']],
        ]);
        $legacy = json_decode(DB::table('scales_registry')->where('code', IqPublicEntryPackage::CODE)->value('content_i18n_json'), true);
        $legacy['en']['landing_copy'] = 'Original legacy entry';
        $legacy['en']['legacy_only_note'] = 'Keep legacy-specific bytes';
        DB::table('scales_registry')->where('code', IqPublicEntryPackage::CODE)->update(['content_i18n_json' => json_encode($legacy)]);
    }

    private function databaseState(): array
    {
        return array_map(static fn (string $table): array => DB::table($table)->orderBy($table === 'scales_registry' ? 'code' : 'id')->get()->map(static fn (object $row): array => (array) $row)->all(), ['scales_registry_v2', 'scales_registry', 'scale_slugs']);
    }

    private function protectedState(array $state): array
    {
        foreach ([0, 1] as $index) {
            foreach ($state[$index] as &$row) {
                if ($row['code'] !== IqPublicEntryPackage::CODE) {
                    continue;
                }
                $content = json_decode($row['content_i18n_json'], true);
                foreach (['zh', 'en'] as $key) {
                    foreach (['landing_copy', 'why_choose', 'faq'] as $leaf) {
                        unset($content[$key][$leaf]);
                    }
                }
                $row['content_i18n_json'] = $content;
            }
            unset($row);
        }

        return $state;
    }

    private function prepare(IqPublicScalePromotionAdapter $adapter, PromotionContext $context): void
    {
        $this->previous($context, 'content_promotion_preflight_receipt', $adapter->preflight($context));
        $this->previous($context, 'cms_draft_import_receipt', $adapter->draftImport($context));
    }

    private function previous(PromotionContext $context, string $kind, array $result): void
    {
        $file = tempnam(sys_get_temp_dir(), 'iq-scale-test-receipt-');
        $this->receiptFiles[] = $file;
        $receipt = [
            'phase' => match ($kind) {
                'content_promotion_preflight_receipt' => 'preflight',
                'cms_draft_import_receipt' => 'draft-import',
                'cms_publication_receipt' => 'publish',
            },
            'adapter' => 'w6_iq_public_scale_v2', 'source_repository' => 'fermatmind/fap-api',
            'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
            'executor_release_sha256' => $context->executorReleaseSha256, 'idempotency_key' => $context->idempotencyKey,
            'receipt_kind' => $kind, 'result' => 'SUCCEEDED', 'lane' => $context->lane, 'subscope' => $context->subscope,
            'package_sha256' => $context->packageSha256, 'source_commit' => $context->sourceCommit,
            'release_policy_sha256' => $context->releasePolicySha256, 'expected_count' => 2,
            'target_state_sha256' => $result['target_state_sha256'], 'rollback_reference' => $result['rollback_reference'],
        ];
        $receipt['receipt_content_sha256'] = hash('sha256', \App\Services\ContentPromotion\PromotionContextFactory::canonicalJson($receipt));
        file_put_contents($file, json_encode($receipt, JSON_THROW_ON_ERROR));
        config(['content_promotion.execution.previous_receipt' => $file]);
    }

    private function context(): PromotionContext
    {
        return new PromotionContext(base_path(IqPublicEntryPackage::PACKAGE), IqPublicEntryPackage::SHA256, 'W6', IqPublicScalePromotionAdapter::SUBSCOPE, str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '12', 1, str_repeat('d', 64), 2, str_repeat('e', 64));
    }
}

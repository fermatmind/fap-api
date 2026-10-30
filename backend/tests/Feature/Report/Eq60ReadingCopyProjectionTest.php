<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Eq60PackLoader;
use App\Services\Content\Publisher\ContentPackV2Publisher;
use App\Services\Eq\Eq60ReadingCopyProjection;
use App\Services\Eq\EqAgentContextBuilder;
use App\Services\Eq\EqAgentRuntimeResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class Eq60ReadingCopyProjectionTest extends TestCase
{
    use RefreshDatabase;

    private array $createdReleases = [];

    private function cases(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/eq60_precloseout_reading_reports_20261030.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function nonBalancedCases(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/eq60_843_nonbalanced_reading_reports_20261030.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function prepareReleases(): void
    {
        foreach ([...$this->cases(), ...$this->nonBalancedCases()] as $case) {
            if (! DB::table('content_pack_releases')->where('id', $case['testing_release']['id'])->exists()) {
                DB::table('content_pack_releases')->insert([...$case['testing_release'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $publisher = app(ContentPackV2Publisher::class);
        // Explicit local fixture identity; this uncommitted candidate is not the old source commit.
        $release = $publisher->publishCompiled('EQ_60', 'v1', ['source_commit' => str_repeat('0', 40)]);
        $this->createdReleases[] = $release;
        $publisher->activateRelease($release['id']);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdReleases as $release) {
            File::deleteDirectory(storage_path('app/'.$release['storage_path']));
            File::deleteDirectory(storage_path('app/content_packs_v2/EQ_60/v1/'.$release['id']));
        }
        parent::tearDown();
    }

    private function rebind(array $report): array
    {
        $payload = $report;
        unset($payload['_meta'], $payload['generated_at']);
        $report['_meta']['snapshot_binding_v1']['canonical_payload_sha256'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $report;
    }

    public function test_two_real_local_old_release_composer_fixtures_get_current_copy_without_changing_facts(): void
    {
        $this->prepareReleases();
        $service = app(Eq60ReadingCopyProjection::class);
        foreach ($this->cases() as $locale => $case) {
            $report = $case['report'];
            $before = $report;
            $view = $service->project($report);
            $this->assertSame('ready', $view['status'], $locale.':'.($view['reason'] ?? ''));
            $reading = $view['report'];
            foreach (['scores', 'dimension_summary', 'quality', 'methodology', 'access', 'next_module', '_meta', 'generated_at'] as $key) {
                $this->assertSame($report[$key], $reading[$key], $locale.'.'.$key);
            }
            $this->assertSame($before, $report);
            $this->assertSame($view, $service->project($report), 'Repeated reads are deterministic and do not persist the view.');
            $ids = ['SA_ER_mixed', 'EM_RM_mixed'];
            foreach (['interpretation.primary_mechanism_ids', 'interpretation.selected_asset_ids.mechanism_ids', 'asset_refs.mechanism_ids', 'asset_refs.selected_asset_ids.mechanism_ids', 'assets.personalization_route.selected_asset_ids.mechanism_ids'] as $path) {
                $this->assertSame($ids, data_get($reading, $path));
            }
            $compiled = app(Eq60PackLoader::class)->loadReportAssets('v1');
            foreach (['SA_ER', 'EM_RM'] as $i => $pair) {
                $this->assertSame(['id' => $pair.'_mixed', 'pair' => $pair, 'state' => 'mixed', ...$compiled['assets']['mechanism_map']['pairs'][$pair]['mixed'][$locale]], $reading['assets']['mechanisms'][$i]);
            }
            $this->assertNotSame($report['assets']['result_snapshot']['headline'], $reading['assets']['result_snapshot']['headline']);
            $this->assertSame('bootstrap_v1', data_get($reading, '_meta.snapshot_binding_v1.norm_version'));
        }
    }

    public function test_unknown_binding_locale_prestate_and_explicit_quality_alias_fail_closed(): void
    {
        $this->prepareReleases();
        $original = $this->cases()['en']['report'];
        foreach (['legacy', 'locale', 'source', 'payload', 'copy', 'alias', 'mechanism'] as $case) {
            $report = $original;
            match ($case) {
                'legacy' => $report['_meta']['eq60_private_result_authority']['mode'] = 'immutable_legacy_snapshot',
                'locale' => $report['locale'] = 'xx',
                'source' => $report['_meta']['eq60_private_result_authority']['source_hash'] = str_repeat('a', 64),
                'payload' => $report['_meta']['snapshot_binding_v1']['canonical_payload_sha256'] = 'invalid',
                'copy' => $report['assets']['result_snapshot']['headline'] = 'Unknown third copy',
                'alias' => $report['assets']['quality_confidence']['do_not_overread'] = '',
                'mechanism' => $report['assets']['mechanisms'][0]['id'] = 'unknown',
            };
            if (in_array($case, ['copy', 'alias', 'mechanism'], true)) {
                $report = $this->rebind($report);
            }
            $view = app(Eq60ReadingCopyProjection::class)->project($report);
            $this->assertSame('unavailable', $view['status'], $case);
            $this->assertArrayNotHasKey('report', $view);
        }
    }

    public function test_nonbalanced_843_snapshots_and_new_composer_share_the_finite_copy_without_rescoring(): void
    {
        $this->prepareReleases();
        Http::preventStrayRequests();
        foreach ($this->nonBalancedCases() as $key => $case) {
            $original = $case['report'];
            $view = app(Eq60ReadingCopyProjection::class)->project($original);
            $this->assertSame('ready', $view['status'], $key.':'.($view['reason'] ?? ''));
            $this->assertSame(Eq60ReadingCopyProjection::REVISION, $view['revision']);
            foreach (['scores', 'dimension_summary', 'quality', 'methodology', 'access', 'next_module', '_meta', 'generated_at', 'interpretation', 'asset_refs'] as $fact) {
                $this->assertSame($original[$fact], $view['report'][$fact], $key.'.'.$fact);
            }
            $this->assertNotSame($original['assets'], $view['report']['assets']);
            foreach ($original['assets']['mechanisms'] as $i => $mechanism) {
                foreach (['id', 'pair', 'state'] as $identity) {
                    $this->assertSame($mechanism[$identity], $view['report']['assets']['mechanisms'][$i][$identity]);
                }
            }
            $codes = ['scale_code' => 'EQ_60', 'scale_code_legacy' => 'EQ_60', 'scale_code_v2' => 'EQ_60', 'scale_uid' => null];
            $context = app(EqAgentContextBuilder::class)->build(new Attempt(['locale' => $original['locale']]), new Result,
                ['report' => $original], $codes, $original['locale'], null);
            $this->assertTrue($context['ready']);
            $this->assertSame($view['report']['assets']['mechanisms'], $context['resolved_assets']['mechanisms']);
            $this->assertSame($view['report']['assets']['core_formulation'], $context['resolved_assets']['core_formulation']);
            $this->assertSame($case['report'], $original);

            // Exact same bounded score input through the current real composer; no question answers or scorer call.
            $composed = app(\App\Services\Report\Eq60ReportComposer::class)->composeVariant(
                new Attempt(['locale' => $original['locale'], 'scale_code' => 'EQ_60', 'dir_version' => 'v1']),
                new Result(['result_json' => ['normed_json' => $case['score_input']]]), 'full'
            );
            $this->assertTrue($composed['ok'] ?? false);
            $fresh = app(Eq60ReadingCopyProjection::class)->project($composed['report']);
            $this->assertSame('ready', $fresh['status']);
            $this->assertSame($view['report']['assets']['mechanisms'], $fresh['report']['assets']['mechanisms']);
        }
        $original = $this->nonBalancedCases()['self_clear_repair_weak.en']['report'];
        $original['assets']['mechanisms'][1]['title'] = 'Unknown third mechanism copy';
        $view = app(Eq60ReadingCopyProjection::class)->project($this->rebind($original));
        $this->assertSame('unavailable', $view['status']);
        $this->assertSame('reading_copy_prestate_unknown', $view['reason']);
        $this->assertArrayNotHasKey('report', $view);
        Http::assertNothingSent();
    }

    public function test_agent_uses_the_same_reading_assets_and_stops_unsupported_history_before_provider(): void
    {
        $this->prepareReleases();
        $report = $this->cases()['en']['report'];
        $attempt = new Attempt(['id' => 'local-eq-reading-attempt', 'locale' => 'en']);
        $result = new Result(['id' => 'local-eq-reading-result']);
        $codes = ['scale_code' => 'EQ_60', 'scale_code_legacy' => 'EQ_60', 'scale_code_v2' => 'EQ_60', 'scale_uid' => null];
        $builder = app(EqAgentContextBuilder::class);
        $context = $builder->build($attempt, $result, ['report' => $report], $codes, 'en', null);
        $this->assertTrue($context['ready']);
        $view = app(Eq60ReadingCopyProjection::class)->project($report);
        $this->assertSame($view['report']['assets']['result_snapshot'], $context['resolved_assets']['result_snapshot']);
        $this->assertSame($view['report']['assets']['mechanisms'], $context['resolved_assets']['mechanisms']);
        $this->assertSame($report['scores'], $context['report_context']['scores']);
        $this->assertSame($report['_meta']['snapshot_binding_v1'], $context['snapshot_binding_v1']);

        Http::preventStrayRequests();
        Http::fake(['https://api.openai.test/v1/responses' => Http::response(['output' => []], 200)]);
        config(['ai.eq_agent.llm_enabled' => true, 'ai.eq_agent.staging_only' => false,
            'ai.eq_agent.openai.base_url' => 'https://api.openai.test/v1', 'ai.eq_agent.openai.api_key' => 'test-only', 'ai.eq_agent.openai.model' => 'gpt-test']);
        app(EqAgentRuntimeResponder::class)->respond($context, 'Explain this result', null, 'en');
        $after = $view['report']['assets']['result_snapshot']['headline'];
        $before = $report['assets']['result_snapshot']['headline'];
        Http::assertSent(static function ($request) use ($after, $before): bool {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return str_contains($body, $after) && ! str_contains($body, $before);
        });
        Http::assertSentCount(1);
        $report['_meta']['eq60_private_result_authority']['mode'] = 'immutable_legacy_snapshot';
        $blocked = $builder->build($attempt, $result, ['report' => $report], $codes, 'en', null);
        $this->assertFalse($blocked['ready']);
        $this->assertSame('historical_eq_report_not_supported', $blocked['reason_code']);
        $runtime = app(EqAgentRuntimeResponder::class)->respond($blocked, 'Explain this result', null, 'en');
        $this->assertFalse($runtime['ready']);
        Http::assertSentCount(1); // Unsupported history made zero additional provider requests.
    }

    public function test_reading_copy_preserves_historical_reference_metadata_instead_of_resolving_current_norms(): void
    {
        $this->prepareReleases();
        foreach (['provisional', 'calibrated', 'missing'] as $status) {
            $report = $this->cases()['en']['report'];
            $report['methodology']['norm_status'] = $status;
            $report['methodology']['norm_provenance'] = ['norm_status' => $status, 'norm_version' => 'historical_'.$status];
            $report['_meta']['snapshot_binding_v1']['norm_status'] = $status;
            $report['_meta']['snapshot_binding_v1']['norm_version'] = 'historical_'.$status;
            $report = $this->rebind($report);
            $view = app(Eq60ReadingCopyProjection::class)->project($report);
            $this->assertSame('ready', $view['status']);
            $this->assertSame($report['methodology'], $view['report']['methodology']);
            $this->assertSame($report['_meta'], $view['report']['_meta']);
            $this->assertSame($report['scores'], $view['report']['scores']);
        }
    }

    public function test_activation_between_authority_and_asset_reads_cannot_mislabel_the_reading_source(): void
    {
        $this->prepareReleases();
        $current = $this->createdReleases[0];
        $temporary = sys_get_temp_dir().'/eq-old-compiled-'.bin2hex(random_bytes(8));
        $compiledDirectory = $temporary.'/backend/content_packs/EQ_60/v1/compiled';
        $armed = false;
        try {
            File::ensureDirectoryExists($compiledDirectory);
            File::copyDirectory(base_path('content_packs/EQ_60/v1/compiled'), $compiledDirectory);
            // Reconstruct exact historical bytes without shipping duplicate question banks.
            (new \Symfony\Component\Process\Process(['git', 'apply', '--reverse', base_path('tests/Fixtures/eq60_843_to_reading_v2_compiled.patch')], $temporary))->mustRun();
            (new \Symfony\Component\Process\Process(['git', 'apply', '--reverse', base_path('tests/Fixtures/eq60_737_to_current_compiled.patch')], $temporary))->mustRun();
            $manifest = json_decode((string) file_get_contents($compiledDirectory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('f1645cbd8b9c5b5686d2611a22e12a90609a2d64647eb5a5d348d12c2d056de7', $manifest['source_hash']);
            foreach ($manifest['hashes'] as $name => $expected) {
                $this->assertSame($expected, hash_file('sha256', $compiledDirectory.'/'.$name));
            }
            $publisher = app(ContentPackV2Publisher::class);
            $old = $publisher->publishCompiled('EQ_60', 'v1', ['source_compiled_dir' => $compiledDirectory,
                'source_commit' => '737fdaabd71d46d8b4cc6a870b361806967585ce']);
            $this->createdReleases[] = $old;
            $publisher->activateRelease($old['id']);
            $switched = false;
            DB::listen(static function ($query) use (&$armed, &$switched, $publisher, $current): void {
                if ($armed && ! $switched && str_contains($query->sql, 'content_pack_activations')
                    && preg_match('/^\s*select\s+["`]?release_id["`]?\s+from/i', $query->sql)) {
                    // The old authority row has been read; assets will see the newly activated pack.
                    $switched = true;
                    $publisher->activateRelease($current['id']);
                }
            });
            $armed = true;
            $view = app(Eq60ReadingCopyProjection::class)->project($this->cases()['en']['report']);
            $armed = false;
            $this->assertTrue($switched, 'The fixture must actually cross the two immutable release reads.');
            $this->assertSame('unavailable', $view['status']);
            $this->assertSame('reading_source_conflicting', $view['reason']);
            $this->assertArrayNotHasKey('report', $view);
        } finally {
            $armed = false;
            File::deleteDirectory($temporary);
        }
    }
}

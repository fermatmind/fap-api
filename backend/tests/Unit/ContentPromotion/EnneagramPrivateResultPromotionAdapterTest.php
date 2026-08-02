<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Services\ContentPromotion\PromotionAdapterRegistry;
use App\Services\ContentPromotion\PromotionContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tests\Unit\ContentPromotion\Concerns\AssertsExactPackagePromotionConformance;

require_once __DIR__.'/Concerns/AssertsExactPackagePromotionConformance.php';

final class EnneagramPrivateResultPromotionAdapterTest extends TestCase
{
    use AssertsExactPackagePromotionConformance;
    use RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    private string $w9Directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w9Directory = sys_get_temp_dir().'/enneagram-private-result-w9-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->w9Directory);
        config()->set('content_promotion.w9_authority_root', $this->w9Directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        File::deleteDirectory($this->w9Directory);
        parent::tearDown();
    }

    public function test_private_result_adapter_stages_exact_inactive_release_then_activates_and_rolls_back(): void
    {
        $directory = $this->package();
        $sha = $this->makePromotable($directory);
        $context = $this->context($directory, $sha);
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W5', 'enneagram-results');

        $this->assertExactPhaseResult($adapter->preflight($context), $context, 'preflight');
        $draft = $adapter->draftImport($context);
        $this->assertExactPhaseResult($draft, $context, 'draft-import');
        self::assertSame(630, $draft['written_count']);
        self::assertSame(0, $adapter->draftImport($context)['written_count']);
        self::assertSame(0, DB::table('content_pack_activations')->count());

        $previousReleaseId = $this->previousActiveRelease();
        $publish = $adapter->publish($context);
        $this->assertExactPhaseResult($publish, $context, 'publish');
        self::assertSame(630, $publish['published_count']);
        self::assertNotSame($previousReleaseId, DB::table('content_pack_activations')->value('release_id'));
        self::assertSame(0, $adapter->publish($context)['written_count']);
        $this->assertExactPhaseResult($adapter->liveQa($context), $context, 'live-qa');

        $adapter->rollback($context, (string) $publish['rollback_reference']);
        self::assertSame($previousReleaseId, DB::table('content_pack_activations')->value('release_id'));
    }

    public function test_missing_w9_cjk_private_fields_and_incomplete_close_call_coverage_fail_closed(): void
    {
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W5', 'enneagram-results');

        $withoutW9 = $this->package();
        $this->expectExceptionObject(new DomainException('enneagram_private_result_w9_evidence_incomplete'));
        $adapter->preflight($this->context($withoutW9, $this->packageSha($withoutW9)));
    }

    public function test_cjk_private_fields_and_incomplete_close_call_coverage_fail_closed(): void
    {
        $adapter = app(PromotionAdapterRegistry::class)->resolve('W5', 'enneagram-results');

        $cjk = $this->package();
        File::put($cjk.'/candidate/candidate_payloads/baseline_000.json', json_encode(['body' => '中文'], JSON_UNESCAPED_UNICODE));
        $cjkSha = $this->makePromotable($cjk);
        try {
            $adapter->preflight($this->context($cjk, $cjkSha));
            self::fail('CJK payloads must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('enneagram_private_result_payload_cjk_leakage', $exception->getMessage());
        }

        $coverage = $this->package();
        $mapping = $this->decodeJson($coverage.'/candidate/candidate_payload_source_mapping.json');
        array_pop($mapping['close_call_pairs']);
        File::put($coverage.'/candidate/candidate_payload_source_mapping.json', json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $coverageSha = $this->makePromotable($coverage);
        try {
            $adapter->preflight($this->context($coverage, $coverageSha));
            self::fail('All 36 close-call pairs are mandatory.');
        } catch (DomainException $exception) {
            self::assertSame('enneagram_private_result_close_call_coverage_invalid', $exception->getMessage());
        }

        $private = $this->package();
        File::put($private.'/candidate/candidate_payloads/baseline_000.json', json_encode(['raw_score' => 42], JSON_UNESCAPED_UNICODE));
        $privateSha = $this->makePromotable($private);
        $this->expectExceptionObject(new DomainException('enneagram_private_result_payload_private_field'));
        $adapter->preflight($this->context($private, $privateSha));
    }

    private function previousActiveRelease(): string
    {
        $id = 'enneagram_previous_active_release';
        $storagePath = 'private/content_releases/ENNEAGRAM/v2/'.$id;
        $root = storage_path('app/'.$storagePath.'/registry');
        File::ensureDirectoryExists(dirname($root));
        File::copyDirectory(base_path('content_packs/ENNEAGRAM/v2/registry'), $root);
        DB::table('content_pack_releases')->insert([
            'id' => $id, 'action' => 'existing', 'region' => 'GLOBAL', 'locale' => 'global', 'dir_alias' => 'v2',
            'to_pack_id' => 'ENNEAGRAM', 'status' => 'success', 'pack_version' => 'v2', 'storage_path' => $storagePath,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content_pack_activations')->insert([
            'pack_id' => 'ENNEAGRAM', 'pack_version' => 'v2', 'release_id' => $id,
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function package(): string
    {
        $root = sys_get_temp_dir().'/enneagram-private-result-package-'.bin2hex(random_bytes(8));
        $candidate = $root.'/candidate';
        File::ensureDirectoryExists($candidate.'/candidate_payloads');
        $this->directories[] = $root;
        $runtimeSha = hash_file('sha256', base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json')) ?: '';
        $coverage = [
            'batch_1r_a_replaces' => ['page1_summary', 'type_summary', 'deep_dive_intro'],
            'batch_1r_b_replaces' => ['core_motivation', 'core_fear', 'core_desire', 'self_image', 'attention_pattern', 'strength', 'blindspot', 'stress_pattern', 'relationship_pattern', 'work_pattern', 'growth_direction', 'daily_observation', 'boundary'],
            'batch_1r_c_adds' => ['low_resonance_response'], 'batch_1r_d_adds' => ['partial_resonance_response'],
            'batch_1r_e_adds' => ['diffuse_convergence_response'], 'batch_1r_f_adds' => ['close_call_pair'],
            'batch_1r_g_adds' => ['scene_localization_response'], 'batch_1r_h_adds' => ['fc144_recommendation_response'],
        ];
        $manifest = [
            'out_of_launch_scope' => ['1R-I', '1R-J'], 'candidate_item_count' => 1332,
            'candidate_items_by_batch' => ['1R-A' => 315, '1R-B' => 423, '1R-C' => 108, '1R-D' => 90, '1R-E' => 108, '1R-F' => 36, '1R-G' => 162, '1R-H' => 90],
            'replacement_coverage' => $coverage, 'production_import_happened' => false, 'full_replacement_happened' => false,
        ];
        File::put($candidate.'/candidate_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $manifestSha = hash_file('sha256', $candidate.'/candidate_manifest.json') ?: '';
        File::put($candidate.'/candidate_hashes.json', json_encode(['candidate_manifest_sha256' => $manifestSha, 'runtime_registry_manifest_sha256' => $runtimeSha], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/rollback_plan.md', "# rollback\n");
        File::put($candidate.'/import_diff_summary.json', json_encode(['no_full_replacement' => true, 'no_production_registry_write' => true], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/replacement_additive_map.json', json_encode($coverage, JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/source_mapping_report.json', json_encode(['source_mapping_failure_count' => 0, 'missing_count' => 0, 'fallback_count' => 0, 'blocked_count' => 0, 'duplicate_selection_count' => 0, 'metadata_leak_count' => 0], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/forbidden_claim_report.json', json_encode(['violation_count' => 0], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/legacy_residual_scan.json', json_encode(['legacy_deep_core_residual_count' => 0], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/fc144_boundary_report.json', json_encode(['violation_count' => 0], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/phase8b_summary.json', json_encode(['verdict' => 'PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA'], JSON_PRETTY_PRINT)."\n");
        $counts = ['baseline' => 36, 'low_resonance' => 108, 'partial_resonance' => 90, 'diffuse_convergence' => 108, 'close_call_pair' => 36, 'scene_localization' => 162, 'fc144_recommendation' => 90];
        File::put($candidate.'/candidate_payloads_manifest.json', json_encode(['total_payload_count' => 630, 'payload_counts' => $counts], JSON_PRETTY_PRINT)."\n");
        File::put($candidate.'/candidate_payload_hashes.json', json_encode(['candidate_payloads_manifest_sha256' => 'fixture'], JSON_PRETTY_PRINT)."\n");
        $pairs = [];
        for ($a = 1; $a <= 9; $a++) {
            for ($b = $a + 1; $b <= 9; $b++) {
                $pairs[] = ['pair_key' => $a.'_'.$b];
            }
        }
        File::put($candidate.'/candidate_payload_source_mapping.json', json_encode(['source_mapping_failure_count' => 0, 'missing_count' => 0, 'fallback_count' => 0, 'blocked_count' => 0, 'duplicate_selection_count' => 0, 'branch_provenance_mismatch_count' => 0, 'branch_payload_counts' => ['low_resonance_response' => 108, 'partial_resonance_response' => 90, 'diffuse_convergence_response' => 108, 'close_call_pair' => 36, 'scene_localization_response' => 162, 'fc144_recommendation_response' => 90], 'close_call_pairs' => $pairs], JSON_PRETTY_PRINT)."\n");
        $index = 0;
        foreach ($counts as $group => $count) {
            for ($i = 0; $i < $count; $i++) {
                File::put($candidate.'/candidate_payloads/'.$group.'_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.json', json_encode(['asset_key' => $group.'_'.$index++, 'locale' => 'en', 'body' => 'Working hypothesis for reflection.'], JSON_UNESCAPED_SLASHES));
            }
        }
        $this->recomputePackage($root);

        return $root;
    }

    private function makePromotable(string $root): string
    {
        $sha = $this->recomputePackage($root);
        $report = ['schema_version' => 'fermatmind.en_parity.independent_w9_report.v1', 'review_kind' => 'independent_w9', 'verdict' => 'PASS', 'package_sha256' => $sha, 'lane_id' => 'W5', 'subscope' => 'enneagram-results', 'reviewed_row_count' => 630];
        $bytes = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $ref = 'w5-'.substr($sha, 0, 16).'.json';
        File::put($this->w9Directory.'/'.$ref, $bytes);
        $manifest = $this->decodeJson($root.'/package_manifest.json');
        $manifest['quality_gates']['independent_w9'] = ['status' => 'pass', 'report_ref' => $ref, 'report_sha256' => hash('sha256', $bytes)];
        File::put($root.'/package_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $sha;
    }

    private function recomputePackage(string $root): string
    {
        $files = [];
        foreach (File::allFiles($root) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            if ($path !== 'package_manifest.json') {
                $files[$path] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        $chain = '';
        foreach ($files as $path => $hash) {
            $chain .= $path."\0".$hash."\n";
        }
        $sha = hash('sha256', $chain);
        $manifest = ['schema_version' => 'fermatmind.en_parity.enneagram_private_result_package.v2', 'lane_id' => 'W5', 'subscope' => 'enneagram-results', 'locale' => 'en', 'status' => 'unpublished_candidate', 'expected_row_count' => 630, 'source_asset_count' => 1332, 'source_commit' => str_repeat('a', 40), 'package_sha256' => $sha, 'files' => array_map(static fn (string $path, string $hash): array => ['path' => $path, 'sha256' => $hash], array_keys($files), $files)];
        File::put($root.'/package_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $sha;
    }

    private function packageSha(string $root): string
    {
        return (string) ($this->decodeJson($root.'/package_manifest.json')['package_sha256'] ?? '');
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $path): array
    {
        return json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(string $root, string $sha): PromotionContext
    {
        return new PromotionContext($root, $sha, 'W5', 'enneagram-results', str_repeat('a', 40), str_repeat('b', 64), str_repeat('c', 64), '123', 1, str_repeat('d', 64), 630, str_repeat('e', 64));
    }
}

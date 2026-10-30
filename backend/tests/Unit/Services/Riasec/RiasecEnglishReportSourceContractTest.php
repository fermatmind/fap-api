<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecEnglishReportSourceContract as Contract;
use App\Services\Riasec\RiasecPublicProjectionService as Projection;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishReportSourceContractTest extends TestCase
{
    public function test_native_both_form_instances_bind_real_sources_without_granting_pdf_or_ownership(): void
    {
        foreach (['riasec_60', 'riasec_140'] as $form) {
            $result = $this->resultFixture($form);
            $before = $result->toArray();
            $p = app(Projection::class)->buildV2FromResult($result, 'en', true);
            $this->assertSame('complete', data_get($p, 'report_content_v1.status'), json_encode(data_get($p, 'report_content_v1.errors')));
            $this->assertSame(Contract::AUTHORITY_ID, data_get($p, 'private_result_authority.authority_id'));
            $this->assertSame(data_get($p, 'report_content_v1.instance_hash'), data_get($p, 'private_result_authority.instance_hash'));
            $this->assertSame('report_sources', data_get($p, 'private_result_authority.coverage_scope'));
            $this->assertSame('not_required_when_absent_from_snapshot_unknown', data_get($p, 'report_content_v1.raw_scores'));
            foreach (['pdf_eligible', 'owner', 'paid', 'entitlement'] as $key) {
                $this->assertArrayNotHasKey($key, $p['private_result_authority']);
            }
            $this->assertSame($before, $result->toArray());
            if ($form === 'riasec_140') {
                $result->result_json = array_merge($result->result_json, ['riasec_140q_layer_states' => ['task' => 'agreement', 'environment' => 'tension', 'role' => 'agreement']]);
                $explicit = app(Projection::class)->buildV2FromResult($result, 'en', true);
                $this->assertSame('complete', data_get($explicit, 'report_content_v1.status'), json_encode(data_get($explicit, 'report_content_v1.errors')));
            }
        }
    }

    public function test_missing_facts_and_weak_quality_preserve_selected_display_without_report_authority(): void
    {
        foreach (['snapshot', 'score', 'numeric_string', 'unsupported_form', 'missing_count', 'missing_grade', 'missing_content_version', 'type_identity', 'weak_grade', 'version', 'mismatch', 'caution', 'low_quality'] as $case) {
            $r = $this->resultFixture('riasec_140');
            if ($case === 'score') {
                $r->scores_pct = array_diff_key($r->scores_pct, ['C' => true]);
            }
            if ($case === 'numeric_string') {
                $r->scores_pct = array_merge($r->scores_pct, ['C' => '30']);
            }
            if ($case === 'version') {
                $r->scoring_spec_version = null;
            }
            $payload = $r->result_json;
            if ($case === 'mismatch') {
                $r->type_code = $payload['top_code'] = 'RIA';
            }
            if ($case === 'unsupported_form') {
                $payload['form_code'] = 'unsupported';
            }
            if ($case === 'missing_count') {
                unset($payload['answer_count']);
            }
            if ($case === 'missing_grade') {
                unset($payload['quality_grade']);
            }
            if ($case === 'missing_content_version') {
                $r->content_package_version = null;
            }
            if ($case === 'type_identity') {
                $payload['primary_type'] = 'C';
            }
            if ($case === 'weak_grade') {
                $payload['quality_grade'] = 'B';
            }
            if ($case === 'caution') {
                $payload['too_fast'] = true;
            }
            if ($case === 'low_quality') {
                $payload['answer_count'] = 139;
            }
            $r->result_json = $payload;
            $p = app(Projection::class)->buildV2FromResult($r, 'en', $case !== 'snapshot');
            $this->assertNotSame('complete', data_get($p, 'report_content_v1.status'), $case);
            $this->assertNull($p['private_result_authority'], $case);
            $this->assertCount(6, $p['scores']['dimensions']);
            $this->assertNotEmpty($p['report_content_v1']['errors']);
        }
    }

    public function test_source_descriptor_is_computed_and_invalid_sources_fail_even_with_rebound_hashes(): void
    {
        $compiled = app(Compiler::class)->compile();
        $p = $compiled['payload'];
        $this->assertSame('selected_modules', data_get(Loader::localeProjection($p, 'en'), 'authority.coverage_scope'));
        $this->assertSame(Contract::ID, data_get(Contract::sourceAuthority($p), 'report_contract_id'));
        $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], data_get($p, 'coverage.english_report_sources.dimensions'));
        foreach (['missing', 'locale', 'surface', 'coverage', 'hash'] as $case) {
            $bad = $p;
            match ($case) {
                'missing' => $bad['assets']['faq_v1.en.json'] = [],
                'locale' => $bad['assets']['faq_v1.en.json']['locale'] = 'zh-CN',
                'surface' => $bad['assets'][Contract::SURFACE_ASSET]['result_summary']['boundary'] = 'Changed',
                'coverage' => $bad['coverage']['english_report_sources']['dimensions'] = ['R'],
                'hash' => $bad['source_hash'] = str_repeat('a', 64),
            };
            unset($bad['compiled_hash']);
            $bad['compiled_hash'] = Contract::digest($bad);
            try {
                Contract::sourceAuthority($bad);
                $this->fail('Accepted invalid '.$case);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0', '1.7.0', '1.8.0'] as $version) {
            $old = $p;
            $old['compiler']['version'] = $version;
            $this->assertNull(Contract::sourceAuthority($old));
            $this->assertArrayNotHasKey(Contract::SURFACE_ASSET, Compiler::sourceContract($version));
        }
    }

    public function test_projection_mutations_cannot_promote_missing_or_misbound_reader_fields(): void
    {
        $r = $this->resultFixture('riasec_140');
        $p = app(Projection::class)->buildV2FromResult($r, 'en', true);
        $expected = [
            'score_rows' => $p['scores']['dimensions'],
            'summary' => $p['result_summary_v1'], 'module_policy' => $p['module_visibility_policy'],
            'interpretation_state' => $p['interpretation_state'], 'quality' => $p['quality'],
            'quality_display' => $p['quality']['display_v1'], 'tie_display' => $p['interpretation_state']['tie_display_v1'],
            'slots' => $p['deep_content_slots_v1']['slots'], 'lifecycle' => $p['lifecycle_copy_v1'],
            'activity' => $p['activity_explorer_v0_1'],
        ];
        $facts = [
            'scores' => $r->scores_pct, 'top_code' => 'IAR', 'result_type_code' => 'IAR',
            'original_form_code' => 'riasec_140', 'measurement_form_code' => null, 'answer_count' => 140, 'quality_grade' => 'A',
            'version_candidates' => ['scoring_spec_version' => [$r->scoring_spec_version], 'form_version' => [$r->dir_version], 'content_package_version' => [$r->content_package_version]],
        ];
        foreach ([
            'scale_code' => 'EQ_60',
            'scores.score_kind' => 'percentile',
            'scores.dimensions.0.label' => '',
            'claim_boundary' => [],
            'dimension_score_band_contract_v1.percentile_interpretation_allowed' => true,
            'scores.dimensions.0.score' => 0,
            'scores.dimensions.0.code' => 'C',
            'form.question_count' => 60,
            'measurement_evidence.scoring_spec_version' => 'different-snapshot',
            'measurement_evidence.normalization_method' => 'percentile',
            'holland_code.code' => 'AIR',
            'quality.grade' => 'D',
            'quality.display_v1.boundary' => '',
            'interpretation_state.profile_shape' => 'clear',
            'interpretation_state.tie_display_v1.note' => '',
            'result_summary_v1.highlights' => [],
            'module_visibility_policy.modules' => [],
            'deep_content_slots_v1.slots.0.selection_v1.score_band' => 'low',
            'deep_content_slots_v1.slots.0.content.title' => '',
            'deep_content_slots_v1.slots.0.state.dimension_code' => 'C',
            'deep_content_slots_v1.slots' => [],
            'lifecycle_copy_v1.faq_items.0.a' => '',
            'lifecycle_copy_v1.technical_note_summary_sections.0.data_status' => 'validated',
            'lifecycle_copy_v1.locale' => 'zh-CN',
            'activity_explorer_v0_1.code_activity_pack.activities' => [],
        ] as $path => $value) {
            $bad = $p;
            data_set($bad, $path, $value);
            $validation = Contract::validateProjection($bad, $p['private_result_authority'], $facts, $expected);
            $this->assertNotSame('complete', $validation['status'], $path);
            $this->assertNotEmpty($validation['errors'], $path);
            $this->assertNotSame($p['report_content_v1']['instance_hash'], $validation['instance_hash'], $path);
        }
        // The original consumer hides unknown modules. Their content cannot enter this report qualification.
        $p['deep_content_slots_v1']['slots'][] = ['module_key' => 'unknown', 'content' => ['copy' => 'Hidden']];
        $v = Contract::validateProjection($p, $p['private_result_authority'], $facts, $expected);
        $this->assertSame('complete', $v['status']);
        $this->assertSame($p['report_content_v1']['instance_hash'], $v['instance_hash']);
    }

    public function test_source_identity_and_hashes_flow_through_original_composer_without_access_mutation(): void
    {
        $attempt = new \App\Models\Attempt(['locale' => 'en', 'scale_code' => 'RIASEC']);
        $before = $attempt->toArray();
        $r = app(\App\Services\Report\RiasecReportComposer::class)->composeVariant($attempt, $this->resultFixture('riasec_140'), 'full', ['snapshot_bound' => true]);
        $this->assertTrue($r['ok']);
        $this->assertSame(Contract::AUTHORITY_ID, data_get($r, 'report._meta.snapshot_binding_v1.canonical_authority_identity'));
        $this->assertSame(data_get($r, 'report._meta.riasec_private_result_authority.source_hash'), data_get($r, 'report._meta.snapshot_binding_v1.canonical_source_hash'));
        $this->assertSame($before, $attempt->toArray());
    }

    public function test_published_1_8_bytes_and_selected_authority_remain_immutable_and_readable(): void
    {
        $c = app(Compiler::class)->compile();
        $c = \Tests\Unit\Services\Riasec\RiasecEnglishReportSourceContractTest::restoreLegacyReaderCopy($c);
        $p = $c['payload'];
        $m = $c['manifest'];
        // Rebuild immutable releases with their original reader copy, not today's source.
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/riasec_activity_reader_copy_precleanup.v1.json')), true, 512, JSON_THROW_ON_ERROR) as $original) {
            data_set($p['assets'][Compiler::ENGLISH_ACTIVITY_ASSET], $original['row'].'.'.$original['path'], $original['copy']);
        }
        $p['compiler']['version'] = '1.8.0';
        unset($p['assets'][Contract::SURFACE_ASSET], $p['coverage']['english_report_sources'], $p['compiled_hash']);
        $m['source_files'] = array_values(array_filter($m['source_files'], fn ($row) => $row['path'] !== Contract::SURFACE_ASSET));
        foreach ($m['source_files'] as &$row) {
            if ($row['path'] === Compiler::ENGLISH_ACTIVITY_ASSET) {
                $row['sha256'] = Contract::digest($p['assets'][Compiler::ENGLISH_ACTIVITY_ASSET]);
            }
        }
        unset($row);
        $p['source_hash'] = $m['source_hash'] = $m['content_hash'] = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $m['source_files'])));
        $p['compiled_hash'] = $m['compiled_hash'] = Contract::digest($p);
        $m['compiler'] = $p['compiler'];
        $m['coverage'] = $p['coverage'];
        $canonical = new \ReflectionMethod(Compiler::class, 'canonicalJson');
        $bytes = $canonical->invoke(app(Compiler::class), $p)."\n";
        $m['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('5fad2d6cbaa2fc0109a41161f2bfa40af3c1225115c6bb916f2f09d82d3e8b52', $p['source_hash']);
        $this->assertSame('3769d13a7939fa7341a0acef06bae7139e56a6e09a5fc82040f67c26113dc551', $p['compiled_hash']);
        app(Loader::class)->assertPackage($m, $p, $bytes);
        $this->assertNull(Loader::reportSourceAuthority($p, 'en'));
        $source = new \App\Services\Riasec\RiasecPrivateResultSourceRepository;
        $root = $p;
        $root['compiler']['version'] = '1.0.0';
        (new \ReflectionProperty($source, 'loaded'))->setValue($source, ['zh-CN' => Loader::localeProjection($root, 'zh-CN')]);
        $this->assertSame('Original inline copy', $source->englishReportCopy('result_summary.headline', 'Original inline copy'));
        $this->assertNull($source->reportSourceAuthority('en'));
        $this->assertSame('selected_modules', Loader::localeProjection($p, 'en')['authority']['coverage_scope']);
    }

    private function resultFixture(string $form): Result
    {
        return new Result([
            'scale_code' => 'RIASEC', 'type_code' => 'IAR',
            'scoring_spec_version' => 'synthetic-native-spec', 'dir_version' => 'synthetic-native-form',
            'content_package_version' => 'synthetic-native-content',
            'scores_pct' => ['R' => 75, 'I' => 90, 'A' => 87.5, 'S' => 55, 'E' => 40, 'C' => 30],
            'result_json' => ['top_code' => 'IAR', 'form_code' => $form, 'answer_count' => $form === 'riasec_140' ? 140 : 60, 'quality_grade' => 'A', 'quality_flags' => []],
        ]);
    }

    /** Reconstruct earlier fixtures from the accepted 1.9 reader, never current copy. */
    public static function restoreLegacyReaderCopy(array $compiled): array
    {
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/riasec_reader_copy_precloseout_1_9.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            \PHPUnit\Framework\Assert::assertArrayHasKey($row['asset'], $payload['assets']);
            data_set($payload['assets'][$row['asset']], implode('.', $row['path']), $row['copy']);
        }
        $compiler = app(Compiler::class);
        $canonical = new \ReflectionMethod($compiler, 'canonicalJson');
        $coverage = (new \ReflectionMethod($compiler, 'coverage'))->invoke($compiler, $payload['assets']);
        $payload['coverage'] = $manifest['coverage'] = $coverage;
        foreach ($manifest['source_files'] as &$source) {
            $source['sha256'] = Contract::digest($payload['assets'][$source['path']]);
        }
        unset($source);
        $sourceHash = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = $sourceHash;
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = Contract::digest($payload);
        $bytes = $canonical->invoke($compiler, $payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);

        \PHPUnit\Framework\Assert::assertSame('58910dbd3a03a1c57c099c365ab55470218dc87c8828545caacc95af1758ce43', $sourceHash);
        \PHPUnit\Framework\Assert::assertSame('0e53b7c1cfd3b731fac97a94b735393fb8f268ca5a7a7f3ef99baecc0f9d46ac', $payload['compiled_hash']);

        return ['payload' => $payload, 'manifest' => $manifest, 'bytes' => $bytes,
            'manifest_bytes' => $canonical->invoke($compiler, $manifest)."\n",
            'source_hash' => $sourceHash, 'compiled_hash' => $payload['compiled_hash']];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Result;
use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use App\Services\Riasec\RiasecPublicProjectionService;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishDimensionStateReleaseTest extends TestCase
{
    public function test_complete_source_resolves_and_only_explicit_normal_140q_states_reach_projection(): void
    {
        $compiled = app(Compiler::class)->compile();
        app(Loader::class)->assertPackage($compiled['manifest'], $compiled['payload'], $compiled['bytes']);
        $this->assertSame(126, $compiled['payload']['coverage']['english_selected_sources']['dimension_state_rows']);
        $registry = app(RiasecDeepCopySlotRegistry::class);
        $rows = $compiled['payload']['assets'][Compiler::ENGLISH_DIMENSION_STATE_ASSET];
        foreach ($rows as $row) {
            $slot = $registry->resolveForLocale($registry->resolve140qDimensionLayerSlot($row['dimension'], $row['layer'], $row['layer_state']), 'en');
            $this->assertSame('authored', $slot['content_status']);
            foreach (array_diff(Compiler::DIMENSION_STATE_READER_FIELDS, ['cannot_claim']) as $field) {
                $this->assertSame($row[$field], $slot[$field]);
            }
            $this->assertSame('draft', $slot['review_status']);
            $this->assertSame($row['review_status'], $slot['source_review_status']);
            $this->assertNotSame('expert_reviewed', $slot['evidence_level']);
        }
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $dimension) {
            foreach (['agreement', 'tension'] as $state) {
                $projection = $this->projection($dimension, $state);
                $this->assertNull($projection['private_result_authority']);
                $slots = $this->dimensionSlots($projection);
                $this->assertCount(3, $slots);
                foreach ($slots as $slot) {
                    $layer = $slot['state']['layer'];
                    $source = array_values(array_filter($rows, fn ($row) => $row['dimension'] === $dimension && $row['layer'] === $layer && $row['layer_state'] === $state))[0];
                    $this->assertSame($source['summary'], $slot['content']['summary']);
                    $this->assertSame($source['example_question'], $slot['content']['example_question']);
                    $card = ['task' => 'task_activity_card', 'environment' => 'environment_card', 'role' => 'role_responsibility_card'][$layer];
                    $this->assertSame($source[$card], $slot['content'][$card]);
                    $this->assertCount(1, array_intersect(['task_activity_card', 'environment_card', 'role_responsibility_card'], array_keys($slot['content'])));
                    $this->assertSame($compiled['payload']['assets']['runtime_surface_copy_v1.en.json']['generic_slots']['layer_140q']['task_activity_card']['user_visible_boundary'], $slot['boundaries']['user_visible_boundary']);
                }
                $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', json_encode($slots, JSON_UNESCAPED_UNICODE));
            }
        }
        foreach ([['riasec_60', 'A', 'agreement'], ['riasec_140', 'D', 'agreement'], ['riasec_140', 'A', 'unavailable'], ['riasec_140', 'A', 'insufficient_quality']] as [$form, $grade, $state]) {
            $this->assertSame([], $this->dimensionSlots($this->projection('R', $state, $form, $grade)));
        }
    }

    public function test_invalid_english_source_fails_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'duplicate', 'identity', 'locale', 'empty', 'cjk', 'flag', 'boundaries', 'provenance'] as $case) {
            $rows = $assets[Compiler::ENGLISH_DIMENSION_STATE_ASSET];
            match ($case) {
                'missing' => array_pop($rows), 'duplicate' => $rows[1] = $rows[0],
                'identity' => $rows[0]['dimension'] = 'I', 'locale' => $rows[0]['locale'] = 'zh-CN',
                'empty' => $rows[0]['summary'] = '', 'cjk' => $rows[0]['summary'] = '中文',
                'flag' => $rows[0]['result_mutation_allowed'] = true, 'boundaries' => $rows[0]['required_boundaries'] = [],
                'provenance' => $rows[0]['source_binding']['source_row'] = 2,
            };
            try {
                app(Compiler::class)->assertEnglishDimensionStates($rows, $assets['140q_task_environment_role_v1.zh-CN.jsonl']);
                $this->fail('Invalid English dimension state accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English dimension-state', $exception->getMessage());
            }
        }
    }

    public function test_selected_1_2_release_retains_exact_hashes_and_omits_new_asset(): void
    {
        $compiled = app(Compiler::class)->compile();
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $payload['compiler']['version'] = '1.2.0';
        unset($payload['assets'][Compiler::ENGLISH_DIMENSION_STATE_ASSET]);
        $payload['coverage']['english_selected_sources']['dimension_state_rows'] = 0;
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $manifest['source_files'] = array_values(array_filter($manifest['source_files'], fn ($row) => $row['path'] !== Compiler::ENGLISH_DIMENSION_STATE_ASSET));
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        unset($payload['compiled_hash']);
        $canonical = fn ($value) => (new \ReflectionMethod(Compiler::class, 'canonicalJson'))->invoke(app(Compiler::class), $value);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = hash('sha256', $canonical($payload));
        $bytes = $canonical($payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('e1b42aef62fbb96931900828263e8ee38f2aabf92f013ce99990dda130590a01', $payload['source_hash']);
        $this->assertSame('3624d69e3f504d32e066a66a356f1d07c7484a34f0151273636e101dd22cc7d4', $payload['compiled_hash']);
        app(Loader::class)->assertPackage($manifest, $payload, $bytes);
        $this->assertArrayNotHasKey(Compiler::ENGLISH_DIMENSION_STATE_ASSET, Loader::localeProjection($payload, 'en')['assets']);
    }

    private function dimensionSlots(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => isset($slot['state']['layer_dimension'])));
    }

    private function projection(string $dimension, string $state, string $form = 'riasec_140', string $grade = 'A'): array
    {
        $code = $dimension.implode('', array_slice(array_diff(['R', 'I', 'A', 'S', 'E', 'C'], [$dimension]), 0, 2));
        $scores = array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], 30);
        foreach (str_split($code) as $index => $letter) {
            $scores[$letter] = [85, 70, 55][$index];
        }
        $result = new Result(['scale_code' => 'RIASEC', 'type_code' => $code, 'scores_pct' => $scores, 'result_json' => ['top_code' => $code, 'form_code' => $form, 'answer_count' => $form === 'riasec_60' ? 60 : 140, 'quality_grade' => $grade, 'quality_flags' => $grade === 'D' ? ['attention_133_failed', 'attention_137_failed'] : [], 'riasec_140q_layer_states' => array_fill_keys(['task', 'environment', 'role'], $state)]]);

        return app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'en', true);
    }
}

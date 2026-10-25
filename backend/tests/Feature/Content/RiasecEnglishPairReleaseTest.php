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

final class RiasecEnglishPairReleaseTest extends TestCase
{
    public function test_all_fifteen_sources_render_only_the_actual_top2_teaser_in_both_forms(): void
    {
        $compiled = app(Compiler::class)->compile();
        app(Loader::class)->assertPackage($compiled['manifest'], $compiled['payload'], $compiled['bytes']);
        $this->assertSame(Compiler::ENGLISH_PAIR_KEYS, $compiled['payload']['coverage']['english_selected_sources']['pairs']);
        $rows = array_column($compiled['payload']['assets']['pair_blend_15_pairs_v1.en.jsonl'], null, 'pair_key');
        $registry = app(RiasecDeepCopySlotRegistry::class);
        foreach (Compiler::ENGLISH_PAIR_KEYS as $key) {
            $slot = $registry->resolveForLocale($registry->resolvePairBlendSlot($key), 'en');
            foreach (array_diff(Compiler::ENGLISH_PAIR_READER_FIELDS, ['result_page_teaser']) as $field) {
                $this->assertSame($rows[$key][$field], $slot[$field]);
            }
            foreach (['riasec_60', 'riasec_140'] as $form) {
                foreach ([explode('_', $key), array_reverse(explode('_', $key))] as $top2) {
                    $third = array_values(array_diff(['R', 'I', 'A', 'S', 'E', 'C'], $top2))[0];
                    $projection = $this->projection(implode('', $top2).$third, $form);
                    $this->assertNull($projection['private_result_authority']);
                    $slots = $this->pairs($projection);
                    $this->assertCount(3, $slots);
                    foreach ($slots as $public) {
                        $source = $rows[$public['state']['pair_key']];
                        foreach (Compiler::ENGLISH_PAIR_READER_FIELDS as $field) {
                            if ($field === 'user_visible_boundary') {
                                $this->assertSame($source[$field], $public['boundaries']['user_visible_boundary']);
                            } elseif ($field === 'result_page_teaser' && $public['state']['pair_key'] !== $key) {
                                $this->assertArrayNotHasKey($field, $public['content']);
                            } else {
                                $this->assertSame($source[$field], $public['content'][$field]);
                            }
                        }
                    }
                    $this->assertCount(1, array_filter($slots, fn ($item) => isset($item['content']['result_page_teaser'])));
                }
            }
        }
        $this->assertSame([], $this->pairs($this->projection('SEC', 'riasec_140', ['attention_133_failed', 'attention_137_failed'])));
        $this->assertSame('unavailable', $registry->resolveForLocale($registry->resolveTop3ChainSlot('SEC'), 'en')['content_status']);
    }

    public function test_incomplete_or_drifted_pair_sources_fail_closed(): void
    {
        $assets = app(Compiler::class)->compile()['payload']['assets'];
        foreach (['missing', 'duplicate', 'dimensions', 'locale', 'version', 'empty', 'cjk', 'list', 'boundaries', 'form', 'provenance', 'review'] as $case) {
            $rows = $assets['pair_blend_15_pairs_v1.en.jsonl'];
            match ($case) {
                'missing' => array_pop($rows), 'duplicate' => $rows[4] = $rows[3],
                'dimensions' => $rows[3]['dimensions'] = ['R', 'I'], 'locale' => $rows[3]['locale'] = 'zh-CN',
                'version' => $rows[3]['asset_version'] = 'unknown', 'empty' => $rows[3]['context_costs'][0] = '',
                'cjk' => $rows[3]['validation_questions'][0] = '中文', 'list' => $rows[3]['activities_to_validate'] = [],
                'boundaries' => $rows[3]['required_boundaries'] = [], 'form' => $rows[3]['applicable_form_codes'] = ['riasec_140'],
                'provenance' => $rows[3]['source_binding']['source_row'] = 1,
                'review' => $rows[3]['review_status'] = 'expert_reviewed',
            };
            try {
                app(Compiler::class)->assertEnglishPairSources($rows, $assets['pair_blend_15_pairs_v1.zh-CN.jsonl']);
                $this->fail('Invalid English pair accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('English pair', $exception->getMessage());
            }
        }
    }

    public function test_published_three_pair_1_3_snapshot_keeps_exact_hashes_and_original_bytes(): void
    {
        $this->assertSame('459c27aee43e5a1eb483d560cb8f56d74e6c75173b6de2187a792ccf613a0bf7', hash('sha256', implode('', array_slice(file(base_path('content_assets/riasec/pair_blend_15_pairs_v1.en.jsonl')), 0, 3))));
        $compiled = app(Compiler::class)->compile();
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $payload['compiler']['version'] = '1.3.0';
        $payload['assets']['pair_blend_15_pairs_v1.en.jsonl'] = array_slice($payload['assets']['pair_blend_15_pairs_v1.en.jsonl'], 0, 3);
        $payload['coverage']['english_selected_sources']['pairs'] = Compiler::englishPairCoverage('1.3.0');
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $canonical = fn ($value) => (new \ReflectionMethod(Compiler::class, 'canonicalJson'))->invoke(app(Compiler::class), $value);
        foreach ($manifest['source_files'] as &$row) {
            $row = array_merge($row, Compiler::sourceContract('1.3.0')[$row['path']]);
            $row['sha256'] = hash('sha256', $canonical($payload['assets'][$row['path']]));
        }
        unset($row);
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = $manifest['compiled_hash'] = hash('sha256', $canonical($payload));
        $bytes = $canonical($payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('44af265e9756c268c89fd219b7a859aa7492d805ca86d71a547584c5a2e1849c', $payload['source_hash']);
        $this->assertSame('da80ad53486bfd001b3856ed408eeb76d04b864dce04f28421157867ccb2d94b', $payload['compiled_hash']);
        $this->assertSame('e5850552c3716bfa1df5c5a3a7d5ddde6140617a85eac9061a9e38d145fc20af', hash('sha256', $bytes));
        app(Loader::class)->assertPackage($manifest, $payload, $bytes);
        $this->assertCount(3, Loader::localeProjection($payload, 'en')['assets']['pair_blend_15_pairs_v1.en.jsonl']);
    }

    private function pairs(array $projection): array
    {
        return array_values(array_filter($projection['deep_content_slots_v1']['slots'], fn ($slot) => $slot['slot_key'] === 'pair_blend_copy'));
    }

    private function projection(string $code, string $form, array $flags = []): array
    {
        $scores = array_fill_keys(['R', 'I', 'A', 'S', 'E', 'C'], 30);
        foreach (str_split($code) as $index => $letter) {
            $scores[$letter] = [85, 70, 55][$index];
        }
        $result = new Result(['scale_code' => 'RIASEC', 'type_code' => $code, 'scores_pct' => $scores, 'result_json' => ['top_code' => $code, 'form_code' => $form, 'answer_count' => $form === 'riasec_60' ? 60 : 140, 'quality_flags' => $flags]]);

        return app(RiasecPublicProjectionService::class)->buildV2FromResult($result, 'en', true);
    }
}

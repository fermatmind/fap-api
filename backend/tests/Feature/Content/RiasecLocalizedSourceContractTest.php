<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\RiasecLocalizedSourceContract;
use App\Services\Content\RiasecPrivateResultCompileService;
use App\Services\Content\RiasecPrivateResultPackLoader;
use RuntimeException;
use Tests\TestCase;

final class RiasecLocalizedSourceContractTest extends TestCase
{
    private function assets(): array
    {
        return json_decode(file_get_contents(base_path('content_assets/riasec/compiled/private_result.compiled.json')), true, 512, JSON_THROW_ON_ERROR)['assets'];
    }

    public function test_three_languages_have_source_bound_assets_and_chinese_projection_stays_isolated(): void
    {
        $payload = json_decode(file_get_contents(base_path('content_assets/riasec/compiled/private_result.compiled.json')), true, 512, JSON_THROW_ON_ERROR);
        $coverage = RiasecLocalizedSourceContract::coverage($payload['assets']);
        $this->assertCount(19, $coverage['en']);
        $this->assertCount(19, $coverage['zh-TW']);
        $this->assertCount(31, RiasecPrivateResultCompileService::sourceContract('1.9.0'));
        $this->assertCount(58, RiasecPrivateResultCompileService::sourceContract('1.10.0'));
        $this->assertCount(19, RiasecPrivateResultPackLoader::localeProjection($payload, 'zh-CN')['assets']);
        $this->assertCount(20, RiasecPrivateResultPackLoader::localeProjection($payload, 'en')['assets']);
        $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], array_column($payload['assets']['dimension_deep_copy_v1.zh-TW.json']['dimensions'], 'dimension_code'));
    }

    public function test_all_six_dimensions_have_complete_score_bound_actions_in_all_three_languages(): void
    {
        $assets = $this->assets();
        foreach (['dimension_deep_copy_v1.zh-CN.r3.json', 'dimension_deep_copy_v1.zh-TW.json', 'dimension_deep_copy_v1.en.json'] as $path) {
            $this->assertSame(['R', 'I', 'A', 'S', 'E', 'C'], array_column($assets[$path]['dimensions'], 'dimension_code'));
            foreach ($assets[$path]['dimensions'] as $dimension) {
                $this->assertCount(10, $dimension['desktop_atlas_by_decile']);
                foreach ($dimension['desktop_atlas_by_decile'] as $entry) {
                    $this->assertCount(4, array_unique($entry['examples']));
                    $this->assertCount(4, array_unique($entry['next_steps']));
                    $this->assertSame($entry['next_step'], $entry['next_steps'][0]);
                }
            }
        }
    }

    public function test_missing_translated_rows_fail_closed(): void
    {
        $assets = $this->assets();
        array_pop($assets['top3_code_chain_strategy_v1.zh-TW.jsonl']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ROW_COUNT_INVALID');
        RiasecLocalizedSourceContract::coverage($assets);
    }

    public function test_localization_cannot_change_a_result_identity(): void
    {
        $assets = $this->assets();
        $assets['aspirations_calibration_v1.en.jsonl'][0]['domain_key'] = 'a_different_domain';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IDENTITY_CHANGED');
        RiasecLocalizedSourceContract::coverage($assets);
    }

    public function test_localization_cannot_add_numeric_claims(): void
    {
        $assets = $this->assets();
        $assets['disagree_path_v1.en.jsonl'][0]['summary'] .= ' 999';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TOKENS_CHANGED');
        RiasecLocalizedSourceContract::coverage($assets);
    }

    public function test_source_drift_invalidates_existing_translation(): void
    {
        $assets = $this->assets();
        $assets['faq_v1.zh-CN.json']['questions'][0]['q'] .= ' source changed';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BINDING_INVALID');
        RiasecLocalizedSourceContract::coverage($assets);
    }
}

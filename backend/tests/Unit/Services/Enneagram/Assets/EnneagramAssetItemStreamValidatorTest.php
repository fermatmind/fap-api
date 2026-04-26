<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\EnneagramAssetItemStreamLoader;
use App\Services\Enneagram\Assets\EnneagramAssetItemStreamValidator;
use Tests\TestCase;

final class EnneagramAssetItemStreamValidatorTest extends TestCase
{
    use EnneagramAssetTestPaths;

    public function test_it_validates_asset_counts_policy_and_deep_core_coverage(): void
    {
        $this->skipWhenAssetsMissing();
        $this->skipWhenBatchCMissing();
        $this->skipWhenBatchDMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $validator = app(EnneagramAssetItemStreamValidator::class);

        $batchAReport = $validator->validate($loader->load($this->batchAPath()));
        $batchBReport = $validator->validate($loader->load($this->batchBPath()));
        $batchCReport = $validator->validate($loader->load($this->batchCPath()));
        $batchDReport = $validator->validate($loader->load($this->batchDPath()));

        $this->assertSame('PASS', $batchAReport['status']);
        $this->assertSame('PASS', $batchBReport['status']);
        $this->assertSame('PASS', $batchCReport['status']);
        $this->assertSame('PASS', $batchDReport['status']);
        $this->assertSame(315, $batchAReport['asset_count']);
        $this->assertSame(423, $batchBReport['asset_count']);
        $this->assertSame(108, $batchCReport['asset_count']);
        $this->assertSame(90, $batchDReport['asset_count']);
        $this->assertFalse($batchAReport['production_import_allowed']);
        $this->assertFalse($batchBReport['production_import_allowed']);
        $this->assertFalse($batchCReport['production_import_allowed']);
        $this->assertFalse($batchDReport['production_import_allowed']);
        $this->assertTrue($batchAReport['staging_preview_allowed']);
        $this->assertTrue($batchBReport['staging_preview_allowed']);
        $this->assertTrue($batchCReport['staging_preview_allowed']);
        $this->assertTrue($batchDReport['staging_preview_allowed']);
        $this->assertFalse($batchAReport['full_replacement_allowed']);
        $this->assertFalse($batchBReport['full_replacement_allowed']);
        $this->assertFalse($batchCReport['full_replacement_allowed']);
        $this->assertFalse($batchDReport['full_replacement_allowed']);

        foreach ([
            'core_motivation',
            'core_fear',
            'core_desire',
            'self_image',
            'attention_pattern',
            'strength',
            'blindspot',
            'stress_pattern',
            'relationship_pattern',
            'work_pattern',
            'growth_direction',
            'daily_observation',
            'boundary',
        ] as $category) {
            $this->assertArrayHasKey($category, data_get($batchBReport, 'counts.category_counts'));
        }

        $this->assertSame(
            ['low_resonance_response' => 108],
            data_get($batchCReport, 'counts.category_counts')
        );
        $this->assertSame(
            ['partial_resonance_response' => 90],
            data_get($batchDReport, 'counts.category_counts')
        );
    }

    public function test_it_blocks_duplicate_key_banned_phrase_and_full_replacement(): void
    {
        $this->skipWhenAssetsMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $validator = app(EnneagramAssetItemStreamValidator::class);
        $stream = $loader->load($this->batchAPath());
        $stream['metadata']['replacement_policy']['mode'] = 'full_replacement';
        $stream['metadata']['import_policy'] = 'production_full_replacement';
        $stream['metadata']['preflight_self_check']['production_import_allowed'] = true;
        $stream['metadata']['preflight_self_check']['staging_merge_preview_allowed'] = false;
        $stream['items'][1]['asset_key'] = $stream['items'][0]['asset_key'];
        $stream['items'][0]['body_zh'] .= ' 终极判型';

        $report = $validator->validate($stream);
        $blocked = implode('|', $report['blocked_reasons']);

        $this->assertSame('FAIL', $report['status']);
        $this->assertStringContainsString('duplicate_asset_key', $blocked);
        $this->assertStringContainsString('banned_body_phrase', $blocked);
        $this->assertStringContainsString('full_replacement_blocked', $blocked);
        $this->assertStringContainsString('production_import_allowed_must_be_false_for_phase_0', $blocked);
        $this->assertStringContainsString('staging_preview_allowed_must_be_true_for_phase_0', $blocked);
    }

    public function test_it_blocks_1r_c_if_treated_as_full_replacement(): void
    {
        $this->skipWhenBatchCMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $validator = app(EnneagramAssetItemStreamValidator::class);
        $stream = $loader->load($this->batchCPath());
        $stream['metadata']['replacement_policy']['mode'] = 'full_replacement';
        $stream['metadata']['import_policy'] = 'production_full_replacement';
        $stream['metadata']['preflight_self_check']['production_import_allowed'] = true;
        $stream['metadata']['preflight_self_check']['staging_merge_preview_allowed'] = false;

        $report = $validator->validate($stream);
        $blocked = implode('|', $report['blocked_reasons']);

        $this->assertSame('FAIL', $report['status']);
        $this->assertStringContainsString('full_replacement_blocked', $blocked);
        $this->assertStringContainsString('batch_1r_c_requires_additive_branch_expansion_mode', $blocked);
        $this->assertStringContainsString('production_import_allowed_must_be_false_for_phase_0', $blocked);
        $this->assertStringContainsString('staging_preview_allowed_must_be_true_for_phase_0', $blocked);
    }

    public function test_it_blocks_1r_d_if_treated_as_full_replacement(): void
    {
        $this->skipWhenBatchDMissing();

        $loader = app(EnneagramAssetItemStreamLoader::class);
        $validator = app(EnneagramAssetItemStreamValidator::class);
        $stream = $loader->load($this->batchDPath());
        $stream['metadata']['replacement_policy']['mode'] = 'full_replacement';
        $stream['metadata']['import_policy'] = 'production_full_replacement';
        $stream['metadata']['preflight_self_check']['production_import_allowed'] = true;
        $stream['metadata']['preflight_self_check']['staging_merge_preview_allowed'] = false;

        $report = $validator->validate($stream);
        $blocked = implode('|', $report['blocked_reasons']);

        $this->assertSame('FAIL', $report['status']);
        $this->assertStringContainsString('full_replacement_blocked', $blocked);
        $this->assertStringContainsString('batch_1r_d_requires_additive_branch_expansion_mode', $blocked);
        $this->assertStringContainsString('production_import_allowed_must_be_false_for_phase_0', $blocked);
        $this->assertStringContainsString('staging_preview_allowed_must_be_true_for_phase_0', $blocked);
    }
}

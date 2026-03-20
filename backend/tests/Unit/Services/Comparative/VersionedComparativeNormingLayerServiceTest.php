<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Comparative;

use App\Services\Comparative\VersionedComparativeNormingLayerService;
use Tests\TestCase;

final class VersionedComparativeNormingLayerServiceTest extends TestCase
{
    public function test_it_builds_privacy_safe_mbti_comparative_contract(): void
    {
        $service = app(VersionedComparativeNormingLayerService::class);

        $comparative = $service->buildForMbti(
            [
                'type_code' => 'INTJ-A',
                'dominant_axes' => [
                    ['axis' => 'EI'],
                ],
                'axis_vector' => [
                    'EI' => ['pct' => 67],
                ],
                'boundary_flags' => [
                    'EI' => true,
                    'AT' => true,
                ],
                'privacy_contract_v1' => [
                    'consent_scope' => [
                        'norming_anonymized_only' => true,
                    ],
                ],
            ],
            [
                'norms' => [
                    'version_id' => 'norm_2026_02',
                    'metrics' => [
                        'EI' => [
                            'score_int' => 67,
                            'percentile' => 0.73,
                            'over_percent' => 73,
                        ],
                    ],
                ],
            ],
            [
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
            ]
        );

        $this->assertSame('comparative.norming.v1', $comparative['version']);
        $this->assertSame(true, $comparative['enabled']);
        $this->assertSame('EI', data_get($comparative, 'percentile.metric_key'));
        $this->assertSame(73, data_get($comparative, 'percentile.value'));
        $this->assertSame('same_type.boundary_axes', data_get($comparative, 'same_type_contrast.key'));
        $this->assertSame('norm_2026_02', data_get($comparative, 'norming_version'));
        $this->assertSame('CN_MAINLAND.zh-CN.anonymized_population', data_get($comparative, 'norming_scope'));
        $this->assertSame('anonymized_norms_table', data_get($comparative, 'norming_source'));
        $this->assertNotSame('', trim((string) data_get($comparative, 'comparative_fingerprint')));
    }

    public function test_it_falls_back_to_big5_projection_percentiles(): void
    {
        $service = app(VersionedComparativeNormingLayerService::class);

        $comparative = $service->buildForBigFive(
            [
                'dominant_traits' => [
                    [
                        'key' => 'O',
                        'label' => 'Openness',
                        'percentile' => 81,
                    ],
                ],
                'variant_keys' => ['profile:explorer', 'band:o.high'],
            ],
            [
                'norms' => [
                    'norms_version' => 'big5_norm_2026_01',
                    'group_id' => 'CN_MAINLAND.zh-CN.all',
                    'source_id' => 'big5_seed',
                ],
            ],
            [
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
            ]
        );

        $this->assertSame(true, $comparative['enabled']);
        $this->assertSame('O', data_get($comparative, 'percentile.metric_key'));
        $this->assertSame(81, data_get($comparative, 'percentile.value'));
        $this->assertSame('same_type.profile_family', data_get($comparative, 'same_type_contrast.key'));
        $this->assertSame('big5_norm_2026_01', data_get($comparative, 'norming_version'));
        $this->assertSame('CN_MAINLAND.zh-CN.all', data_get($comparative, 'norming_scope'));
        $this->assertSame('big5_seed', data_get($comparative, 'norming_source'));
    }
}

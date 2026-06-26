<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report\Pdf\Mbti;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Pdf\Mbti\MbtiPdfPayloadBuilder;
use Tests\TestCase;

final class MbtiPdfPayloadBuilderTest extends TestCase
{
    public function test_builds_reader_safe_payload_from_mbti_content_authority(): void
    {
        $attempt = new Attempt([
            'id' => 'attempt-should-not-appear',
            'scale_code' => 'MBTI',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'pack_id' => 'default',
            'dir_version' => 'MBTI-CN-v0.3',
        ]);
        $result = new Result([
            'type_code' => 'ISFP-T',
            'scores_pct' => [
                'EI' => 82,
                'SN' => 65,
                'TF' => 41,
                'JP' => 37,
                'AT' => 29,
            ],
            'axis_states' => [
                'EI' => 'strong',
                'SN' => 'clear',
                'TF' => 'moderate',
                'JP' => 'clear',
                'AT' => 'strong',
            ],
            'result_json' => [
                'type_code' => 'ISFP-T',
                'raw_score' => 999,
                'debug' => ['unsafe' => true],
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 82,
                        'SN' => 65,
                        'TF' => 41,
                        'JP' => 37,
                        'AT' => 29,
                    ],
                ],
            ],
        ]);

        /** @var MbtiPdfPayloadBuilder $builder */
        $builder = app(MbtiPdfPayloadBuilder::class);
        $envelope = $builder->build($attempt, $result);
        $payload = $envelope[MbtiPdfPayloadBuilder::PAYLOAD_KEY] ?? null;

        $this->assertIsArray($payload);
        $this->assertSame(MbtiPdfPayloadBuilder::SCHEMA_VERSION, $payload['schema_version'] ?? null);
        $this->assertSame('pdf', $payload['surface_key'] ?? null);
        $this->assertSame('backend_mbti_content_package_and_result_projection', data_get($payload, 'adapter_policy.source'));
        $this->assertSame(false, data_get($payload, 'adapter_policy.frontend_authored_body_allowed'));
        $this->assertSame('ISFP-T', data_get($payload, 'type.type_code'));
        $this->assertNotEmpty(data_get($payload, 'type.short_summary'));
        $this->assertCount(5, $payload['axis_scores'] ?? []);
        $this->assertNotEmpty($payload['highlights'] ?? []);
        $this->assertNotEmpty($payload['sections'] ?? []);
    }

    public function test_payload_filters_internal_and_raw_fields_recursively(): void
    {
        $attempt = new Attempt([
            'id' => 'attempt-should-not-appear',
            'scale_code' => 'MBTI',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'pack_id' => 'default',
            'dir_version' => 'MBTI-CN-v0.3',
        ]);
        $result = new Result([
            'type_code' => 'INTJ-A',
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'result_json' => [
                'attempt_id' => 'attempt-should-not-appear',
                'raw_scores' => ['unsafe' => true],
                'quality' => ['level' => 'internal'],
                'source_trace' => ['/private/internal/path'],
            ],
        ]);

        /** @var MbtiPdfPayloadBuilder $builder */
        $builder = app(MbtiPdfPayloadBuilder::class);
        $encoded = json_encode($builder->build($attempt, $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($encoded);
        foreach ([
            'attempt-should-not-appear',
            'raw_score',
            'raw_scores',
            'source_trace',
            'quality_level',
            '/private/internal/path',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
}

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
        $this->assertSame('zh-CN', data_get($payload, 'document.language'));
        $this->assertSame([
            'type_portrait',
            'core_traits',
            'dimension_explanation',
            'career_direction',
            'growth_plan',
            'relationships_communication',
            'use_boundaries',
        ], array_column((array) data_get($payload, 'document.chapters', []), 'chapter_key'));
        $this->assertStringContainsString('职业探索', (string) data_get($payload, 'document.subtitle'));
        $this->assertStringContainsString('医疗诊断', json_encode(data_get($payload, 'document.chapters'), JSON_UNESCAPED_UNICODE));
    }

    public function test_builds_english_document_depth_without_chinese_machine_copy(): void
    {
        $attempt = new Attempt([
            'id' => 'attempt-should-not-appear',
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'GLOBAL',
            'pack_id' => 'default',
            'dir_version' => 'MBTI-CN-v0.3',
        ]);
        $result = new Result([
            'type_code' => 'ENTJ-A',
            'scores_pct' => [
                'EI' => 74,
                'SN' => 58,
                'TF' => 63,
                'JP' => 79,
                'AT' => 66,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'moderate',
                'TF' => 'clear',
                'JP' => 'strong',
                'AT' => 'moderate',
            ],
        ]);

        /** @var MbtiPdfPayloadBuilder $builder */
        $builder = app(MbtiPdfPayloadBuilder::class);
        $payload = $builder->build($attempt, $result)[MbtiPdfPayloadBuilder::PAYLOAD_KEY] ?? [];
        $document = is_array($payload) ? (array) ($payload['document'] ?? []) : [];
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame('en', $document['language'] ?? null);
        $this->assertSame('MBTI Full Personality Report', $document['title'] ?? null);
        $this->assertStringContainsString('Career direction', $encoded);
        $this->assertStringContainsString('Growth plan', $encoded);
        $this->assertStringContainsString('Relationships and communication', $encoded);
        $this->assertStringContainsString('not a medical diagnosis', $encoded);
        $this->assertStringNotContainsString('职业方向', $encoded);
        $this->assertStringNotContainsString('医疗诊断', $encoded);
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

    public function test_document_depth_keeps_required_pdf_chapters(): void
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
            'type_code' => 'INFJ-T',
            'scores_pct' => [
                'EI' => 88,
                'SN' => 72,
                'TF' => 61,
                'JP' => 69,
                'AT' => 45,
            ],
        ]);

        /** @var MbtiPdfPayloadBuilder $builder */
        $builder = app(MbtiPdfPayloadBuilder::class);
        $chapters = data_get($builder->build($attempt, $result), 'mbti_pdf_payload.document.chapters');
        $this->assertIsArray($chapters);

        foreach ($chapters as $chapter) {
            $this->assertIsArray($chapter);
            $this->assertNotEmpty($chapter['title'] ?? null);
            $this->assertNotEmpty($chapter['body'] ?? null);
            $this->assertNotEmpty($chapter['source_section_keys'] ?? null);
        }

        $encoded = json_encode($chapters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('类型画像', $encoded);
        $this->assertStringContainsString('核心特质', $encoded);
        $this->assertStringContainsString('维度解释', $encoded);
        $this->assertStringContainsString('职业方向', $encoded);
        $this->assertStringContainsString('成长建议', $encoded);
        $this->assertStringContainsString('关系与沟通', $encoded);
        $this->assertStringContainsString('使用边界', $encoded);
    }
}

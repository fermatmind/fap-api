<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\Mbti;

use App\Services\Legacy\Mbti\Report\LegacyMbtiReportPayloadBuilder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyMbtiReportPayloadBuilderTest extends TestCase
{
    private string $packsRoot;
    private string $contentDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = storage_path('framework/testing/legacy_mbti_builder_' . uniqid('', true));
        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.root', $this->packsRoot);

        $packDir = $this->packsRoot . '/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        $this->contentDir = 'default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        File::ensureDirectoryExists($packDir);

        $this->writeJson($packDir . '/report_highlights_templates.json', [
            'templates' => [],
            'rules' => [
                'min_items' => 3,
            ],
        ]);

        $this->writeJson($packDir . '/report_highlights_overrides.json', [
            'items' => [],
        ]);

        $this->writeJson($packDir . '/report_highlights.json', [
            'items' => [
                'INTJ-A' => [
                    [
                        'id' => 'hl.seed.1',
                        'dim' => 'EI',
                        'side' => 'I',
                        'level' => 'clear',
                        'title' => 'Seed',
                        'text' => 'Seed text',
                        'tags' => ['kind:strength'],
                        'tips' => ['tip'],
                    ],
                ],
            ],
        ]);

        $this->writeJson($packDir . '/report_borderline_templates.json', [
            'items' => [
                'EI' => ['title' => 'EI', 'text' => 'ei', 'examples' => [], 'suggestions' => []],
                'SN' => ['title' => 'SN', 'text' => 'sn', 'examples' => [], 'suggestions' => []],
                'TF' => ['title' => 'TF', 'text' => 'tf', 'examples' => [], 'suggestions' => []],
                'JP' => ['title' => 'JP', 'text' => 'jp', 'examples' => [], 'suggestions' => []],
                'AT' => ['title' => 'AT', 'text' => 'at', 'examples' => [], 'suggestions' => []],
            ],
        ]);

        $this->writeJson($packDir . '/report_roles.json', [
            'items' => [
                'NT' => ['code' => 'NT', 'title' => 'Role NT'],
            ],
        ]);

        $this->writeJson($packDir . '/report_strategies.json', [
            'items' => [
                'IA' => ['code' => 'IA', 'title' => 'Strategy IA'],
                'IT' => ['code' => 'IT', 'title' => 'Strategy IT'],
            ],
        ]);

        $this->writeJson($packDir . '/report_recommended_reads.json', [
            'items' => [
                'by_type' => [
                    'INTJ-A' => [
                        ['id' => 'read.1', 'title' => 'Read 1', 'url' => 'https://example.com/1', 'priority' => 10],
                    ],
                ],
                'by_role' => [],
                'by_strategy' => [],
                'by_top_axis' => [],
                'fallback' => [],
            ],
            'rules' => [
                'max_items' => 8,
                'fill_order' => ['by_type', 'by_role', 'by_strategy', 'by_top_axis', 'fallback'],
                'bucket_quota' => ['by_type' => 8],
            ],
        ]);

        foreach (['traits', 'career', 'growth', 'relationships'] as $section) {
            $this->writeJson($packDir . '/report_cards_' . $section . '.json', [
                'items' => [],
                'rules' => [
                    'min_cards' => 1,
                    'target_cards' => 1,
                    'max_cards' => 3,
                ],
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_build_report_parts_has_expected_keys_and_types(): void
    {
        /** @var LegacyMbtiReportPayloadBuilder $builder */
        $builder = app(LegacyMbtiReportPayloadBuilder::class);

        $out = $builder->buildLegacyMbtiReportParts([
            'contentDir' => $this->contentDir,
            'scores' => [
                'EI' => 40,
                'SN' => 72,
                'TF' => 65,
                'JP' => 70,
                'AT' => 52,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'borderline',
            ],
            'typeProfile' => [
                'type_code' => 'INTJ-A',
                'type_name' => 'INTJ-A',
                'tagline' => 'Architect',
                'keywords' => ['logic', 'strategy', 'focus'],
            ],
            'opts' => [
                'recommended_reads_max' => 8,
            ],
        ]);

        $this->assertIsArray($out);
        $this->assertIsArray($out['highlights'] ?? null);
        $this->assertTrue($this->isListArray($out['highlights'] ?? []));

        $this->assertArrayHasKey('identity_layer', $out);
        $this->assertTrue(is_array($out['identity_layer']) || $out['identity_layer'] === null);

        $this->assertIsArray($out['recommended_reads'] ?? null);
        $this->assertTrue($this->isListArray($out['recommended_reads'] ?? []));
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function isListArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}

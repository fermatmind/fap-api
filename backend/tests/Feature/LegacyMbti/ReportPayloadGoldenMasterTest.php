<?php

declare(strict_types=1);

namespace Tests\Feature\LegacyMbti;

use App\Services\Legacy\Mbti\Report\LegacyMbtiReportPayloadBuilder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ReportPayloadGoldenMasterTest extends TestCase
{
    private string $packsRoot;

    private string $contentDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = storage_path('framework/testing/legacy_mbti_golden_' . uniqid('', true));
        File::ensureDirectoryExists($this->packsRoot);
        config()->set('content_packs.root', $this->packsRoot);

        $this->contentDir = 'default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2';
        $packDir = $this->packsRoot . '/' . $this->contentDir;
        File::ensureDirectoryExists($packDir);

        $this->writeJson($packDir . '/report_highlights_templates.json', [
            'templates' => [],
            'rules' => ['min_items' => 3],
        ]);
        $this->writeJson($packDir . '/report_highlights_overrides.json', ['items' => []]);
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
        $this->writeJson($packDir . '/report_roles.json', ['items' => ['NT' => ['code' => 'NT', 'title' => 'Role NT']]]);
        $this->writeJson($packDir . '/report_strategies.json', ['items' => ['IA' => ['code' => 'IA', 'title' => 'Strategy IA']]]);
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
                'rules' => ['min_cards' => 1, 'target_cards' => 1, 'max_cards' => 3],
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_payload_matches_golden_master_fixture(): void
    {
        config()->set('features.legacy_mbti_report_payload_v2', true);

        $actual = $this->buildPayload();
        $fixturePath = base_path('tests/Fixtures/legacy_mbti_payload_expected.json');
        $expectedRaw = file_get_contents($fixturePath);

        $this->assertIsString($expectedRaw, 'golden fixture missing');
        $expected = json_decode($expectedRaw, true);

        $this->assertIsArray($expected, 'golden fixture invalid json');
        $this->assertSame($expected, $actual);
    }

    public function test_section_contract_shape_is_stable(): void
    {
        $payload = $this->buildPayload();

        $this->assertArrayHasKey('highlights', $payload);
        $this->assertArrayHasKey('cards', $payload);
        $this->assertArrayHasKey('recommended_reads', $payload);
        $this->assertArrayHasKey('identity_layer', $payload);

        $this->assertIsArray($payload['highlights']);
        $this->assertIsArray($payload['cards']);
        $this->assertIsArray($payload['recommended_reads']);
        $this->assertTrue(is_array($payload['identity_layer']) || $payload['identity_layer'] === null);
    }

    public function test_cards_sections_exist_and_are_arrays(): void
    {
        $payload = $this->buildPayload();
        $cards = $payload['cards'] ?? null;

        $this->assertIsArray($cards);

        foreach (['traits', 'career', 'growth', 'relationships'] as $section) {
            $this->assertArrayHasKey($section, $cards);
            $this->assertIsArray($cards[$section]);
        }
    }

    public function test_highlights_items_keep_minimum_shape(): void
    {
        $payload = $this->buildPayload();
        $items = $payload['highlights'] ?? [];
        $this->assertIsArray($items);
        $this->assertGreaterThanOrEqual(1, count($items));

        $first = $items[0] ?? null;
        $this->assertIsArray($first);
        $this->assertIsString($first['id'] ?? null);
        $this->assertIsString($first['title'] ?? null);
        $this->assertIsString($first['text'] ?? null);
    }

    public function test_recommended_reads_item_shape_is_stable(): void
    {
        $payload = $this->buildPayload();
        $items = $payload['recommended_reads'] ?? null;
        $this->assertIsArray($items);
        $this->assertGreaterThanOrEqual(1, count($items));

        $first = $items[0] ?? null;
        $this->assertIsArray($first);
        $this->assertIsString($first['id'] ?? null);
        $this->assertIsString($first['title'] ?? null);
        $this->assertIsString($first['url'] ?? null);
    }

    public function test_borderline_and_identity_layer_contract_is_stable(): void
    {
        $payload = $this->buildPayload();

        $this->assertArrayHasKey('borderline', $payload);
        $this->assertArrayHasKey('identity_layer', $payload);
        $this->assertTrue(is_array($payload['borderline']) || $payload['borderline'] === null);
        $this->assertTrue(is_array($payload['identity_layer']) || $payload['identity_layer'] === null);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(): array
    {
        /** @var LegacyMbtiReportPayloadBuilder $builder */
        $builder = app(LegacyMbtiReportPayloadBuilder::class);

        return $builder->buildLegacyMbtiReportParts([
            'contentDir' => $this->contentDir,
            'scores' => ['EI' => 40, 'SN' => 72, 'TF' => 65, 'JP' => 70, 'AT' => 52],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'borderline'],
            'typeProfile' => [
                'type_code' => 'INTJ-A',
                'type_name' => 'INTJ-A',
                'tagline' => 'Architect',
                'keywords' => ['logic', 'strategy', 'focus'],
            ],
            'opts' => ['recommended_reads_max' => 8],
        ]);
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

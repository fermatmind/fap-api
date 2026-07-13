<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\Mbti;

use App\Services\Legacy\Mbti\Report\LegacyMbtiReportAssetRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyMbtiReportAssetRepositoryTest extends TestCase
{
    private string $packsRoot;

    private string $contentDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = storage_path('framework/testing/legacy_mbti_asset_repo_'.uniqid('', true));
        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.root', $this->packsRoot);

        $packDir = $this->packsRoot.'/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        $this->contentDir = 'default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        File::ensureDirectoryExists($packDir);

        file_put_contents($packDir.'/report_cards_traits.json', json_encode([
            'items' => [
                [
                    'id' => 'traits_1',
                    'type_code' => 'INTJ-A',
                    'title' => 'Traits',
                    'desc' => 'desc',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($packDir.'/report_highlights_templates.json', json_encode([
            'schema' => 'fap.report.highlights.v1',
            'templates' => [],
            'rules' => [],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_load_asset_items_returns_indexed_items(): void
    {
        /** @var LegacyMbtiReportAssetRepository $repo */
        $repo = app(LegacyMbtiReportAssetRepository::class);

        $items = $repo->loadAssetItems($this->contentDir, 'report_cards_traits.json', [
            'primaryIndexKey' => 'type_code',
        ]);

        $this->assertIsArray($items);
        $this->assertArrayHasKey('INTJ-A', $items);
    }

    public function test_finalize_highlights_schema_fills_required_keys(): void
    {
        /** @var LegacyMbtiReportAssetRepository $repo */
        $repo = app(LegacyMbtiReportAssetRepository::class);

        $out = $repo->finalizeHighlightsSchema([
            [
                'id' => '',
                'title' => '',
                'text' => '',
                'tips' => [],
                'tags' => [],
            ],
        ], 'INTJ-A');

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('id', $out[0]);
        $this->assertArrayHasKey('kind', $out[0]);
        $this->assertArrayHasKey('title', $out[0]);
        $this->assertArrayHasKey('text', $out[0]);
        $this->assertArrayHasKey('tips', $out[0]);
        $this->assertArrayHasKey('tags', $out[0]);
        $this->assertIsArray($out[0]['tips']);
        $this->assertIsArray($out[0]['tags']);
    }

    public function test_asset_reads_do_not_reuse_payloads_across_content_roots(): void
    {
        /** @var LegacyMbtiReportAssetRepository $repo */
        $repo = app(LegacyMbtiReportAssetRepository::class);
        $first = $repo->loadAssetItems($this->contentDir, 'report_cards_traits.json', [
            'primaryIndexKey' => 'type_code',
        ]);
        $this->assertSame('Traits', $first['INTJ-A']['title'] ?? null);

        $secondRoot = storage_path('framework/testing/legacy_mbti_asset_repo_second_'.uniqid('', true));
        $secondPackDir = $secondRoot.'/'.$this->contentDir;
        File::ensureDirectoryExists($secondPackDir);
        file_put_contents($secondPackDir.'/report_cards_traits.json', json_encode([
            'items' => [[
                'id' => 'traits_2',
                'type_code' => 'INTJ-A',
                'title' => 'Traits from second root',
                'desc' => 'desc',
            ]],
        ], JSON_UNESCAPED_UNICODE));

        try {
            config()->set('content_packs.root', $secondRoot);
            $second = $repo->loadAssetItems($this->contentDir, 'report_cards_traits.json', [
                'primaryIndexKey' => 'type_code',
            ]);

            $this->assertSame('Traits from second root', $second['INTJ-A']['title'] ?? null);
        } finally {
            File::deleteDirectory($secondRoot);
        }
    }
}

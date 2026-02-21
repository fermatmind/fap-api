<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ContentStoreContractTest extends TestCase
{
    use RefreshDatabase;

    private string $packsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = storage_path('framework/testing/content_store_contract_' . uniqid('', true));
        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.root', $this->packsRoot);
        config()->set('content.packs_root', $this->packsRoot);
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');
        config()->set('cache.default', 'array');

        $this->seedPack('MBTI-CN-v0.3-A', [
            'schema' => 'fap.report.highlights.v1',
            'templates' => ['v' => '0.3.1'],
            'rules' => ['min_items' => 3],
        ]);

        $this->seedPack('MBTI-CN-v0.3-B', [
            'schema' => 'fap.report.highlights.v1',
            'templates' => ['v' => '0.3.2'],
            'rules' => ['min_items' => 3],
        ]);

        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3-A');
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.MBTI-CN-v0.3-A');

        $this->app->forgetInstance(ContentStore::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_same_pack_dir_locale_returns_consistent_content(): void
    {
        $store = app(ContentStore::class);

        $first = $store->loadHighlights();
        $second = $store->loadHighlights();

        $this->assertSame($first, $second);
        $this->assertSame('0.3.1', (string) ($first['templates']['v'] ?? ''));
    }

    public function test_cache_hit_keeps_second_read_stable_when_source_changes(): void
    {
        $store = app(ContentStore::class);
        $first = $store->loadHighlights();

        $path = $this->packPath('MBTI-CN-v0.3-A') . '/report_highlights_templates.json';
        file_put_contents($path, json_encode([
            'schema' => 'fap.report.highlights.v1',
            'templates' => ['v' => 'mutated'],
            'rules' => ['min_items' => 3],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $second = $store->loadHighlights();

        $this->assertSame($first, $second);
        $this->assertSame('0.3.1', (string) ($second['templates']['v'] ?? ''));
    }

    public function test_dir_version_change_invalidates_cache_and_returns_new_content(): void
    {
        $storeA = app(ContentStore::class);
        $a = $storeA->loadHighlights();

        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3-B');
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.MBTI-CN-v0.3-B');
        $this->app->forgetInstance(ContentStore::class);

        $storeB = app(ContentStore::class);
        $b = $storeB->loadHighlights();

        $this->assertNotSame((string) ($a['templates']['v'] ?? ''), (string) ($b['templates']['v'] ?? ''));
        $this->assertSame('0.3.2', (string) ($b['templates']['v'] ?? ''));
    }

    public function test_missing_resource_returns_empty_doc_contract(): void
    {
        $this->seedPack('MBTI-CN-v0.3-missing', null, includeHighlights: false);
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3-missing');
        config()->set('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.MBTI-CN-v0.3-missing');
        $this->app->forgetInstance(ContentStore::class);

        $store = app(ContentStore::class);
        $doc = $store->loadHighlights();

        $this->assertSame([], $doc);
    }

    public function test_load_report_overrides_returns_empty_rules_when_missing(): void
    {
        $store = app(ContentStore::class);
        $doc = $store->loadReportOverrides();

        $this->assertIsArray($doc);
        $this->assertSame([], $doc['rules'] ?? null);
        $this->assertSame([], $doc['__src_chain'] ?? null);
    }

    public function test_load_cards_doc_returns_default_contract_when_file_missing(): void
    {
        $store = app(ContentStore::class);
        $doc = $store->loadCardsDoc('non_existing_section');

        $this->assertIsArray($doc);
        $this->assertIsArray($doc['items'] ?? null);
        $this->assertIsArray($doc['rules'] ?? null);
    }

    private function seedPack(string $version, ?array $highlightsDoc, bool $includeHighlights = true): void
    {
        $packDir = $this->packPath($version);
        File::ensureDirectoryExists($packDir);

        $manifest = [
            'schema_version' => 'pack-manifest@v1',
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => $version,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.' . $version,
            'assets' => [
                'highlights' => $includeHighlights ? ['report_highlights_templates.json'] : [],
            ],
            'schemas' => [],
            'capabilities' => [],
            'fallback' => [],
        ];

        file_put_contents(
            $packDir . '/manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($includeHighlights && is_array($highlightsDoc)) {
            file_put_contents(
                $packDir . '/report_highlights_templates.json',
                json_encode($highlightsDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        file_put_contents(
            $packDir . '/report_cards_traits.json',
            json_encode(['items' => [], 'rules' => ['min_cards' => 2, 'target_cards' => 3, 'max_cards' => 6]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function packPath(string $version): string
    {
        return $this->packsRoot . '/default/CN_MAINLAND/zh-CN/' . $version;
    }
}

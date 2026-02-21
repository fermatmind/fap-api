<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\Mbti;

use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyMbtiPackRepositoryTest extends TestCase
{
    private string $packsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packsRoot = storage_path('framework/testing/legacy_mbti_pack_repo_' . uniqid('', true));
        File::ensureDirectoryExists($this->packsRoot);

        config()->set('content_packs.root', $this->packsRoot);
        config()->set('content_packs.default_region', 'CN_MAINLAND');
        config()->set('content_packs.default_locale', 'zh-CN');
        config()->set('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        $packDir = $this->packsRoot . '/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3';
        File::ensureDirectoryExists($packDir);

        file_put_contents($packDir . '/manifest.json', json_encode([
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'content_package_version' => 'v0.3',
            'assets' => [
                'questions' => ['questions.json'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents($packDir . '/questions.json', json_encode([
            'items' => [
                [
                    'question_id' => 'q1',
                    'order' => 1,
                    'dimension' => 'EI',
                    'text' => 'Q1',
                    'is_active' => true,
                    'options' => [
                        ['code' => 'A', 'text' => 'A', 'score' => 1],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packsRoot);
        parent::tearDown();
    }

    public function test_load_manifest_and_questions_docs(): void
    {
        /** @var LegacyMbtiPackRepository $repo */
        $repo = app(LegacyMbtiPackRepository::class);
        $contentDir = $repo->resolveContentDir(null, 'MBTI-CN-v0.3', 'CN_MAINLAND', 'zh-CN');

        $manifest = $repo->loadManifestDoc($contentDir);
        $questions = $repo->loadQuestionsDoc($contentDir);

        $this->assertIsArray($manifest);
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', (string) ($manifest['pack_id'] ?? ''));

        $this->assertIsArray($questions);
        $this->assertIsArray($questions['items'] ?? null);
        $this->assertSame('q1', (string) ($questions['items'][0]['question_id'] ?? ''));
    }

    public function test_returns_null_when_file_missing(): void
    {
        /** @var LegacyMbtiPackRepository $repo */
        $repo = app(LegacyMbtiPackRepository::class);
        $contentDir = $repo->resolveContentDir(null, 'MBTI-CN-v0.3', 'CN_MAINLAND', 'zh-CN');

        $missing = $repo->loadJsonFromPack($contentDir, 'not_exists.json');

        $this->assertNull($missing);
    }
}

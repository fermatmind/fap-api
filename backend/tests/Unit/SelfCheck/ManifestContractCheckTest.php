<?php

declare(strict_types=1);

namespace Tests\Unit\SelfCheck;

use App\Services\SelfCheck\Checks\ManifestContractCheck;
use App\Services\SelfCheck\SelfCheckContext;
use App\Services\SelfCheck\SelfCheckIo;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ManifestContractCheckTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('framework/testing/selfcheck_manifest_' . uniqid('', true));
        File::ensureDirectoryExists($this->workDir);

        $manifest = [
            'schema_version' => 'pack-manifest@v1',
            'scale_code' => 'MBTI',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'content_package_version' => 'v0.3',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'assets' => [],
            'schemas' => [],
            'capabilities' => [],
            'fallback' => [],
        ];

        file_put_contents($this->workDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_manifest_contract_check_passes_for_minimal_valid_manifest(): void
    {
        $manifestPath = $this->workDir . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $ctx = SelfCheckContext::fromCommandOptions(['path' => $manifestPath]);
        $ctx->withManifestPath($manifestPath)->withManifest(is_array($manifest) ? $manifest : []);

        $check = new ManifestContractCheck();
        $io = new SelfCheckIo();

        $result = $check->run($ctx, $io);

        $this->assertTrue($result->isOk());
        $this->assertSame([], $result->errors);
    }
}

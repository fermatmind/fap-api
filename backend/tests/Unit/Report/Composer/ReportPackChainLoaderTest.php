<?php

declare(strict_types=1);

namespace Tests\Unit\Report\Composer;

use App\Services\Report\Composer\ReportPackChainLoader;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReportPackChainLoaderTest extends TestCase
{
    public function test_pack_id_to_dir_matches_legacy_mapping(): void
    {
        Storage::fake('local');

        /** @var ReportPackChainLoader $loader */
        $loader = app(ReportPackChainLoader::class);

        $dir = $loader->packIdToDir('MBTI.global.en.v0.2.1-TEST');

        $this->assertSame('MBTI/GLOBAL/en/v0.2.1-TEST', $dir);
    }
}

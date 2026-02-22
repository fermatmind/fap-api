<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Big5ComplianceLintTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_compile_outputs_legal_compiled_asset(): void
    {
        $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $legalCompiled = $loader->readCompiledJson('legal.compiled.json', 'v1');

        $this->assertIsArray($legalCompiled);
        $this->assertSame('big5.legal.compiled.v1', (string) ($legalCompiled['schema'] ?? ''));
        $this->assertNotSame('', (string) data_get($legalCompiled, 'legal.disclaimer_version', ''));
        $this->assertNotSame('', (string) data_get($legalCompiled, 'legal.hash', ''));
        $this->assertNotSame('', (string) data_get($legalCompiled, 'legal.texts.zh-CN', ''));
        $this->assertNotSame('', (string) data_get($legalCompiled, 'legal.texts.en', ''));
    }

    public function test_big5_compliance_lint_fails_when_prohibited_phrase_is_present(): void
    {
        $rawPath = base_path('content_packs/BIG5_OCEAN/v1/raw/legal/disclaimer.json');
        $original = File::get($rawPath);

        try {
            $doc = json_decode($original, true);
            $this->assertIsArray($doc);
            $doc['texts']['en'] = 'You are diagnosed with a disorder and should not wait.';
            File::put(
                $rawPath,
                (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
            );

            $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(1);
        } finally {
            File::put($rawPath, $original);
        }
    }
}


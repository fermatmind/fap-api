<?php

declare(strict_types=1);

namespace Tests\Feature\BigFive;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class BigFiveEn52ReleasePackageTest extends TestCase
{
    private string $sourceRoot;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceRoot = dirname(__DIR__, 4).'/generated/big-five-en52-translation';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_compiler_emits_exact_locked_text_only_en52_release(): void
    {
        $result = app(BigFiveEn52PackageCompiler::class)->compile($this->sourceRoot);
        $package = $result['release_package'];

        $this->assertSame(BigFiveEn52PackageCompiler::SCHEMA_VERSION, $package['schema_version']);
        $this->assertSame(BigFiveEn52PackageCompiler::RELEASE_ID, $package['release_id']);
        $this->assertSame('en-US', $package['editorial_locale']);
        $this->assertSame('en', $package['locale']);
        $this->assertSame(52, $package['asset_count']);
        $this->assertSame(170, $package['claims_count']);
        $this->assertSame(261, $package['faq_count']);
        $this->assertSame(11, $package['source_count']);
        $this->assertSame(0, $package['legacy_alias_content_page_count']);
        $this->assertFalse($package['media_supported']);
        $this->assertFalse($package['search_submit_allowed']);
        $this->assertSame(BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256, $package['input_hashes']['source_content_sha256']);
        $this->assertSame(BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256, $package['input_hashes']['cohort_snapshot_sha256']);
        $this->assertSame(BigFiveEn52PackageCompiler::INPUT_PACKAGE_PAYLOAD_SHA256, $package['input_hashes']['final_package_payload_sha256']);
        $this->assertSame(BigFiveEn52PackageCompiler::INPUT_PACKAGE_FILE_SHA256, $package['input_hashes']['final_package_file_sha256']);

        $assets = $package['assets'];
        $this->assertCount(52, $assets);
        $this->assertSame([
            'domain' => 5,
            'facet_detail' => 30,
            'facet_hub' => 1,
            'hub' => 1,
            'polarity' => 15,
        ], $package['family_counts']);

        $canonicals = [];
        $claims = 0;
        $faqs = 0;
        foreach ($assets as $descriptor) {
            $asset = $descriptor['asset'];
            app(PersonalityPublicContentAssetContract::class)->validateAsset($asset);
            $this->assertSame('en', $asset['locale']);
            $this->assertSame(
                BigFiveCanonicalRouteCatalog::expectedPath('en', $asset['entity_type'], $asset['entity_key']),
                $asset['canonical']['path'],
            );
            $this->assertSame($asset['canonical_path'], $asset['canonical']['path']);
            $this->assertSame('index,follow', $asset['robots']);
            $this->assertTrue($asset['is_public']);
            $this->assertTrue($asset['index_eligible']);
            $this->assertTrue($asset['sitemap_eligible']);
            $this->assertTrue($asset['llms_eligible']);
            $this->assertSame([], $asset['schema']);
            $this->assertSame([], $asset['hreflang']);
            $canonicals[] = $asset['canonical']['path'];
            $claims += count($descriptor['evidence_claims']);
            $faqs += count($asset['faq']);
        }
        $this->assertCount(52, array_unique($canonicals));
        $this->assertSame(170, $claims);
        $this->assertSame(261, $faqs);

        $json = $result['release_json'];
        $this->assertSame($package['package_payload_sha256'], hash('sha256', app(BigFiveEn52PackageCompiler::class)->stableJson(
            array_diff_key($package, ['package_payload_sha256' => true]),
        )));
        $this->assertSame($result['compile_report']['package_file_sha256'], hash('sha256', $json));
        $this->assertSame(BigFiveEn52PackageCompiler::RELEASE_PACKAGE_PAYLOAD_SHA256, $package['package_payload_sha256']);
        $this->assertSame(BigFiveEn52PackageCompiler::RELEASE_PACKAGE_FILE_SHA256, hash('sha256', $json));
        foreach (['hero_image', 'inline_media', 'og_image', 'twitter_image', '![', '<img', '<picture'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($json));
        }
        foreach ($this->legacyAliases() as $alias) {
            $this->assertDoesNotMatchRegularExpression(
                '#/en/personality/big-five/(?:[^"\s/]+/)?'.preg_quote($alias, '#').'(?=["\s]|$)#',
                $json,
            );
        }
    }

    public function test_two_clean_command_builds_are_byte_identical(): void
    {
        $first = $this->temporaryDirectory('build-a');
        $second = $this->temporaryDirectory('build-b');

        $this->artisan('personality:big-five-en52-package-build', [
            '--source' => $this->sourceRoot,
            '--output' => $first,
        ])->assertSuccessful();
        $this->artisan('personality:big-five-en52-package-build', [
            '--source' => $this->sourceRoot,
            '--output' => $second,
        ])->assertSuccessful();

        foreach (['release-package.json', 'compile-report.json', 'README.md'] as $file) {
            $this->assertSame(File::get($first.'/'.$file), File::get($second.'/'.$file), $file.' drifted');
        }
        $this->assertSame(
            BigFiveEn52PackageCompiler::RELEASE_ID,
            json_decode(File::get($first.'/release-package.json'), true, flags: JSON_THROW_ON_ERROR)['release_id'],
        );
    }

    public function test_locked_input_drift_fails_closed_before_compilation(): void
    {
        $copy = $this->temporaryDirectory('drift-source');
        File::copyDirectory($this->sourceRoot, $copy);
        File::append($copy.'/package-manifest.json', "\n");

        $this->expectException(RuntimeException::class);
        app(BigFiveEn52PackageCompiler::class)->compile($copy);
    }

    private function temporaryDirectory(string $suffix): string
    {
        $directory = sys_get_temp_dir().'/fap-api-en52-'.getmypid().'-'.$suffix.'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    /** @return list<string> */
    private function legacyAliases(): array
    {
        return [
            'emotional-stability',
            'high-agreeableness',
            'high-conscientiousness',
            'high-extraversion',
            'high-neuroticism',
            'high-openness',
            'low-agreeableness',
            'low-conscientiousness',
            'low-extraversion',
            'low-openness',
        ];
    }
}

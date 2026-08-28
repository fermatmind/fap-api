<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCurrentPackageCompiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Tests\TestCase;

final class CareerTenBlockCurrentPackageCompilerTest extends TestCase
{
    public function test_bilingual_presentation_v2_dry_compile_is_deterministic_and_zero_write(): void
    {
        ini_set('memory_limit', '2048M');
        $output = sys_get_temp_dir().'/career-presentation-v2-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($output, 0700));
        try {
            $this->artisan('career:ten-block-current-package-compile', [
                '--output-root' => $output,
                '--presentation-v2' => true,
            ])->assertSuccessful();

            $receipt = json_decode((string) file_get_contents($output.'/full-compile-receipt.json'), true, 512, JSON_THROW_ON_ERROR);
            $diff = json_decode((string) file_get_contents($output.'/package-diff-report.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1046, $receipt['career_count']);
            self::assertSame(2092, $receipt['locale_page_count']);
            self::assertSame(2, $receipt['enhanced_locale_page_count']);
            self::assertSame(2090, $receipt['legacy_locale_page_count']);
            self::assertSame(0, $receipt['database_writes']);
            self::assertSame(0, $receipt['cache_writes']);
            self::assertSame(0, $receipt['discoverability_writes']);
            self::assertSame(0, $diff['existing_component_content_changes']);
            self::assertSame(0, $diff['presentation_changes']);
            self::assertSame(0, $diff['component_order_changes']);
        } finally {
            foreach (glob($output.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($output);
        }
    }

    public function test_sharded_current_dry_compile_does_not_require_retired_top_level_registries(): void
    {
        $output = sys_get_temp_dir().'/career-ten-block-sharded-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($output, 0700));
        try {
            self::assertFileDoesNotExist(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/presentation-source-registry.json'));
            self::assertFileDoesNotExist(base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/structured-component-source-registry.json'));

            $this->artisan('career:ten-block-current-package-compile', [
                '--output-root' => $output,
                '--presentation-v2' => true,
            ])->assertSuccessful();

            self::assertFileExists($output.'/assets.jsonl');
            self::assertFileExists($output.'/manifest.json');
            self::assertFileDoesNotExist($output.'/presentation-source-registry.json');
            self::assertFileDoesNotExist($output.'/structured-component-source-registry.json');
            self::assertSame(
                'career.sharded_current.manifest.v1',
                json_decode((string) file_get_contents($output.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['contract_version'],
            );
        } finally {
            foreach (glob($output.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($output);
        }
    }

    public function test_current_rows_satisfy_recursive_public_negative_contract(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());

        app(CareerTenBlockCurrentPackageCompiler::class)->assertCandidatePublicContract($package['rows']);

        self::assertCount(1046, $package['rows']);
    }

    public function test_private_fields_and_forbidden_schema_types_fail_closed(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $row = $package['rows']['actors'];
        foreach ([
            static function (array &$candidate): void {
                $candidate['page_payload_json']['page']['en']['hero']['private_answers'] = ['secret'];
            },
            static function (array &$candidate): void {
                $candidate['structured_data_json']['forbidden'] = [
                    '@context' => 'https://schema.org', '@type' => 'JobPosting',
                ];
            },
            static function (array &$candidate): void {
                $candidate['structured_data_json']['forbidden'] = [
                    '@context' => 'https://schema.org', '@type' => 'Review',
                ];
            },
        ] as $mutation) {
            $candidate = $row;
            $mutation($candidate);
            try {
                app(CareerTenBlockCurrentPackageCompiler::class)->assertCandidatePublicContract(['actors' => $candidate]);
                self::fail('Expected recursive public contract rejection.');
            } catch (CareerTenBlockCompileFailure $failure) {
                self::assertSame('TEN_BLOCK_CURRENT_PUBLIC_CONTRACT_INVALID', $failure->safeCode);
            }
        }
    }

    public function test_accountants_boundary_notice_is_published_for_both_locales(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $row = $package['rows']['accountants-and-auditors'];

        self::assertContains('boundary_notice', $row['component_order_json']);
        foreach (['en', 'zh'] as $locale) {
            self::assertNotEmpty($row['page_payload_json']['page'][$locale]['boundary_notice']);
        }
    }

    public function test_it_fails_closed_when_a_same_locale_accountants_boundary_source_is_missing(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $pages = $package['rows']['accountants-and-auditors']['page_payload_json']['page'];
        unset($pages['en']['ai_impact_table']['explanation']['en']['boundary']);
        unset($pages['en']['boundary_notice']);

        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');

        app(CareerTenBlockCurrentPackageCompiler::class)->deriveAccountantsBoundaryNotices($pages);
    }

    /** @return array<string,array<string,mixed>> */
    private function rowsFromAssets(string $assets): array
    {
        $rows = [];
        foreach (explode("\n", trim($assets)) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[$row['canonical_slug']] = $row;
        }

        return $rows;
    }

    /** @return array{array<string,mixed>,array<string,string>} */
    private function accountantsAndRowHashes(string $assets): array
    {
        $accountants = null;
        $hashes = [];
        foreach (explode("\n", trim($assets)) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $slug = $row['canonical_slug'];
            $hashes[$slug] = CareerCurrentAuthorityPackage::hashValue($row);
            if ($slug === 'accountants-and-auditors') {
                $accountants = $row;
            }
            unset($row);
        }
        self::assertIsArray($accountants);

        return [$accountants, $hashes];
    }
}

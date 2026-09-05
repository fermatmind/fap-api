<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCurrentPackageCompiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Tests\TestCase;

final class CareerTenBlockCurrentPackageCompilerTest extends TestCase
{
    public function test_retired_compiler_cannot_rewrite_per_page_current_with_flat_assets(): void
    {
        $output = sys_get_temp_dir().'/career-retired-compile-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($output, 0700));
        $manifest = base_path(CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json');
        $before = hash_file('sha256', $manifest);
        try {
            foreach ([false, true] as $writeCurrent) {
                $this->artisan('career:ten-block-current-package-compile', [
                    '--output-root' => $output,
                    '--presentation-v2' => true,
                    '--write-current' => $writeCurrent,
                ])->expectsOutputToContain('FAIL_TEN_BLOCK_CURRENT_PACKAGE_COMPILE')->assertFailed();
                self::assertSame($before, hash_file('sha256', $manifest));
                self::assertSame([], glob($output.'/*'));
            }
        } finally {
            rmdir($output);
        }
    }

    public function test_legacy_transport_fixture_satisfies_recursive_public_negative_contract(): void
    {
        app(CareerTenBlockCurrentPackageCompiler::class)->assertCandidatePublicContract([
            'fixture-occupation' => $this->legacyRow(),
        ]);
        self::assertTrue(true);
    }

    public function test_private_fields_and_forbidden_schema_types_fail_closed(): void
    {
        $row = $this->legacyRow();
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

    public function test_accountants_boundary_notices_preserve_same_locale_sources(): void
    {
        $pages = $this->legacyRow()['page_payload_json']['page'];
        self::assertSame([
            'en' => ['Fixture caveat', 'Fixture boundary'],
            'zh' => ['测试限制', '测试边界'],
        ], app(CareerTenBlockCurrentPackageCompiler::class)->deriveAccountantsBoundaryNotices($pages));
    }

    public function test_it_fails_closed_when_a_same_locale_accountants_boundary_source_is_missing(): void
    {
        $pages = $this->legacyRow()['page_payload_json']['page'];
        unset($pages['en']['ai_impact_table']['explanation']['en']['boundary']);
        $this->expectException(CareerTenBlockCompileFailure::class);
        $this->expectExceptionMessage('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
        app(CareerTenBlockCurrentPackageCompiler::class)->deriveAccountantsBoundaryNotices($pages);
    }

    private function legacyRow(): array
    {
        return [
            'page_payload_json' => ['page' => [
                'en' => ['fermat_decision_card' => ['caveat' => 'Fixture caveat'], 'ai_impact_table' => ['explanation' => ['en' => ['boundary' => 'Fixture boundary']]]],
                'zh' => ['fermat_decision_card' => ['caveat' => '测试限制'], 'ai_impact_table' => ['explanation' => ['zh' => ['boundary' => '测试边界']]]],
            ]],
            'structured_data_json' => ['@type' => 'WebPage'],
        ];
    }
}

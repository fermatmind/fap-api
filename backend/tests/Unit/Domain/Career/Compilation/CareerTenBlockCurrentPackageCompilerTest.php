<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockCurrentPackageCompiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use Tests\TestCase;

final class CareerTenBlockCurrentPackageCompilerTest extends TestCase
{
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

    public function test_it_derives_accountants_boundary_notices_from_same_locale_published_authority_only(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $compiler = app(CareerTenBlockCurrentPackageCompiler::class);
        $pages = $package['rows']['accountants-and-auditors']['page_payload_json']['page'];

        $notices = $compiler->deriveAccountantsBoundaryNotices($pages);
        $first = $compiler->compileAccountantsBoundaryNoticeProjection(base_path());
        $second = $compiler->compileAccountantsBoundaryNoticeProjection(base_path());
        $rows = $this->rowsFromAssets($first['assets_bytes']);

        self::assertSame($pages['en']['fermat_decision_card']['caveat'], $notices['en'][0]);
        self::assertSame($pages['en']['ai_impact_table']['explanation']['en']['boundary'], $notices['en'][1]);
        self::assertSame($pages['zh']['fermat_decision_card']['caveat'], $notices['zh'][0]);
        self::assertSame($pages['zh']['ai_impact_table']['explanation']['zh']['boundary'], $notices['zh'][1]);
        self::assertCount(2, $rows['accountants-and-auditors']['page_payload_json']['page']['en']['boundary_notice']);
        self::assertCount(2, $rows['accountants-and-auditors']['page_payload_json']['page']['zh']['boundary_notice']);
        self::assertContains($first['package_diff']['changed_slugs'], [[], ['accountants-and-auditors']]);
        self::assertContains($first['package_diff']['changed_row_count'], [0, 1]);
        self::assertContains($first['package_diff']['public_changed_locale_page_count'], [0, 2]);
        self::assertSame($first['assets_bytes'], $second['assets_bytes']);
        self::assertSame($first['receipt'], $second['receipt']);
        foreach ([
            'health-educators',
            'dancers',
            'forging-machine-setters-operators-and-tenders-metal-and-plastic',
            'veterinarians',
            'preventive-medicine-physicians',
        ] as $slug) {
            self::assertSame(
                CareerCurrentAuthorityPackage::hashValue($package['rows'][$slug]),
                CareerCurrentAuthorityPackage::hashValue($rows[$slug]),
            );
        }
    }

    public function test_it_fails_closed_when_a_same_locale_accountants_boundary_source_is_missing(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $pages = $package['rows']['accountants-and-auditors']['page_payload_json']['page'];
        unset($pages['en']['ai_impact_table']['explanation']['en']['boundary']);

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
}

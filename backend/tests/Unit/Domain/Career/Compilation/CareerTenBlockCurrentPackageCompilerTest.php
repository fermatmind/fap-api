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
}

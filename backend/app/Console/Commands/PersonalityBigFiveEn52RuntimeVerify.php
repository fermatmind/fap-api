<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52RuntimeVerifier;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityBigFiveEn52RuntimeVerify extends Command
{
    /** @var list<string> */
    private const SAFE_ERROR_CODES = [
        'approval_sha_invalid', 'approval_zh_fingerprint_invalid', 'approval_non_target_fingerprint_invalid',
        'approval_search_fingerprint_invalid', 'release_name_invalid', 'public_origin_invalid',
        'release_identity_override_prohibited', 'release_identity_unavailable', 'release_identity_mismatch',
        'database_or_package_boundary_mismatch', 'zh_fingerprint_mismatch', 'non_target_fingerprint_mismatch',
        'search_fingerprint_mismatch', 'database_write_detected', 'canonical_cohort_count_mismatch',
        'canonical_revision_boundary_mismatch', 'canonical_or_alias_boundary_mismatch', 'public_api_failed',
        'public_api_count_mismatch', 'public_api_projection_mismatch', 'alias_public_api_reappearance',
        'sitemap_source_failed', 'sitemap_source_cohort_mismatch', 'sitemap_failed', 'sitemap_cohort_mismatch',
        'llms_failed', 'llms_cohort_mismatch', 'llms_full_failed', 'llms_full_cohort_mismatch',
        'canonical_redirect_or_http_failure', 'alias_redirect_boundary_mismatch',
    ];

    protected $signature = 'personality:big-five-en52-runtime-verify
        {--approved-sha= : Exact approved fap-api main SHA}
        {--release-name= : Exact deployed release directory identity}
        {--api-origin= : Exact public HTTPS backend origin}
        {--frontend-origin= : Exact public HTTPS frontend origin}
        {--package=../generated/big-five-en52-release/release-package.json : Locked PR11 package}
        {--expected-zh-fingerprint= : Exact approved pre-publish zh-CN fingerprint}
        {--expected-non-target-fingerprint= : Exact approved pre-publish non-target fingerprint}
        {--expected-search-fingerprint= : Exact approved pre-publish search-channel fingerprint}
        {--json : Emit sanitized JSON}';

    protected $description = 'Verify-only exact production readback for the locked Big Five English EN52 release.';

    public function handle(BigFiveEn52RuntimeVerifier $verifier): int
    {
        try {
            $result = $verifier->verify([
                'approved_sha' => (string) $this->option('approved-sha'),
                'release_name' => (string) $this->option('release-name'),
                'api_origin' => (string) $this->option('api-origin'),
                'frontend_origin' => (string) $this->option('frontend-origin'),
                'package_path' => (string) $this->option('package'),
                'expected_zh_fingerprint' => (string) $this->option('expected-zh-fingerprint'),
                'expected_non_target_fingerprint' => (string) $this->option('expected-non-target-fingerprint'),
                'expected_search_fingerprint' => (string) $this->option('expected-search-fingerprint'),
            ]);
        } catch (Throwable $throwable) {
            $code = in_array($throwable->getMessage(), self::SAFE_ERROR_CODES, true)
                ? $throwable->getMessage()
                : 'verification_failed';
            $result = [
                'schema_version' => 'big_five_en52_runtime_verify.v1',
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_EN52_RUNTIME_VERIFY',
                'error_code' => $code,
                'writes_committed' => false,
                'production_execution' => false,
            ];
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ((bool) $this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}

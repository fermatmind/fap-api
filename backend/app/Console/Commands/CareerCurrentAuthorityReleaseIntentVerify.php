<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerCurrentAuthorityReleaseIntent;
use Illuminate\Console\Command;
use Throwable;

final class CareerCurrentAuthorityReleaseIntentVerify extends Command
{
    protected $signature = 'career:current-authority-release-intent-verify';

    protected $description = 'Verify the immutable Career Current release intent against repository authority';

    public function handle(CareerCurrentAuthorityReleaseIntent $releaseIntent): int
    {
        ini_set('memory_limit', '2048M');

        try {
            $result = $releaseIntent->verify(base_path());
            $intent = $result['intent'];
            $this->line((string) json_encode([
                'contract_version' => CareerCurrentAuthorityReleaseIntent::CONTRACT_VERSION,
                'status' => 'PASS_CAREER_CURRENT_RELEASE_INTENT',
                'source_merge_sha' => $intent['source_merge_sha'],
                'manifest_sha256' => $intent['manifest_sha256'],
                'aggregate_sha256' => $intent['aggregate_sha256'],
                'versionless_projection_sha256' => $intent['versionless_projection_sha256'],
                'operation_key' => $result['operation_key'],
                'slug_count' => $result['package']['slug_count'],
                'locale_page_count' => $result['package']['locale_page_count'],
                'file_count' => $result['package']['file_count'],
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'contract_version' => CareerCurrentAuthorityReleaseIntent::CONTRACT_VERSION,
                'status' => 'FAIL_CAREER_CURRENT_RELEASE_INTENT',
                'safe_error_code' => $throwable instanceof CareerCurrentAuthorityPackageFailure
                    ? $throwable->safeCode
                    : 'UNEXPECTED_CAREER_CURRENT_RELEASE_INTENT_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

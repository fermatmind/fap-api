<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Personality\Current\PersonalityCurrentAuthorityFailure;
use App\Domain\Personality\Current\PersonalityCurrentAuthorityPackage;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityCurrentManifest extends Command
{
    protected $signature = 'personality:current-manifest';

    protected $description = 'Validate the compiler-owned 364-page Personality Current authority package';

    public function handle(PersonalityCurrentAuthorityPackage $package): int
    {
        try {
            $index = $package->manifestIndex(base_path());
            $manifest = $index['manifest'];
            $this->line((string) json_encode([
                'status' => 'PASS_PERSONALITY_CURRENT_MANIFEST',
                'aggregate_sha256' => $manifest['aggregate_sha256'],
                'locale_page_count' => count($manifest['files']),
                'pages_per_locale' => $manifest['coverage']['pages_per_locale'],
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_PERSONALITY_CURRENT_MANIFEST',
                'safe_error_code' => $throwable instanceof PersonalityCurrentAuthorityFailure
                    ? $throwable->safeCode
                    : 'UNEXPECTED_PERSONALITY_CURRENT_MANIFEST_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}

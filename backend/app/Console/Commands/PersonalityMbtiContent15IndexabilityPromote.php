<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiContent15IndexabilityPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiContent15IndexabilityPromote extends Command
{
    protected $signature = 'personality:mbti-content15-indexability-promote
        {--package= : Exact promotion package path}
        {--package-sha256= : Exact promotion package SHA256; required for --write}
        {--authorization-payload-sha256= : Exact authorization payload SHA256; required for --write}
        {--record-count=9 : Exact record count lock}
        {--dry-run : Validate without writes}
        {--write : Execute the exact authorized promotion}
        {--production-promotion-authorized : Required with --write}
        {--release-indexability : Required with --write}
        {--release-sitemap : Required with --write}
        {--release-llms : Required with --write}
        {--no-gsc : Required with --write}
        {--no-url-inspection : Required with --write}
        {--json : Emit JSON}';

    protected $description = 'Fail-closed indexability promotion for the exact nine CONTENT-15 MBTI records.';

    public function handle(MbtiContent15IndexabilityPromotionService $service): int
    {
        try {
            $write = (bool) $this->option('write');
            if ($write === (bool) $this->option('dry-run')) {
                throw new RuntimeException('Exactly one of --dry-run or --write is required.');
            }
            $path = (string) $this->option('package');
            $resolved = str_starts_with($path, '/') ? $path : base_path($path);
            $package = json_decode((string) File::get($resolved), true);
            if (! is_array($package)) {
                throw new RuntimeException('Promotion package must be a JSON object.');
            }
            $summary = $service->plan($package);
            if ((int) $this->option('record-count') !== 9) {
                throw new RuntimeException('--record-count=9 is required.');
            }
            if ($write) {
                foreach (['production-promotion-authorized', 'release-indexability', 'release-sitemap', 'release-llms', 'no-gsc', 'no-url-inspection'] as $flag) {
                    if (! (bool) $this->option($flag)) {
                        throw new RuntimeException('--'.$flag.' is required with --write.');
                    }
                }
                if (! hash_equals((string) $summary['promotion_package_sha256'], (string) $this->option('package-sha256')) ||
                    ! hash_equals((string) $summary['authorization_payload_sha256'], (string) $this->option('authorization-payload-sha256'))) {
                    throw new RuntimeException('Exact promotion package or authorization payload SHA256 mismatch.');
                }
                $summary = $service->promote($package);
            }
        } catch (Throwable $exception) {
            $summary = ['ok' => false, 'error' => $exception->getMessage(), 'production_promotion_executed' => false, 'search_submission_executed' => false];
        }

        $json = (string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($json);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}

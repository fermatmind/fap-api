<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\MbtiFullIndexabilityPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbtiFullIndexabilityPromote extends Command
{
    protected $signature = 'personality:mbti-full-indexability-promote
        {--package= : Exact CMS-39 43-record package path}
        {--source-package-sha256= : Exact CMS-39 logical source SHA256}
        {--promotion-package-sha256= : Exact dry-run promotion package SHA256; required for --write}
        {--authorization-payload-sha256= : Exact dry-run authorization payload SHA256; required for --write}
        {--import-scope-mode= : Exact import scope mode}
        {--record-count=43 : Exact record count lock}
        {--dry-run : Validate without writes}
        {--write : Execute the exact authorized release}
        {--production-promotion-authorized : Required with --write}
        {--release-indexability : Required with --write}
        {--release-sitemap : Required with --write}
        {--release-llms : Required with --write}
        {--no-gsc : Required with --write}
        {--no-url-inspection : Required with --write}
        {--no-search-submission : Required with --write}
        {--json : Emit JSON}';

    protected $description = 'Fail-closed indexability and feed release for the exact 43 CMS-40 MBTI repairs.';

    public function handle(MbtiFullIndexabilityPromotionService $service): int
    {
        try {
            $write = (bool) $this->option('write');
            if ($write === (bool) $this->option('dry-run')) {
                throw new RuntimeException('Exactly one of --dry-run or --write is required.');
            }
            if ((string) $this->option('source-package-sha256') !== '840288581ce02e26afdd40dde1e25cf995fe334791b0a306929a13c76247a78d') {
                throw new RuntimeException('The exact CMS-39 logical source SHA256 is required.');
            }
            if ((string) $this->option('import-scope-mode') !== 'full_chinese_mbti_repair_batch_only' || (int) $this->option('record-count') !== 43) {
                throw new RuntimeException('The exact 43-record import scope lock is required.');
            }

            $path = (string) $this->option('package');
            $resolved = str_starts_with($path, '/') ? $path : base_path($path);
            $package = json_decode((string) File::get($resolved), true);
            if (! is_array($package)) {
                throw new RuntimeException('Promotion package must be a JSON object.');
            }
            $summary = $service->plan($package);
            if ($write) {
                foreach (['production-promotion-authorized', 'release-indexability', 'release-sitemap', 'release-llms', 'no-gsc', 'no-url-inspection', 'no-search-submission'] as $flag) {
                    if (! (bool) $this->option($flag)) {
                        throw new RuntimeException('--'.$flag.' is required with --write.');
                    }
                }
                if (! hash_equals((string) $summary['promotion_package_sha256'], (string) $this->option('promotion-package-sha256'))
                    || ! hash_equals((string) $summary['authorization_payload_sha256'], (string) $this->option('authorization-payload-sha256'))) {
                    throw new RuntimeException('Exact promotion package or authorization payload SHA256 mismatch.');
                }
                $summary = $service->promote($package);
            }
        } catch (Throwable $exception) {
            $summary = [
                'ok' => false,
                'status' => 'fail',
                'errors' => [['field' => 'command', 'code' => 'command_failed', 'message' => $exception->getMessage()]],
                'writes_committed' => false,
                'production_promotion_executed' => false,
                'gsc_executed' => false,
                'url_inspection_executed' => false,
            ];
        }

        $this->line((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerContentV3PageUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerContentV3PageUpdate extends Command
{
    protected $signature = 'career:content-v3-page-update
        {slug : Exact canonical slug}
        {locale : en or zh-CN}
        {--write : Atomically refresh only the selected Current page and repository bindings}';

    protected $description = 'Validate or update one Current Career content-v3 page without runtime, cache, or discoverability writes';

    public function handle(CareerContentV3PageUpdater $updater): int
    {
        ini_set('memory_limit', '2048M');
        try {
            $result = $updater->update(
                base_path(),
                (string) $this->argument('slug'),
                (string) $this->argument('locale'),
                (bool) $this->option('write'),
            );
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CAREER_CONTENT_V3_PAGE_UPDATE',
                'safe_error_code' => $throwable instanceof CareerCurrentAuthorityPackageFailure
                    ? $throwable->safeCode : 'CURRENT_CONTENT_V3_PAGE_UPDATE_UNEXPECTED_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerContentV3BatchUpdater;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use Illuminate\Console\Command;
use Throwable;

final class CareerContentV3BatchUpdate extends Command
{
    protected $signature = 'career:content-v3-batch-update
        {--source= : Directory containing selected careers/<slug>/<locale>.json files}
        {--selection= : JSON file with exact selected slug, locale, path and source SHA-256 rows}
        {--write : Apply the validated batch to Current and refresh manifest plus release intent}';

    protected $description = 'Validate or apply one bounded Career Current content-v3 batch without runtime writes';

    public function handle(CareerContentV3BatchUpdater $updater): int
    {
        ini_set('memory_limit', '2048M');
        try {
            $selectionPath = (string) $this->option('selection');
            if ($selectionPath === '' || ! is_file($selectionPath) || is_link($selectionPath)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SELECTION_INVALID');
            }
            $selection = json_decode((string) file_get_contents($selectionPath), true, 512, JSON_THROW_ON_ERROR);
            $selectionKeys = is_array($selection) ? array_keys($selection) : [];
            sort($selectionKeys, SORT_STRING);
            if (! is_array($selection)
                || ($selection['schema_version'] ?? null) !== 'career.content_v3_batch_selection.v1'
                || ! is_array($selection['pages'] ?? null)
                || $selectionKeys !== ['pages', 'schema_version']) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SELECTION_INVALID');
            }
            $result = $updater->update(
                base_path(),
                (string) $this->option('source'),
                $selection['pages'],
                (bool) $this->option('write'),
            );
            $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->line((string) json_encode([
                'status' => 'FAIL_CAREER_CONTENT_V3_BATCH_UPDATE',
                'safe_error_code' => $error instanceof CareerCurrentAuthorityPackageFailure
                    ? $error->safeCode : 'CURRENT_CONTENT_V3_BATCH_UNEXPECTED_FAILURE',
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\PromotionReadiness\BigFiveZh6PromotionReadiness;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV249PromotionReadiness extends Command
{
    protected $signature = 'personality:big-five-authority-v2-zh6-promotion-readiness
        {--package= : Promotion-readiness package JSON path}
        {--package-only : Validate the checked package without database reads}
        {--json : Emit JSON output}';

    protected $description = 'Read-only ZH6 editorial, source, media, runtime-baseline, and rollback readiness gate; never writes or promotes';

    public function handle(BigFiveZh6PromotionReadiness $readiness): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new RuntimeException('--package is required.');
            }
            $packageOnly = (bool) $this->option('package-only');
            $result = $packageOnly
                ? $readiness->packageOnly($package)
                : $readiness->databasePreflight($package);
            $this->emit($result);

            return ($packageOnly ? ($result['contract_valid'] ?? false) : ($result['ready'] ?? false)) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'contract_valid' => false,
                'ready' => false,
                'status' => 'FAIL_CLOSED',
                'error' => $throwable->getMessage(),
                'actions' => [
                    'database_reads' => 0,
                    'database_writes' => 0,
                    'cms_writes' => 0,
                    'media_library_writes' => 0,
                    'media_uploads' => 0,
                    'working_revisions_created' => 0,
                    'promotions' => 0,
                    'published_pointer_changes' => 0,
                    'indexability_changes' => 0,
                    'sitemap_changes' => 0,
                    'llms_changes' => 0,
                    'schema_changes' => 0,
                    'search_submissions' => 0,
                    'cache_operations' => 0,
                    'deployments' => 0,
                ],
            ];
            $this->emit($result);

            return self::FAILURE;
        }
    }

    /** @param array<string,mixed> $result */
    private function emit(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        foreach (['ok', 'contract_valid', 'ready', 'status', 'mode', 'release_snapshot_sha256', 'package_payload_sha256'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }
            $value = is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field];
            $this->line($field.'='.$value);
        }
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}

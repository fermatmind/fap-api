<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\ReviewPromotion\BigFiveReviewPromotionPreflight;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV247ReviewPromotionPreflight extends Command
{
    protected $signature = 'personality:big-five-authority-v2-review-promotion-preflight
        {--review-manifest= : Review manifest JSON path}
        {--authorization-packet= : Cohort authorization packet JSON path}
        {--rollback-plan= : Rollback plan JSON path}
        {--package-only : Validate the locked pending package without database reads}
        {--json : Emit JSON output}';

    protected $description = 'Read-only Big Five Authority V2 review and cohort-promotion preflight; never promotes or writes';

    public function handle(BigFiveReviewPromotionPreflight $preflight): int
    {
        try {
            $arguments = [
                $this->requiredOption('review-manifest'),
                $this->requiredOption('authorization-packet'),
                $this->requiredOption('rollback-plan'),
            ];
            $result = (bool) $this->option('package-only')
                ? $preflight->packageOnly(...$arguments)
                : $preflight->databasePreflight(...$arguments);
            $this->emit($result);

            return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->emit([
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'error' => $throwable->getMessage(),
                'actions' => [
                    'database_writes' => 0,
                    'cms_writes' => 0,
                    'promotions' => 0,
                    'rollbacks' => 0,
                    'public_release_changes' => 0,
                    'indexability_changes' => 0,
                    'sitemap_changes' => 0,
                    'llms_changes' => 0,
                    'search_submissions' => 0,
                    'cache_operations' => 0,
                    'deployments' => 0,
                ],
            ]);

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        return $value;
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

        foreach (['ok', 'status', 'mode', 'promotion_preflight_fingerprint'] as $field) {
            if (array_key_exists($field, $result)) {
                $value = is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field];
                $this->line($field.'='.$value);
            }
        }
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\ContentOnlyRelease\BigFiveZhContentOnlyPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV2ZhContentOnlyPublish extends Command
{
    protected $signature = 'personality:big-five-authority-v2-zh-content-publish
        {--package=../generated/big-five-authority-v2/big5-authority-v2-zh-content-only-release/release-package.json : Exact zh-CN content-only release package}
        {--execute : Publish the exact 112-asset zh-CN cohort; omission is read-only preflight}
        {--allow-testing : Permit execute only in APP_ENV=testing with SQLite}
        {--json : Emit full JSON output}
        {--output= : Optional JSON report path}';

    protected $description = 'Publish the exact 112 Big Five Authority V2 zh-CN content assets with operator-authorized editorial/media deferrals.';

    public function handle(BigFiveZhContentOnlyPublisher $publisher): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '') {
                throw new RuntimeException('--package is required.');
            }
            if ((bool) $this->option('execute')) {
                $this->assertExecuteEnvironment();
                $result = $publisher->publish($package);
            } else {
                $result = $publisher->preflight($package);
            }
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'status' => 'FAIL_CLOSED_ZH_CONTENT_ONLY_RELEASE',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->writeOutput($result);
        $this->emit($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function assertExecuteEnvironment(): void
    {
        if (app()->environment('production')) {
            return;
        }
        if ((bool) $this->option('allow-testing')
            && app()->environment('testing')
            && config('database.default') === 'sqlite') {
            return;
        }

        throw new RuntimeException('--execute requires APP_ENV=production, or --allow-testing with APP_ENV=testing and SQLite.');
    }

    /** @param array<string,mixed> $result */
    private function writeOutput(array $result): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }
        $resolved = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
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

        foreach ([
            'ok',
            'status',
            'mode',
            'release_id',
            'release_package_sha256',
            'asset_count',
            'public_release_count',
            'media_deferred_by_operator_count',
            'media_library_write_count',
            'english_write_count',
            'writes_committed',
        ] as $field) {
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

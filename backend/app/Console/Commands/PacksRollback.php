<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackPublisher;
use Illuminate\Console\Command;

final class PacksRollback extends Command
{
    protected $signature = 'packs:rollback
        {--scale=BIG5_OCEAN : Scale code}
        {--region=CN_MAINLAND : Region}
        {--locale=zh-CN : Locale}
        {--dir_alias=v1 : Target dir alias}
        {--probe=0 : Run post-rollback probe}
        {--base_url= : Probe base url}';

    protected $description = 'Rollback BIG5_OCEAN content pack to last successful publish backup.';

    public function handle(ContentPackPublisher $publisher): int
    {
        $scaleCode = strtoupper(trim((string) $this->option('scale')));
        if ($scaleCode !== 'BIG5_OCEAN') {
            $this->error('packs:rollback currently supports only --scale=BIG5_OCEAN');
            return 1;
        }

        $region = trim((string) $this->option('region'));
        $locale = trim((string) $this->option('locale'));
        $dirAlias = trim((string) $this->option('dir_alias'));
        $baseUrl = trim((string) $this->option('base_url'));
        $probe = $this->isTruthy($this->option('probe'));

        if ($region === '' || $locale === '' || $dirAlias === '') {
            $this->error('--region/--locale/--dir_alias are required.');
            return 1;
        }

        $result = $publisher->rollback(
            $region,
            $locale,
            $dirAlias,
            $probe,
            $baseUrl === '' ? null : $baseUrl
        );

        $status = (string) ($result['status'] ?? 'failed');
        $releaseId = (string) ($result['release_id'] ?? '');
        $message = (string) ($result['message'] ?? '');
        $rolledBackTo = is_array($result['rolled_back_to'] ?? null) ? $result['rolled_back_to'] : [];
        $packId = (string) ($rolledBackTo['pack_id'] ?? '');
        $versionId = (string) ($rolledBackTo['version_id'] ?? '');

        $this->line("release_id={$releaseId}");
        $this->line("status={$status}");
        if ($packId !== '') {
            $this->line("rolled_back_pack_id={$packId}");
        }
        if ($versionId !== '') {
            $this->line("rolled_back_version_id={$versionId}");
        }
        if ($message !== '') {
            $this->line("message={$message}");
        }

        return $status === 'success' ? 0 : 1;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

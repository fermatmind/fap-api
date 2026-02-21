<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class PacksPublish extends Command
{
    protected $signature = 'packs:publish
        {--scale=BIG5_OCEAN : Scale code}
        {--pack=BIG5_OCEAN : Pack id}
        {--pack-version=v1 : Pack version}
        {--region=CN_MAINLAND : Region}
        {--locale=zh-CN : Locale}
        {--dir_alias=v1 : Target dir alias}
        {--probe=0 : Run post-publish probe}
        {--skip_drift=0 : Skip norms drift check}
        {--drift_from= : Drift check source norms version}
        {--drift_to= : Drift check target norms version}
        {--drift_group_id= : Drift check group id scope}
        {--drift_threshold_mean=0.35 : Drift threshold for mean}
        {--drift_threshold_sd=0.35 : Drift threshold for sd}
        {--base_url= : Probe base url}
        {--created_by=cli : Operator id}';

    protected $description = 'Publish BIG5_OCEAN local content pack through ContentPackPublisher.';

    public function handle(ContentPackPublisher $publisher): int
    {
        $scaleCode = strtoupper(trim((string) $this->option('scale')));
        if ($scaleCode !== 'BIG5_OCEAN') {
            $this->error('packs:publish currently supports only --scale=BIG5_OCEAN');
            return 1;
        }

        $packId = trim((string) $this->option('pack'));
        $version = trim((string) $this->option('pack-version'));
        $region = trim((string) $this->option('region'));
        $locale = trim((string) $this->option('locale'));
        $dirAlias = trim((string) $this->option('dir_alias'));
        $baseUrl = trim((string) $this->option('base_url'));
        $createdBy = trim((string) $this->option('created_by'));
        $probe = $this->isTruthy($this->option('probe'));
        $skipDrift = $this->isTruthy($this->option('skip_drift'));

        if ($packId === '' || $version === '' || $region === '' || $locale === '' || $dirAlias === '') {
            $this->error('--pack/--version/--region/--locale/--dir_alias are required.');
            return 1;
        }

        $sourceDir = base_path(sprintf('content_packs/%s/%s', $packId, $version));
        if (!File::isDirectory($sourceDir)) {
            $this->error("pack source directory not found: {$sourceDir}");
            return 1;
        }

        if (! $skipDrift) {
            $fromVersion = trim((string) $this->option('drift_from'));
            $toVersion = trim((string) $this->option('drift_to'));
            if ($fromVersion !== '' && $toVersion !== '') {
                $driftArgs = [
                    '--scale' => $scaleCode,
                    '--from' => $fromVersion,
                    '--to' => $toVersion,
                    '--threshold_mean' => (string) $this->option('drift_threshold_mean'),
                    '--threshold_sd' => (string) $this->option('drift_threshold_sd'),
                ];
                $driftGroupId = trim((string) $this->option('drift_group_id'));
                if ($driftGroupId !== '') {
                    $driftArgs['--group_id'] = $driftGroupId;
                }
                $driftCode = $this->call('norms:big5:drift-check', $driftArgs);
                if ($driftCode !== 0) {
                    $this->error('drift-check failed, publish aborted.');

                    return $driftCode;
                }
            } else {
                $this->line('drift-check skipped: provide --drift_from and --drift_to to enable.');
            }
        } else {
            $this->line('drift-check skipped by --skip_drift=1');
        }

        try {
            $versionId = $this->stageVersion($sourceDir, $packId, $version, $region, $locale, $dirAlias, $createdBy === '' ? 'cli' : $createdBy);
        } catch (\Throwable $e) {
            $this->error('failed to stage content version: '.$e->getMessage());
            return 1;
        }

        $this->info("staged version_id={$versionId}");
        $result = $publisher->publish(
            $versionId,
            $region,
            $locale,
            $dirAlias,
            $probe,
            $baseUrl === '' ? null : $baseUrl
        );

        $status = (string) ($result['status'] ?? 'failed');
        $releaseId = (string) ($result['release_id'] ?? '');
        $message = (string) ($result['message'] ?? '');
        $toPackId = (string) ($result['to_pack_id'] ?? '');

        $this->line("release_id={$releaseId}");
        $this->line("status={$status}");
        $this->line("to_pack_id={$toPackId}");
        if ($message !== '') {
            $this->line("message={$message}");
        }

        return $status === 'success' ? 0 : 1;
    }

    private function stageVersion(
        string $sourceDir,
        string $packId,
        string $version,
        string $region,
        string $locale,
        string $dirAlias,
        string $createdBy
    ): string {
        $versionId = (string) Str::uuid();
        $privateRoot = rtrim(storage_path('app/private'), '/\\');
        $releaseRoot = $privateRoot.DIRECTORY_SEPARATOR.'content_releases'.DIRECTORY_SEPARATOR.$versionId;
        $stagedDir = $releaseRoot.DIRECTORY_SEPARATOR.'source_pack';

        if (File::isDirectory($releaseRoot)) {
            File::deleteDirectory($releaseRoot);
        }
        File::ensureDirectoryExists($releaseRoot);

        if (!File::copyDirectory($sourceDir, $stagedDir)) {
            throw new RuntimeException('COPY_SOURCE_FAILED');
        }

        $manifestPayload = $this->buildManifestPayload($sourceDir, $packId, $version, $region, $locale);
        $sha256 = $this->hashDirectory($stagedDir);
        $relativeSource = 'content_releases/'.$versionId.'/source_pack';
        $sourceRef = Str::after($sourceDir, base_path().DIRECTORY_SEPARATOR);

        DB::table('content_pack_versions')->insert([
            'id' => $versionId,
            'region' => $region,
            'locale' => $locale,
            'pack_id' => $packId,
            'content_package_version' => $version,
            'dir_version_alias' => $dirAlias,
            'source_type' => 'local_repo',
            'source_ref' => $sourceRef,
            'sha256' => $sha256,
            'manifest_json' => json_encode($manifestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'extracted_rel_path' => $relativeSource,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function buildManifestPayload(
        string $sourceDir,
        string $packId,
        string $version,
        string $region,
        string $locale
    ): array {
        $compiledManifest = $sourceDir.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'manifest.json';
        if (File::exists($compiledManifest)) {
            $decoded = json_decode((string) File::get($compiledManifest), true);
            if (is_array($decoded)) {
                $decoded['pack_id'] = $packId;
                $decoded['content_package_version'] = $version;
                $decoded['region'] = $region;
                $decoded['locale'] = $locale;
                return $decoded;
            }
        }

        return [
            'pack_id' => $packId,
            'content_package_version' => $version,
            'region' => $region,
            'locale' => $locale,
            'schema' => 'big5.compiled.manifest.v1',
        ];
    }

    private function hashDirectory(string $dir): string
    {
        $files = File::allFiles($dir);
        usort($files, static function ($a, $b): int {
            return strcmp($a->getPathname(), $b->getPathname());
        });

        $ctx = hash_init('sha256');
        foreach ($files as $file) {
            $pathname = $file->getPathname();
            $relative = ltrim(Str::after($pathname, rtrim($dir, '/\\')), '/\\');
            $digest = (string) hash_file('sha256', $pathname);
            hash_update($ctx, $relative.':'.$digest."\n");
        }

        return hash_final($ctx);
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

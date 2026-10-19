<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class Platform12ActivationInstallFence
{
    public static function lockIdentity(): string
    {
        $store = (string) config('seo_council.runtime_cache_store');

        return hash('sha256', serialize([$store, config('cache.stores.'.$store),
            config('cache.prefix'), config('database.redis')]));
    }

    public static function snapshot(string $deploymentRoot): array
    {
        clearstatcache(true);
        $current = realpath($deploymentRoot.'/current');
        $booted = realpath(dirname(base_path()));
        if ($current === false || $current !== $booted || ! is_file($current.'/REVISION')) {
            throw new RuntimeException('A08_CURRENT_SUPERSEDED');
        }
        $state = Cache::store(config('seo_council.runtime_cache_store'))->get(Platform12RuntimeControl::CACHE_KEY);
        $capabilities = app(RuntimeCapabilitySnapshotBuilder::class)->snapshot();

        return ['sha' => trim(file_get_contents($current.'/REVISION')),
            'generation' => is_array($state) ? ($state['generation'] ?? null) : null,
            'version_vector_hash' => $capabilities['version_vector_hash'],
            'lock_identity' => self::lockIdentity(), 'release_id' => basename($current)];
    }

    public static function assertMatches(array $expected, array $current): void
    {
        foreach (['sha', 'generation', 'version_vector_hash', 'lock_identity', 'release_id'] as $key) {
            if (! array_key_exists($key, $expected) || ! array_key_exists($key, $current)
                || $expected[$key] !== $current[$key]) {
                throw new RuntimeException('A08_CURRENT_SUPERSEDED');
            }
        }
        if (preg_match('/^[a-f0-9]{40}$/D', (string) $current['sha']) !== 1
            || ($current['generation'] !== null && preg_match('/^[a-f0-9]{32}$/D', (string) $current['generation']) !== 1)
            || preg_match('/^[a-f0-9]{64}$/D', (string) $current['lock_identity']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) $current['version_vector_hash']) !== 1) {
            throw new RuntimeException('A08_INSTALL_FENCE_HOLD');
        }
    }

    /** Called only while the existing runtime control lock is held. */
    public static function publish(string $path, string $bytes, array $expected, callable $observe): void
    {
        $current = $observe();
        self::assertMatches($expected, $current);
        if (is_link($path) || is_link($path.'.sha256') || is_link($path.'.atomic.json')) {
            throw new RuntimeException('A08_PATH_HOLD');
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $previous = is_file($path.'.atomic.json') ? file_get_contents($path.'.atomic.json') : null;
        $temp = tempnam(dirname($path), '.a08-');
        try {
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp, 0640);
            if (file_put_contents($temp.'.sha256', hash('sha256', $bytes)."\n", LOCK_EX) !== 65) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp.'.sha256', 0640);
            $atomic = json_encode(['bytes' => $bytes, 'sha256' => hash('sha256', $bytes), 'installation_fence' => $current], JSON_THROW_ON_ERROR);
            if (file_put_contents($temp.'.atomic.json', $atomic, LOCK_EX) !== strlen($atomic)) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            chmod($temp.'.atomic.json', 0640);
            // One authoritative rename. Legacy mirrors remain readable for software rollback.
            if (! rename($temp.'.atomic.json', $path.'.atomic.json')) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
            try {
                self::assertMatches($expected, $observe());
            } catch (\Throwable $exception) {
                // Still under the original control lock. Restore only our own
                // envelope; never overwrite a proof installed by another owner.
                if (file_get_contents($path.'.atomic.json') === $atomic) {
                    if ($previous === null) {
                        unlink($path.'.atomic.json');
                    } else {
                        file_put_contents($temp.'.atomic.json', $previous, LOCK_EX);
                        chmod($temp.'.atomic.json', 0640);
                        rename($temp.'.atomic.json', $path.'.atomic.json');
                    }
                }
                throw $exception;
            }
            if (! rename($temp, $path) || ! rename($temp.'.sha256', $path.'.sha256')) {
                throw new RuntimeException('A08_INSTALL_HOLD');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
            if (is_file($temp.'.atomic.json')) {
                unlink($temp.'.atomic.json');
            }
            if (is_file($temp.'.sha256')) {
                unlink($temp.'.sha256');
            }
        }
    }
}

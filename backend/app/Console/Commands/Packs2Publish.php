<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Console\Command;

final class Packs2Publish extends Command
{
    protected $signature = 'packs2:publish {--pack=} {--pack-version=v1} {--activate=1} {--source_commit=}';

    protected $description = 'Publish compiled content pack to packs2 storage and optionally activate it.';

    public function handle(ContentPackV2Publisher $publisher): int
    {
        $pack = strtoupper(trim((string) $this->option('pack')));
        $packVersion = trim((string) $this->option('pack-version'));

        if ($pack === '') {
            $this->error('--pack is required.');

            return self::FAILURE;
        }
        if ($packVersion === '') {
            $this->error('--pack-version is required.');

            return self::FAILURE;
        }

        $lintCode = $this->call('content:lint', [
            '--pack' => $pack,
            '--pack-version' => $packVersion,
        ]);
        if ($lintCode !== 0) {
            return $lintCode;
        }

        $compileCode = $this->call('content:compile', [
            '--pack' => $pack,
            '--pack-version' => $packVersion,
        ]);
        if ($compileCode !== 0) {
            return $compileCode;
        }

        $release = $publisher->publishCompiled($pack, $packVersion, [
            'source_commit' => trim((string) $this->option('source_commit')),
            'created_by' => 'packs2:publish',
        ]);

        if ((int) $this->option('activate') === 1) {
            $publisher->activateRelease((string) ($release['id'] ?? ''));
            $this->info('activated release_id='.(string) ($release['id'] ?? ''));
        }

        $this->line('release_id='.(string) ($release['id'] ?? ''));
        $this->line('pack='.(string) ($release['to_pack_id'] ?? $pack));
        $this->line('pack_version='.(string) ($release['pack_version'] ?? $packVersion));
        $this->line('manifest_hash='.(string) ($release['manifest_hash'] ?? ''));

        return self::SUCCESS;
    }
}

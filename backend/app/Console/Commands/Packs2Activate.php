<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Console\Command;

final class Packs2Activate extends Command
{
    protected $signature = 'packs2:activate {--pack=} {--pack-version=v1} {--release_id=}';

    protected $description = 'Activate a packs2 release for a given pack/version.';

    public function handle(ContentPackV2Publisher $publisher): int
    {
        $pack = strtoupper(trim((string) $this->option('pack')));
        $packVersion = trim((string) $this->option('pack-version'));
        $releaseId = trim((string) $this->option('release_id'));

        if ($pack === '') {
            $this->error('--pack is required.');

            return self::FAILURE;
        }
        if ($packVersion === '') {
            $this->error('--pack-version is required.');

            return self::FAILURE;
        }

        if ($releaseId === '') {
            $releaseId = (string) ($publisher->resolveLatestReleaseId($pack, $packVersion) ?? '');
        }

        if ($releaseId === '') {
            $this->error('No release found to activate. Pass --release_id explicitly.');

            return self::FAILURE;
        }

        $publisher->activateRelease($releaseId);

        $this->info("activated pack={$pack} pack_version={$packVersion} release_id={$releaseId}");

        return self::SUCCESS;
    }
}

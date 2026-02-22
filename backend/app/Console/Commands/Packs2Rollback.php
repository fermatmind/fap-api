<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Console\Command;

final class Packs2Rollback extends Command
{
    protected $signature = 'packs2:rollback {--pack=} {--pack-version=v1} {--to_release_id=}';

    protected $description = 'Rollback packs2 activation to a previous release id.';

    public function handle(ContentPackV2Publisher $publisher): int
    {
        $pack = strtoupper(trim((string) $this->option('pack')));
        $packVersion = trim((string) $this->option('pack-version'));
        $toReleaseId = trim((string) $this->option('to_release_id'));

        if ($pack === '' || $packVersion === '' || $toReleaseId === '') {
            $this->error('--pack, --pack-version and --to_release_id are required.');

            return self::FAILURE;
        }

        $publisher->rollbackToRelease($pack, $packVersion, $toReleaseId);
        $this->info("rolled back pack={$pack} pack_version={$packVersion} to_release_id={$toReleaseId}");

        return self::SUCCESS;
    }
}

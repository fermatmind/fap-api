<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\Publisher\ContentPackV2Publisher;
use Illuminate\Console\Command;

final class Packs2List extends Command
{
    protected $signature = 'packs2:list {--pack=} {--pack-version=v1} {--limit=20}';

    protected $description = 'List packs2 releases and active status for a pack/version.';

    public function handle(ContentPackV2Publisher $publisher): int
    {
        $pack = strtoupper(trim((string) $this->option('pack')));
        $packVersion = trim((string) $this->option('pack-version'));
        $limit = (int) $this->option('limit');

        if ($pack === '' || $packVersion === '') {
            $this->error('--pack and --pack-version are required.');

            return self::FAILURE;
        }

        $items = $publisher->listReleases($pack, $packVersion, $limit);
        if ($items === []) {
            $this->warn("no releases found for pack={$pack} pack_version={$packVersion}");

            return self::SUCCESS;
        }

        $rows = array_map(static function (array $item): array {
            return [
                'active' => ! empty($item['is_active']) ? 'yes' : 'no',
                'release_id' => (string) ($item['release_id'] ?? ''),
                'action' => (string) ($item['action'] ?? ''),
                'manifest_hash' => (string) ($item['manifest_hash'] ?? ''),
                'source_commit' => (string) ($item['source_commit'] ?? ''),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ];
        }, $items);

        $this->table(['active', 'release_id', 'action', 'manifest_hash', 'source_commit', 'created_at'], $rows);

        return self::SUCCESS;
    }
}

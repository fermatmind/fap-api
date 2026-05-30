<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentPacksIndex;
use App\Services\Content\ContentPacksIndexArtifactStore;
use Illuminate\Console\Command;

final class ContentPacksIndexBuild extends Command
{
    protected $signature = 'content-packs:index:build
        {--output= : Optional output path for content-packs-index.json}
        {--json : Emit JSON output}';

    protected $description = 'Build the precompiled content pack index artifact without changing public API contracts.';

    public function handle(ContentPacksIndex $index, ContentPacksIndexArtifactStore $artifacts): int
    {
        try {
            $snapshot = $index->getIndex(true);
            if (! (bool) ($snapshot['ok'] ?? false)) {
                throw new \RuntimeException('content pack index scan did not produce an ok snapshot');
            }

            $path = $this->outputPath($artifacts);
            $result = $artifacts->write($snapshot, $path);

            $payload = [
                'status' => 'materialized',
                'path' => (string) ($result['path'] ?? $path),
                'schema_version' => ContentPacksIndexArtifactStore::SCHEMA_VERSION,
                'item_count' => (int) ($result['item_count'] ?? 0),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->line('status=materialized');
            $this->line('path='.$payload['path']);
            $this->line('schema_version='.$payload['schema_version']);
            $this->line('item_count='.$payload['item_count']);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function outputPath(ContentPacksIndexArtifactStore $artifacts): string
    {
        $output = $this->option('output');
        if ($output !== null && trim((string) $output) !== '') {
            return trim((string) $output);
        }

        return $artifacts->configuredPath();
    }
}

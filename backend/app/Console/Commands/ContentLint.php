<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentLintService;
use Illuminate\Console\Command;

final class ContentLint extends Command
{
    protected $signature = 'content:lint
        {--all : Lint all content packs}
        {--pack= : Lint a single pack_id}';

    protected $description = 'Lint content packs for schema/access/template safety before release.';

    public function handle(ContentLintService $service): int
    {
        $pack = $this->option('pack');
        $result = $service->lintAll(is_string($pack) ? $pack : null);

        $packs = is_array($result['packs'] ?? null) ? $result['packs'] : [];
        if ($packs === []) {
            $this->warn('No content packs found.');
            return 1;
        }

        foreach ($packs as $packResult) {
            $packId = (string) ($packResult['pack_id'] ?? 'unknown');
            $version = (string) ($packResult['version'] ?? 'unknown');
            $ok = (bool) ($packResult['ok'] ?? false);

            if ($ok) {
                $this->info("[PASS] {$packId} ({$version})");
                continue;
            }

            $this->error("[FAIL] {$packId} ({$version})");
            foreach ((array) ($packResult['errors'] ?? []) as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $file = (string) ($err['file'] ?? '');
                $block = (string) ($err['block_id'] ?? '');
                $msg = (string) ($err['message'] ?? '');
                $this->line("  - {$file} :: {$block} :: {$msg}");
            }
        }

        return ($result['ok'] ?? false) ? 0 : 1;
    }
}

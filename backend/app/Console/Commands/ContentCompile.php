<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Content\ContentCompileService;
use Illuminate\Console\Command;

final class ContentCompile extends Command
{
    protected $signature = 'content:compile
        {--all : Compile all content packs}
        {--pack= : Compile a single pack_id}';

    protected $description = 'Compile content packs into normalized runtime artifacts.';

    public function handle(ContentCompileService $service): int
    {
        $pack = $this->option('pack');
        $result = $service->compileAll(is_string($pack) ? $pack : null);

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
                $compiledDir = (string) ($packResult['compiled_dir'] ?? '');
                $this->info("[PASS] {$packId} ({$version}) -> {$compiledDir}");
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

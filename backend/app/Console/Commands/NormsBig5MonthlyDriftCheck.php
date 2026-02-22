<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormsBig5MonthlyDriftCheck extends Command
{
    protected $signature = 'norms:big5:monthly-drift-check
        {--scale=BIG5_OCEAN : Scale code}
        {--group_id= : Optional group id scope}
        {--threshold_mean=0.35 : Mean drift threshold}
        {--threshold_sd=0.35 : SD drift threshold}';

    protected $description = 'Run BIG5 drift-check against latest two norms versions.';

    public function handle(): int
    {
        if (! Schema::hasTable('scale_norms_versions')) {
            $this->error('Missing table scale_norms_versions. Run migrations first.');

            return self::FAILURE;
        }

        $scale = strtoupper(trim((string) $this->option('scale')));
        if ($scale === '') {
            $this->error('--scale is required.');

            return self::FAILURE;
        }

        $rows = DB::table('scale_norms_versions')
            ->where('scale_code', $scale)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get(['version']);

        $versions = [];
        foreach ($rows as $row) {
            $version = trim((string) ($row->version ?? ''));
            if ($version === '' || in_array($version, $versions, true)) {
                continue;
            }
            $versions[] = $version;
            if (count($versions) >= 2) {
                break;
            }
        }

        if (count($versions) < 2) {
            $this->warn('skip drift-check: not enough versions to compare.');

            return self::SUCCESS;
        }

        $toVersion = $versions[0];
        $fromVersion = $versions[1];

        $this->info("drift-check from={$fromVersion} to={$toVersion}");

        $args = [
            '--scale' => $scale,
            '--from' => $fromVersion,
            '--to' => $toVersion,
            '--threshold_mean' => (string) $this->option('threshold_mean'),
            '--threshold_sd' => (string) $this->option('threshold_sd'),
        ];

        $groupId = trim((string) $this->option('group_id'));
        if ($groupId !== '') {
            $args['--group_id'] = $groupId;
        }

        return (int) $this->call('norms:big5:drift-check', $args);
    }
}

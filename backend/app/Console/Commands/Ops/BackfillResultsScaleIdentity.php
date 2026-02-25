<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Scale\ScaleIdentityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillResultsScaleIdentity extends Command
{
    protected $signature = 'ops:backfill-results-scale-identity
        {--chunk=1000 : Chunk size}
        {--dry-run : Compute and report only; do not write updates}';

    protected $description = 'Backfill results.scale_code_v2 and results.scale_uid from results.scale_code';

    public function __construct(private readonly ScaleIdentityResolver $identityResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('results')) {
            $this->warn('results table missing, skipping.');

            return self::SUCCESS;
        }

        if (! Schema::hasColumn('results', 'scale_code_v2') || ! Schema::hasColumn('results', 'scale_uid')) {
            $this->warn('results scale identity columns missing, skipping.');

            return self::SUCCESS;
        }

        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $scanned = 0;
        $updated = 0;
        $skippedUnknown = 0;
        $lastId = '';

        do {
            $rows = DB::table('results')
                ->select(['id', 'scale_code', 'scale_code_v2', 'scale_uid'])
                ->where(function ($q): void {
                    $q->whereNull('scale_code_v2')
                        ->orWhere('scale_code_v2', '')
                        ->orWhereNull('scale_uid')
                        ->orWhere('scale_uid', '');
                })
                ->when($lastId !== '', fn ($q) => $q->where('id', '>', $lastId))
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $resultId = trim((string) ($row->id ?? ''));
                if ($resultId === '') {
                    continue;
                }
                $lastId = $resultId;
                $scanned++;

                $legacyCode = strtoupper(trim((string) ($row->scale_code ?? '')));
                if ($legacyCode === '') {
                    $skippedUnknown++;
                    continue;
                }

                $identity = $this->identityResolver->resolveByAnyCode($legacyCode);
                if (! is_array($identity) || ! (bool) ($identity['is_known'] ?? false)) {
                    $skippedUnknown++;
                    continue;
                }

                $resolvedV2 = strtoupper(trim((string) ($identity['scale_code_v2'] ?? '')));
                $resolvedUid = trim((string) ($identity['scale_uid'] ?? ''));
                if ($resolvedV2 === '') {
                    $skippedUnknown++;
                    continue;
                }

                $currentV2 = strtoupper(trim((string) ($row->scale_code_v2 ?? '')));
                $currentUid = trim((string) ($row->scale_uid ?? ''));

                $needsV2 = ($currentV2 === '' || $currentV2 !== $resolvedV2);
                $needsUid = ($resolvedUid !== '' && $currentUid !== $resolvedUid);
                if (! $needsV2 && ! $needsUid) {
                    continue;
                }

                if (! $dryRun) {
                    $payload = [
                        'scale_code_v2' => $resolvedV2,
                        'updated_at' => now(),
                    ];
                    if ($resolvedUid !== '') {
                        $payload['scale_uid'] = $resolvedUid;
                    }

                    DB::table('results')
                        ->where('id', $resultId)
                        ->update($payload);
                }

                $updated++;
            }
        } while (true);

        $this->info(sprintf(
            'backfill_results_scale_identity scanned=%d updated=%d skipped_unknown=%d dry_run=%d',
            $scanned,
            $updated,
            $skippedUnknown,
            $dryRun ? 1 : 0
        ));

        return self::SUCCESS;
    }
}


<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NormsEq60Activate extends Command
{
    private const SCALE_CODE = 'EQ_60';

    protected $signature = 'norms:eq60:activate
        {--version= : Norms version to activate}
        {--group_id= : Optional group id scope}
        {--locale= : Optional locale scope}';

    protected $description = 'Activate an imported EQ_60 norms version and retire older active rows in the same group.';

    public function handle(): int
    {
        if (!Schema::hasTable('scale_norms_versions')) {
            $this->error('Missing required table: scale_norms_versions.');

            return 1;
        }

        $version = trim((string) $this->option('version'));
        if ($version === '') {
            $this->error('--version is required.');

            return 1;
        }

        $groupId = trim((string) $this->option('group_id'));
        $locale = trim((string) $this->option('locale'));

        $query = DB::table('scale_norms_versions')
            ->where('scale_code', self::SCALE_CODE)
            ->where('version', $version);
        if ($groupId !== '') {
            $query->where('group_id', $groupId);
        }
        if ($locale !== '') {
            $query->where('locale', $locale);
        }

        $rows = $query->get(['id', 'group_id', 'locale', 'region']);
        if ($rows->isEmpty()) {
            $this->error('No matching EQ_60 norms versions found to activate.');

            return 1;
        }

        $now = now();
        $activated = 0;

        DB::transaction(function () use ($rows, $now, &$activated): void {
            foreach ($rows as $row) {
                $id = (string) ($row->id ?? '');
                $groupId = (string) ($row->group_id ?? '');
                $locale = (string) ($row->locale ?? '');
                $region = (string) ($row->region ?? '');
                if ($id === '' || $groupId === '' || $locale === '' || $region === '') {
                    continue;
                }

                DB::table('scale_norms_versions')
                    ->where('scale_code', self::SCALE_CODE)
                    ->where('group_id', $groupId)
                    ->where('locale', $locale)
                    ->where('region', $region)
                    ->where('id', '!=', $id)
                    ->update([
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);

                $activated += DB::table('scale_norms_versions')
                    ->where('id', $id)
                    ->update([
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);
            }
        });

        $this->info("activated rows={$activated}");

        return 0;
    }
}

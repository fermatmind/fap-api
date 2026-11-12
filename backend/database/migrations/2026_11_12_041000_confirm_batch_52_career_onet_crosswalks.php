<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

return new class extends Migration
{
    private const REPORTS = [
        'career_onet_crosswalk_review_batch52_01.json' => ['9082899b6d97ce6835742d40bc27220291d1178e092c399f12c96c66256ec387', 100],
        'career_onet_crosswalk_review_batch52_02.json' => ['432e67a5e69842d05d8a698de947ae27300c22cc9616b0becef835a3f5d4cf67', 100],
        'career_onet_crosswalk_review_batch52_03.json' => ['fd121bcdeaf43435f55c61db7a156fdf4ba7645c17c76e504ec93de7e62f2dd7', 100],
        'career_onet_crosswalk_review_batch52_04.json' => ['dc80d15269faf582aab4f56cbaf6188a5595e404be33e512be1227e98ec03c84', 100],
        'career_onet_crosswalk_review_batch52_05.json' => ['27363f1cff9cf3a95923a85d3d076ca6c2dbabe0f386bd4c7fa6ab073ad64490', 8],
    ];

    public function up(): void
    {
        // Staging's partial cohort cannot prove the full production mapping set.
        if (! app()->environment('production')) {
            return;
        }

        $receipts = DB::transaction(function (): array {
            $receipts = [];
            foreach (self::REPORTS as $file => [$sha, $count]) {
                try {
                    $planned = $this->review($file, $sha, $count, false);
                    $applied = $this->review($file, $sha, $count, true);
                } finally {
                    // A reader caches resolved pages. Retain at most one partition.
                    app()->forgetInstance(CareerContentV3CanonicalReader::class);
                }
                if ($planned['changed_or_planned'] !== $applied['changed_or_planned']
                    || $planned['already_confirmed_slugs'] !== $applied['already_confirmed_slugs']
                    || $planned['held'] !== $applied['held']) {
                    throw new RuntimeException('Batch 5.2 mapping changed between preflight and apply.');
                }
                $receipts[$file] = [
                    'report_sha256' => $sha,
                    'dry_run' => $planned,
                    'confirmed' => $applied['changed_or_planned'],
                    'already_confirmed' => $applied['already_confirmed_slugs'],
                    'held' => $applied['held'],
                ];
            }

            return $receipts;
        });

        Log::notice('career_onet_crosswalk_batch_52_applied', ['reports' => $receipts]);
    }

    public function down(): void
    {
        // The confirmed O*NET code itself is unchanged and remains backward compatible.
    }

    private function review(string $file, string $sha, int $count, bool $apply): array
    {
        $output = new BufferedOutput;
        $options = ['--report' => __DIR__.'/../data/'.$file, '--sha256' => $sha];
        if ($apply) {
            $options['--apply'] = true;
        }
        if (Artisan::call('career:confirm-reviewed-onet-crosswalks', $options, $output) !== 0) {
            throw new RuntimeException('Batch 5.2 failed native mapping verification: '.trim($output->fetch()));
        }
        $receipt = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        if ($receipt['selected'] !== $count || $receipt['report_sha256'] !== $sha
            || $receipt['mode'] !== ($apply ? 'apply' : 'dry_run')
            || count($receipt['changed_or_planned']) + $receipt['already_confirmed'] + count($receipt['held']) !== $count) {
            throw new RuntimeException('Batch 5.2 mapping receipt scope is incomplete.');
        }

        return $receipt;
    }
};

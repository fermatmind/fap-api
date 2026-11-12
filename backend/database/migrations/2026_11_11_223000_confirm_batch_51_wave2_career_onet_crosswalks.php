<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

return new class extends Migration
{
    private const REPORT_SHA256 = '5bd0c8e8caaa76cbb17666f099ecce6e6b63c70b96ac5236abc46747694ea48d';

    public function up(): void
    {
        // Staging carries a partial Career cohort. The command and frozen report are exercised in CI.
        if (! app()->environment('production')) {
            return;
        }

        $arguments = [
            '--report' => __DIR__.'/../data/career_onet_crosswalk_review_batch51_wave2_20261111.json',
            '--sha256' => self::REPORT_SHA256,
        ];
        [$preflight, $applied] = DB::transaction(function () use ($arguments): array {
            $preflight = $this->runReview($arguments);
            $this->assertComplete($preflight);
            $applied = $this->runReview($arguments + ['--apply' => true]);
            $this->assertComplete($applied);

            return [$preflight, $applied];
        });

        Log::notice('career_onet_crosswalk_batch_51_wave2_applied', [
            'review_sha256' => self::REPORT_SHA256,
            'preflight_held' => $preflight['held'],
            'confirmed' => count($applied['changed_or_planned']),
            'already_confirmed' => $applied['already_confirmed'],
            'held' => $applied['held'],
        ]);
    }

    public function down(): void
    {
        // The confirmed code is unchanged and can be read by the previous API release.
    }

    private function assertComplete(array $receipt): void
    {
        if ($receipt['held'] !== [] || $receipt['selected'] !== 6
            || count($receipt['changed_or_planned']) + $receipt['already_confirmed'] !== 6) {
            throw new RuntimeException('Career batch 5.1 wave 2 did not confirm the exact six occupations.');
        }
    }

    private function runReview(array $arguments): array
    {
        $output = new BufferedOutput;
        $status = Artisan::call('career:confirm-reviewed-onet-crosswalks', $arguments, $output);
        $receipt = trim($output->fetch());
        if ($status !== 0) {
            throw new RuntimeException('Career batch 5.1 wave 2 crosswalk release blocked: '.$receipt);
        }

        return json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
    }
};

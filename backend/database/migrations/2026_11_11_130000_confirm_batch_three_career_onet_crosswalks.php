<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

return new class extends Migration
{
    private const REPORT_SHA256 = '050e82d4542895a3f4c1fb958cd79b424478392ebf2e0dbe75e6c679a68922f7';

    public function up(): void
    {
        // Staging carries a partial Career cohort. The command and frozen report are exercised in CI.
        if (! app()->environment('production')) {
            return;
        }

        $arguments = [
            '--report' => __DIR__.'/../data/career_onet_crosswalk_review_batch3_20261111.json',
            '--sha256' => self::REPORT_SHA256,
        ];
        $preflight = $this->runReview($arguments);
        $applied = $this->runReview($arguments + ['--apply' => true]);

        Log::notice('career_onet_crosswalk_batch_three_applied', [
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

    private function runReview(array $arguments): array
    {
        $output = new BufferedOutput;
        $status = Artisan::call('career:confirm-reviewed-onet-crosswalks', $arguments, $output);
        $receipt = trim($output->fetch());
        if ($status !== 0) {
            throw new RuntimeException('Career batch three crosswalk release blocked: '.$receipt);
        }

        return json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
    }
};

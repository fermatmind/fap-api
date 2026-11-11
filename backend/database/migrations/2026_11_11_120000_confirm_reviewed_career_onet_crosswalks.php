<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;

return new class extends Migration
{
    private const REPORT_SHA256 = '61cf8e7b81bb1ff73e0da0c6d78352bb8dbbe66f56e5151eac916549f4af99db';

    public function up(): void
    {
        // Staging carries a partial Career cohort. The exact full cohort is exercised in CI/local.
        if (! app()->environment('production')) {
            return;
        }

        $arguments = [
            '--report' => __DIR__.'/../data/career_onet_crosswalk_review_20261111.json',
            '--sha256' => self::REPORT_SHA256,
        ];
        $preflight = $this->runReview($arguments);
        if ($preflight['selected'] !== 90) {
            throw new RuntimeException('Career crosswalk preflight must select the 90 reviewed occupations.');
        }

        $applied = $this->runReview($arguments + ['--apply' => true]);
        if ($applied['selected'] !== 90) {
            throw new RuntimeException('Career crosswalk confirmation selected an unexpected cohort.');
        }

        Log::notice('career_onet_crosswalk_review_applied', [
            'review_sha256' => self::REPORT_SHA256,
            'selected' => $applied['selected'],
            'confirmed' => count($applied['changed_or_planned']),
        ]);
    }

    public function down(): void
    {
        // Confirmed source codes remain compatible with the previous API release.
    }

    private function runReview(array $arguments): array
    {
        $output = new BufferedOutput;
        $status = Artisan::call('career:confirm-reviewed-onet-crosswalks', $arguments, $output);
        $receipt = trim($output->fetch());
        if ($status !== 0) {
            throw new RuntimeException('Career crosswalk release blocked: '.$receipt);
        }

        return json_decode($receipt, true, flags: JSON_THROW_ON_ERROR);
    }
};

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerConfirmReviewedOnetCrosswalks extends Command
{
    protected $signature = 'career:confirm-reviewed-onet-crosswalks
        {--report= : Path to the reviewed 618-occupation evidence report}
        {--sha256= : SHA-256 of those exact report bytes}
        {--apply : Confirm the selected crosswalks in one transaction}';

    protected $description = 'Preflight or confirm existing O*NET directory crosswalks from a frozen review';

    public function handle(CareerContentV3CanonicalReader $reader): int
    {
        try {
            $path = (string) $this->option('report');
            $digest = (string) $this->option('sha256');
            $bytes = is_file($path) && filesize($path) <= 2_000_000 ? file_get_contents($path) : false;
            if (! is_string($bytes) || ! hash_equals(hash('sha256', $bytes), $digest)) {
                throw new RuntimeException('Review report digest does not match.');
            }

            $report = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $authority = $reader->authority();
            $manifest = $authority['manifest'];
            if (($report['career_manifest_sha256'] ?? null) !== $manifest['aggregate_sha256']
                || count($report['rows'] ?? []) !== 618) {
                throw new RuntimeException('Review report does not match the installed Career manifest and cohort.');
            }

            $selected = [];
            $seen = [];
            foreach ($report['rows'] as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if (isset($seen[$slug]) || ! isset($authority['entries'][$slug]['en'], $authority['entries'][$slug]['zh-CN'])) {
                    throw new RuntimeException('Review report contains a duplicate or unknown Career identity.');
                }
                $seen[$slug] = true;
                if (($row['status'] ?? null) === 'hold') {
                    continue;
                }
                $code = (string) ($row['onet'] ?? '');
                if (($row['status'] ?? null) !== 'review_pass' || ($row['reasons'] ?? null) !== []
                    || isset($manifest['identity_scopes'][$slug])
                    || preg_match('/^\d{2}-\d{4}\.\d{2}$/D', $code) !== 1
                    || ($row['soc'] ?? null) !== substr($code, 0, 7)
                    || ! in_array($row['title_evidence'] ?? null, ['official_title', 'unique_alias'], true)
                    || count(array_unique($row['official_core_task_ids'] ?? [])) < 2
                    || count($row['bilingual_task_counts'] ?? []) !== 2
                    || min($row['bilingual_task_counts']) < 3
                    || count($row['vector'] ?? []) !== 6
                    || ! $reader->hasPublicBody($slug, 'en') || ! $reader->hasPublicBody($slug, 'zh-CN')) {
                    throw new RuntimeException("Invalid mapping evidence for {$slug}.");
                }
                $selected[$slug] = $code;
            }
            if ($selected === [] || count($selected) !== ($report['review_pass'] ?? null)) {
                throw new RuntimeException('Reviewed selection count does not match.');
            }
            ksort($selected);
            $apply = (bool) $this->option('apply');

            $changes = DB::transaction(function () use ($selected, $apply): array {
                $changes = [];
                foreach ($selected as $slug => $code) {
                    $occupation = Occupation::query()->where('canonical_slug', $slug)->lockForUpdate()->first();
                    $rows = $occupation?->crosswalks()->whereIn('source_system', ['us_soc', 'onet_soc_2019'])->lockForUpdate()->get();
                    $onetRows = $rows?->where('source_system', 'onet_soc_2019')->values();
                    $socRows = $rows?->where('source_system', 'us_soc')->values();
                    $onet = $onetRows?->first();
                    $soc = $socRows?->first();
                    if ($onetRows?->count() !== 1 || $socRows?->count() !== 1 || $onet->source_code !== $code
                        || $soc->source_code !== substr($code, 0, 7) || $soc->mapping_type !== 'direct_match') {
                        throw new RuntimeException("Live crosswalk differs for {$slug}.");
                    }
                    if ($onet->mapping_type === 'direct_match' && $onet->confidence_score >= 0.8) {
                        continue;
                    }
                    if ($onet->mapping_type !== 'directory_candidate' || $onet->confidence_score !== 0.5) {
                        throw new RuntimeException("Live mapping status differs for {$slug}.");
                    }

                    $changes[] = $slug;
                    if ($apply) {
                        $onet->update(['mapping_type' => 'direct_match', 'confidence_score' => 1.0]);
                        $confirmed = OccupationCrosswalk::query()->findOrFail($onet->id);
                        if ($confirmed->source_code !== $code || $confirmed->mapping_type !== 'direct_match'
                            || $confirmed->confidence_score !== 1.0) {
                            throw new RuntimeException("Crosswalk readback failed for {$slug}.");
                        }
                    }
                }

                return $changes;
            });

            $this->line(json_encode([
                'report_sha256' => $digest,
                'mode' => $apply ? 'apply' : 'dry_run',
                'selected' => count($selected),
                'already_confirmed' => count($selected) - count($changes),
                'changed_or_planned' => $changes,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Crosswalk confirmation failed.');

            return self::FAILURE;
        }
    }
}

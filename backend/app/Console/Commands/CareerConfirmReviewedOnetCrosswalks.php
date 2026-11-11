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
        {--report= : Path to the frozen occupation evidence report}
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
            $batchThree = ($report['schema'] ?? null) === 'career.onet-crosswalk-review.batch-3.v1';
            $batchFive = ($report['schema'] ?? null) === 'career.onet-crosswalk-review.batch-5.v1';
            $boundEvidence = $batchThree || $batchFive;
            $rowCount = count($report['rows'] ?? []);
            $validSize = $batchFive ? $rowCount >= 1 && $rowCount <= 100 && ($report['reviewed'] ?? null) === $rowCount
                : $rowCount === ($batchThree ? 157 : 618);
            $authority = $reader->authority();
            $manifest = $authority['manifest'];
            if (($report['career_manifest_sha256'] ?? null) !== $manifest['aggregate_sha256']
                || ! $validSize
                || ($batchFive && ($report['onet_version'] ?? null) !== '31.0')) {
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
                    || ! $this->hasTitleEvidence($row, $boundEvidence)
                    || count(array_unique($row['official_core_task_ids'] ?? [])) < 2
                    || count($row['bilingual_task_counts'] ?? []) !== 2
                    || min($row['bilingual_task_counts']) < 3
                    || count($row['vector'] ?? []) !== 6
                    || ($boundEvidence && ! $this->hasBoundEvidence($row, $authority['entries'][$slug]))
                    || ($batchFive && ! $this->hasTaskBindings($row, $reader))
                    || ! $reader->hasPublicBody($slug, 'en') || ! $reader->hasPublicBody($slug, 'zh-CN')) {
                    throw new RuntimeException("Invalid mapping evidence for {$slug}.");
                }
                $selected[$slug] = $code;
            }
            if ((! $batchThree && $selected === []) || count($selected) !== ($report['review_pass'] ?? null)) {
                throw new RuntimeException('Reviewed selection count does not match.');
            }
            ksort($selected);
            $apply = (bool) $this->option('apply');

            $result = DB::transaction(function () use ($selected, $apply, $boundEvidence): array {
                $changes = [];
                $confirmedSlugs = [];
                $held = [];
                foreach ($selected as $slug => $code) {
                    $occupation = Occupation::query()->where('canonical_slug', $slug)->lockForUpdate()->first();
                    $rows = $occupation?->crosswalks()->whereIn('source_system', ['us_soc', 'onet_soc_2019'])->lockForUpdate()->get();
                    $onetRows = $rows?->where('source_system', 'onet_soc_2019')->values();
                    $socRows = $rows?->where('source_system', 'us_soc')->values();
                    $onet = $onetRows?->first();
                    $soc = $socRows?->first();
                    if ($onetRows?->count() !== 1 || $socRows?->count() !== 1 || $onet->source_code !== $code
                        || $soc->source_code !== substr($code, 0, 7) || $soc->mapping_type !== 'direct_match') {
                        if ($boundEvidence) {
                            $held[$slug] = 'live_crosswalk_differs';

                            continue;
                        }
                        throw new RuntimeException("Live crosswalk differs for {$slug}.");
                    }
                    if ($onet->mapping_type === 'direct_match' && $onet->confidence_score >= 0.8) {
                        $confirmedSlugs[] = $slug;

                        continue;
                    }
                    if ($onet->mapping_type !== 'directory_candidate' || $onet->confidence_score !== 0.5) {
                        if ($boundEvidence) {
                            $held[$slug] = 'live_mapping_status_differs';

                            continue;
                        }
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

                return ['changes' => $changes, 'confirmed' => $confirmedSlugs, 'held' => $held];
            });

            $receipt = [
                'report_sha256' => $digest,
                'mode' => $apply ? 'apply' : 'dry_run',
                'selected' => count($selected),
                'already_confirmed' => count($result['confirmed']),
                'changed_or_planned' => $result['changes'],
            ];
            if ($boundEvidence) {
                $receipt['already_confirmed_slugs'] = $result['confirmed'];
                $receipt['held'] = $result['held'];
            }
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Crosswalk confirmation failed.');

            return self::FAILURE;
        }
    }

    private function hasTaskBindings(array $row, CareerContentV3CanonicalReader $reader): bool
    {
        $ids = $row['official_core_task_ids'];
        if (! array_is_list($ids) || count($ids) !== count(array_unique($ids))) {
            return false;
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $bindings = $row['task_bindings'][$locale] ?? null;
            if (! is_array($bindings) || array_keys($bindings) !== array_map('intval', $ids)
                || count($bindings) !== count(array_unique($bindings))) {
                return false;
            }
            $page = $reader->page($row['slug'], $locale);
            $tasks = $this->citedResponsibilities($page, $row['onet']);
            if (count(array_unique($tasks)) < 3) {
                return false;
            }
            foreach ($bindings as $pointer) {
                if (! is_string($pointer) || ! isset($tasks[$pointer])) {
                    return false;
                }
            }
            if (count(array_unique(array_map(static fn (string $pointer): string => $tasks[$pointer], $bindings))) !== count($bindings)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function citedResponsibilities(array $page, string $code): array
    {
        $sourceIds = [];
        $items = [];
        foreach ($page['blocks'] as $blockIndex => $block) {
            if ($block['availability'] !== 'available') {
                continue;
            }
            foreach ($block['items'] as $itemIndex => $item) {
                if ($item['availability'] !== 'available') {
                    continue;
                }
                if ($item['copy_key'] === 'career.item.responsibilities-block') {
                    $items["/blocks/{$blockIndex}/items/{$itemIndex}"] = $item;
                }
                if ($item['type'] !== 'sources') {
                    continue;
                }
                foreach ($item['data']['entries'] as $source) {
                    if (preg_match('~^https://(?:www\\.)?onetonline\\.org/link/(?:summary|details|custom)/([^/?#]+)(?:[/?#]|$)~', $source['url'] ?? '', $match)) {
                        $sourceIds[$source['id']] = $match[1];
                    }
                }
            }
        }

        $tasks = [];
        foreach ($items as $pointer => $item) {
            $codes = array_intersect_key($sourceIds, array_flip($item['source_refs'] ?? []));
            if ($codes === [] || array_values(array_unique($codes)) !== [$code]) {
                continue;
            }
            foreach ($item['data']['entries'] as $index => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $tasks["{$pointer}/data/entries/{$index}"] = trim($text);
                }
            }
        }

        return $tasks;
    }

    private function hasTitleEvidence(array $row, bool $batchThree): bool
    {
        return in_array($row['title_evidence'] ?? null, ['official_title', 'unique_alias'], true)
            || ($batchThree && ($row['title_evidence'] ?? null) === 'reviewed_scope'
                && trim((string) ($row['scope_evidence'] ?? '')) !== '');
    }

    private function hasBoundEvidence(array $row, array $entries): bool
    {
        $fingerprints = $row['body_fingerprints'] ?? [];
        foreach (['en', 'zh-CN'] as $locale) {
            if (! isset($fingerprints[$locale], $entries[$locale]['source_content_sha256'])
                || $fingerprints[$locale] !== $entries[$locale]['source_content_sha256']) {
                return false;
            }
        }

        foreach ($row['vector'] as $value) {
            if ((! is_int($value) && ! is_float($value)) || $value < 1 || $value > 7) {
                return false;
            }
        }

        return true;
    }
}

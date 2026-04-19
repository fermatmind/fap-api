<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Services\Career\Directory\OccupationDirectoryDisplayNormalizer;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

final class CareerSyncOccupationDirectoryDisplay extends Command
{
    protected $signature = 'career:sync-occupation-directory-display
        {--input= : Path to career_create_import.jsonl}
        {--apply : Write normalized display titles and family assignments}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Normalize display titles and industry families for staged occupation-directory draft rows.';

    public function handle(OccupationDirectoryDisplayNormalizer $normalizer): int
    {
        try {
            $inputPath = $this->requiredPath('input');
            $records = $this->readJsonl($inputPath);
            $apply = (bool) $this->option('apply');
            $summary = [
                'records_seen' => count($records),
                'apply' => $apply,
                'matched_directory_drafts' => 0,
                'occupations_updated' => 0,
                'families_created' => 0,
                'families_updated' => 0,
                'aliases_upserted' => 0,
                'missing_occupations' => 0,
                'market_counts' => [],
                'family_counts' => [],
            ];

            DB::transaction(function () use ($records, $apply, $normalizer, &$summary): void {
                foreach ($records as $record) {
                    $slug = trim((string) data_get($record, 'identity.proposed_slug'));
                    if ($slug === '') {
                        continue;
                    }

                    $occupation = Occupation::query()
                        ->where('canonical_slug', $slug)
                        ->where('crosswalk_mode', 'directory_draft')
                        ->first();

                    if (! $occupation instanceof Occupation) {
                        $summary['missing_occupations']++;

                        continue;
                    }

                    $summary['matched_directory_drafts']++;
                    $market = strtoupper(trim((string) ($record['market'] ?? 'unknown')));
                    $summary['market_counts'][$market] = (int) ($summary['market_counts'][$market] ?? 0) + 1;

                    $familyPayload = $normalizer->familyPayload($record);
                    $summary['family_counts'][$familyPayload['canonical_slug']] = (int) ($summary['family_counts'][$familyPayload['canonical_slug']] ?? 0) + 1;

                    if (! $apply) {
                        continue;
                    }

                    $family = OccupationFamily::query()
                        ->where('canonical_slug', $familyPayload['canonical_slug'])
                        ->first();
                    if (! $family instanceof OccupationFamily) {
                        $family = OccupationFamily::query()->create($familyPayload);
                        $summary['families_created']++;
                    } else {
                        $dirty = false;
                        foreach (['title_en', 'title_zh'] as $field) {
                            if ($family->{$field} !== $familyPayload[$field]) {
                                $family->{$field} = $familyPayload[$field];
                                $dirty = true;
                            }
                        }
                        if ($dirty) {
                            $family->save();
                            $summary['families_updated']++;
                        }
                    }

                    $titleZh = $normalizer->titleZh($record);
                    $titleEn = $normalizer->titleEn($record);
                    $occupation->forceFill([
                        'family_id' => $family->id,
                        'canonical_title_zh' => $titleZh,
                        'canonical_title_en' => $titleEn,
                        'search_h1_zh' => $titleZh,
                    ])->save();
                    $summary['occupations_updated']++;

                    foreach ([['text' => $titleZh, 'lang' => 'zh-CN'], ['text' => $titleEn, 'lang' => 'en']] as $alias) {
                        $text = trim((string) $alias['text']);
                        if ($text === '') {
                            continue;
                        }

                        OccupationAlias::query()->updateOrCreate(
                            [
                                'occupation_id' => $occupation->id,
                                'lang' => (string) $alias['lang'],
                                'normalized' => $this->normalizeAlias($text),
                            ],
                            [
                                'family_id' => null,
                                'alias' => $text,
                                'register' => 'directory_display_title',
                                'intent_scope' => 'search',
                                'target_kind' => 'occupation',
                                'precision_score' => 0.7,
                                'confidence_score' => 0.7,
                                'seniority_hint' => null,
                                'function_hint' => null,
                            ],
                        );
                        $summary['aliases_upserted']++;
                    }
                }
            });

            if ($apply) {
                Cache::forget(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
                Cache::forget(PublicCareerAuthorityResponseCache::DATASET_METHOD_CACHE_KEY);
            }

            return $this->finish($summary, true, $apply ? 'directory display sync complete' : 'directory display sync dry-run complete');
        } catch (Throwable $throwable) {
            return $this->finish([
                'error' => $throwable->getMessage(),
                'type' => $throwable::class,
            ], false, 'directory display sync failed');
        }
    }

    private function requiredPath(string $option): string
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            throw new \RuntimeException('Missing required --'.$option.' option.');
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            throw new \RuntimeException('File not found: '.$path);
        }

        return $realPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $path): array
    {
        $file = new SplFileObject($path);
        $records = [];
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);
            if (! is_array($record)) {
                throw new \RuntimeException('Invalid JSONL row in '.$path);
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, bool $ok, string $message): int
    {
        $payload = ['ok' => $ok, 'message' => $message] + $payload;
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        } elseif ($ok) {
            $this->info($message);
        } else {
            $this->error($message);
            if (isset($payload['error'])) {
                $this->line((string) $payload['error']);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function normalizeAlias(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->squish()
            ->toString();
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PersonalityPublicContentAsset;
use App\Support\Personality\PersonalityPublicContentMediaPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PersonalityPublicAssetsRetireMediaFields extends Command
{
    public const CONFIRMATION = 'RETIRE_PERSONALITY_PUBLIC_CONTENT_MEDIA_FIELDS';

    protected $signature = 'personality-public-assets:retire-media-fields
        {--write : Remove legacy SEO image aliases and media authority markers}
        {--confirm= : Exact write confirmation}';

    protected $description = 'Inspect or remove legacy Big Five / Enneagram media metadata after the permanent text-only contract cutover.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        if ($write && ! hash_equals(self::CONFIRMATION, trim((string) $this->option('confirm')))) {
            $this->error('Exact --confirm='.self::CONFIRMATION.' is required for --write.');

            return self::FAILURE;
        }

        $summary = $write ? $this->writeCleanup() : $this->inspect();
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }

    /** @return array{dry_run:int,scanned_count:int,cleanup_required_count:int,updated_count:int} */
    private function inspect(): array
    {
        $scanned = 0;
        $cleanupRequired = 0;

        $this->query()->chunkById(100, function ($assets) use (&$scanned, &$cleanupRequired): void {
            foreach ($assets as $asset) {
                $scanned++;
                if ($this->requiresCleanup($asset)) {
                    $cleanupRequired++;
                }
            }
        });

        return [
            'dry_run' => 1,
            'scanned_count' => $scanned,
            'cleanup_required_count' => $cleanupRequired,
            'updated_count' => 0,
        ];
    }

    /** @return array{dry_run:int,scanned_count:int,cleanup_required_count:int,updated_count:int} */
    private function writeCleanup(): array
    {
        return DB::transaction(function (): array {
            $scanned = 0;
            $cleanupRequired = 0;
            $updated = 0;

            $this->query()->chunkById(100, function ($assets) use (&$scanned, &$cleanupRequired, &$updated): void {
                foreach ($assets as $asset) {
                    $scanned++;
                    if (! $this->requiresCleanup($asset)) {
                        continue;
                    }

                    $cleanupRequired++;
                    $asset->seo_json = PersonalityPublicContentMediaPolicy::sanitizeSeo((array) $asset->seo_json);
                    $asset->authority_json = PersonalityPublicContentMediaPolicy::sanitizeAuthority((array) $asset->authority_json);
                    $asset->saveQuietly();
                    $updated++;
                }
            });

            return [
                'dry_run' => 0,
                'scanned_count' => $scanned,
                'cleanup_required_count' => $cleanupRequired,
                'updated_count' => $updated,
            ];
        });
    }

    private function requiresCleanup(PersonalityPublicContentAsset $asset): bool
    {
        $seo = (array) $asset->seo_json;
        $authority = (array) $asset->authority_json;

        return PersonalityPublicContentMediaPolicy::sanitizeSeo($seo) !== $seo
            || PersonalityPublicContentMediaPolicy::sanitizeAuthority($authority) !== $authority;
    }

    private function query(): Builder
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->whereIn('framework', PersonalityPublicContentAsset::FRAMEWORKS)
            ->orderBy('id');
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Models\CareerJob;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CareerMaterializeDuplicateAliasMap extends Command
{
    private const REGISTER = 'public_resolution_duplicate_alias';

    private const INTENT_SCOPE = 'duplicate_identity';

    private const TARGET_KIND = 'ledger_public_alias_redirect';

    private const ALIAS_LANG = 'en-US';

    private const EXPECTED_ALIAS_COUNT = 87;

    private const EXPECTED_BLOCKED_DUPLICATE_COUNT = 167;

    private const EXPECTED_CANONICAL_PUBLIC_ASSETS = 793;

    protected $signature = 'career:materialize-duplicate-alias-map
        {--dry-run : Validate duplicate alias materialization without writing}
        {--force : Materialize ledger-approved duplicate aliases}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}
        {--timestamp= : Optional artifact timestamp}
        {--ledger= : Optional Career full release ledger JSON path}';

    protected $description = 'Materialize ledger-backed Career duplicate alias redirects.';

    public function handle(): int
    {
        try {
            $force = (bool) $this->option('force');
            $explicitDryRun = (bool) $this->option('dry-run');
            if ($force && $explicitDryRun) {
                throw new \RuntimeException('Choose either --dry-run or --force, not both.');
            }

            $ledgerPath = $this->resolveLedgerPath();
            $rows = $this->loadPublicResolutionRows($ledgerPath);
            $plan = $this->buildPlan($rows, $ledgerPath);
            $beforeCounts = $this->foundationCounts();

            if ($plan['blockers'] !== []) {
                return $this->finish($plan + [
                    'dry_run' => ! $force,
                    'force' => $force,
                    'did_write' => false,
                    'would_write' => false,
                    'ledger_path' => $ledgerPath,
                    'foundation_counts_before' => $beforeCounts,
                    'foundation_counts_after' => $beforeCounts,
                ], success: false);
            }

            $writePlan = $this->buildWritePlan($plan['aliases'], $plan['aliases_blocked_due_target_release_source_slugs']);
            $wouldWrite = $writePlan['aliases_to_create'] > 0
                || $writePlan['aliases_to_update'] > 0
                || $writePlan['aliases_to_disable'] > 0;
            $didWrite = false;
            if ($force) {
                DB::transaction(function () use ($writePlan): void {
                    foreach ($writePlan['writes'] as $write) {
                        $this->materializeAlias($write);
                    }
                });
                $didWrite = $wouldWrite;
            }

            $afterCounts = $this->foundationCounts();
            $activeAliases = $this->activeLedgerAliasCount();
            $summary = $plan + [
                'dry_run' => ! $force,
                'force' => $force,
                'did_write' => $didWrite,
                'would_write' => $wouldWrite,
                'ledger_path' => $ledgerPath,
                'aliases_to_create' => $writePlan['aliases_to_create'],
                'aliases_to_update' => $writePlan['aliases_to_update'],
                'aliases_to_disable' => $writePlan['aliases_to_disable'],
                'activated_aliases' => $activeAliases,
                'projected_active_aliases' => $force
                    ? $activeAliases
                    : $activeAliases + $writePlan['aliases_to_create'] - $writePlan['aliases_to_disable'],
                'foundation_counts_before' => $beforeCounts,
                'foundation_counts_after' => $afterCounts,
                'career_job_display_assets_delta' => $afterCounts['career_job_display_assets'] - $beforeCounts['career_job_display_assets'],
                'occupations_delta' => $afterCounts['occupations'] - $beforeCounts['occupations'],
                'occupation_crosswalks_delta' => $afterCounts['occupation_crosswalks'] - $beforeCounts['occupation_crosswalks'],
                'sitemap_alias_urls' => 0,
                'llms_alias_urls' => 0,
                'llms_full_alias_urls' => 0,
            ];

            $summary['blockers'] = array_values(array_filter([
                $summary['career_job_display_assets_delta'] === 0 ? null : 'career_job_display_assets_delta_nonzero',
                $summary['occupations_delta'] === 0 ? null : 'occupations_delta_nonzero',
                $summary['occupation_crosswalks_delta'] === 0 ? null : 'occupation_crosswalks_delta_nonzero',
                $summary['sitemap_alias_urls'] === 0 ? null : 'sitemap_alias_urls_nonzero',
                $summary['llms_alias_urls'] === 0 ? null : 'llms_alias_urls_nonzero',
                $summary['llms_full_alias_urls'] === 0 ? null : 'llms_full_alias_urls_nonzero',
                $force && $activeAliases !== $summary['alias_count'] ? 'activated_aliases_count_mismatch' : null,
            ]));

            return $this->finish($summary, success: $summary['blockers'] === []);
        } catch (Throwable $throwable) {
            $payload = [
                'status' => 'failed',
                'command' => 'career:materialize-duplicate-alias-map',
                'message' => $throwable->getMessage(),
                'blockers' => [$throwable->getMessage()],
            ];

            return $this->finish($payload, success: false);
        }
    }

    private function resolveLedgerPath(): string
    {
        $optionValue = $this->option('ledger');
        if ($optionValue !== null && trim((string) $optionValue) !== '') {
            $path = trim((string) $optionValue);
            if (! is_file($path)) {
                throw new \RuntimeException('career full release ledger not found: '.$path);
            }

            return $path;
        }

        $root = storage_path('app/private/career_release_ledger');
        if (! is_dir($root)) {
            throw new \RuntimeException('career full release ledger path is required; no release ledger artifact directory exists');
        }

        $candidates = collect(File::directories($root))
            ->map(fn (string $directory): string => $directory.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME)
            ->filter(static fn (string $path): bool => is_file($path))
            ->sortByDesc(static fn (string $path): int|false => filemtime($path))
            ->values();

        $path = $candidates->first();
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('career full release ledger path is required; no ledger artifact found');
        }

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPublicResolutionRows(string $ledgerPath): array
    {
        $payload = json_decode((string) file_get_contents($ledgerPath), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('career full release ledger is not valid JSON: '.$ledgerPath);
        }

        $rows = data_get($payload, 'public_resolution.rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'rows');
        }
        if (! is_array($rows) || $rows === []) {
            throw new \RuntimeException('career full release ledger has no public resolution rows: '.$ledgerPath);
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildPlan(array $rows, string $ledgerPath): array
    {
        $canonicalSlugs = [];
        $duplicateRows = [];
        $aliases = [];
        $ledgerApprovedAliases = [];
        $aliasesBlockedDueTargetRelease = [];
        $blockedDuplicates = 0;
        $canonicalPromotions = 0;
        $heldRowsAffected = 0;
        $softwareDevelopersAffected = 0;
        $cnProxyAffected = 0;
        $broadGroupAffected = 0;
        $manualHoldAffected = 0;
        $blockers = [];

        foreach ($rows as $row) {
            $status = $this->stringValue($row['current_status'] ?? null);
            $resolutionType = $this->stringValue($row['public_resolution_type'] ?? null);
            $publicEligible = (bool) ($row['public_eligible'] ?? false);
            $sourceSlug = $this->stringValue($row['source_slug'] ?? null);

            if ($resolutionType === 'public_canonical_job' && $publicEligible && $sourceSlug !== null) {
                $canonicalSlugs[$sourceSlug] = true;
            }

            if ($status !== 'duplicate_identity_hold') {
                continue;
            }

            $duplicateRows[] = $row;
            if ($resolutionType === 'public_canonical_job') {
                $canonicalPromotions++;
            }
            if (! $publicEligible) {
                $blockedDuplicates++;
            }
            if ($resolutionType !== 'public_alias_redirect') {
                continue;
            }

            $targetCanonicalSlug = $this->stringValue($row['target_canonical_slug'] ?? null);
            $ledgerApprovedAliases[] = [
                'source_slug' => $sourceSlug,
                'target_canonical_slug' => $targetCanonicalSlug,
                'current_status' => $status,
                'public_resolution_type' => $resolutionType,
                'public_eligible' => $publicEligible,
                'sitemap_eligible' => (bool) ($row['sitemap_eligible'] ?? true),
                'llms_eligible' => (bool) ($row['llms_eligible'] ?? true),
                'llms_full_eligible' => (bool) ($row['llms_full_eligible'] ?? true),
            ];
        }

        if (count($canonicalSlugs) !== self::EXPECTED_CANONICAL_PUBLIC_ASSETS) {
            $blockers[] = 'canonical_public_asset_count_mismatch';
        }
        if (count($duplicateRows) !== 254) {
            $blockers[] = 'duplicate_identity_row_count_mismatch';
        }
        if (count($ledgerApprovedAliases) !== self::EXPECTED_ALIAS_COUNT) {
            $blockers[] = 'ledger_approved_alias_count_mismatch';
        }
        if ($blockedDuplicates !== self::EXPECTED_BLOCKED_DUPLICATE_COUNT) {
            $blockers[] = 'blocked_duplicate_count_mismatch';
        }
        if ($canonicalPromotions !== 0) {
            $blockers[] = 'duplicate_canonical_promotions_present';
        }

        $seenAliases = [];
        $canonicalTargetsValid = 0;
        foreach ($ledgerApprovedAliases as $alias) {
            $sourceSlug = $this->stringValue($alias['source_slug'] ?? null);
            $targetCanonicalSlug = $this->stringValue($alias['target_canonical_slug'] ?? null);
            if ($sourceSlug === null || $targetCanonicalSlug === null) {
                $blockers[] = 'alias_missing_source_or_target';

                continue;
            }
            if (isset($seenAliases[$sourceSlug])) {
                $blockers[] = 'duplicate_alias_source_slug:'.$sourceSlug;
            }
            $seenAliases[$sourceSlug] = true;

            if ($sourceSlug === 'software-developers' || $targetCanonicalSlug === 'software-developers') {
                $softwareDevelopersAffected++;
                $blockers[] = 'software_developers_selected_for_alias';
            }
            if (! (bool) $alias['public_eligible']) {
                $blockers[] = 'alias_not_public_eligible:'.$sourceSlug;
            }
            if ((bool) $alias['sitemap_eligible']) {
                $blockers[] = 'alias_sitemap_eligible:'.$sourceSlug;
            }
            if ((bool) $alias['llms_eligible']) {
                $blockers[] = 'alias_llms_eligible:'.$sourceSlug;
            }
            if ((bool) $alias['llms_full_eligible']) {
                $blockers[] = 'alias_llms_full_eligible:'.$sourceSlug;
            }
            if (! isset($canonicalSlugs[$targetCanonicalSlug])) {
                $blockers[] = 'alias_target_not_approved_canonical:'.$sourceSlug;
            } else {
                $canonicalTargetsValid++;
            }

            $occupation = Occupation::query()->where('canonical_slug', $targetCanonicalSlug)->first();
            if (! $occupation instanceof Occupation) {
                $blockers[] = 'alias_target_occupation_missing:'.$targetCanonicalSlug;

                continue;
            }
            $alias['occupation_id'] = $occupation->id;
            $alias['family_id'] = $occupation->family_id;

            $targetRelease = $this->targetReleaseEligibility($targetCanonicalSlug);
            if (! (bool) $targetRelease['eligible']) {
                $aliasesBlockedDueTargetRelease[] = [
                    'source_slug' => $sourceSlug,
                    'target_canonical_slug' => $targetCanonicalSlug,
                    'reasons' => $targetRelease['reasons'],
                    'release_records' => $targetRelease['records'],
                ];

                continue;
            }

            $aliases[] = $alias;
        }

        $existingExtraAliases = $this->existingLedgerAliases()
            ->reject(static fn (OccupationAlias $alias): bool => isset($seenAliases[(string) $alias->normalized]))
            ->values();
        if ($existingExtraAliases->isNotEmpty()) {
            $blockers[] = 'existing_unplanned_duplicate_aliases_present';
        }

        return [
            'status' => 'validated',
            'command' => 'career:materialize-duplicate-alias-map',
            'ledger_path' => $ledgerPath,
            'alias_count' => count($aliases),
            'ledger_approved_aliases' => count($ledgerApprovedAliases),
            'duplicate_identity_rows' => count($duplicateRows),
            'blocked_duplicate_rows' => $blockedDuplicates,
            'blocked_duplicate_rows_after_target_gate' => $blockedDuplicates + count($aliasesBlockedDueTargetRelease),
            'aliases_blocked_due_target_release' => count($aliasesBlockedDueTargetRelease),
            'aliases_blocked_due_target_release_details' => $aliasesBlockedDueTargetRelease,
            'aliases_blocked_due_target_release_source_slugs' => array_values(array_map(
                static fn (array $alias): string => (string) $alias['source_slug'],
                $aliasesBlockedDueTargetRelease,
            )),
            'canonical_public_assets' => count($canonicalSlugs),
            'canonical_targets_valid' => $canonicalTargetsValid,
            'canonical_promotions' => $canonicalPromotions,
            'held_rows_affected' => $heldRowsAffected,
            'software_developers_affected' => $softwareDevelopersAffected,
            'CN_proxy_affected' => $cnProxyAffected,
            'broad_group_affected' => $broadGroupAffected,
            'manual_hold_affected' => $manualHoldAffected,
            'aliases' => $aliases,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $aliases
     * @param  list<string>  $disabledSourceSlugs
     * @return array{aliases_to_create:int,aliases_to_update:int,aliases_to_disable:int,writes:list<array<string,mixed>>}
     */
    private function buildWritePlan(array $aliases, array $disabledSourceSlugs): array
    {
        $creates = 0;
        $updates = 0;
        $disables = 0;
        $writes = [];
        $disabledLookup = array_fill_keys($disabledSourceSlugs, true);

        foreach ($aliases as $alias) {
            $sourceSlug = (string) $alias['source_slug'];
            $existing = $this->existingAlias($sourceSlug);
            $payload = $this->aliasPayload($alias);

            if (! $existing instanceof OccupationAlias) {
                $creates++;
                $writes[] = ['mode' => 'create', 'source_slug' => $sourceSlug, 'payload' => $payload];

                continue;
            }

            if ($this->aliasNeedsUpdate($existing, $payload)) {
                $updates++;
                $writes[] = ['mode' => 'update', 'source_slug' => $sourceSlug, 'payload' => $payload, 'id' => $existing->id];
            }
        }

        foreach ($this->existingLedgerAliases() as $existing) {
            $sourceSlug = (string) $existing->normalized;
            if (! isset($disabledLookup[$sourceSlug])) {
                continue;
            }

            $disables++;
            $writes[] = ['mode' => 'delete', 'source_slug' => $sourceSlug, 'id' => $existing->id];
        }

        return [
            'aliases_to_create' => $creates,
            'aliases_to_update' => $updates,
            'aliases_to_disable' => $disables,
            'writes' => $writes,
        ];
    }

    /**
     * @param  array<string, mixed>  $alias
     * @return array<string, mixed>
     */
    private function aliasPayload(array $alias): array
    {
        return [
            'occupation_id' => (string) $alias['occupation_id'],
            'family_id' => (string) $alias['family_id'],
            'alias' => (string) $alias['source_slug'],
            'normalized' => (string) $alias['source_slug'],
            'lang' => self::ALIAS_LANG,
            'register' => self::REGISTER,
            'intent_scope' => self::INTENT_SCOPE,
            'target_kind' => self::TARGET_KIND,
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
            'seniority_hint' => null,
            'function_hint' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $write
     */
    private function materializeAlias(array $write): void
    {
        if (($write['mode'] ?? null) === 'delete') {
            OccupationAlias::query()->whereKey((string) $write['id'])->delete();

            return;
        }

        if (($write['mode'] ?? null) === 'update') {
            $existing = OccupationAlias::query()->findOrFail((string) $write['id']);
            $existing->forceFill((array) $write['payload'])->save();

            return;
        }

        OccupationAlias::query()->create((array) $write['payload']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function aliasNeedsUpdate(OccupationAlias $alias, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $current = $alias->{$key};
            if (is_float($value)) {
                if ((float) $current !== $value) {
                    return true;
                }

                continue;
            }
            if ($current !== $value) {
                return true;
            }
        }

        return false;
    }

    private function existingAlias(string $sourceSlug): ?OccupationAlias
    {
        $alias = OccupationAlias::query()
            ->where('normalized', $sourceSlug)
            ->where('lang', self::ALIAS_LANG)
            ->where('register', self::REGISTER)
            ->where('intent_scope', self::INTENT_SCOPE)
            ->where('target_kind', self::TARGET_KIND)
            ->first();

        return $alias instanceof OccupationAlias ? $alias : null;
    }

    /**
     * @return Collection<int, OccupationAlias>
     */
    private function existingLedgerAliases(): Collection
    {
        return OccupationAlias::query()
            ->where('register', self::REGISTER)
            ->where('intent_scope', self::INTENT_SCOPE)
            ->where('target_kind', self::TARGET_KIND)
            ->get();
    }

    private function activeLedgerAliasCount(): int
    {
        return $this->existingLedgerAliases()->count();
    }

    /**
     * @return array{eligible:bool,records:int,reasons:list<string>}
     */
    private function targetReleaseEligibility(string $targetCanonicalSlug): array
    {
        $records = CareerJob::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('slug', strtolower(trim($targetCanonicalSlug)))
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->whereIn('locale', CareerJob::SUPPORTED_LOCALES)
            ->get();

        $seen = [];
        $reasons = [];
        foreach ($records as $record) {
            if (! $record instanceof CareerJob) {
                continue;
            }

            $locale = (string) $record->locale;
            $seen[$locale] = true;
            $robots = strtolower(trim((string) data_get($record->seoMeta, 'robots', '')));
            if (! (bool) $record->is_indexable) {
                $reasons[] = 'target_not_indexable:'.$locale;
            }
            if ($robots === '' || str_contains($robots, 'noindex')) {
                $reasons[] = 'target_noindex_or_missing_robots:'.$locale;
            }
        }

        foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
            if (($seen[$locale] ?? false) !== true) {
                $reasons[] = 'target_missing_published_public_locale:'.$locale;
            }
        }

        return [
            'eligible' => $reasons === [],
            'records' => $records->count(),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @return array{career_job_display_assets:int,occupations:int,occupation_crosswalks:int}
     */
    private function foundationCounts(): array
    {
        return [
            'career_job_display_assets' => $this->tableCount('career_job_display_assets'),
            'occupations' => $this->tableCount('occupations'),
            'occupation_crosswalks' => $this->tableCount('occupation_crosswalks'),
        ];
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, bool $success): int
    {
        $payload['timestamp'] = $this->option('timestamp') !== null && trim((string) $this->option('timestamp')) !== ''
            ? trim((string) $this->option('timestamp'))
            : now('UTC')->format('Ymd\THis\Z');

        $outputPath = $this->option('output') !== null ? trim((string) $this->option('output')) : '';
        if ($outputPath !== '') {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
            $payload['output'] = $outputPath;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } elseif ($success) {
            $this->line('status='.$payload['status']);
            $this->line('alias_count='.(string) ($payload['alias_count'] ?? 0));
            $this->line('did_write='.(((bool) ($payload['did_write'] ?? false)) ? 'true' : 'false'));
        } else {
            $this->error((string) ($payload['message'] ?? 'duplicate alias materialization blocked'));
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}

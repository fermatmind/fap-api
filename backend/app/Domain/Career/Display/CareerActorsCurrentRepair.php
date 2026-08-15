<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerActorsCurrentRepairFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        ?Throwable $previous = null,
        public readonly string $writeCommitState = 'confirmed_zero_write',
    ) {
        parent::__construct($safeCode, 0, $previous);
    }
}

/** One-time, single-row repair for the legacy Actors sections container. */
final class CareerActorsCurrentRepair
{
    public const CONTRACT_VERSION = 'career.actors_current_repair.v1';

    public const SOURCE_SHA256 = 'ea4f66f810f276a9debfd256cd311e1fbdaf989d99df7ea7a277fa7fb4ec90f6';

    public const WORKBUDDY_SHA256 = '6ee7a409fb4e2f15fed1709bd05fe2f7f28b22136ace589d8c308720520bc9bd';

    public const SOURCE_RELATIVE_PATH = 'content_assets/career/actors-current-repair-v1/actors_v4_2_pilot_master.json';

    public const WORKBUDDY_RELATIVE_PATH = 'content_assets/career/workbuddy-1046-display-v1/assets.jsonl';

    private const TARGET_SLUG = 'actors';

    private const LOCALES = ['en', 'zh-CN'];

    private const SECTION_MAP = [
        'fermat_quick_fit' => 'fermat_decision_card',
        'fit_decision' => 'fit_decision_checklist',
        'riasec_fit' => 'riasec_fit_block',
        'personality_fit' => 'personality_fit_block',
        'definition' => 'definition_block',
        'responsibilities' => 'responsibilities_block',
        'work_context' => 'work_context_block',
        'market_signal' => 'market_signal_card',
        'comparison' => 'adjacent_career_comparison_table',
        'ai_impact' => 'ai_impact_table',
        'career_risks' => 'career_risk_cards',
        'contract_risks' => 'contract_project_risk_block',
        'next_steps' => 'next_steps_block',
        'faq' => 'faq_block',
    ];

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $backendRoot, array $binding): array
    {
        $package = $this->buildTargetPackage($backendRoot);
        $asset = $this->uniqueAsset();
        $this->assertUntouchedPublicFields($asset, $package['source']);
        $before = $this->snapshot($asset);
        $targetMetadata = $this->targetMetadata((array) $asset->metadata_json, $package);
        $target = [
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            'page_payload_json' => $package['page'],
            'metadata_json' => $targetMetadata,
        ];
        $state = $this->classifyState($before, $target, $package);

        if ($state === 'applied') {
            $this->assertReadback($target);

            return $this->result($binding, $package, 'idempotent_noop', self::zeroWrites(), 'not_required');
        }

        $prepared = [];
        $databaseCommitted = false;
        $pointersActivated = false;
        $rollbackSnapshots = [];
        try {
            DB::transaction(function () use ($before, $target): void {
                $current = CareerJobDisplayAsset::query()->whereKey($before['id'])->lockForUpdate()->first();
                if (! $current instanceof CareerJobDisplayAsset || $this->snapshot($current) !== $before) {
                    throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_TARGET_DRIFT');
                }
                $affected = DB::table('career_job_display_assets')->where('id', $before['id'])->update([
                    'component_order_json' => self::encode($target['component_order_json']),
                    'page_payload_json' => self::encode($target['page_payload_json']),
                    'metadata_json' => self::encode($target['metadata_json']),
                    'updated_at' => now(),
                ]);
                if ($affected !== 1) {
                    throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_UPDATE_FAILED');
                }
                $this->assertDatabaseTarget($target);
            }, 1);
            $databaseCommitted = true;

            foreach (self::LOCALES as $locale) {
                $entry = $this->responseCache->preparePublishedJobDetailReplacement(self::TARGET_SLUG, $locale);
                if (($entry['status'] ?? null) !== 'ready' || ($entry['classification'] ?? null) !== 'ready_staged') {
                    throw new CareerActorsCurrentRepairFailure('ACTORS_CACHE_PREPARATION_FAILED');
                }
                $this->assertPreparedPayload($entry, $package['page'][$locale === 'zh-CN' ? 'zh' : 'en']);
                $prepared[] = $entry;
            }

            $activation = $this->responseCache->activatePreparedJobDetailPayloadsForExposure($prepared, true);
            if (($activation['status'] ?? null) !== 'pass'
                || count((array) ($activation['entries'] ?? [])) !== 2
                || ($activation['failures'] ?? []) !== []) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_CACHE_ACTIVATION_FAILED');
            }
            $pointersActivated = true;
            $rollbackSnapshots = (array) ($activation['rollback_snapshots'] ?? []);
            $this->assertReadback($target);

            return $this->result($binding, $package, 'repaired', [
                'database_update_count' => 1,
                'cache_candidate_write_count' => 4,
                'cache_pointer_activation_count' => 2,
                'cache_pointer_restore_count' => 0,
                'database_compensation_count' => 0,
                'generation_write_count' => 0,
                'discoverability_write_count' => 0,
                'occupation_write_count' => 0,
                'search_submission_count' => 0,
            ], 'not_required');
        } catch (Throwable $throwable) {
            $compensationFailure = null;
            $pointerRestoreFailed = false;
            try {
                if ($pointersActivated) {
                    $this->responseCache->restorePreparedJobDetailExposurePointers($prepared, $rollbackSnapshots);
                }
            } catch (Throwable $failedPointerRestore) {
                $compensationFailure = $failedPointerRestore;
                $pointerRestoreFailed = true;
            }
            if (! $pointerRestoreFailed) {
                try {
                    $this->responseCache->forgetPreparedJobDetailCandidates($prepared);
                } catch (Throwable $failedCandidateCleanup) {
                    $compensationFailure ??= $failedCandidateCleanup;
                }
                if ($databaseCommitted) {
                    try {
                        $this->restoreDatabase($before, $target);
                    } catch (Throwable $failedDatabaseRestore) {
                        $compensationFailure ??= $failedDatabaseRestore;
                    }
                }
            }
            if ($compensationFailure instanceof Throwable) {
                throw new CareerActorsCurrentRepairFailure(
                    'ACTORS_REPAIR_COMPENSATION_FAILED',
                    $compensationFailure,
                    'ambiguous',
                );
            }

            throw new CareerActorsCurrentRepairFailure(
                $throwable instanceof CareerActorsCurrentRepairFailure
                    ? $throwable->safeCode
                    : 'ACTORS_REPAIR_FAILED',
                $throwable,
                ($databaseCommitted || $prepared !== []) ? 'rolled_back' : 'confirmed_zero_write',
            );
        }
    }

    /** @return array{source: array<string,mixed>, page: array<string,mixed>, workbuddy: array<string,mixed>, hashes: array<string,string>} */
    public function buildTargetPackage(string $backendRoot): array
    {
        $sourcePath = rtrim($backendRoot, '/').'/'.self::SOURCE_RELATIVE_PATH;
        $workBuddyPath = rtrim($backendRoot, '/').'/'.self::WORKBUDDY_RELATIVE_PATH;
        if (! is_file($sourcePath) || ! hash_equals(self::SOURCE_SHA256, (string) hash_file('sha256', $sourcePath))) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_INVALID');
        }
        if (! is_file($workBuddyPath) || ! hash_equals(self::WORKBUDDY_SHA256, (string) hash_file('sha256', $workBuddyPath))) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_WORKBUDDY_SOURCE_INVALID');
        }
        $source = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($source)
            || data_get($source, 'asset.slug') !== self::TARGET_SLUG
            || ($source['component_order'] ?? null) !== CareerDisplayAssetComponentContract::LEGACY_V4_2_ORDER) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_CONTRACT_INVALID');
        }
        $workBuddy = $this->loadWorkBuddy($workBuddyPath);
        $page = [];
        foreach (['en', 'zh'] as $locale) {
            $sourcePage = data_get($source, 'page.'.$locale);
            if (! is_array($sourcePage)) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_LOCALE_INVALID');
            }
            $page[$locale] = $this->flattenPage($sourcePage, $source, $workBuddy[$locale === 'zh' ? 'zh-CN' : 'en'], $locale);
            $this->assertTargetPage($page[$locale]);
        }

        return [
            'source' => $source,
            'page' => $page,
            'workbuddy' => $workBuddy,
            'hashes' => [
                'source_sha256' => self::SOURCE_SHA256,
                'workbuddy_sha256' => self::WORKBUDDY_SHA256,
                'page_sha256' => self::hashValue($page),
            ],
        ];
    }

    /** @return array<string, array<string,mixed>> */
    private function loadWorkBuddy(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_WORKBUDDY_SOURCE_INVALID');
        }
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && ($decoded['slug'] ?? null) === self::TARGET_SLUG) {
                $locale = (string) ($decoded['locale'] ?? '');
                if (isset($rows[$locale]) || ! in_array($locale, self::LOCALES, true)) {
                    throw new CareerActorsCurrentRepairFailure('ACTORS_WORKBUDDY_ROWS_INVALID');
                }
                $blocks = $decoded['blocks'] ?? null;
                if (! is_array($blocks)
                    || array_keys($blocks) !== ['career_ai_description_block', 'career_path_block']
                    || preg_match('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', self::encode($blocks)) === 1) {
                    throw new CareerActorsCurrentRepairFailure('ACTORS_WORKBUDDY_ROWS_INVALID');
                }
                $rows[$locale] = $blocks;
            }
        }
        fclose($handle);
        if (array_keys($rows) !== self::LOCALES) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_WORKBUDDY_ROWS_INVALID');
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function flattenPage(array $sourcePage, array $source, array $workBuddy, string $locale): array
    {
        $sections = $sourcePage['sections'] ?? null;
        if (! is_array($sections) || count($sections) !== 16) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_SECTIONS_INVALID');
        }
        $byId = [];
        foreach ($sections as $section) {
            $id = is_array($section) ? trim((string) ($section['id'] ?? '')) : '';
            if ($id === '' || isset($byId[$id])) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_SECTIONS_INVALID');
            }
            $byId[$id] = $section;
        }
        $snapshotIds = $locale === 'zh'
            ? ['china_snapshot', 'us_bls_snapshot']
            : ['us_bls_snapshot', 'china_reference'];
        $expectedIds = array_merge(['fermat_quick_fit'], $snapshotIds, array_slice(array_keys(self::SECTION_MAP), 1));
        if (array_keys($byId) !== $expectedIds) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_SOURCE_SECTIONS_INVALID');
        }

        $attribution = [
            'target_action' => 'start_riasec_test',
            'entry_surface' => 'career_job_detail',
            'source_page_type' => 'career_job_detail',
            'subject_kind' => 'career_job',
            'subject_key' => self::TARGET_SLUG,
            'test_slug' => 'holland-career-interest-test-riasec',
        ];
        $primaryCta = array_merge((array) data_get($sourcePage, 'hero.primary_cta'), $attribution);
        $finalCta = array_merge((array) data_get($byId, 'next_steps.cta'), $attribution);
        $secondary = (array) data_get($sourcePage, 'hero.secondary_cta');
        $secondaryHrefs = array_values((array) ($secondary['hrefs'] ?? []));
        $related = [
            'primary_test' => (string) ($primaryCta['href'] ?? ''),
            'secondary_tests' => $secondaryHrefs,
            'validation_required' => (bool) data_get($source, 'support_components.related_next_pages_rules.validation_required'),
            'validation_rules' => array_values((array) data_get($source, 'support_components.related_next_pages_rules.rules')),
            'render_policy' => (string) data_get($source, 'support_components.related_next_pages_rules.render_policy'),
        ];
        $review = (array) data_get($source, 'support_components.review_validity');
        $review['review_status'] = 'overdue';
        $review['refresh_required'] = true;

        $page = [
            'path' => $sourcePage['path'] ?? null,
            'breadcrumb' => $sourcePage['breadcrumb'] ?? null,
            'hero' => $sourcePage['hero'] ?? null,
            'fermat_decision_card' => $byId['fermat_quick_fit'],
            'primary_cta' => $primaryCta,
            'career_snapshot_primary_locale' => $byId[$snapshotIds[0]],
            'career_snapshot_secondary_locale' => $byId[$snapshotIds[1]],
            'fit_decision_checklist' => $byId['fit_decision'],
            'riasec_fit_block' => $byId['riasec_fit'],
            'personality_fit_block' => $byId['personality_fit'],
            'definition_block' => $byId['definition'],
            'career_ai_description_block' => $workBuddy['career_ai_description_block'],
            'responsibilities_block' => $byId['responsibilities'],
            'work_context_block' => $byId['work_context'],
            'market_signal_card' => array_merge($byId['market_signal'], [
                'signal_status' => 'expired_snapshot',
                'current_market_claim_allowed' => false,
            ]),
            'adjacent_career_comparison_table' => $byId['comparison'],
            'ai_impact_table' => $byId['ai_impact'],
            'career_risk_cards' => $byId['career_risks'],
            'career_path_block' => $workBuddy['career_path_block'],
            'contract_project_risk_block' => $byId['contract_risks'],
            'next_steps_block' => $byId['next_steps'],
            'faq_block' => $byId['faq'],
            'related_next_pages' => $related,
            'source_card' => ['source_refs' => 'sources_json'],
            'review_validity_card' => $review,
            'boundary_notice' => array_values((array) data_get($source, 'support_components.boundary_notice.'.$locale)),
            'final_cta' => $finalCta,
            'secondary_cta' => [
                'label' => (string) ($secondary['label'] ?? ''),
                'hrefs' => $secondaryHrefs,
            ],
        ];

        return $page;
    }

    private function assertTargetPage(array $page): void
    {
        foreach (CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER as $component) {
            if (! array_key_exists($component, $page) || $page[$component] === null || $page[$component] === [] || $page[$component] === '') {
                throw new CareerActorsCurrentRepairFailure('ACTORS_TARGET_COMPONENT_INVALID');
            }
        }
        if (array_key_exists('sections', $page)
            || $this->containsPlaceholder($page)
            || preg_match('/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u', self::encode($page['career_ai_description_block'])) === 1) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_TARGET_COMPONENT_INVALID');
        }
    }

    private function uniqueAsset(): CareerJobDisplayAsset
    {
        $assets = CareerJobDisplayAsset::query()
            ->where('canonical_slug', self::TARGET_SLUG)
            ->where('surface_version', 'display.surface.v1')
            ->where('asset_version', 'v4.2')
            ->where('template_version', 'v4.2')
            ->where('asset_type', 'career_job_public_display')
            ->where('asset_role', 'formal_pilot_master')
            ->where('status', 'ready_for_pilot')
            ->get();
        if ($assets->count() !== 1 || ! $assets->first() instanceof CareerJobDisplayAsset) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_DISPLAY_ROW_NOT_UNIQUE');
        }

        return $assets->first();
    }

    private function assertUntouchedPublicFields(CareerJobDisplayAsset $asset, array $source): void
    {
        if (self::hashValue((array) $asset->seo_payload_json) !== self::hashValue((array) ($source['seo'] ?? []))
            || self::hashValue((array) $asset->sources_json) !== self::hashValue((array) ($source['sources'] ?? []))
            || self::hashValue((array) $asset->structured_data_json) !== self::hashValue((array) ($source['structured_data_from_visible_content'] ?? []))
            || self::hashValue((array) $asset->implementation_contract_json) !== self::hashValue((array) ($source['implementation_contract'] ?? []))) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_UNTOUCHED_PUBLIC_FIELD_DRIFT');
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $target @param array<string,mixed> $package */
    private function classifyState(array $before, array $target, array $package): string
    {
        if ($before['component_order_json'] === $target['component_order_json']
            && self::hashValue($before['page_payload_json']) === self::hashValue($target['page_payload_json'])
            && self::hashValue($before['metadata_json']) === self::hashValue($target['metadata_json'])) {
            return 'applied';
        }
        $pages = $this->localizedPages((array) $before['page_payload_json']);
        foreach (['en', 'zh'] as $locale) {
            $expectedSourcePage = data_get($package, 'source.page.'.$locale);
            if (! is_array($pages[$locale] ?? null) || ! is_array($expectedSourcePage)) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_INITIAL_STATE_INVALID');
            }
            $actual = $pages[$locale];
            $workBuddyLocale = $locale === 'zh' ? 'zh-CN' : 'en';
            $expectedInitial = $expectedSourcePage;
            $expectedInitial['career_ai_description_block'] = data_get($package, 'workbuddy.'.$workBuddyLocale.'.career_ai_description_block');
            $expectedInitial['career_path_block'] = data_get($package, 'workbuddy.'.$workBuddyLocale.'.career_path_block');
            if (self::hashValue($actual) !== self::hashValue($expectedInitial)) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_INITIAL_STATE_INVALID');
            }
            $missing = array_values(array_filter(
                CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
                static fn (string $component): bool => ! array_key_exists($component, $actual),
            ));
            if (count($missing) !== 22) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_INITIAL_STATE_INVALID');
            }
        }
        if ($before['component_order_json'] !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_INITIAL_STATE_INVALID');
        }

        return 'initial';
    }

    /** @return array<string,mixed> */
    private function targetMetadata(array $metadata, array $package): array
    {
        $metadata['actors_current_repair_lineage'] = [
            'contract_version' => self::CONTRACT_VERSION,
            'source_sha256' => self::SOURCE_SHA256,
            'workbuddy_sha256' => self::WORKBUDDY_SHA256,
            'target_page_sha256' => (string) data_get($package, 'hashes.page_sha256'),
        ];

        return $metadata;
    }

    /** @return array<string,mixed> */
    private function snapshot(CareerJobDisplayAsset $asset): array
    {
        return [
            'id' => (string) $asset->getKey(),
            'component_order_json' => array_values((array) $asset->component_order_json),
            'page_payload_json' => (array) $asset->page_payload_json,
            'metadata_json' => (array) $asset->metadata_json,
            'updated_at' => $asset->updated_at?->format('Y-m-d H:i:s.u'),
        ];
    }

    private function assertPreparedPayload(array $entry, array $expectedPage): void
    {
        $payload = $this->responseCache->preparedJobDetailReplacementPayload($entry);
        $surface = is_array($payload) ? data_get($payload, 'display_surface_v1') : null;
        if (! is_array($surface)
            || data_get($surface, 'component_order') !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
            || self::hashValue(data_get($surface, 'page.content')) !== self::hashValue($expectedPage)) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_CACHE_CANDIDATE_MISMATCH');
        }
    }

    private function assertDatabaseTarget(array $target): void
    {
        $asset = $this->uniqueAsset();
        if (array_values((array) $asset->component_order_json) !== $target['component_order_json']
            || self::hashValue((array) $asset->page_payload_json) !== self::hashValue($target['page_payload_json'])
            || self::hashValue((array) $asset->metadata_json) !== self::hashValue($target['metadata_json'])) {
            throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_READBACK_FAILED');
        }
    }

    private function assertReadback(array $target): void
    {
        $this->assertDatabaseTarget($target);
        foreach (self::LOCALES as $locale) {
            $expected = $target['page_payload_json'][$locale === 'zh-CN' ? 'zh' : 'en'];
            $readiness = $this->responseCache->jobDetailCacheReadiness(self::TARGET_SLUG, $locale);
            $api = $this->responseCache->jobDetailVerifyOnlyRead(self::TARGET_SLUG, $locale);
            if (($readiness['classification'] ?? null) !== 'ready_active'
                || ($api['state'] ?? null) !== 'fresh'
                || self::hashValue(data_get($readiness, 'payload.display_surface_v1.page.content')) !== self::hashValue($expected)
                || self::hashValue(data_get($api, 'payload.display_surface_v1.page.content')) !== self::hashValue($expected)) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_CACHE_READBACK_FAILED');
            }
        }
    }

    private function restoreDatabase(array $before, array $target): void
    {
        DB::transaction(function () use ($before, $target): void {
            $current = CareerJobDisplayAsset::query()->whereKey($before['id'])->lockForUpdate()->first();
            if (! $current instanceof CareerJobDisplayAsset) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_COMPENSATION_FAILED');
            }
            $currentSnapshot = $this->snapshot($current);
            if ($currentSnapshot['component_order_json'] !== $target['component_order_json']
                || self::hashValue($currentSnapshot['page_payload_json']) !== self::hashValue($target['page_payload_json'])
                || self::hashValue($currentSnapshot['metadata_json']) !== self::hashValue($target['metadata_json'])) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_COMPENSATION_DRIFT');
            }
            DB::table('career_job_display_assets')->where('id', $before['id'])->update([
                'component_order_json' => self::encode($before['component_order_json']),
                'page_payload_json' => self::encode($before['page_payload_json']),
                'metadata_json' => self::encode($before['metadata_json']),
                'updated_at' => $before['updated_at'],
            ]);
            $restored = CareerJobDisplayAsset::query()->whereKey($before['id'])->first();
            if (! $restored instanceof CareerJobDisplayAsset || $this->snapshot($restored) !== $before) {
                throw new CareerActorsCurrentRepairFailure('ACTORS_DATABASE_COMPENSATION_FAILED');
            }
        }, 1);
    }

    /** @return array<string,mixed> */
    private function result(array $binding, array $package, string $state, array $writes, string $compensation): array
    {
        return array_merge([
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'PASS_ACTORS_CURRENT_REPAIR',
            'target_slug' => self::TARGET_SLUG,
            'state' => $state,
            'source_sha256' => self::SOURCE_SHA256,
            'workbuddy_sha256' => self::WORKBUDDY_SHA256,
            'target_page_sha256' => data_get($package, 'hashes.page_sha256'),
            'components_per_page' => 26,
            'locale_page_count' => 2,
            'compensation_state' => $compensation,
            'write_counts' => $writes,
        ], $binding);
    }

    /** @return array<string,int> */
    private static function zeroWrites(): array
    {
        return [
            'database_update_count' => 0,
            'cache_candidate_write_count' => 0,
            'cache_pointer_activation_count' => 0,
            'cache_pointer_restore_count' => 0,
            'database_compensation_count' => 0,
            'generation_write_count' => 0,
            'discoverability_write_count' => 0,
            'occupation_write_count' => 0,
            'search_submission_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function localizedPages(array $payload): array
    {
        return is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
    }

    private function containsPlaceholder(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['content_available'] ?? null) === false
            || str_starts_with((string) ($value['module_state'] ?? ''), 'pending_')
            || ($value['source'] ?? null) === 'component_order_contract') {
            return true;
        }
        foreach ($value as $child) {
            if ($this->containsPlaceholder($child)) {
                return true;
            }
        }

        return false;
    }

    private static function hashValue(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encode(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }
}

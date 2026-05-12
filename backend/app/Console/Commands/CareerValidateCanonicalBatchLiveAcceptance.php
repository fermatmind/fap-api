<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class CareerValidateCanonicalBatchLiveAcceptance extends Command
{
    private const ACCEPTABLE_INDEX_STATES = ['indexable', 'indexed'];

    private const REQUIRED_TRUTH_FIELDS = [
        'route_exists',
        'final_200',
        'robots_indexable',
        'canonical_self',
        'dataset_visible',
        'search_visible',
        'sitemap_live',
        'llms_live',
        'llms_full_live',
        'release_gate_pass',
    ];

    protected $signature = 'career:validate-canonical-batch-live-acceptance
        {--batch-id= : Canonical rollout batch identifier}
        {--slugs= : Comma-separated promoted canonical slugs}
        {--locales= : Comma-separated target locales, defaults to en,zh}
        {--projection= : Optional runtime publish projection JSON artifact}
        {--truth= : Optional canonical runtime truth JSON artifact}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--base-url= : Optional public site base URL for live HTML surface verification}
        {--json : Emit JSON output}';

    protected $description = 'Read-only live acceptance validator for a promoted canonical career batch.';

    public function __construct(
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
        private readonly CareerCanonicalRuntimeTruthValidator $truthValidator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $batchId = $this->requiredOption('batch-id');
            $slugs = $this->csvOption('slugs', required: true);
            $locales = $this->csvOption('locales', default: 'en,zh');
            $expectedRows = count($slugs) * count($locales);

            $projection = $this->projectionPayload();
            $truth = $this->truthPayload($projection);
            $truthValidation = $this->truthValidator->validate($truth);
            $occupationAuthority = $this->occupationAuthority($slugs);
            $indexStateAuthority = $this->indexStateAuthority($slugs);
            $projectionTruth = $this->projectionTruth($truth, $slugs, $locales);
            $releaseGate = $this->releaseGate($truth, $slugs, $locales);
            $surfaces = $this->surfaces($truthValidation, $slugs, $locales);

            $failures = array_values(array_merge(
                $this->occupationFailures($occupationAuthority),
                $this->indexStateFailures($indexStateAuthority),
                $this->projectionTruthFailures($projectionTruth),
                $this->releaseGateFailures($releaseGate),
                $this->surfaceFailures($surfaces),
            ));

            $accepted = $failures === []
                && $expectedRows > 0
                && ($surfaces['surface_equality'] ?? null) === 'pass'
                && (int) ($surfaces['mismatch_count'] ?? 0) === 0
                && (int) ($surfaces['unexpected_exposure'] ?? 0) === 0;

            $status = $accepted ? 'pass' : ($this->hasOnlyContextFailures($failures) ? 'blocked' : 'fail');
            $payload = [
                'status' => $status,
                'batch_id' => $batchId,
                'expected_rows' => $expectedRows,
                'occupation_authority' => $occupationAuthority,
                'index_state_authority' => $indexStateAuthority,
                'projection_truth' => $projectionTruth,
                'release_gate' => $releaseGate,
                'surfaces' => $surfaces,
                'accepted' => $accepted,
                'read_only' => true,
                'writes_database' => false,
                'failures' => $failures,
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('accepted='.($accepted ? 'true' : 'false'));
                $this->line('expected_rows='.(string) $expectedRows);
                $this->line('mismatch_count='.(string) $surfaces['mismatch_count']);
            }

            return $accepted ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $payload = [
                'status' => 'blocked',
                'accepted' => false,
                'read_only' => true,
                'writes_database' => false,
                'failures' => [[
                    'type' => 'validator_context_missing',
                    'reason' => $throwable->getMessage(),
                ]],
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->error($throwable->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new \RuntimeException('--'.$name.' is required.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name, ?string $default = null, bool $required = false): array
    {
        $value = trim((string) ($this->option($name) ?? $default ?? ''));
        if ($required && $value === '') {
            throw new \RuntimeException('--'.$name.' is required.');
        }

        $items = array_values(array_unique(array_filter(array_map(
            fn (string $item): string => $name === 'locales' ? $this->normalizeLocale($item) : strtolower(trim($item)),
            explode(',', $value),
        ), static fn (string $item): bool => $item !== '')));

        if ($required && $items === []) {
            throw new \RuntimeException('--'.$name.' must contain at least one value.');
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectionPayload(): array
    {
        $projectionPath = $this->pathOption('projection');
        if ($projectionPath !== null) {
            return $this->readJsonPayload($projectionPath, 'projection');
        }

        return $this->projectionExporter->build($this->pathOption('ledger'));
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function truthPayload(array $projection): array
    {
        $truthPath = $this->pathOption('truth');
        if ($truthPath !== null) {
            return $this->readJsonPayload($truthPath, 'truth');
        }

        return $this->truthExporter->buildFromProjectionArray($projection);
    }

    private function pathOption(string $name): ?string
    {
        $value = trim((string) ($this->option($name) ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPayload(string $path, string $envelopeKey): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException($envelopeKey.' artifact not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException($envelopeKey.' artifact is not valid JSON: '.$path);
        }

        return is_array($payload[$envelopeKey] ?? null) ? $payload[$envelopeKey] : $payload;
    }

    /**
     * @param  list<string>  $slugs
     * @return array{expected:int,found:int,missing:list<string>}
     */
    private function occupationAuthority(array $slugs): array
    {
        $found = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->pluck('canonical_slug')
            ->map(static fn (mixed $slug): string => strtolower((string) $slug))
            ->all();

        $missing = array_values(array_diff($slugs, $found));

        return [
            'expected' => count($slugs),
            'found' => count(array_unique($found)),
            'missing' => $missing,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function indexStateAuthority(array $slugs): array
    {
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->get(['id', 'canonical_slug']);
        $observedStates = [];
        $missing = [];
        $notAcceptable = [];

        foreach ($occupations as $occupation) {
            $slug = strtolower((string) $occupation->canonical_slug);
            $state = IndexState::query()
                ->where('occupation_id', $occupation->id)
                ->orderByDesc('changed_at')
                ->orderByDesc('created_at')
                ->value('index_state');

            if (! is_string($state) || trim($state) === '') {
                $missing[] = $slug;

                continue;
            }

            $normalized = strtolower(trim($state));
            $observedStates[] = $normalized;
            if (! in_array($normalized, self::ACCEPTABLE_INDEX_STATES, true)) {
                $notAcceptable[] = [
                    'slug' => $slug,
                    'state' => $normalized,
                ];
            }
        }

        return [
            'acceptable_raw_states' => self::ACCEPTABLE_INDEX_STATES,
            'observed_states' => array_values(array_unique($observedStates)),
            'missing_latest_index_state' => $missing,
            'not_acceptable_raw_state' => $notAcceptable,
        ];
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function projectionTruth(array $truth, array $slugs, array $locales): array
    {
        $items = $this->items($truth);
        $missingRows = [];
        $notPublishedRows = [];

        foreach ($this->expectedPairs($slugs, $locales) as $pair) {
            $item = $this->itemFor($items, $pair['slug'], $pair['locale']);
            if ($item === null) {
                $missingRows[] = $pair;

                continue;
            }

            if (($item['projection_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                $notPublishedRows[] = [
                    'slug' => $pair['slug'],
                    'locale' => $pair['locale'],
                    'state' => $item['projection_state'] ?? null,
                ];
            }
        }

        return [
            'expected_rows' => count($slugs) * count($locales),
            'found_published' => count($slugs) * count($locales) - count($missingRows) - count($notPublishedRows),
            'missing_rows' => $missingRows,
            'not_published_rows' => $notPublishedRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $truth
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function releaseGate(array $truth, array $slugs, array $locales): array
    {
        $items = $this->items($truth);
        $blockedRows = [];
        $pass = 0;

        foreach ($this->expectedPairs($slugs, $locales) as $pair) {
            $item = $this->itemFor($items, $pair['slug'], $pair['locale']);
            $missingFields = [];

            if ($item === null) {
                $blockedRows[] = [
                    'slug' => $pair['slug'],
                    'locale' => $pair['locale'],
                    'reason' => 'truth_row_missing',
                ];

                continue;
            }

            foreach (self::REQUIRED_TRUTH_FIELDS as $field) {
                if (! (bool) ($item[$field] ?? false)) {
                    $missingFields[] = $field;
                }
            }

            if ($missingFields === []) {
                $pass++;

                continue;
            }

            $blockedRows[] = [
                'slug' => $pair['slug'],
                'locale' => $pair['locale'],
                'reason' => 'release_gate_truth_fields_missing',
                'missing_fields' => $missingFields,
            ];
        }

        return [
            'expected_rows' => count($slugs) * count($locales),
            'pass' => $pass,
            'blocked' => count($blockedRows),
            'blocked_rows' => $blockedRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $truthValidation
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function surfaces(array $truthValidation, array $slugs, array $locales): array
    {
        $truthFailures = is_array($truthValidation['failures'] ?? null) ? $truthValidation['failures'] : [];
        $unexpectedExposure = $this->unexpectedExposureCount($truthValidation);
        $verified = ['projection_truth'];
        $unverified = [];
        $liveMismatches = [];

        $baseUrl = $this->pathOption('base-url');
        if ($baseUrl === null) {
            $unverified[] = [
                'surface' => 'live_html',
                'type' => 'validator_context_missing',
                'reason' => '--base-url not provided; live HTML canonical, robots, and CTA cannot be proven from backend artifacts',
            ];
        } else {
            $verified[] = 'live_html';
            $liveMismatches = $this->liveHtmlMismatches(rtrim($baseUrl, '/'), $slugs, $locales);
        }

        $mismatchCount = count($truthFailures) + count($liveMismatches);

        return [
            'surface_equality' => $mismatchCount === 0 && $unexpectedExposure === 0 && $unverified === [] ? 'pass' : 'fail',
            'mismatch_count' => $mismatchCount,
            'unexpected_exposure' => $unexpectedExposure,
            'verified_surfaces' => $verified,
            'unverified_surfaces' => $unverified,
            'real_surface_mismatches' => array_values(array_merge($truthFailures, $liveMismatches)),
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function liveHtmlMismatches(string $baseUrl, array $slugs, array $locales): array
    {
        $mismatches = [];

        foreach ($this->expectedPairs($slugs, $locales) as $pair) {
            $url = $baseUrl.'/'.$pair['locale'].'/career/jobs/'.$pair['slug'];
            try {
                $response = Http::timeout(12)->withOptions(['allow_redirects' => true])->get($url);
            } catch (Throwable $throwable) {
                $mismatches[] = [
                    'type' => 'real_surface_mismatch',
                    'surface' => 'live_html',
                    'slug' => $pair['slug'],
                    'locale' => $pair['locale'],
                    'reason' => 'request_failed',
                    'detail' => $throwable->getMessage(),
                ];

                continue;
            }

            $html = (string) $response->body();
            $canonical = $this->firstMatch('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html)
                ?? $this->firstMatch('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html);
            $robots = $this->firstMatch('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html);
            $reasons = [];

            if (! $response->ok()) {
                $reasons[] = 'http_not_200';
            }
            if (! is_string($canonical) || ! str_contains($canonical, '/'.$pair['locale'].'/career/jobs/'.$pair['slug'])) {
                $reasons[] = 'canonical_not_self';
            }
            if (is_string($robots) && str_contains(strtolower($robots), 'noindex')) {
                $reasons[] = 'noindex_present';
            }
            if (! $this->htmlHasRequiredCta($html, $pair['slug'])) {
                $reasons[] = 'cta_missing_or_unattributed';
            }

            foreach ($reasons as $reason) {
                $mismatches[] = [
                    'type' => 'real_surface_mismatch',
                    'surface' => 'live_html',
                    'slug' => $pair['slug'],
                    'locale' => $pair['locale'],
                    'reason' => $reason,
                ];
            }
        }

        return $mismatches;
    }

    private function htmlHasRequiredCta(string $html, string $slug): bool
    {
        return str_contains($html, 'holland-career-interest-test-riasec')
            && str_contains($html, 'start_riasec_test')
            && str_contains($html, 'career_job_detail')
            && (str_contains($html, 'subject_key='.$slug) || str_contains($html, 'subject_key%3D'.$slug));
    }

    private function firstMatch(string $pattern, string $html): ?string
    {
        if (preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        return (string) ($matches[1] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        $items = $payload['items'] ?? [];

        return is_array($items)
            ? array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)))
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function itemFor(array $items, string $slug, string $locale): ?array
    {
        foreach ($items as $item) {
            if ($this->normalizeSlug((string) ($item['slug'] ?? '')) === $slug
                && $this->normalizeLocale((string) ($item['locale'] ?? '')) === $locale) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return list<array{slug:string,locale:string}>
     */
    private function expectedPairs(array $slugs, array $locales): array
    {
        $pairs = [];
        foreach ($slugs as $slug) {
            foreach ($locales as $locale) {
                $pairs[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                ];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $truthValidation
     */
    private function unexpectedExposureCount(array $truthValidation): int
    {
        $counts = is_array($truthValidation['counts'] ?? null) ? $truthValidation['counts'] : [];

        return (int) ($counts['candidate_unexpected_route_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_api_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_dataset_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_search_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_sitemap_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_llms_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_llms_full_exposure_count'] ?? 0)
            + (int) ($counts['candidate_unexpected_indexable_exposure_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return list<array<string, mixed>>
     */
    private function occupationFailures(array $authority): array
    {
        return ($authority['missing'] ?? []) === [] ? [] : [[
            'type' => 'validator_context_missing',
            'reason' => 'occupation_authority_missing',
            'missing' => $authority['missing'],
        ]];
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return list<array<string, mixed>>
     */
    private function indexStateFailures(array $authority): array
    {
        $failures = [];
        if (($authority['missing_latest_index_state'] ?? []) !== []) {
            $failures[] = [
                'type' => 'validator_context_missing',
                'reason' => 'latest_index_state_missing',
                'rows' => $authority['missing_latest_index_state'],
            ];
        }
        if (($authority['not_acceptable_raw_state'] ?? []) !== []) {
            $failures[] = [
                'type' => 'real_surface_mismatch',
                'reason' => 'latest_index_state_not_acceptable',
                'rows' => $authority['not_acceptable_raw_state'],
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $projectionTruth
     * @return list<array<string, mixed>>
     */
    private function projectionTruthFailures(array $projectionTruth): array
    {
        $failures = [];
        if (($projectionTruth['missing_rows'] ?? []) !== []) {
            $failures[] = [
                'type' => 'validator_context_missing',
                'reason' => 'projection_truth_rows_missing',
                'rows' => $projectionTruth['missing_rows'],
            ];
        }
        if (($projectionTruth['not_published_rows'] ?? []) !== []) {
            $failures[] = [
                'type' => 'real_surface_mismatch',
                'reason' => 'projection_truth_rows_not_published',
                'rows' => $projectionTruth['not_published_rows'],
            ];
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $releaseGate
     * @return list<array<string, mixed>>
     */
    private function releaseGateFailures(array $releaseGate): array
    {
        return ($releaseGate['blocked_rows'] ?? []) === [] ? [] : [[
            'type' => 'real_surface_mismatch',
            'reason' => 'release_gate_blocked',
            'rows' => $releaseGate['blocked_rows'],
        ]];
    }

    /**
     * @param  array<string, mixed>  $surfaces
     * @return list<array<string, mixed>>
     */
    private function surfaceFailures(array $surfaces): array
    {
        $failures = [];
        foreach ((array) ($surfaces['unverified_surfaces'] ?? []) as $row) {
            if (is_array($row)) {
                $failures[] = $row;
            }
        }
        if (($surfaces['real_surface_mismatches'] ?? []) !== []) {
            $failures[] = [
                'type' => 'real_surface_mismatch',
                'reason' => 'surface_equality_failed',
                'rows' => $surfaces['real_surface_mismatches'],
            ];
        }
        if ((int) ($surfaces['unexpected_exposure'] ?? 0) > 0) {
            $failures[] = [
                'type' => 'real_surface_mismatch',
                'reason' => 'unexpected_exposure',
                'count' => (int) $surfaces['unexpected_exposure'],
            ];
        }

        return $failures;
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function hasOnlyContextFailures(array $failures): bool
    {
        return $failures !== [] && count(array_filter(
            $failures,
            static fn (array $failure): bool => ($failure['type'] ?? null) !== 'validator_context_missing',
        )) === 0;
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function normalizeLocale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'zh-cn', 'zh_cn', 'zh' => 'zh',
            'en-us', 'en_us', 'en' => 'en',
            default => strtolower(trim($locale)),
        };
    }
}

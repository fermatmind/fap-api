<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\API\V0_5\Cms\PersonalityPublicContentAssetController;
use App\Models\PersonalityProfile;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

final class WarmPublicContentReadModels extends Command
{
    public const PAYLOAD_BUDGET_BYTES = 524288;

    private const PRODUCTION_CONFIRMATION = 'PUBLIC-CONTENT-WARM';

    private const PRIORITY_ORDER = ['L1:mbti', 'L2:big-five', 'L3:career-industries'];

    protected $signature = 'public-content:warm-read-models
        {--dry-run : Print the bounded plan without reading or writing cache entries}
        {--verify-only : Read and validate current active/LKG entries without warming}
        {--warm : Explicitly execute the bounded warm plan}
        {--production-write : Acknowledge that --warm may write production cache entries}
        {--confirm= : Exact production confirmation phrase}
        {--json : Emit a machine-readable report}';

    protected $description = 'Dry-run, verify, or explicitly warm bounded public read models in L1/L2/L3 order.';

    public function handle(
        PersonalityPublicReadModelCache $mbtiCache,
        PersonalityPublicAssetReadModelCache $personalityAssetCache,
        PersonalityPublicContentAssetController $personalityAssetController,
        PublicCareerAuthorityResponseCache $careerCache,
    ): int {
        $mode = $this->mode();
        if ($mode === null) {
            return self::FAILURE;
        }

        if ($mode !== 'warm' && ((bool) $this->option('production-write') || trim((string) $this->option('confirm')) !== '')) {
            return $this->failCommand('Production write acknowledgements are valid only with --warm.');
        }

        if ($mode === 'warm' && ! $this->warmIsAllowed()) {
            return self::FAILURE;
        }

        $report = [
            'command' => 'public-content:warm-read-models',
            'mode' => $mode,
            'environment' => app()->environment(),
            'priority_order' => self::PRIORITY_ORDER,
            'payload_budget_bytes' => self::PAYLOAD_BUDGET_BYTES,
            'write_executed' => false,
            'status' => 'planned',
            'entries' => [],
        ];

        if ($mode === 'dry-run') {
            $report['entries'] = $this->planEntries();
            $this->render($report);

            return self::SUCCESS;
        }

        if ($mode === 'warm') {
            $warmEntries = $this->warmEntries($personalityAssetController);
            $report['write_executed'] = true;
            if ($this->hasFailure($warmEntries)) {
                $report['status'] = 'failed';
                $report['entries'] = $warmEntries;
                $this->render($report);

                return self::FAILURE;
            }
        }

        $entries = $this->verifyEntries($mbtiCache, $personalityAssetCache, $careerCache);
        $report['status'] = $this->hasFailure($entries) ? 'failed' : 'verified';
        $report['entries'] = $entries;
        $this->render($report);

        return $report['status'] === 'verified' ? self::SUCCESS : self::FAILURE;
    }

    private function mode(): ?string
    {
        $selected = array_values(array_filter([
            (bool) $this->option('dry-run') ? 'dry-run' : null,
            (bool) $this->option('verify-only') ? 'verify-only' : null,
            (bool) $this->option('warm') ? 'warm' : null,
        ]));

        if (count($selected) > 1) {
            $this->error('Choose exactly one of --dry-run, --verify-only, or --warm.');

            return null;
        }

        return $selected[0] ?? 'dry-run';
    }

    private function warmIsAllowed(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        $environmentGate = (bool) config('public_content_observability.warm_production_enabled', false);
        $acknowledged = (bool) $this->option('production-write');
        $confirmed = hash_equals(self::PRODUCTION_CONFIRMATION, trim((string) $this->option('confirm')));

        if ($environmentGate && $acknowledged && $confirmed) {
            return true;
        }

        $this->error(
            'Production warm refused before writes: require PUBLIC_CONTENT_WARM_PRODUCTION_ENABLED=true, '
            .'--production-write, and --confirm='.self::PRODUCTION_CONFIRMATION.'.'
        );

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function planEntries(): array
    {
        return [
            [
                'priority' => 'L1',
                'family' => 'mbti',
                'status' => 'planned',
                'targets' => count(PersonalityProfile::BASE_TYPE_CODES) * 2 * 2 * 2,
                'description' => '32 A/T variants x 2 locales x detail/seo',
            ],
            [
                'priority' => 'L2',
                'family' => 'big-five',
                'status' => 'planned',
                'targets' => 2,
                'description' => 'backend-authoritative Big Five collection x 2 locales',
            ],
            [
                'priority' => 'L3',
                'family' => 'career-industries',
                'status' => 'planned',
                'targets' => 2,
                'description' => 'career directory active/LKG read model x 2 locales',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function warmEntries(PersonalityPublicContentAssetController $personalityAssetController): array
    {
        $entries = [];

        $mbtiExit = $this->callSilently('personality:warm-public-read-models', ['--locales' => 'en,zh-CN']);
        $entries[] = [
            'priority' => 'L1',
            'family' => 'mbti',
            'status' => $mbtiExit === self::SUCCESS ? 'warmed' : 'failed',
            'exit_code' => $mbtiExit,
        ];
        if ($mbtiExit !== self::SUCCESS) {
            return $entries;
        }

        foreach (['en', 'zh-CN'] as $locale) {
            try {
                $response = $personalityAssetController->index(Request::create(
                    '/api/v0.5/personality-content-assets',
                    'GET',
                    [
                        'org_id' => 0,
                        'locale' => $locale,
                        'framework' => 'big_five',
                        'page' => 1,
                        'per_page' => 100,
                    ],
                ));
                $bytes = strlen((string) $response->getContent());
                $ready = $response->getStatusCode() === 200 && $bytes <= self::PAYLOAD_BUDGET_BYTES;
                $entries[] = [
                    'priority' => 'L2',
                    'family' => 'big-five',
                    'locale' => $locale,
                    'status' => $ready ? 'warmed' : 'failed',
                    'http_status' => $response->getStatusCode(),
                    'bytes' => $bytes,
                    'budget_bytes' => self::PAYLOAD_BUDGET_BYTES,
                ];
                if (! $ready) {
                    return $entries;
                }
            } catch (Throwable $throwable) {
                $entries[] = $this->failureEntry('L2', 'big-five', $locale, $throwable);

                return $entries;
            }
        }

        $careerExit = $this->callSilently('career:warm-public-authority-cache', [
            '--directory-only' => true,
            '--json' => true,
        ]);
        $entries[] = [
            'priority' => 'L3',
            'family' => 'career-industries',
            'status' => $careerExit === self::SUCCESS ? 'warmed' : 'failed',
            'exit_code' => $careerExit,
        ];

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    private function verifyEntries(
        PersonalityPublicReadModelCache $mbtiCache,
        PersonalityPublicAssetReadModelCache $personalityAssetCache,
        PublicCareerAuthorityResponseCache $careerCache,
    ): array {
        $entries = [];

        foreach ($this->mbtiTypes() as $type) {
            foreach (['en', 'zh-CN'] as $locale) {
                foreach (['detail', 'seo'] as $surface) {
                    $entries[] = $this->verifyVersionedPayload(
                        'L1',
                        'mbti',
                        $type.':'.$surface,
                        $locale,
                        $mbtiCache->activeKey($surface, $type, $locale),
                        $mbtiCache->lkgKey($surface, $type, $locale),
                        fn (string $version): string => $mbtiCache->key($surface, $type, $locale, $version),
                    );
                }
            }
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $entries[] = $this->verifyVersionedPayload(
                'L2',
                'big-five',
                'collection',
                $locale,
                $personalityAssetCache->activeKey('index', 'big_five', 'all', 'page:1:per-page:100', $locale),
                $personalityAssetCache->lkgKey('index', 'big_five', 'all', 'page:1:per-page:100', $locale),
                fn (string $version): string => $personalityAssetCache->key(
                    'index', 'big_five', 'all', 'page:1:per-page:100', $locale, $version,
                ),
            );
        }

        foreach (['en', 'zh-CN'] as $locale) {
            try {
                $status = $careerCache->directoryCacheStatus($locale);
                $payload = $careerCache->directoryReadModelPayload($locale);
                $activeReady = ($status['status'] ?? null) === 'ready';
                $version = $activeReady ? ($status['active_version'] ?? null) : ($status['lkg_version'] ?? null);
                $bytes = $this->payloadBytes($payload);
                $ready = is_string($version) && $version !== '' && $bytes <= self::PAYLOAD_BUDGET_BYTES;
                $entries[] = [
                    'priority' => 'L3',
                    'family' => 'career-industries',
                    'target' => 'directory',
                    'locale' => $locale,
                    'status' => $ready ? 'ready' : ($bytes > self::PAYLOAD_BUDGET_BYTES ? 'budget_exceeded' : 'unavailable'),
                    'source' => $activeReady ? 'active' : 'lkg',
                    'version' => is_string($version) ? $version : null,
                    'active_version' => $status['active_version'] ?? null,
                    'lkg_version' => $status['lkg_version'] ?? null,
                    'bytes' => $bytes,
                    'budget_bytes' => self::PAYLOAD_BUDGET_BYTES,
                ];
            } catch (Throwable $throwable) {
                $entries[] = $this->failureEntry('L3', 'career-industries', $locale, $throwable);
            }
        }

        return $entries;
    }

    /**
     * @param  callable(string): string  $payloadKey
     * @return array<string, mixed>
     */
    private function verifyVersionedPayload(
        string $priority,
        string $family,
        string $target,
        string $locale,
        string $activeKey,
        string $lkgKey,
        callable $payloadKey,
    ): array {
        foreach (['active' => $activeKey, 'lkg' => $lkgKey] as $source => $pointerKey) {
            $version = Cache::get($pointerKey);
            $payload = is_string($version) && $version !== '' ? Cache::get($payloadKey($version)) : null;
            if (! is_array($payload)) {
                continue;
            }

            try {
                $bytes = $this->payloadBytes($payload);
            } catch (Throwable $throwable) {
                return $this->failureEntry($priority, $family, $locale, $throwable, $target);
            }

            return [
                'priority' => $priority,
                'family' => $family,
                'target' => $target,
                'locale' => $locale,
                'status' => $bytes <= self::PAYLOAD_BUDGET_BYTES ? 'ready' : 'budget_exceeded',
                'source' => $source,
                'version' => $version,
                'bytes' => $bytes,
                'budget_bytes' => self::PAYLOAD_BUDGET_BYTES,
            ];
        }

        return [
            'priority' => $priority,
            'family' => $family,
            'target' => $target,
            'locale' => $locale,
            'status' => 'unavailable',
            'source' => null,
            'version' => null,
            'bytes' => null,
            'budget_bytes' => self::PAYLOAD_BUDGET_BYTES,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadBytes(array $payload): int
    {
        return strlen(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @param list<array<string, mixed>> $entries */
    private function hasFailure(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (! in_array($entry['status'] ?? null, ['planned', 'ready', 'warmed'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function failureEntry(
        string $priority,
        string $family,
        string $locale,
        Throwable $throwable,
        ?string $target = null,
    ): array {
        return array_filter([
            'priority' => $priority,
            'family' => $family,
            'target' => $target,
            'locale' => $locale,
            'status' => 'failed',
            'error_class' => $throwable::class,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return list<string> */
    private function mbtiTypes(): array
    {
        return array_values(array_merge(...array_map(
            static fn (string $base): array => [$base.'-A', $base.'-T'],
            PersonalityProfile::BASE_TYPE_CODES,
        )));
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line((string) json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ));
            } catch (JsonException $exception) {
                $this->error('Unable to encode warm/verify report: '.$exception->getMessage());
            }

            return;
        }

        $this->line(sprintf(
            'mode=%s status=%s write_executed=%s order=%s',
            (string) $report['mode'],
            (string) $report['status'],
            ($report['write_executed'] ?? false) ? 'true' : 'false',
            implode(',', self::PRIORITY_ORDER),
        ));
        foreach ((array) $report['entries'] as $entry) {
            $this->line(sprintf(
                'priority=%s family=%s target=%s locale=%s status=%s version=%s bytes=%s',
                (string) ($entry['priority'] ?? ''),
                (string) ($entry['family'] ?? ''),
                (string) ($entry['target'] ?? ''),
                (string) ($entry['locale'] ?? ''),
                (string) ($entry['status'] ?? ''),
                (string) ($entry['version'] ?? ''),
                (string) ($entry['bytes'] ?? ''),
            ));
        }
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}

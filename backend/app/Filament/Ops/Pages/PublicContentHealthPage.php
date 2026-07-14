<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Ops\PublicContentDeliveryProbeService;
use App\Services\Ops\PublicContentRuntimeMetricsService;
use App\Support\Rbac\PermissionNames;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Throwable;

final class PublicContentHealthPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'public-content-health';

    protected static string $view = 'filament.ops.pages.public-content-health';

    /** @var list<array<string, mixed>> */
    public array $overviewFields = [];

    /** @var list<array<string, mixed>> */
    public array $runtimeCards = [];

    /** @var list<array<string, mixed>> */
    public array $probeCards = [];

    /** @var list<array<string, mixed>> */
    public array $publicationCards = [];

    /** @var list<array<string, mixed>> */
    public array $boundaryFields = [];

    /** @var list<string> */
    public array $sourceErrors = [];

    public string $generatedAt = '';

    public int $windowMinutes = 60;

    public function mount(
        PublicContentRuntimeMetricsService $metrics,
        PublicContentDeliveryProbeService $probes,
    ): void {
        $runtime = $this->runtimePayload($metrics);
        $probe = $this->probePayload($probes);
        $runtimeItems = array_values(array_filter(
            (array) ($runtime['items'] ?? []),
            static fn (mixed $item): bool => is_array($item),
        ));
        $probeRows = $this->probeRows($probe);

        $this->runtimeCards = array_map(
            fn (array $item): array => $this->runtimeCard($item),
            array_slice($runtimeItems, 0, 100),
        );
        $this->probeCards = array_map(
            fn (array $row): array => $this->probeCard($row),
            $probeRows,
        );
        $this->publicationCards = array_map(
            fn (array $row): array => $this->publicationCard($row),
            $probeRows,
        );

        $requestCount = array_sum(array_map(
            static fn (array $item): int => max(0, (int) ($item['request_count'] ?? 0)),
            $runtimeItems,
        ));
        $observedProbeCount = count(array_filter(
            $probeRows,
            static fn (array $row): bool => is_array($row['result'] ?? null),
        ));
        $healthyProbeCount = count(array_filter(
            $probeRows,
            static fn (array $row): bool => ($row['result']['ok'] ?? false) === true,
        ));
        $healthyReadbackCount = count(array_filter(
            $probeRows,
            static fn (array $row): bool => ($row['result']['readback']['ok'] ?? false) === true,
        ));
        $expectedProbeCount = count($probeRows);

        $this->generatedAt = $this->displayString(
            $runtime['generated_at'] ?? null,
            CarbonImmutable::now('UTC')->toIso8601String(),
        );
        $this->overviewFields = [
            $this->field(
                __('public-content-health.overview.runtime_source'),
                ($runtime['available'] ?? false)
                    ? __('public-content-health.states.available')
                    : __('public-content-health.states.unavailable'),
                ($runtime['available'] ?? false) ? 'healthy' : 'failed',
                __('public-content-health.overview.runtime_source_hint'),
            ),
            $this->field(
                __('public-content-health.overview.requests'),
                (string) $requestCount,
                $requestCount > 0 ? 'healthy' : 'no_data',
                __('public-content-health.overview.requests_hint', ['minutes' => $this->windowMinutes]),
            ),
            $this->field(
                __('public-content-health.overview.probe_source'),
                ($probe['available'] ?? false)
                    ? __('public-content-health.states.available')
                    : __('public-content-health.states.unavailable'),
                ($probe['available'] ?? false) ? 'healthy' : 'failed',
                __('public-content-health.overview.probe_source_hint'),
            ),
            $this->field(
                __('public-content-health.overview.probes'),
                "{$healthyProbeCount}/{$expectedProbeCount}",
                $this->countState($healthyProbeCount, $expectedProbeCount),
                __('public-content-health.overview.probes_hint', ['observed' => $observedProbeCount]),
            ),
            $this->field(
                __('public-content-health.overview.readbacks'),
                "{$healthyReadbackCount}/{$expectedProbeCount}",
                $this->countState($healthyReadbackCount, $expectedProbeCount),
                __('public-content-health.overview.readbacks_hint'),
            ),
        ];
        $this->boundaryFields = [
            $this->field(
                __('public-content-health.boundary.scope'),
                __('public-content-health.boundary.scope_value'),
                'healthy',
                __('public-content-health.boundary.scope_hint'),
            ),
            $this->field(
                __('public-content-health.boundary.authority'),
                __('public-content-health.boundary.authority_value'),
                'healthy',
                __('public-content-health.boundary.authority_hint'),
            ),
            $this->field(
                __('public-content-health.boundary.actions'),
                __('public-content-health.boundary.actions_value'),
                'disabled',
                __('public-content-health.boundary.actions_hint'),
            ),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('public-content-health.navigation_label');
    }

    public function getTitle(): string
    {
        return __('public-content-health.title');
    }

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OWNER)
                || $user->hasPermission(PermissionNames::ADMIN_OPS_READ)
                || $user->hasPermission(PermissionNames::ADMIN_EVENTS_READ)
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** @return array<string, mixed> */
    private function runtimePayload(PublicContentRuntimeMetricsService $metrics): array
    {
        try {
            $payload = $metrics->query($this->windowMinutes);
            if (($payload['ok'] ?? false) !== true) {
                throw new \RuntimeException('runtime metrics query unavailable');
            }

            return array_merge($payload, ['available' => true]);
        } catch (Throwable) {
            $this->sourceErrors[] = 'metrics_unavailable';

            return [
                'available' => false,
                'items' => [],
                'generated_at' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    private function probePayload(PublicContentDeliveryProbeService $probes): array
    {
        try {
            return [
                'available' => true,
                'catalog' => $probes->catalog(),
                'latest' => $probes->latest(),
            ];
        } catch (Throwable) {
            $this->sourceErrors[] = 'probe_unavailable';

            return [
                'available' => false,
                'catalog' => [],
                'latest' => ['items' => []],
            ];
        }
    }

    /** @param array<string, mixed> $probe @return list<array{catalog: array<string, mixed>, result: array<string, mixed>|null}> */
    private function probeRows(array $probe): array
    {
        $results = [];
        foreach ((array) data_get($probe, 'latest.items', []) as $item) {
            if (is_array($item) && is_string($item['target_id'] ?? null)) {
                $results[(string) $item['target_id']] = $item;
            }
        }

        $rows = [];
        foreach ((array) ($probe['catalog'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }
            $targetId = (string) ($target['id'] ?? '');
            if ($targetId === '') {
                continue;
            }
            $rows[] = [
                'catalog' => $target,
                'result' => isset($results[$targetId]) && is_array($results[$targetId])
                    ? $results[$targetId]
                    : null,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function runtimeCard(array $item): array
    {
        $requests = max(0, (int) ($item['request_count'] ?? 0));
        $errorRate = array_sum(array_map(
            static fn (string $key): float => max(0.0, (float) ($item[$key] ?? 0)),
            ['client_error_rate', 'server_error_rate', 'timeout_rate'],
        ));
        $warningRate = array_sum(array_map(
            static fn (string $key): float => max(0.0, (float) ($item[$key] ?? 0)),
            ['not_found_rate', 'rate_limited_rate'],
        ));
        $state = match (true) {
            $requests === 0 => 'no_data',
            $errorRate > 0 => 'failed',
            $warningRate > 0 => 'warning',
            default => 'healthy',
        };

        return [
            'title' => $this->familyLabel((string) ($item['route_family'] ?? 'unknown')),
            'meta' => $this->displayString($item['priority'] ?? null, '—')
                .' · '.$this->displayString($item['locale'] ?? null, 'unknown'),
            'description' => __('public-content-health.runtime.description', [
                'requests' => $requests,
                'success' => $this->percentage($item['success_rate'] ?? 0),
                'not_found' => $this->percentage($item['not_found_rate'] ?? 0),
                'server_error' => $this->percentage($item['server_error_rate'] ?? 0),
                'timeout' => $this->percentage($item['timeout_rate'] ?? 0),
                'p95' => number_format(max(0, (float) ($item['p95_ms'] ?? 0)), 0),
            ]),
            'status' => __('public-content-health.states.'.$state),
            'status_state' => $state,
        ];
    }

    /** @param array{catalog: array<string, mixed>, result: array<string, mixed>|null} $row @return array<string, mixed> */
    private function probeCard(array $row): array
    {
        $target = $row['catalog'];
        $result = $row['result'];
        $state = $result === null ? 'no_data' : (($result['ok'] ?? false) === true ? 'healthy' : 'failed');

        return [
            'title' => $this->familyLabel((string) ($target['family'] ?? 'unknown')),
            'meta' => $this->displayString($target['priority'] ?? null, '—')
                .' · '.$this->displayString($target['locale'] ?? null, 'unknown')
                .' · '.$this->displayString($result['cache_state'] ?? null, 'unobserved'),
            'description' => $result === null
                ? __('public-content-health.probe.no_observation')
                : __('public-content-health.probe.description', [
                    'status' => max(0, (int) ($result['status_code'] ?? 0)),
                    'duration' => number_format(max(0, (float) ($result['duration_ms'] ?? 0)), 2),
                    'bytes' => max(0, (int) ($result['bytes'] ?? 0)),
                    'observed_at' => $this->displayString($result['observed_at'] ?? null, '—'),
                    'error' => $this->displayString($result['error_code'] ?? null, __('public-content-health.states.none')),
                ]),
            'status' => __('public-content-health.states.'.$state),
            'status_state' => $state,
        ];
    }

    /** @param array{catalog: array<string, mixed>, result: array<string, mixed>|null} $row @return array<string, mixed> */
    private function publicationCard(array $row): array
    {
        $target = $row['catalog'];
        $readback = is_array($row['result']['readback'] ?? null) ? $row['result']['readback'] : [];
        $fields = is_array($readback['fields'] ?? null) ? $readback['fields'] : [];
        $ok = ($readback['ok'] ?? false) === true;

        return [
            'title' => $this->familyLabel((string) ($target['family'] ?? 'unknown')),
            'meta' => $this->displayString($readback['profile'] ?? null, __('public-content-health.states.unobserved')),
            'description' => $ok
                ? $this->fieldSummary($fields)
                : __('public-content-health.publication.no_readback'),
            'status' => __('public-content-health.states.'.($ok ? 'healthy' : 'no_data')),
            'status_state' => $ok ? 'healthy' : 'no_data',
        ];
    }

    /** @param array<string, mixed> $fields */
    private function fieldSummary(array $fields): string
    {
        $parts = [];
        foreach (array_slice($fields, 0, 8, true) as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $parts[] = $this->displayString($key, 'field')
                .'='.$this->displayString($value, '—');
        }

        return $parts === []
            ? __('public-content-health.publication.no_readback')
            : implode(' · ', $parts);
    }

    private function familyLabel(string $family): string
    {
        $key = 'public-content-health.families.'.str_replace('-', '_', strtolower(trim($family)));
        $translation = __($key);

        return $translation === $key ? $this->displayString($family, 'unknown') : $translation;
    }

    /** @return array<string, mixed> */
    private function field(string $label, string $value, string $state, string $hint): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'kind' => 'pill',
            'state' => $state,
            'hint' => $hint,
        ];
    }

    private function countState(int $healthy, int $expected): string
    {
        if ($expected === 0 || $healthy === 0) {
            return 'failed';
        }

        return $healthy === $expected ? 'healthy' : 'warning';
    }

    private function percentage(mixed $rate): string
    {
        return number_format(max(0.0, min(1.0, (float) $rate)) * 100, 2).'%';
    }

    private function displayString(mixed $value, string $fallback): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            return $fallback;
        }

        $display = trim((string) $value);

        return $display === '' ? $fallback : mb_substr($display, 0, 160);
    }
}

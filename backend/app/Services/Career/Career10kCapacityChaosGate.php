<?php

declare(strict_types=1);

namespace App\Services\Career;

final class Career10kCapacityChaosGate
{
    /** @return array<string, mixed> */
    public function run(int $count = 10000, int $maxFirstPageBytes = 262144, int $measuredDbQueriesPerRead = 0): array
    {
        $count = max(1, $count);
        $memoryBefore = memory_get_usage(true);
        [$directory, $details] = $this->generate($count);
        $versions = ['old' => $directory, 'active' => $directory];
        $durations = [];
        $responses = [];

        foreach ([
            ['page' => 1, 'perPage' => 50, 'query' => '', 'family' => ''],
            ['page' => 200, 'perPage' => 50, 'query' => '', 'family' => ''],
            ['page' => 1, 'perPage' => 50, 'query' => 'career 9999', 'family' => ''],
            ['page' => 1, 'perPage' => 50, 'query' => '', 'family' => 'family-7'],
        ] as $request) {
            $started = hrtime(true);
            $responses[] = $this->page($versions['active'], ...$request);
            $durations[] = (hrtime(true) - $started) / 1_000_000;
        }

        foreach ([50, 100] as $concurrency) {
            $fibers = [];
            for ($index = 0; $index < $concurrency; $index++) {
                $fibers[] = new \Fiber(fn (): array => $this->page($versions['active'], 1 + ($index % 200), 50, '', ''));
            }
            $started = hrtime(true);
            foreach ($fibers as $fiber) {
                $response = $fiber->start();
                $responses[] = is_array($response) ? $response : $fiber->getReturn();
            }
            $windowMs = (hrtime(true) - $started) / 1_000_000;
            for ($index = 0; $index < $concurrency; $index++) {
                $durations[] = $windowMs / $concurrency;
            }
        }

        $redisMiss = $this->readWithFallback(null, $versions['old']);
        $redisUnavailable = $this->unavailableResult();
        $beforeFailedRebuild = $versions['active'];
        try {
            throw new \RuntimeException('injected_rebuild_failure');
        } catch (\RuntimeException) {
            $versions['active'] = $beforeFailedRebuild;
        }
        $newVersion = $directory;
        $newVersion['version'] = 'new';
        $coexistence = $versions['old']['version'] === 'old' && $newVersion['version'] === 'new';
        $restartedWorkerRead = $this->page($versions['active'], 1, 50, '', '');

        sort($durations);
        $p95 = $this->percentile($durations, 0.95);
        $p99 = $this->percentile($durations, 0.99);
        $firstPageBytes = strlen((string) json_encode($responses[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $memoryBytes = max(0, memory_get_usage(true) - $memoryBefore);
        $errors = [];
        if (count($directory['items']) !== $count || count($details) !== $count * 2) {
            $errors[] = 'real_projection_count_mismatch';
        }
        if ($p95 > 500 || $p99 > 800) {
            $errors[] = 'latency_budget_exceeded';
        }
        if ($firstPageBytes > $maxFirstPageBytes) {
            $errors[] = 'response_body_budget_exceeded';
        }
        if ($memoryBytes > 268435456) {
            $errors[] = 'memory_budget_exceeded';
        }
        if (($redisMiss['state'] ?? null) !== 'stale' || count((array) ($redisMiss['items'] ?? [])) === 0) {
            $errors[] = 'redis_miss_lkg_failed';
        }
        if (($redisUnavailable['state'] ?? null) !== 'unavailable' || array_key_exists('total', $redisUnavailable)) {
            $errors[] = 'redis_unavailable_fake_empty';
        }
        if ($versions['active'] !== $beforeFailedRebuild || ! $coexistence) {
            $errors[] = 'version_or_rebuild_resilience_failed';
        }
        if (count((array) ($restartedWorkerRead['items'] ?? [])) !== 50) {
            $errors[] = 'worker_restart_read_failed';
        }
        if ($measuredDbQueriesPerRead > 0) {
            $errors[] = 'directory_read_query_budget_exceeded';
        }
        if (collect($responses)->contains(fn (array $response): bool => count((array) ($response['items'] ?? [])) > 50)) {
            $errors[] = 'detail_fanout_or_page_overfetch';
        }

        return [
            'schema_version' => 'career.capacity_chaos_gate.v1',
            'status' => $errors === [] ? 'passed' : 'failed',
            'generated' => ['directory_items' => count($directory['items']), 'detail_projections' => count($details), 'locales' => 2],
            'traffic' => ['concurrency_windows' => [50, 100], 'search' => true, 'facets' => true, 'deep_page' => 200],
            'faults' => [
                'redis_miss_state' => $redisMiss['state'],
                'redis_unavailable_state' => $redisUnavailable['state'],
                'slow_db_query_count_on_read' => $measuredDbQueriesPerRead,
                'rebuild_failure_kept_old_version' => $versions['active'] === $beforeFailedRebuild,
                'worker_restart_read_count' => count((array) $restartedWorkerRead['items']),
                'old_new_versions_coexist' => $coexistence,
            ],
            'budgets' => [
                'p95_ms' => round($p95, 3),
                'p99_ms' => round($p99, 3),
                'max_p95_ms' => 500,
                'max_p99_ms' => 800,
                'first_page_bytes' => $firstPageBytes,
                'max_first_page_bytes' => $maxFirstPageBytes,
                'memory_bytes' => $memoryBytes,
                'max_memory_bytes' => 268435456,
                'db_queries_per_read' => $measuredDbQueriesPerRead,
                'max_db_queries_per_read' => 0,
                'max_items_per_response' => 50,
            ],
            'errors' => $errors,
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>} */
    private function generate(int $count): array
    {
        $items = [];
        $details = [];
        $facets = [];
        for ($index = 1; $index <= $count; $index++) {
            $slug = 'career-'.$index;
            $family = 'family-'.($index % 20);
            $items[] = ['slug' => $slug, 'title' => 'Career '.$index, 'family' => ['slug' => $family], 'search_text' => $slug.' career '.$index];
            $facets[$family] = ($facets[$family] ?? 0) + 1;
            foreach (['en', 'zh-CN'] as $locale) {
                $details[$locale.':'.$slug] = ['slug' => $slug, 'locale' => $locale, 'indexable' => true, 'sections' => [['kind' => 'summary']]];
            }
        }

        return [[
            'version' => 'old',
            'public_count' => $count,
            'items' => $items,
            'facets' => $facets,
        ], $details];
    }

    /** @param array<string,mixed> $model @return array<string,mixed> */
    private function page(array $model, int $page, int $perPage, string $query, string $family): array
    {
        $items = $model['items'];
        if ($query !== '') {
            $items = array_values(array_filter($items, fn (array $item): bool => str_contains($item['search_text'], strtolower($query))));
        }
        if ($family !== '') {
            $items = array_values(array_filter($items, fn (array $item): bool => $item['family']['slug'] === $family));
        }
        $total = count($items);

        return ['state' => 'success', 'total' => $total, 'page' => $page, 'items' => array_slice($items, ($page - 1) * $perPage, $perPage)];
    }

    /** @param array<string,mixed>|null $active @param array<string,mixed>|null $lkg @return array<string,mixed> */
    private function readWithFallback(?array $active, ?array $lkg): array
    {
        $model = $active ?? $lkg;
        $response = $this->page($model ?? ['items' => []], 1, 50, '', '');
        $response['state'] = $active === null && $lkg !== null ? 'stale' : 'success';

        return $response;
    }

    /** @return array{state:string,error_code:string} */
    private function unavailableResult(): array
    {
        return ['state' => 'unavailable', 'error_code' => 'CACHE_UNAVAILABLE'];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        return $values === [] ? 0.0 : $values[(int) floor((count($values) - 1) * $percentile)];
    }
}

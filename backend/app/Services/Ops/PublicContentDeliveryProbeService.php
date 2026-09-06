<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class PublicContentDeliveryProbeService
{
    private const KEY_PREFIX = 'public_content_delivery_probe:v1';

    public function __construct(
        private readonly PublicContentPublicationReadbackService $readback,
    ) {}

    /** @return array<string, mixed> */
    public function probeNext(): array
    {
        $targets = $this->targets();
        $store = $this->store();
        $cursor = max(0, (int) $store->get(self::KEY_PREFIX.':cursor', 0));
        $target = $targets[$cursor % count($targets)];
        $store->put(
            self::KEY_PREFIX.':cursor',
            ($cursor + 1) % count($targets),
            $this->ttlSeconds(),
        );

        return $this->probe($target);
    }

    /** @return list<array<string, mixed>> */
    public function probeAll(): array
    {
        return array_map(fn (array $target): array => $this->probe($target), $this->targets());
    }

    /** @return array{ok: bool, scope: string, items: list<array<string, mixed>>} */
    public function latest(): array
    {
        $items = [];
        foreach ($this->targets() as $target) {
            $item = $this->store()->get($this->latestKey((string) $target['id']));
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return [
            'ok' => count($items) === count($this->targets())
                && collect($items)->every(static fn (array $item): bool => ($item['ok'] ?? false) === true),
            'scope' => 'fixed_anonymous_public_allowlist',
            'items' => $items,
        ];
    }

    /** @return list<array{id: string, family: string, priority: string, locale: string}> */
    public function catalog(): array
    {
        return array_map(static fn (array $target): array => [
            'id' => (string) $target['id'],
            'family' => (string) $target['family'],
            'priority' => (string) $target['priority'],
            'locale' => (string) $target['locale'],
        ], $this->targets());
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function probe(array $target): array
    {
        $startedAt = hrtime(true);
        $observedAt = CarbonImmutable::now('UTC')->toIso8601String();

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'FermatMind-Public-Content-Probe/1.0'])
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->timeoutSeconds())
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($this->targetUrl((string) $target['path']), (array) $target['query']);

            $body = $this->boundedBody($response);
            $bytes = strlen($body);
            $cacheState = $this->cacheState($response);
            $statusCode = $response->status();
            $readback = $bytes <= $this->payloadBudgetBytes()
                ? $this->readback->extract((string) $target['readback_profile'], $body)
                : $this->emptyReadback((string) $target['readback_profile']);
            $cacheReady = in_array($cacheState, (array) $target['allowed_cache_states'], true);
            $ok = $statusCode >= 200
                && $statusCode < 300
                && $bytes <= $this->payloadBudgetBytes()
                && $cacheReady
                && $readback['ok'] === true;

            $result = $this->resultEnvelope($target, $observedAt, $startedAt, [
                'ok' => $ok,
                'status_code' => $statusCode,
                'status_class' => $this->statusClass($statusCode),
                'bytes' => $bytes,
                'cache_state' => $cacheState,
                'readback' => $readback,
                'error_code' => $this->failureCode($statusCode, $bytes, $cacheReady, $readback['ok']),
            ]);
        } catch (ConnectionException) {
            $result = $this->failureEnvelope($target, $observedAt, $startedAt, 'connection_failed');
        } catch (Throwable) {
            $result = $this->failureEnvelope($target, $observedAt, $startedAt, 'probe_failed');
        }

        $this->store()->put($this->latestKey((string) $target['id']), $result, $this->ttlSeconds());

        return $result;
    }

    private function boundedBody(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $limit = $this->payloadBudgetBytes() + 1;
        $body = '';
        while (! $stream->eof() && strlen($body) < $limit) {
            $chunk = $stream->read(min(8192, $limit - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return $body;
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $fields @return array<string, mixed> */
    private function resultEnvelope(array $target, string $observedAt, int $startedAt, array $fields): array
    {
        return array_merge([
            'target_id' => (string) $target['id'],
            'family' => (string) $target['family'],
            'priority' => (string) $target['priority'],
            'locale' => (string) $target['locale'],
            'observed_at' => $observedAt,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1000000, 2),
        ], $fields);
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function failureEnvelope(array $target, string $observedAt, int $startedAt, string $errorCode): array
    {
        return $this->resultEnvelope($target, $observedAt, $startedAt, [
            'ok' => false,
            'status_code' => 0,
            'status_class' => 'network_error',
            'bytes' => 0,
            'cache_state' => 'unknown',
            'readback' => $this->emptyReadback((string) $target['readback_profile']),
            'error_code' => $errorCode,
        ]);
    }

    /** @return array{ok: false, profile: string, fields: array{}, version_fingerprint: null} */
    private function emptyReadback(string $profile): array
    {
        return [
            'ok' => false,
            'profile' => $profile,
            'fields' => [],
            'version_fingerprint' => null,
        ];
    }

    private function failureCode(int $statusCode, int $bytes, bool $cacheReady, bool $readbackReady): ?string
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            return 'http_status';
        }
        if ($bytes > $this->payloadBudgetBytes()) {
            return 'payload_budget_exceeded';
        }
        if (! $cacheReady) {
            return 'cache_state_degraded';
        }
        if (! $readbackReady) {
            return 'publication_readback_failed';
        }

        return null;
    }

    private function cacheState(Response $response): string
    {
        $state = strtolower(trim((string) $response->header('X-Fermat-Public-Read-Cache', 'unknown')));

        return in_array($state, ['miss', 'fresh', 'stale', 'bypass'], true) ? $state : 'unknown';
    }

    private function statusClass(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode >= 300 && $statusCode < 400 => 'redirect',
            $statusCode >= 400 && $statusCode < 500 => 'client_error',
            $statusCode >= 500 => 'server_error',
            default => 'unknown',
        };
    }

    private function targetUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('public_content_observability.probe.base_url'), '/');
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($baseUrl, PHP_URL_HOST) === null
            || parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
            || ! in_array(parse_url($baseUrl, PHP_URL_PATH), [null, '', '/'], true)) {
            throw new RuntimeException('public content probe base URL is invalid.');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /** @return list<array<string, mixed>> */
    private function targets(): array
    {
        if (! (bool) config('public_content_observability.probe.enabled', true)) {
            throw new RuntimeException('public content delivery probes are disabled.');
        }

        $targets = array_values(array_filter(
            (array) config('public_content_observability.probe.targets', []),
            static fn (mixed $target): bool => is_array($target),
        ));
        if ($targets === []) {
            throw new RuntimeException('public content delivery probe allowlist is empty.');
        }

        $ids = [];
        foreach ($targets as $target) {
            foreach (['id', 'family', 'priority', 'locale', 'path', 'query', 'readback_profile', 'allowed_cache_states'] as $key) {
                if (! array_key_exists($key, $target)) {
                    throw new RuntimeException('public content delivery probe target is incomplete.');
                }
            }
            $id = (string) $target['id'];
            $path = (string) $target['path'];
            if (preg_match('/^[a-z0-9_]{3,80}$/', $id) !== 1
                || isset($ids[$id])
                || ! str_starts_with($path, '/api/v0.5/')
                || parse_url($path, PHP_URL_PATH) !== $path) {
                throw new RuntimeException('public content delivery probe target is invalid.');
            }
            if (preg_match('#/(attempts?|reports?|results?|orders?|payments?|shortlist|internal|ops)(/|$)#i', $path) === 1) {
                throw new RuntimeException('private route entered public content delivery probe allowlist.');
            }
            $query = (array) $target['query'];
            if (array_diff(array_keys($query), ['locale', 'org_id', 'scale_code']) !== []) {
                throw new RuntimeException('public content delivery probe query is not allowlisted.');
            }
            if (! array_key_exists('org_id', $query) || ! in_array($query['org_id'], [0, '0'], true)) {
                throw new RuntimeException('public content delivery probe must use anonymous org_id=0.');
            }
            if ((string) $target['family'] === 'mbti' && ($query['scale_code'] ?? null) !== 'MBTI') {
                throw new RuntimeException('public content delivery probe MBTI scale code is invalid.');
            }
            $allowedCacheStates = array_values((array) $target['allowed_cache_states']);
            if ($allowedCacheStates === []
                || array_diff($allowedCacheStates, ['miss', 'fresh', 'unknown']) !== []) {
                throw new RuntimeException('public content delivery probe cache states are unsafe.');
            }
            $ids[$id] = true;
        }
        if (array_column($targets, 'priority') !== ['L1', 'L2', 'L3']) {
            throw new RuntimeException('public content delivery probe priority rotation is invalid.');
        }

        return $targets;
    }

    private function store(): Repository
    {
        return Cache::store((string) config(
            'public_content_observability.probe.cache_store',
            config('public_content_observability.cache_store', 'redis'),
        ));
    }

    private function latestKey(string $targetId): string
    {
        return self::KEY_PREFIX.':latest:'.$targetId;
    }

    private function payloadBudgetBytes(): int
    {
        return max(1024, min(1048576, (int) config(
            'public_content_observability.probe.payload_budget_bytes',
            524288,
        )));
    }

    private function timeoutSeconds(): int
    {
        return max(1, min(30, (int) config('public_content_observability.probe.timeout_seconds', 8)));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, min($this->timeoutSeconds(), (int) config(
            'public_content_observability.probe.connect_timeout_seconds',
            3,
        )));
    }

    private function ttlSeconds(): int
    {
        return max(3600, min(2592000, (int) config(
            'public_content_observability.probe.retention_seconds',
            604800,
        )));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

final class AccessTestIdentity
{
    public const RULE_VERSION = 'access_test_statistics.v1';

    public const TIMEZONE = 'Asia/Shanghai';

    public function rememberAuthenticatedProxyChain(Request $request): void
    {
        $candidates = [(string) $request->server('REMOTE_ADDR', '')];
        array_push($candidates, ...explode(',', (string) $request->header('X-Forwarded-For', '')));

        try {
            foreach ($candidates as $candidate) {
                $normalized = $this->normalizeIp($candidate);
                if ($normalized !== null) {
                    Cache::put($this->trustedProxyCacheKey($normalized), true, now()->addDays(2));
                }
            }
        } catch (Throwable) {
            // Statistics must remain fail-closed without affecting event ingestion.
        }
    }

    /**
     * @return array{ip_hash:?string,ip_status:string,eligible:bool,exclusion_reason:?string,traffic_class:string,rule_version:string}
     */
    public function snapshotRequest(Request $request, ?\DateTimeInterface $occurredAt = null): array
    {
        $classification = $this->classify(
            (string) $request->userAgent(),
            (string) ($request->header('X-FermatMind-Internal-Probe') ?? ''),
            (string) ($request->attributes->get('client_anon_id') ?? $request->input('anon_id', '')),
            app()->environment()
        );
        $resolved = $this->resolveClientIp($request);

        return $this->snapshot(
            $resolved['ip'],
            $resolved['status'],
            $classification,
            $occurredAt
        );
    }

    /**
     * The header is accepted only by a controller that has already authenticated
     * the server-to-server tracking token. The browser never sends this value.
     *
     * @return array{ip_hash:?string,ip_status:string,eligible:bool,exclusion_reason:?string,traffic_class:string,rule_version:string}
     */
    public function snapshotTrustedDigest(
        ?string $digest,
        ?string $digestDay,
        array $trafficLabels,
        ?\DateTimeInterface $occurredAt = null,
    ): array {
        $day = CarbonImmutable::parse($occurredAt ?? 'now', self::TIMEZONE)
            ->setTimezone(self::TIMEZONE)
            ->toDateString();
        $normalizedDigest = strtolower(trim((string) $digest));
        $normalizedDay = trim((string) $digestDay);
        $validDigest = preg_match('/^[a-f0-9]{64}$/', $normalizedDigest) === 1
            && hash_equals($day, $normalizedDay);

        $classification = $this->classifyLabels($trafficLabels);

        return [
            'ip_hash' => $validDigest ? $normalizedDigest : null,
            'ip_status' => $validDigest ? 'trusted_digest' : 'missing',
            'eligible' => $classification['eligible'],
            'exclusion_reason' => $classification['exclusion_reason'],
            'traffic_class' => $classification['traffic_class'],
            'rule_version' => self::RULE_VERSION,
        ];
    }

    public function digestIp(string $ip, ?\DateTimeInterface $occurredAt = null): ?string
    {
        $normalized = $this->normalizeIp($ip);
        $key = $this->hashKey();
        if ($normalized === null || $key === '') {
            return null;
        }

        return hash_hmac('sha256', self::RULE_VERSION.'|'.$normalized, $key);
    }

    /**
     * @param  array{eligible:bool,exclusion_reason:?string,traffic_class:string}  $classification
     * @return array{ip_hash:?string,ip_status:string,eligible:bool,exclusion_reason:?string,traffic_class:string,rule_version:string}
     */
    private function snapshot(
        ?string $ip,
        string $ipStatus,
        array $classification,
        ?\DateTimeInterface $occurredAt,
    ): array {
        return [
            'ip_hash' => $ip === null ? null : $this->digestIp($ip, $occurredAt),
            'ip_status' => $ipStatus,
            'eligible' => $classification['eligible'],
            'exclusion_reason' => $classification['exclusion_reason'],
            'traffic_class' => $classification['traffic_class'],
            'rule_version' => self::RULE_VERSION,
        ];
    }

    /** @return array{ip:?string,status:string} */
    private function resolveClientIp(Request $request): array
    {
        $remote = $this->normalizeIp((string) $request->server('REMOTE_ADDR', ''));
        if ($remote === null) {
            return ['ip' => null, 'status' => 'missing'];
        }

        $trustedProxies = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            (array) config('analytics.access_test_statistics.trusted_proxies', [])
        )));

        if (! $this->isTrustedProxy($remote, $trustedProxies)) {
            return ['ip' => $remote, 'status' => 'direct'];
        }

        $forwarded = array_values(array_filter(array_map(
            fn (string $value): ?string => $this->normalizeIp(trim($value)),
            explode(',', (string) $request->header('X-Forwarded-For', ''))
        )));

        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            $candidate = $forwarded[$index];
            if (! $this->isTrustedProxy($candidate, $trustedProxies)) {
                return ['ip' => $candidate, 'status' => 'trusted_proxy'];
            }
        }

        return ['ip' => null, 'status' => 'untrusted_chain'];
    }

    /** @return array{eligible:bool,exclusion_reason:?string,traffic_class:string} */
    private function classify(string $userAgent, string $probeHeader, string $anonId, string $environment): array
    {
        $normalizedAgent = strtolower(trim($userAgent));
        $botTokens = (array) config('analytics.access_test_statistics.bot_user_agent_tokens', []);
        foreach ($botTokens as $token) {
            if ($token !== '' && str_contains($normalizedAgent, strtolower((string) $token))) {
                return ['eligible' => false, 'exclusion_reason' => 'confirmed_bot', 'traffic_class' => 'bot'];
            }
        }

        if (trim($probeHeader) !== '' || str_starts_with(strtolower(trim($anonId)), 'codex_probe_')) {
            return ['eligible' => false, 'exclusion_reason' => 'internal_probe', 'traffic_class' => 'internal'];
        }

        if (! in_array($environment, ['production', 'testing'], true)) {
            return ['eligible' => false, 'exclusion_reason' => 'non_production', 'traffic_class' => 'internal'];
        }

        return ['eligible' => true, 'exclusion_reason' => null, 'traffic_class' => 'production_user'];
    }

    /** @param array<string,mixed> $labels
     * @return array{eligible:bool,exclusion_reason:?string,traffic_class:string}
     */
    private function classifyLabels(array $labels): array
    {
        $quality = strtolower(trim((string) ($labels['traffic_quality'] ?? 'unknown')));
        $environment = strtolower(trim((string) ($labels['environment'] ?? 'unknown')));

        if (($labels['is_bot'] ?? false) === true || $quality === 'bot') {
            return ['eligible' => false, 'exclusion_reason' => 'confirmed_bot', 'traffic_class' => 'bot'];
        }
        if (($labels['is_qa'] ?? false) === true || $quality === 'qa') {
            return ['eligible' => false, 'exclusion_reason' => 'internal_qa', 'traffic_class' => 'internal'];
        }
        if (($labels['is_internal'] ?? false) === true || $quality === 'internal' || $environment !== 'production') {
            return ['eligible' => false, 'exclusion_reason' => 'non_production', 'traffic_class' => 'internal'];
        }
        if ($quality === 'suspected') {
            return ['eligible' => false, 'exclusion_reason' => 'suspected_traffic', 'traffic_class' => 'suspected'];
        }

        return ['eligible' => true, 'exclusion_reason' => null, 'traffic_class' => 'production_user'];
    }

    private function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip, " \t\n\r\0\x0B[]");
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $mapped;
            }
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $normalized = @inet_ntop($packed);

        return is_string($normalized) && $normalized !== '' ? strtolower($normalized) : null;
    }

    private function hashKey(): string
    {
        $configured = trim((string) config('analytics.access_test_statistics.hash_key', ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) config('fap.events.ingest_token', ''));
    }

    /** @param list<string> $configured */
    private function isTrustedProxy(string $ip, array $configured): bool
    {
        if (IpUtils::checkIp($ip, $configured)) {
            return true;
        }

        try {
            return Cache::get($this->trustedProxyCacheKey($ip)) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function trustedProxyCacheKey(string $ip): string
    {
        $key = $this->hashKey();
        $digest = $key === '' ? hash('sha256', $ip) : hash_hmac('sha256', 'proxy|'.$ip, $key);

        return 'analytics:access-test-statistics:trusted-proxy:'.$digest;
    }
}

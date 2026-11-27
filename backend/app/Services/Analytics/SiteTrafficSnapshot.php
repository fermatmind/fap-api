<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SiteTrafficSnapshot
{
    private const ENDPOINT = 'https://fermatmind.com/api/internal/site-traffic';

    public function refresh(): bool
    {
        $token = trim((string) config('fap.events.ingest_token', ''));
        if ($token === '') {
            return false;
        }

        $now = CarbonImmutable::now(AccessTestIdentity::TIMEZONE);
        $timestamp = (string) $now->timestamp;

        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(14)->withoutRedirecting()->withHeaders([
                'X-Fm-Site-Traffic-Time' => $timestamp,
                'X-Fm-Site-Traffic-Signature' => hash_hmac('sha256', 'fermatmind.site-traffic.v1:'.$timestamp, $token),
            ])->get(self::ENDPOINT);
            $snapshot = $response->json();
            if (! $response->ok() || ! $this->valid($snapshot, $now)) {
                return false;
            }

            $previous = $this->today();
            if ($previous !== null && $previous['through'] > $snapshot['through']) {
                return false;
            }

            return Cache::put($this->key($now), [
                'through' => $snapshot['through'],
                'pageRequests' => $snapshot['pageRequests'],
                'uniqueIps' => $snapshot['uniqueIps'],
            ], now()->addDay());
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{through:int,pageRequests:int,uniqueIps:int}|null */
    public function today(): ?array
    {
        $snapshot = Cache::get($this->key(CarbonImmutable::now(AccessTestIdentity::TIMEZONE)));

        return is_array($snapshot) ? $snapshot : null;
    }

    private function key(CarbonImmutable $day): string
    {
        return 'ops:site-traffic:v1:'.$day->toDateString();
    }

    private function valid(mixed $snapshot, CarbonImmutable $now): bool
    {
        return is_array($snapshot)
            && ($snapshot['date'] ?? null) === $now->toDateString()
            && is_int($snapshot['through'] ?? null)
            && $snapshot['through'] >= max($now->startOfDay()->timestamp, $now->timestamp - 180)
            && $snapshot['through'] <= $now->timestamp + 30
            && is_int($snapshot['pageRequests'] ?? null)
            && $snapshot['pageRequests'] >= 0
            && is_int($snapshot['uniqueIps'] ?? null)
            && $snapshot['uniqueIps'] >= 0
            && $snapshot['uniqueIps'] <= $snapshot['pageRequests'];
    }
}

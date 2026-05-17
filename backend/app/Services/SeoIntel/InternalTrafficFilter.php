<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class InternalTrafficFilter
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function shouldExclude(array $payload, bool $includeInternal = false): bool
    {
        if ($includeInternal) {
            return false;
        }

        if ((bool) ($payload['is_internal'] ?? false) || (bool) ($payload['is_qa'] ?? false) || (bool) ($payload['is_bot'] ?? false)) {
            return true;
        }

        $trafficQuality = strtolower((string) ($payload['traffic_quality'] ?? 'unknown'));

        if (in_array($trafficQuality, ['qa', 'internal', 'bot'], true)) {
            return true;
        }

        $environment = strtolower(trim((string) ($payload['environment'] ?? 'production')));

        return $environment !== '' && ! in_array($environment, ['production', 'prod'], true);
    }
}

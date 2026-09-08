<?php

declare(strict_types=1);

namespace App\Services\Career;

use Symfony\Component\Uid\Ulid;

/** Pure retention policy: only immutable, dated public projections are collectible. */
final class CareerCacheVersionRetention
{
    public const RETENTION_SECONDS = 2 * 86400;

    /** @return array{base: string, version: string, kind: string}|null */
    public function identify(string $key): ?array
    {
        if (! preg_match('/^(career:public-authority:(?:job-detail:v3:[a-z0-9-]+:(?:en|zh-CN)|job-index:v3:(?:en|zh-CN):(?:public|with-non-indexable)|directory-read-model:v2:(?:en|zh-CN))):(versions|exposure-projections):([0-9A-HJKMNP-TV-Z]{26})$/D', $key, $match)) {
            return null;
        }
        if (! Ulid::isValid($match[3])) {
            return null;
        }

        return ['base' => $match[1], 'version' => $match[3], 'kind' => $match[2]];
    }

    public function collectible(string $key, mixed $active, mixed $lkg, bool $pinned, int $idleSeconds, int $now): bool
    {
        $identity = $this->identify($key);
        if ($identity === null || ! is_string($active) || ! is_string($lkg)
            || ! Ulid::isValid($active) || ! Ulid::isValid($lkg) || $pinned
            || $idleSeconds < self::RETENTION_SECONDS) {
            return false;
        }
        $version = $identity['version'];

        // Never remove an active/LKG, a newer staged candidate, or an undated legacy key.
        return strcmp($version, $active) < 0 && strcmp($version, $lkg) < 0
            && (new Ulid($version))->getDateTime()->getTimestamp() <= $now - self::RETENTION_SECONDS;
    }
}

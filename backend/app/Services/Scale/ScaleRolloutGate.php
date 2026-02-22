<?php

declare(strict_types=1);

namespace App\Services\Scale;

use App\Exceptions\Api\ApiProblemException;

final class ScaleRolloutGate
{
    public const PAYWALL_OFF = 'off';
    public const PAYWALL_FREE_ONLY = 'free_only';
    public const PAYWALL_FULL = 'full';

    /**
     * @param array<string,mixed> $scaleRow
     */
    public static function assertEnabled(string $scaleCode, array $scaleRow, string $region, string $subjectKey = ''): void
    {
        $capabilities = self::capabilities($scaleRow);

        $enabledInProd = self::toBool(
            $capabilities['enabled_in_prod'] ?? ($capabilities['rollout']['enabled_in_prod'] ?? true),
            true
        );
        if (!$enabledInProd) {
            throw self::notEnabled($scaleCode, $region, 'disabled');
        }

        $paywallMode = self::paywallMode($scaleRow);
        if ($paywallMode === self::PAYWALL_OFF) {
            throw self::notEnabled($scaleCode, $region, 'paywall_off');
        }

        $enabledRegions = self::enabledRegions($capabilities);
        if ($enabledRegions !== []) {
            $normalizedRegion = self::normalizeRegion($region);
            if (!in_array($normalizedRegion, $enabledRegions, true)) {
                throw self::notEnabled($scaleCode, $region, 'region_not_allowed');
            }
        }

        $rolloutRatio = self::rolloutRatio($capabilities);
        if ($rolloutRatio <= 0.0) {
            throw self::notEnabled($scaleCode, $region, 'ratio_zero');
        }

        if ($rolloutRatio < 1.0 && trim($subjectKey) !== '') {
            $threshold = (int) floor($rolloutRatio * 10000.0);
            $subject = strtoupper(trim($scaleCode)) . '|' . trim($subjectKey);
            $bucket = ((int) sprintf('%u', crc32($subject))) % 10000;
            if ($bucket >= $threshold) {
                throw self::notEnabled($scaleCode, $region, 'ratio_not_hit');
            }
        }
    }

    /**
     * @param array<string,mixed> $scaleRow
     */
    public static function paywallMode(array $scaleRow): string
    {
        $capabilities = self::capabilities($scaleRow);
        $raw = $capabilities['paywall_mode'] ?? ($capabilities['rollout']['paywall_mode'] ?? self::PAYWALL_FULL);

        return self::normalizePaywallMode($raw);
    }

    /**
     * @param array<string,mixed> $scaleRow
     * @return array<string,mixed>
     */
    private static function capabilities(array $scaleRow): array
    {
        $raw = $scaleRow['capabilities_json'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<string,mixed> $capabilities
     * @return list<string>
     */
    private static function enabledRegions(array $capabilities): array
    {
        $raw = $capabilities['enabled_regions'] ?? ($capabilities['rollout']['enabled_regions'] ?? []);
        if (!is_array($raw)) {
            return [];
        }

        $regions = [];
        foreach ($raw as $region) {
            $normalized = self::normalizeRegion((string) $region);
            if ($normalized === '') {
                continue;
            }
            $regions[$normalized] = true;
        }

        return array_keys($regions);
    }

    /**
     * @param array<string,mixed> $capabilities
     */
    private static function rolloutRatio(array $capabilities): float
    {
        $raw = $capabilities['rollout_ratio'] ?? ($capabilities['rollout']['rollout_ratio'] ?? 1.0);
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        $value = is_numeric($raw) ? (float) $raw : 1.0;
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private static function normalizeRegion(string $region): string
    {
        $region = strtoupper(str_replace('-', '_', trim($region)));
        if ($region === '') {
            return 'GLOBAL';
        }

        return $region;
    }

    private static function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function normalizePaywallMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        if (in_array($mode, [self::PAYWALL_OFF, self::PAYWALL_FREE_ONLY, self::PAYWALL_FULL], true)) {
            return $mode;
        }

        return self::PAYWALL_FULL;
    }

    private static function notEnabled(string $scaleCode, string $region, string $reason): ApiProblemException
    {
        return new ApiProblemException(
            403,
            'NOT_ENABLED',
            'scale not enabled.',
            [
                'scale_code' => strtoupper(trim($scaleCode)),
                'region' => self::normalizeRegion($region),
                'reason' => $reason,
            ]
        );
    }
}

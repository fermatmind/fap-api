<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

final class SeoOperationsUiState
{
    public const PRODUCTION_HEALTHY = 'production_healthy';

    public const PRODUCTION_UNPROVEN = 'production_unproven';

    public const PRODUCTION_DEGRADED = 'production_degraded';

    public const DEPLOYED_DISABLED = 'deployed_disabled';

    public const CODE_ONLY = 'code_only';

    public const EXTERNAL_NOT_CONNECTED = 'external_not_connected';

    public const NOT_REQUIRED = 'not_required';

    public const MEASUREMENT_HOLD = 'MEASUREMENT_HOLD';

    public const VERIFIED_ZERO = 'verified_zero';

    public const FILTER_EMPTY = 'filter_empty';

    public const STALE = 'stale';

    public const ERROR = 'error';

    public const PERMISSION_DENIED = 'permission_denied';

    public const UNAVAILABLE = 'unavailable';

    /** @var list<string> */
    public const ALL = [
        self::PRODUCTION_HEALTHY,
        self::PRODUCTION_UNPROVEN,
        self::PRODUCTION_DEGRADED,
        self::DEPLOYED_DISABLED,
        self::CODE_ONLY,
        self::EXTERNAL_NOT_CONNECTED,
        self::NOT_REQUIRED,
        self::MEASUREMENT_HOLD,
        self::VERIFIED_ZERO,
        self::FILTER_EMPTY,
        self::STALE,
        self::ERROR,
        self::PERMISSION_DENIED,
        self::UNAVAILABLE,
    ];

    public static function normalize(?string $state): string
    {
        return in_array($state, self::ALL, true) ? $state : self::UNAVAILABLE;
    }

    public static function tone(?string $state): string
    {
        return match (self::normalize($state)) {
            self::PRODUCTION_HEALTHY, self::VERIFIED_ZERO => 'success',
            self::PRODUCTION_DEGRADED, self::MEASUREMENT_HOLD, self::STALE => 'warning',
            self::ERROR, self::PERMISSION_DENIED => 'danger',
            self::PRODUCTION_UNPROVEN => 'info',
            default => 'gray',
        };
    }

    public static function metricValue(mixed $value, ?string $state): string
    {
        $normalized = self::normalize($state);

        if ($normalized === self::VERIFIED_ZERO) {
            return '0';
        }

        if ($value === null || in_array($normalized, [
            self::PRODUCTION_UNPROVEN,
            self::DEPLOYED_DISABLED,
            self::CODE_ONLY,
            self::EXTERNAL_NOT_CONNECTED,
            self::MEASUREMENT_HOLD,
            self::STALE,
            self::ERROR,
            self::UNAVAILABLE,
        ], true)) {
            return '—';
        }

        return (string) $value;
    }

    public static function blocksExpansion(?string $state): bool
    {
        return self::normalize($state) !== self::PRODUCTION_HEALTHY;
    }
}

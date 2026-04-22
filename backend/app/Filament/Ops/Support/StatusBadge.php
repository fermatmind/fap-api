<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use Illuminate\Support\Str;

final class StatusBadge
{
    public static function color(bool|int|string|null $state): string
    {
        if (is_bool($state) || is_int($state)) {
            return self::isTruthy($state) ? 'success' : 'gray';
        }

        $normalized = self::normalize($state);

        if ($normalized === '') {
            return 'gray';
        }

        return match (true) {
            self::containsAny($normalized, ['published', 'active', 'success', 'approved', 'executed', 'complete', 'completed', 'pass', 'passed', 'valid', 'verified', 'healthy', 'public', 'indexable', 'ready']) => 'success',
            self::containsAny($normalized, ['pending', 'queued', 'queue', 'processing', 'executing', 'running', 'requested', 'warning', 'hold', 'review']) => 'warning',
            self::containsAny($normalized, ['failed', 'error', 'danger', 'rejected', 'revoked', 'expired', 'invalid', 'stop ship', 'rollback']) => 'danger',
            self::containsAny($normalized, ['draft', 'inactive', 'disabled', 'archived', 'hidden', 'suspended', 'non indexable', 'noindex']) => 'gray',
            default => 'gray',
        };
    }

    public static function booleanColor(bool|int|string|null $state): string
    {
        return self::isTruthy($state) ? 'success' : 'gray';
    }

    public static function booleanLabel(bool|int|string|null $state, string $trueLabel, string $falseLabel): string
    {
        return self::isTruthy($state) ? $trueLabel : $falseLabel;
    }

    public static function label(bool|int|string|null $state): string
    {
        if (is_bool($state)) {
            return $state ? __('ops.status.active') : __('ops.status.inactive');
        }

        $normalized = self::normalize($state);
        if ($normalized === '') {
            return '—';
        }

        $key = str_replace(' ', '_', $normalized);
        $translation = __('ops.status.'.$key);

        return $translation === 'ops.status.'.$key ? (string) $state : (string) $translation;
    }

    private static function containsAny(string $state, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($state, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function isTruthy(bool|int|string|null $state): bool
    {
        if (is_bool($state)) {
            return $state;
        }

        if (is_int($state)) {
            return $state === 1;
        }

        return in_array(self::normalize($state), ['1', 'true', 'yes', 'on', 'active', 'published', 'success', 'valid', 'verified', 'public', 'indexable'], true);
    }

    private static function normalize(bool|int|string|null $state): string
    {
        return Str::of((string) $state)
            ->trim()
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->value();
    }
}

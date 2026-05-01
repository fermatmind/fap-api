<?php

declare(strict_types=1);

namespace App\Services\Scale;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublicScaleInputGuard
{
    /**
     * @var array<string,string>
     */
    private const LOCALE_MAP = [
        'en' => 'en',
        'en-us' => 'en-US',
        'en-gb' => 'en-GB',
        'zh' => 'zh-CN',
        'zh-cn' => 'zh-CN',
        'zh-hans' => 'zh-CN',
    ];

    public function normalizeScaleCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/\A[A-Z0-9_]{1,64}\z/', $normalized) === 1 ? $normalized : null;
    }

    public function normalizeSlug(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,125}[a-z0-9])?\z/', $normalized) === 1
            ? $normalized
            : null;
    }

    public function normalizeRequestedLocale(Request $request, string $defaultLocale): string
    {
        $raw = $this->firstScalarValue([
            $request->query('locale'),
            $request->header('X-FAP-Locale'),
            $request->attributes->get('locale'),
            $defaultLocale,
        ], 'locale');

        return $this->normalizeLocale($raw, 'locale');
    }

    public function normalizeRequestedRegion(Request $request, string $defaultRegion): string
    {
        $raw = $this->firstScalarValue([
            $request->query('region'),
            $request->attributes->get('region'),
            $defaultRegion,
        ], 'region');

        return $this->normalizeRegion($raw, 'region');
    }

    public function normalizeLocale(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => 'locale must be a supported scalar value.']);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            $raw = 'en';
        }
        if (strlen($raw) > 16 || preg_match('/\A[A-Za-z]{2,3}(?:[-_][A-Za-z]{2,8})?\z/', $raw) !== 1) {
            throw ValidationException::withMessages([$field => 'locale must be supported.']);
        }

        $key = strtolower(str_replace('_', '-', $raw));
        if (! array_key_exists($key, self::LOCALE_MAP)) {
            throw ValidationException::withMessages([$field => 'locale must be supported.']);
        }

        return self::LOCALE_MAP[$key];
    }

    public function normalizeRegion(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => 'region must be a supported scalar value.']);
        }

        $normalized = strtoupper(str_replace('-', '_', trim((string) $value)));
        if ($normalized === '') {
            $normalized = 'GLOBAL';
        }
        if (strlen($normalized) > 32 || preg_match('/\A[A-Z0-9_]+\z/', $normalized) !== 1) {
            throw ValidationException::withMessages([$field => 'region must be supported.']);
        }

        if (! in_array($normalized, $this->supportedRegions(), true)) {
            throw ValidationException::withMessages([$field => 'region must be supported.']);
        }

        return $normalized;
    }

    public function assertSafeContentIdentifier(string $value, string $field, int $max = 128): void
    {
        $trimmed = trim($value);
        if (
            $trimmed === ''
            || strlen($trimmed) > $max
            || str_contains($trimmed, '..')
            || preg_match('/\A[A-Za-z0-9._-]+\z/', $trimmed) !== 1
        ) {
            throw ValidationException::withMessages([$field => "$field must be a safe content identifier."]);
        }
    }

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstScalarValue(array $candidates, string $field): mixed
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            if (! is_scalar($candidate)) {
                throw ValidationException::withMessages([$field => "$field must be a scalar value."]);
            }
            if (trim((string) $candidate) === '') {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function supportedRegions(): array
    {
        $regions = array_keys((array) config('regions.regions', []));
        $fallbacks = array_keys((array) config('content_packs.region_fallbacks', []));
        $regions[] = 'GLOBAL';

        return array_values(array_unique(array_filter([
            ...array_map(static fn ($region): string => strtoupper(str_replace('-', '_', (string) $region)), $regions),
            ...array_map(static fn ($region): string => strtoupper(str_replace('-', '_', (string) $region)), $fallbacks),
        ], static fn (string $region): bool => $region !== '' && $region !== '*')));
    }
}

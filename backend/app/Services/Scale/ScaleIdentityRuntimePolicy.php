<?php

declare(strict_types=1);

namespace App\Services\Scale;

final class ScaleIdentityRuntimePolicy
{
    public function writeMode(): string
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));
        if (! in_array($mode, ['legacy', 'dual', 'v2'], true)) {
            return 'legacy';
        }

        return $mode;
    }

    public function readMode(): string
    {
        $mode = strtolower(trim((string) config('scale_identity.read_mode', 'legacy')));
        if (! in_array($mode, ['legacy', 'dual_prefer_old', 'dual_prefer_new', 'v2'], true)) {
            return 'legacy';
        }

        return $mode;
    }

    public function apiResponseScaleCodeMode(): string
    {
        $mode = strtolower(trim((string) config('scale_identity.api_response_scale_code_mode', 'legacy')));
        if (! in_array($mode, ['legacy', 'dual', 'v2'], true)) {
            return 'legacy';
        }

        return $mode;
    }

    public function shouldWriteScaleIdentityColumns(): bool
    {
        return in_array($this->writeMode(), ['dual', 'v2'], true);
    }

    public function shouldUseV2PrimaryScaleCode(): bool
    {
        return $this->apiResponseScaleCodeMode() === 'v2';
    }

    public function acceptsLegacyScaleCode(): bool
    {
        return (bool) config('scale_identity.accept_legacy_scale_code', true);
    }

    public function allowsDemoScales(): bool
    {
        return (bool) config('scale_identity.allow_demo_scales', true);
    }
}

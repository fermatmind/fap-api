<?php

declare(strict_types=1);

namespace App\Services\Scale;

use App\Exceptions\Api\ApiProblemException;

final class ScaleCodeInputGuard
{
    public function __construct(
        private readonly ScaleIdentityResolver $identityResolver,
        private readonly ScaleIdentityRuntimePolicy $runtimePolicy,
    ) {}

    public function assertAccepted(string $requestedScaleCode): void
    {
        $requested = strtoupper(trim($requestedScaleCode));
        if ($requested === '') {
            return;
        }

        $resolved = $this->identityResolver->resolveByAnyCode($requested);
        $legacyCode = $requested;
        $v2Code = $requested;

        if (is_array($resolved) && ((bool) ($resolved['is_known'] ?? false))) {
            $resolvedLegacy = strtoupper(trim((string) ($resolved['scale_code_v1'] ?? '')));
            $resolvedV2 = strtoupper(trim((string) ($resolved['scale_code_v2'] ?? '')));
            if ($resolvedLegacy !== '') {
                $legacyCode = $resolvedLegacy;
            }
            if ($resolvedV2 !== '') {
                $v2Code = $resolvedV2;
            }
        }

        if (
            ! $this->runtimePolicy->allowsDemoScales()
            && in_array($legacyCode, ['DEMO_ANSWERS', 'SIMPLE_SCORE_DEMO'], true)
        ) {
            $replacementLegacy = $this->identityResolver->demoReplacement($legacyCode);
            $replacementV2 = null;

            if ($replacementLegacy !== null) {
                $replacementIdentity = $this->identityResolver->resolveByAnyCode($replacementLegacy);
                if (is_array($replacementIdentity) && ((bool) ($replacementIdentity['is_known'] ?? false))) {
                    $resolvedReplacementV2 = strtoupper(trim((string) ($replacementIdentity['scale_code_v2'] ?? '')));
                    if ($resolvedReplacementV2 !== '') {
                        $replacementV2 = $resolvedReplacementV2;
                    }
                }
            }

            throw new ApiProblemException(
                410,
                'SCALE_DEPRECATED',
                'scale is deprecated.',
                [
                    'requested_scale_code' => $requested,
                    'scale_code_legacy' => $legacyCode,
                    'replacement_scale_code' => $replacementLegacy,
                    'replacement_scale_code_v2' => $replacementV2,
                ]
            );
        }

        if (
            $requested === $legacyCode
            && ! $this->runtimePolicy->acceptsLegacyScaleCode()
        ) {
            throw new ApiProblemException(
                410,
                'SCALE_CODE_LEGACY_NOT_ACCEPTED',
                'legacy scale_code is not accepted.',
                [
                    'requested_scale_code' => $requested,
                    'scale_code_legacy' => $legacyCode,
                    'replacement_scale_code_v2' => $v2Code !== '' ? $v2Code : null,
                ]
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Scale;

final class ScaleCodeResponseProjector
{
    public function __construct(
        private readonly ScaleIdentityRuntimePolicy $runtimePolicy,
        private readonly ScaleIdentityResolver $identityResolver,
    ) {}

    /**
     * @return array{
     *   scale_code:string,
     *   scale_code_legacy:string,
     *   scale_code_v2:string,
     *   scale_uid:string|null
     * }
     */
    public function project(string $legacyCode, ?string $v2Code = null, ?string $scaleUid = null): array
    {
        $legacy = strtoupper(trim($legacyCode));
        $v2 = strtoupper(trim((string) $v2Code));
        $uid = trim((string) $scaleUid);

        if ($legacy === '' || $v2 === '' || $uid === '') {
            $identityInput = $legacy !== '' ? $legacy : $v2;
            $resolved = $this->resolveKnownIdentity($identityInput);
            if (is_array($resolved)) {
                if ($legacy === '') {
                    $legacy = $resolved['scale_code_v1'];
                }
                if ($v2 === '') {
                    $v2 = $resolved['scale_code_v2'];
                }
                if ($uid === '') {
                    $uid = $resolved['scale_uid'];
                }
            }
        }

        if ($legacy === '' && $v2 !== '') {
            $legacy = $v2;
        }
        if ($v2 === '' && $legacy !== '') {
            $v2 = $legacy;
        }

        $primary = $this->runtimePolicy->shouldUseV2PrimaryScaleCode()
            ? ($v2 !== '' ? $v2 : $legacy)
            : ($legacy !== '' ? $legacy : $v2);

        return [
            'scale_code' => $primary,
            'scale_code_legacy' => $legacy,
            'scale_code_v2' => $v2,
            'scale_uid' => $uid !== '' ? $uid : null,
        ];
    }

    /**
     * @return array{scale_code_v1:string,scale_code_v2:string,scale_uid:string}|null
     */
    private function resolveKnownIdentity(string $code): ?array
    {
        $input = strtoupper(trim($code));
        if ($input === '') {
            return null;
        }

        $resolved = $this->identityResolver->resolveByAnyCode($input);
        if (! is_array($resolved) || ! ((bool) ($resolved['is_known'] ?? false))) {
            return null;
        }

        $legacy = strtoupper(trim((string) ($resolved['scale_code_v1'] ?? '')));
        $v2 = strtoupper(trim((string) ($resolved['scale_code_v2'] ?? '')));
        $uid = trim((string) ($resolved['scale_uid'] ?? ''));
        if ($legacy === '' || $v2 === '' || $uid === '') {
            return null;
        }

        return [
            'scale_code_v1' => $legacy,
            'scale_code_v2' => $v2,
            'scale_uid' => $uid,
        ];
    }
}

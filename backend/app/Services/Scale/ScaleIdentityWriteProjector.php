<?php

declare(strict_types=1);

namespace App\Services\Scale;

use App\Models\Attempt;

final class ScaleIdentityWriteProjector
{
    public function __construct(
        private readonly ScaleIdentityResolver $identityResolver,
    ) {}

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    public function projectFromAttempt(Attempt $attempt): array
    {
        return $this->projectFromCodes(
            (string) ($attempt->scale_code ?? ''),
            (string) ($attempt->scale_code_v2 ?? ''),
            (string) ($attempt->scale_uid ?? '')
        );
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    public function projectFromCodes(string $scaleCodeV1, ?string $scaleCodeV2 = null, ?string $scaleUid = null): array
    {
        $legacyCode = strtoupper(trim($scaleCodeV1));
        $v2Code = strtoupper(trim((string) $scaleCodeV2));
        $uid = trim((string) $scaleUid);

        if ($v2Code !== '' && $uid !== '') {
            return [
                'scale_code_v2' => $v2Code,
                'scale_uid' => $uid,
            ];
        }

        if ($v2Code !== '') {
            $this->fillFromResolvedIdentity($v2Code, $legacyCode, $v2Code, $uid);
        }

        if ($legacyCode !== '' && ($v2Code === '' || $uid === '')) {
            $this->fillFromResolvedIdentity($legacyCode, $legacyCode, $v2Code, $uid);
        }

        $v1ToV2 = $this->normalizeUpperMap((array) config('scale_identity.code_map_v1_to_v2', []));
        $v2ToV1 = $this->normalizeUpperMap((array) config('scale_identity.code_map_v2_to_v1', []));
        $uidMap = $this->normalizeUidMap((array) config('scale_identity.scale_uid_map', []));

        if ($legacyCode === '' && $v2Code !== '' && isset($v2ToV1[$v2Code])) {
            $legacyCode = $v2ToV1[$v2Code];
        }

        if ($v2Code === '' && $legacyCode !== '' && isset($v1ToV2[$legacyCode])) {
            $v2Code = $v1ToV2[$legacyCode];
        }

        if ($uid === '' && $legacyCode !== '' && isset($uidMap[$legacyCode])) {
            $uid = $uidMap[$legacyCode];
        }

        return [
            'scale_code_v2' => $v2Code !== '' ? $v2Code : null,
            'scale_uid' => $uid !== '' ? $uid : null,
        ];
    }

    private function fillFromResolvedIdentity(string $inputCode, string &$legacyCode, string &$v2Code, string &$uid): void
    {
        $resolved = $this->identityResolver->resolveByAnyCode($inputCode);
        $isKnown = is_array($resolved) && (bool) ($resolved['is_known'] ?? false);
        if (! $isKnown) {
            return;
        }

        $resolvedV1 = strtoupper(trim((string) ($resolved['scale_code_v1'] ?? '')));
        $resolvedV2 = strtoupper(trim((string) ($resolved['scale_code_v2'] ?? '')));
        $resolvedUid = trim((string) ($resolved['scale_uid'] ?? ''));

        if ($legacyCode === '' && $resolvedV1 !== '') {
            $legacyCode = $resolvedV1;
        }
        if ($v2Code === '' && $resolvedV2 !== '') {
            $v2Code = $resolvedV2;
        }
        if ($uid === '' && $resolvedUid !== '') {
            $uid = $resolvedUid;
        }
    }

    /**
     * @param  array<mixed,mixed>  $map
     * @return array<string,string>
     */
    private function normalizeUpperMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $key = strtoupper(trim((string) $k));
            $val = strtoupper(trim((string) $v));
            if ($key === '' || $val === '') {
                continue;
            }
            $out[$key] = $val;
        }

        return $out;
    }

    /**
     * @param  array<mixed,mixed>  $map
     * @return array<string,string>
     */
    private function normalizeUidMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $key = strtoupper(trim((string) $k));
            $val = trim((string) $v);
            if ($key === '' || $val === '') {
                continue;
            }
            $out[$key] = $val;
        }

        return $out;
    }
}

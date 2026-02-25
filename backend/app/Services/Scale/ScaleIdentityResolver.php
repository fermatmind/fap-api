<?php

declare(strict_types=1);

namespace App\Services\Scale;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ScaleIdentityResolver
{
    /**
     * @return array{
     *   input_code:string,
     *   scale_uid:string|null,
     *   scale_code_v1:string,
     *   scale_code_v2:string,
     *   pack_id_v1:?string,
     *   pack_id_v2:?string,
     *   dir_version_v1:?string,
     *   dir_version_v2:?string,
     *   resolved_from_alias:bool,
     *   is_known:bool
     * }|null
     */
    public function resolveByAnyCode(string $code): ?array
    {
        $inputCode = strtoupper(trim($code));
        if ($inputCode === '') {
            return null;
        }

        $resolvedFromDb = $this->resolveIdentityFromDatabase($inputCode);
        if (is_array($resolvedFromDb)) {
            return [
                'input_code' => $inputCode,
                'scale_uid' => $resolvedFromDb['scale_uid'],
                'scale_code_v1' => $resolvedFromDb['scale_code_v1'],
                'scale_code_v2' => $resolvedFromDb['scale_code_v2'],
                'pack_id_v1' => $resolvedFromDb['pack_id_v1'],
                'pack_id_v2' => $resolvedFromDb['pack_id_v2'],
                'dir_version_v1' => $resolvedFromDb['dir_version_v1'],
                'dir_version_v2' => $resolvedFromDb['dir_version_v2'],
                'resolved_from_alias' => $inputCode !== $resolvedFromDb['scale_code_v1'],
                'is_known' => true,
            ];
        }

        $v1ToV2 = $this->v1ToV2Map();
        $v2ToV1 = $this->v2ToV1Map();
        $uidMap = $this->uidMap();

        if (isset($v1ToV2[$inputCode])) {
            $v1 = $inputCode;
            $v2 = (string) $v1ToV2[$inputCode];
        } elseif (isset($v2ToV1[$inputCode])) {
            $v2 = $inputCode;
            $v1 = (string) $v2ToV1[$inputCode];
        } else {
            return [
                'input_code' => $inputCode,
                'scale_uid' => null,
                'scale_code_v1' => $inputCode,
                'scale_code_v2' => $inputCode,
                'pack_id_v1' => null,
                'pack_id_v2' => null,
                'dir_version_v1' => null,
                'dir_version_v2' => null,
                'resolved_from_alias' => false,
                'is_known' => false,
            ];
        }

        $packIdV1 = $this->mapValueByScaleCode('pack_id_map_v1', $v1);
        $packIdV2 = $this->mapValueByScaleCode('pack_id_map_v2', $v1);
        $dirVersionV1 = $this->mapValueByScaleCode('dir_version_map_v1', $v1);
        $dirVersionV2 = $this->mapValueByScaleCode('dir_version_map_v2', $v1);

        return [
            'input_code' => $inputCode,
            'scale_uid' => $uidMap[$v1] ?? null,
            'scale_code_v1' => $v1,
            'scale_code_v2' => $v2,
            'pack_id_v1' => $packIdV1,
            'pack_id_v2' => $packIdV2,
            'dir_version_v1' => $dirVersionV1,
            'dir_version_v2' => $dirVersionV2,
            'resolved_from_alias' => $inputCode !== $v1,
            'is_known' => true,
        ];
    }

    public function acceptsLegacyScaleCode(): bool
    {
        return (bool) config('scale_identity.accept_legacy_scale_code', true);
    }

    public function shouldAllowDemoScale(string $scaleCode): bool
    {
        $code = strtoupper(trim($scaleCode));
        if ($code === '') {
            return false;
        }

        if (! in_array($code, ['DEMO_ANSWERS', 'SIMPLE_SCORE_DEMO'], true)) {
            return true;
        }

        return (bool) config('scale_identity.allow_demo_scales', true);
    }

    public function demoReplacement(string $scaleCode): ?string
    {
        $code = strtoupper(trim($scaleCode));
        if ($code === '') {
            return null;
        }

        $map = (array) config('scale_identity.demo_replacement_map', []);
        $replacement = trim((string) ($map[$code] ?? ''));

        return $replacement !== '' ? $replacement : null;
    }

    public function resolveResponseScaleCode(string $legacyCode, string $v2Code): string
    {
        $legacy = strtoupper(trim($legacyCode));
        $v2 = strtoupper(trim($v2Code));

        if ($legacy === '' && $v2 === '') {
            return '';
        }
        if ($legacy === '') {
            return $v2;
        }
        if ($v2 === '') {
            $v2 = $legacy;
        }

        $mode = strtolower(trim((string) config('scale_identity.api_response_scale_code_mode', 'legacy')));
        if ($mode === 'v2') {
            return $v2;
        }

        // legacy + dual: keep old primary field stable while exposing v2 side-by-side.
        return $legacy;
    }

    /**
     * @return array<string,string>
     */
    private function v1ToV2Map(): array
    {
        $map = (array) config('scale_identity.code_map_v1_to_v2', []);
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
     * @return array<string,string>
     */
    private function v2ToV1Map(): array
    {
        $map = (array) config('scale_identity.code_map_v2_to_v1', []);
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
     * @return array<string,string>
     */
    private function uidMap(): array
    {
        $map = (array) config('scale_identity.scale_uid_map', []);
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

    private function mapValueByScaleCode(string $configKey, string $scaleCodeV1): ?string
    {
        $map = (array) config('scale_identity.'.$configKey, []);
        $value = trim((string) ($map[$scaleCodeV1] ?? ''));

        return $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *     scale_uid:string,
     *     scale_code_v1:string,
     *     scale_code_v2:string,
     *     pack_id_v1:?string,
     *     pack_id_v2:?string,
     *     dir_version_v1:?string,
     *     dir_version_v2:?string
     * }|null
     */
    private function resolveIdentityFromDatabase(string $inputCode): ?array
    {
        if (! Schema::hasTable('scale_identities')) {
            return null;
        }

        try {
            if (Schema::hasTable('scale_code_aliases')) {
                $aliasRow = DB::table('scale_code_aliases as aliases')
                    ->join('scale_identities as identities', 'identities.scale_uid', '=', 'aliases.scale_uid')
                    ->select([
                        'identities.scale_uid',
                        'identities.scale_code_v1',
                        'identities.scale_code_v2',
                        'identities.pack_id_v1',
                        'identities.pack_id_v2',
                        'identities.dir_version_v1',
                        'identities.dir_version_v2',
                    ])
                    ->where('identities.status', 'active')
                    ->whereRaw('upper(aliases.alias_code) = ?', [$inputCode])
                    ->orderByDesc('aliases.is_primary')
                    ->orderBy('aliases.id')
                    ->first();

                if ($aliasRow) {
                    $scaleUid = trim((string) ($aliasRow->scale_uid ?? ''));
                    $v1 = strtoupper(trim((string) ($aliasRow->scale_code_v1 ?? '')));
                    $v2 = strtoupper(trim((string) ($aliasRow->scale_code_v2 ?? '')));
                    if ($scaleUid !== '' && $v1 !== '' && $v2 !== '') {
                        return [
                            'scale_uid' => $scaleUid,
                            'scale_code_v1' => $v1,
                            'scale_code_v2' => $v2,
                            'pack_id_v1' => $this->nullableValue($aliasRow->pack_id_v1 ?? null),
                            'pack_id_v2' => $this->nullableValue($aliasRow->pack_id_v2 ?? null),
                            'dir_version_v1' => $this->nullableValue($aliasRow->dir_version_v1 ?? null),
                            'dir_version_v2' => $this->nullableValue($aliasRow->dir_version_v2 ?? null),
                        ];
                    }
                }
            }

            $identityRow = DB::table('scale_identities')
                ->select([
                    'scale_uid',
                    'scale_code_v1',
                    'scale_code_v2',
                    'pack_id_v1',
                    'pack_id_v2',
                    'dir_version_v1',
                    'dir_version_v2',
                ])
                ->where('status', 'active')
                ->where(function ($query) use ($inputCode): void {
                    $query->whereRaw('upper(scale_code_v1) = ?', [$inputCode])
                        ->orWhereRaw('upper(scale_code_v2) = ?', [$inputCode]);
                })
                ->first();

            if (! $identityRow) {
                return null;
            }

            $scaleUid = trim((string) ($identityRow->scale_uid ?? ''));
            $v1 = strtoupper(trim((string) ($identityRow->scale_code_v1 ?? '')));
            $v2 = strtoupper(trim((string) ($identityRow->scale_code_v2 ?? '')));
            if ($scaleUid === '' || $v1 === '' || $v2 === '') {
                return null;
            }

            return [
                'scale_uid' => $scaleUid,
                'scale_code_v1' => $v1,
                'scale_code_v2' => $v2,
                'pack_id_v1' => $this->nullableValue($identityRow->pack_id_v1 ?? null),
                'pack_id_v2' => $this->nullableValue($identityRow->pack_id_v2 ?? null),
                'dir_version_v1' => $this->nullableValue($identityRow->dir_version_v1 ?? null),
                'dir_version_v2' => $this->nullableValue($identityRow->dir_version_v2 ?? null),
            ];
        } catch (\Throwable) {
            // During phased rollout or partial migrations, keep resolver non-blocking.
            return null;
        }
    }

    private function nullableValue(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}

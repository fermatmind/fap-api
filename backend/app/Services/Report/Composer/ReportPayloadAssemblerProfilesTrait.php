<?php

namespace App\Services\Report\Composer;

use App\Domain\Score\AxisScore;

trait ReportPayloadAssemblerProfilesTrait
{
    private function buildScoresValueObject(array $scoresPct, array $dims): array
    {
        $out = [];

        foreach ($dims as $dim) {
            $rawPct = (int) ($scoresPct[$dim] ?? 50);

            [$p1, $p2] = match ($dim) {
                'EI' => ['E', 'I'],
                'SN' => ['S', 'N'],
                'TF' => ['T', 'F'],
                'JP' => ['J', 'P'],
                'AT' => ['A', 'T'],
                default => ['', ''],
            };

            $side = $rawPct >= 50 ? $p1 : $p2;
            $displayPct = $rawPct >= 50 ? $rawPct : (100 - $rawPct);

            $axis = AxisScore::fromPctAndSide($displayPct, $side);

            $out[$dim] = [
                'pct' => $axis->pct,
                'state' => $axis->state,
                'side' => $axis->side,
                'delta' => $axis->delta,
            ];
        }

        return $out;
    }

    private function loadTypeProfileFromPackChain(
        array $chain,
        string $typeCode,
        array $ctx,
        string $legacyContentPackageDir
    ): array {
        $doc = $this->loadJsonDocFromPackChain(
            $chain,
            'type_profiles',
            'type_profiles.json',
            $ctx,
            $legacyContentPackageDir
        );

        $items = null;

        if (is_array($doc) && is_array($doc['items'] ?? null)) {
            $items = $doc['items'];
        } elseif (is_array($doc)) {
            $items = $doc;
        }

        $picked = $this->pickItemByTypeCode($items, $typeCode, 'type_code');
        if (is_array($picked)) {
            return $picked;
        }

        if (is_callable($ctx['loadTypeProfile'] ?? null)) {
            $p = ($ctx['loadTypeProfile'])($legacyContentPackageDir, $typeCode);
            if (is_array($p)) {
                return $p;
            }
            if (is_object($p)) {
                return json_decode(json_encode($p, JSON_UNESCAPED_UNICODE), true) ?: [];
            }
        }

        return [];
    }

    private function loadIdentityCardFromPackChain(
        array $chain,
        string $typeCode,
        array $ctx,
        string $legacyContentPackageDir
    ): ?array {
        $doc = $this->loadJsonDocFromPackChain(
            $chain,
            'identity',
            'report_identity_cards.json',
            $ctx,
            $legacyContentPackageDir
        );

        $items = null;

        if (is_array($doc) && is_array($doc['items'] ?? null)) {
            $items = $doc['items'];
        } elseif (is_array($doc)) {
            $items = $doc;
        }

        $picked = $this->pickItemByTypeCode($items, $typeCode, 'type_code');
        if (is_array($picked)) {
            return $picked;
        }

        if (is_callable($ctx['loadReportAssetItems'] ?? null)) {
            $map = ($ctx['loadReportAssetItems'])($legacyContentPackageDir, 'report_identity_cards.json', 'type_code');
            if (is_object($map)) {
                $map = json_decode(json_encode($map, JSON_UNESCAPED_UNICODE), true);
            }
            if (is_array($map) && is_array($map[$typeCode] ?? null)) {
                return $map[$typeCode];
            }
        }

        return null;
    }

    private function pickItemByTypeCode($items, string $typeCode, string $keyField = 'type_code'): ?array
    {
        if (!is_array($items)) {
            return null;
        }

        if (isset($items[$typeCode]) && is_array($items[$typeCode])) {
            return $items[$typeCode];
        }

        $isList = (array_keys($items) === range(0, count($items) - 1));
        if ($isList) {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = (string) ($row[$keyField] ?? '');
                if ($k === $typeCode) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function loadRoleCardFromPackChain(
        array $chain,
        string $typeCode,
        array $ctx,
        string $legacyContentPackageDir
    ): array {
        $role = $this->roleCodeFromType($typeCode);

        $doc = $this->loadJsonDocFromPackChain(
            $chain,
            'identity',
            'report_roles.json',
            $ctx,
            $legacyContentPackageDir
        );

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : (is_array($doc) ? $doc : []);
        $card = (isset($items[$role]) && is_array($items[$role])) ? $items[$role] : [];

        if ($card) {
            $card['code'] = $card['code'] ?? $role;
        }

        if (!$card && is_callable($ctx['buildRoleCard'] ?? null)) {
            $x = ($ctx['buildRoleCard'])($legacyContentPackageDir, $typeCode);
            if (is_array($x)) {
                return $x;
            }
            if (is_object($x)) {
                return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: [];
            }
        }

        return $card ?: [];
    }

    private function loadStrategyCardFromPackChain(
        array $chain,
        string $typeCode,
        array $ctx,
        string $legacyContentPackageDir
    ): array {
        $st = $this->strategyCodeFromType($typeCode);

        $doc = $this->loadJsonDocFromPackChain(
            $chain,
            'identity',
            'report_strategies.json',
            $ctx,
            $legacyContentPackageDir
        );

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : (is_array($doc) ? $doc : []);
        $card = (isset($items[$st]) && is_array($items[$st])) ? $items[$st] : [];

        if ($card) {
            $card['code'] = $card['code'] ?? $st;
        }

        if (!$card && is_callable($ctx['buildStrategyCard'] ?? null)) {
            $x = ($ctx['buildStrategyCard'])($legacyContentPackageDir, $typeCode);
            if (is_array($x)) {
                return $x;
            }
            if (is_object($x)) {
                return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: [];
            }
        }

        return $card ?: [];
    }

    private function roleCodeFromType(string $typeCode): string
    {
        $core = strtoupper(explode('-', $typeCode)[0] ?? '');
        if (strlen($core) < 4) {
            return 'NT';
        }

        $m2 = $core[1];
        $m3 = $core[2];
        $m4 = $core[3];

        if ($m2 === 'N') {
            return ($m3 === 'F') ? 'NF' : 'NT';
        }

        return ($m4 === 'P') ? 'SP' : 'SJ';
    }

    private function strategyCodeFromType(string $typeCode): string
    {
        $core = strtoupper(explode('-', $typeCode)[0] ?? '');
        $suffix = strtoupper(explode('-', $typeCode)[1] ?? 'T');

        $ei = ($core !== '' && ($core[0] === 'I')) ? 'I' : 'E';
        $at = ($suffix === 'A') ? 'A' : 'T';

        return $ei . $at;
    }

    private function loadBorderlineNoteFromPackChain(
        array $chain,
        array $scoresPct,
        array $ctx,
        string $legacyContentPackageDir
    ): array {
        $doc = $this->loadJsonDocFromPackChain(
            $chain,
            'borderline',
            'report_borderline_notes.json',
            $ctx,
            $legacyContentPackageDir
        );

        if (is_array($doc)) {
            if (isset($doc['items']) && is_array($doc['items'])) {
                return $doc;
            }
            if ($this->isAssocArrayLoose($doc)) {
                return ['items' => $doc];
            }
        }

        if (is_callable($ctx['buildBorderlineNote'] ?? null)) {
            $x = ($ctx['buildBorderlineNote'])($scoresPct, $legacyContentPackageDir);
            if (is_array($x)) {
                return $x;
            }
            if (is_object($x)) {
                return json_decode(json_encode($x, JSON_UNESCAPED_UNICODE), true) ?: ['items' => []];
            }
        }

        return ['items' => []];
    }

    private function isAssocArrayLoose(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }
}

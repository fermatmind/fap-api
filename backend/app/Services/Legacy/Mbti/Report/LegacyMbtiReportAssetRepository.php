<?php

declare(strict_types=1);

namespace App\Services\Legacy\Mbti\Report;

use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use Illuminate\Support\Str;

class LegacyMbtiReportAssetRepository
{
    public function __construct(
        private readonly LegacyMbtiPackRepository $packRepo,
    ) {
    }

    public function loadAssetJson(string $contentDir, string $relPath, array $opts = []): ?array
    {
        static $cacheByKey = [];

        $cacheKey = $contentDir . '|' . $relPath . '|' . md5((string) json_encode($opts));
        if (array_key_exists($cacheKey, $cacheByKey)) {
            return $cacheByKey[$cacheKey];
        }

        $json = $this->packRepo->loadJsonFromPack($contentDir, $relPath);
        $cacheByKey[$cacheKey] = is_array($json) ? $json : null;

        return $cacheByKey[$cacheKey];
    }

    public function loadAssetItems(string $contentDir, string $relPath, array $opts = []): array
    {
        $json = $this->loadAssetJson($contentDir, $relPath, $opts);
        if (!is_array($json)) {
            return [];
        }

        $items = $json['items'] ?? $json;
        if (!is_array($items)) {
            return [];
        }

        $primaryIndexKey = is_string($opts['primaryIndexKey'] ?? null)
            ? (string) $opts['primaryIndexKey']
            : 'type_code';

        $keys = array_keys($items);
        $isList = (count($keys) > 0) && ($keys === range(0, count($keys) - 1));

        if ($isList) {
            $indexed = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }

                $k = null;
                if ($primaryIndexKey !== '' && isset($it[$primaryIndexKey])) {
                    $k = $it[$primaryIndexKey];
                } elseif (isset($it['type_code'])) {
                    $k = $it['type_code'];
                } elseif (isset($it['meta']['type_code'])) {
                    $k = $it['meta']['type_code'];
                } elseif (isset($it['id'])) {
                    $k = $it['id'];
                } elseif (isset($it['code'])) {
                    $k = $it['code'];
                }

                if ($k === null || $k === '') {
                    continue;
                }
                $indexed[(string) $k] = $it;
            }
            $items = $indexed;
        }

        return $items;
    }

    public function finalizeHighlightsSchema(array $highlights, string $typeCode): array
    {
        $out = [];

        foreach ($highlights as $h) {
            if (!is_array($h)) {
                continue;
            }

            $kind = is_string($h['kind'] ?? null) ? trim($h['kind']) : '';
            if ($kind === '') {
                $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];
                foreach ($tags as $t) {
                    if (is_string($t) && str_starts_with($t, 'kind:')) {
                        $kind = trim(substr($t, 5));
                        break;
                    }
                }
            }
            if ($kind === '') {
                $id0 = (string) ($h['id'] ?? '');
                if (str_starts_with($id0, 'hl.action')) {
                    $kind = 'action';
                } elseif (str_starts_with($id0, 'hl.blindspot')) {
                    $kind = 'blindspot';
                } else {
                    $kind = 'axis';
                }
            }
            $h['kind'] = $kind;

            $id = is_string($h['id'] ?? null) ? trim($h['id']) : '';
            if ($id === '') {
                $id = 'hl.generated.' . (string) Str::uuid();
            }
            $h['id'] = $id;

            $title = is_string($h['title'] ?? null) ? trim($h['title']) : '';
            if ($title === '') {
                $title = match ($kind) {
                    'blindspot' => '盲点提醒',
                    'action' => '行动建议',
                    'strength' => '你的优势',
                    'risk' => '风险提醒',
                    default => '要点',
                };
            }
            $h['title'] = $title;

            $text = is_string($h['text'] ?? null) ? trim($h['text']) : '';
            if ($text === '') {
                $desc = is_string($h['desc'] ?? null) ? trim($h['desc']) : '';
                $text = $desc !== '' ? $desc : '这一条是系统生成的重点提示，可作为自我观察的参考。';
            }
            $h['text'] = $text;

            $tips = is_array($h['tips'] ?? null) ? $h['tips'] : [];
            $tags = is_array($h['tags'] ?? null) ? $h['tags'] : [];

            $tips = array_values(array_filter($tips, fn ($x) => is_string($x) && trim($x) !== ''));
            $tags = array_values(array_filter($tags, fn ($x) => is_string($x) && trim($x) !== ''));

            if (count($tips) < 1) {
                $tips = match ($kind) {
                    'action' => ['把目标写成 1 句话，再拆成 3 个可交付节点'],
                    'blindspot' => ['重要场景先做一次“反向校验”再决定'],
                    default => ['先做一小步，再迭代优化'],
                };
            }
            if (count($tags) < 1) {
                $tags = ['generated', "kind:{$kind}", "type:{$typeCode}"];
            } else {
                $hasKindTag = false;
                foreach ($tags as $t) {
                    if (str_starts_with($t, 'kind:')) {
                        $hasKindTag = true;
                        break;
                    }
                }
                if (!$hasKindTag) {
                    $tags[] = "kind:{$kind}";
                }
            }

            $h['tips'] = $tips;
            $h['tags'] = $tags;

            $out[] = $h;
        }

        return $out;
    }
}

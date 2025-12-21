<?php

namespace App\Services\Overrides;

class HighlightsOverridesApplier
{
    /**
     * @param string $contentPackageVersion
     * @param string $typeCode
     * @param array  $baseHighlights  生成器产物（list）
     * @return array
     */
    public function apply(string $contentPackageVersion, string $typeCode, array $baseHighlights): array
    {
        $ovr = $this->loadReportAssetJson($contentPackageVersion, 'report_highlights_overrides.json');
        $rules = is_array($ovr['rules'] ?? null) ? $ovr['rules'] : [];

        $overrideMode  = (string)($rules['override_mode'] ?? 'merge'); // 目前只实现 merge
        $replaceFields = is_array($rules['replace_fields'] ?? null) ? $rules['replace_fields'] : ['tags', 'tips'];

        $items = is_array($ovr['items'] ?? null) ? $ovr['items'] : [];
        $perType = is_array($items[$typeCode] ?? null) ? $items[$typeCode] : [];

        if (empty($perType) || empty($baseHighlights)) return $baseHighlights;

        $out = [];

        foreach ($baseHighlights as $h) {
            if (!is_array($h)) continue;

            $id    = (string)($h['id'] ?? '');
            $dim   = (string)($h['dim'] ?? '');
            $side  = (string)($h['side'] ?? '');
            $level = (string)($h['level'] ?? '');

            // 1) pick override
            $override = null;

            // a) by card_id
            if ($id !== '' && isset($perType[$id]) && is_array($perType[$id])) {
                $override = $perType[$id];
            }

            // b) by dim/side/level
            if ($override === null && $dim !== '' && $side !== '' && $level !== '') {
                $o2 = $perType[$dim][$side][$level] ?? null;
                if (is_array($o2)) $override = $o2;
            }

            if (is_array($override)) {
                // 2) apply merge
                if ($overrideMode === 'merge') {
                    $h = array_replace_recursive($h, $override);
                } else {
                    // 未来如果你要支持 replace_all
                    $h = $override + $h;
                }

                // 3) replace fields (tags/tips)
                foreach ($replaceFields as $rf) {
                    if (!is_string($rf) || $rf === '') continue;
                    if (array_key_exists($rf, $override)) {
                        $h[$rf] = is_array($override[$rf] ?? null) ? $override[$rf] : [];
                    }
                }
            }

            // normalize
            if (!is_array($h['tags'] ?? null)) $h['tags'] = [];
            if (!is_array($h['tips'] ?? null)) $h['tips'] = [];

            $out[] = $h;
        }

        return $out;
    }

    // ===== package loaders =====

    private function loadReportAssetJson(string $contentPackageVersion, string $filename): array
    {
        static $cache = [];

        $key = $contentPackageVersion . '|' . $filename . '|RAW';
        if (isset($cache[$key])) return $cache[$key];

        $path = $this->resolvePackageFile($contentPackageVersion, $filename);
        if ($path === null) return $cache[$key] = [];

        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return $cache[$key] = [];

        $json = json_decode($raw, true);
        if (!is_array($json)) return $cache[$key] = [];

        return $cache[$key] = $json;
    }

    private function resolvePackageFile(string $contentPackageVersion, string $filename): ?string
    {
        $pkg = trim($contentPackageVersion, "/\\");

        $envRoot = env('FAP_CONTENT_PACKAGES_DIR');
        $envRoot = is_string($envRoot) && $envRoot !== '' ? rtrim($envRoot, '/') : null;

        $candidates = array_values(array_filter([
            storage_path("app/private/content_packages/{$pkg}/{$filename}"),
            storage_path("app/content_packages/{$pkg}/{$filename}"),
            base_path("content_packages/{$pkg}/{$filename}"),
            $envRoot ? "{$envRoot}/{$pkg}/{$filename}" : null,
        ]));

        foreach ($candidates as $p) {
            if (is_string($p) && $p !== '' && file_exists($p)) return $p;
        }
        return null;
    }
}
<?php

namespace App\Services;

class ContentPackage
{
    public static function mbtiPackageVersion(): string
    {
        // 跟你 share 返回的 content_package_version 保持一致
        return (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');
    }

    /**
     * 统一做“多路径兜底”：
     * - 你的部署结构 base_path() = .../repo/backend
     * - 内容包真实位置在 .../repo/content_packages（即 base_path("../content_packages")）
     */
    private static function resolvePkgFile(string $pkg, string $file): string
    {
        $packsRoot = rtrim((string) config('content_packs.root', base_path('../content_packages')), '/');
        $candidates = [
            "{$packsRoot}/{$pkg}/{$file}",
            // ✅ 你当前真实存在的位置（repo 根目录）
            base_path("../content_packages/{$pkg}/{$file}"),

            // 兼容：如果未来把 content_packages 放进 backend/ 里
            base_path("content_packages/{$pkg}/{$file}"),

            // 兼容：如果内容包被搬到 storage（私有内容包）
            storage_path("app/private/content_packages/{$pkg}/{$file}"),
            storage_path("app/content_packages/{$pkg}/{$file}"),
        ];

        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }

        throw new \RuntimeException("content package file not found: pkg={$pkg}, file={$file}");
    }

    private static function loadJson(string $path): array
    {
        $raw = file_get_contents($path);
        $json = json_decode($raw, true);

        if ($json === null) {
            throw new \RuntimeException("invalid JSON: {$path}");
        }
        if (!is_array($json)) {
            throw new \RuntimeException("JSON must decode to array/object: {$path}");
        }

        return $json;
    }

    public static function loadMbtiQuestionsRaw(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $pkg  = self::mbtiPackageVersion();
        $path = self::resolvePkgFile($pkg, "questions.json");

        $json = self::loadJson($path);

        // 兼容多种格式：
        // A) 直接是数组 []
        // B) { items: [...] }
        // C) { questions: [...] } / { data: [...] }
        $items = $json;
        if (isset($json['items'])) $items = $json['items'];
        if (isset($json['questions'])) $items = $json['questions'];
        if (isset($json['data'])) $items = $json['data'];

        if (!is_array($items)) {
            throw new \RuntimeException("questions.json must be array or {items/questions/data:[]}: {$path}");
        }

        $cache = $items;
        return $items;
    }

    public static function sanitizeQuestionsForApi(array $items): array
    {
        // 不把 key_pole/direction/score/irt 暴露给前端（避免“看答案”）
        return array_map(function ($q) {
            $opts = $q['options'] ?? [];
            $opts = array_map(fn($o) => [
                'code' => $o['code'] ?? null,
                'text' => $o['text'] ?? null,
            ], $opts);

            return [
                'question_id' => $q['question_id'] ?? null,
                'order'       => $q['order'] ?? null,
                'dimension'   => $q['dimension'] ?? null,
                'text'        => $q['text'] ?? null,
                'options'     => $opts,
            ];
        }, $items);
    }
}

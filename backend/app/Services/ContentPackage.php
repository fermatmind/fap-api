<?php

namespace App\Services;

class ContentPackage
{
    public static function mbtiPackageVersion(): string
    {
        // 跟你 share 返回的 content_package_version 保持一致
        return env('MBTI_CONTENT_PACKAGE', 'MBTI-CN-v0.2.1-TEST');
    }

    public static function loadMbtiQuestionsRaw(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $pkg = self::mbtiPackageVersion();
        $path = base_path("content_packages/{$pkg}/questions.json");

        if (!file_exists($path)) {
            throw new \RuntimeException("questions.json not found: {$path}");
        }

        $json = json_decode(file_get_contents($path), true);
        if ($json === null) {
            throw new \RuntimeException("questions.json invalid JSON: {$path}");
        }

        // 兼容两种格式：
        // A) 直接是 144 个对象的数组（你截图这种 v2025）
        // B) { items: [...] } 包装格式
        $items = isset($json['items']) ? $json['items'] : $json;

        if (!is_array($items)) {
            throw new \RuntimeException("questions.json must be array or {items:[]}");
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
                'code' => $o['code'],
                'text' => $o['text'],
            ], $opts);

            return [
                'question_id' => $q['question_id'],
                'order'       => $q['order'],
                'dimension'   => $q['dimension'],
                'text'        => $q['text'],
                'options'     => $opts,
            ];
        }, $items);
    }
}
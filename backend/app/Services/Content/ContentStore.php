<?php

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

class ContentStore
{
    /**
     * 在 pack chain 中按顺序寻找第一个存在的相对路径文件，返回 decoded array|null
     */
    public function getJsonFirst(array $packChain, string $relativePath): ?array
    {
        foreach ($packChain as $pack) {
            $full = rtrim($pack->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            if (File::exists($full) && File::isFile($full)) {
                $data = json_decode(File::get($full), true);
                return is_array($data) ? $data : null;
            }
        }
        return null;
    }

    /**
     * 按 assets 列表（相对路径数组）加载多份 JSON，并做浅合并（后读的覆盖前读）
     * 用法：loadAssetGroup($chain, $pack->assets()['cards'] ?? [])
     */
    public function loadAssetGroup(array $packChain, array $relativePaths): array
    {
        $out = [];
        foreach ($relativePaths as $rel) {
            $json = $this->getJsonFirst($packChain, $rel);
            if (is_array($json)) {
                // 简单策略：数组结构按 key 覆盖
                $out = array_replace_recursive($out, $json);
            }
        }
        return $out;
    }
}
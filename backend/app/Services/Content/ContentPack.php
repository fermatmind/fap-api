<?php

namespace App\Services\Content;

class ContentPack
{
    public function __construct(
        protected string $packId,
        protected string $scaleCode,
        protected string $region,
        protected string $locale,
        protected string $version,
        protected string $basePath,
        protected array $manifest,
    ) {}

    // ======== meta getters ========
    public function packId(): string { return $this->packId; }
    public function scaleCode(): string { return $this->scaleCode; }
    public function region(): string { return $this->region; }
    public function locale(): string { return $this->locale; }
    public function version(): string { return $this->version; }
    public function basePath(): string { return $this->basePath; }
    public function manifest(): array { return $this->manifest; }

    // ======== core contract ========

    /**
     * manifest.assets 原样对外暴露（resolver 已经做过结构校验的话，这里就直接返回）
     */
    public function assets(): array
    {
        $assets = $this->manifest['assets'] ?? [];
        return is_array($assets) ? $assets : [];
    }

    /**
     * 可选：manifest.schemas（如果你 manifest 有 schemas 字段）
     */
    public function schemas(): array
    {
        $schemas = $this->manifest['schemas'] ?? [];
        return is_array($schemas) ? $schemas : [];
    }

    /**
     * 可选：manifest.capabilities（如果你 manifest 有 capabilities 字段）
     */
    public function capabilities(): array
    {
        $caps = $this->manifest['capabilities'] ?? [];
        return is_array($caps) ? $caps : [];
    }

    /**
     * fallback：manifest.fallback（你 Phase1 自检里定义的是 list[string]）
     */
    public function fallback(): array
    {
        $fb = $this->manifest['fallback'] ?? [];
        if (!is_array($fb)) return [];

        // 只保留非空字符串
        $out = [];
        foreach ($fb as $x) {
            if (is_string($x) && trim($x) !== '') $out[] = trim($x);
        }
        return $out;
    }

    /**
     * 兼容你当前已有的调用方式：fallbackPackIds()
     */
    public function fallbackPackIds(): array
    {
        return $this->fallback();
    }
}
# Content Packs Index API

## 背景
前端热更新/灰度需要可查询的内容包列表、版本、默认版本与回退规则（region/locale），以便在运行时做包选择与切换。

## GET /api/v0.3/content-packs
说明：返回内容包索引（支持 refresh=1 强制重建缓存）。

Query:
- refresh=1：强制刷新索引缓存

响应结构（示例字段）：
```json
{
  "ok": true,
  "driver": "local",
  "packs_root": "/abs/path/to/content_packages",
  "defaults": {
    "default_pack_id": "MBTI.cn-mainland.zh-CN.v0.3",
    "default_dir_version": "MBTI-CN-v0.3",
    "default_region": "CN_MAINLAND",
    "default_locale": "zh-CN",
    "region_fallbacks": {"*": ["GLOBAL"]},
    "locale_fallback": true
  },
  "items": [
    {
      "pack_id": "MBTI.cn-mainland.zh-CN.v0.3",
      "dir_version": "MBTI-CN-v0.3",
      "content_package_version": "v0.3",
      "scale_code": "MBTI",
      "region": "CN_MAINLAND",
      "locale": "zh-CN",
      "pack_path": "default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3",
      "manifest_path": "/abs/path/to/manifest.json",
      "questions_path": "/abs/path/to/questions.json",
      "updated_at": 1700000000
    }
  ],
  "by_pack_id": {
    "MBTI.cn-mainland.zh-CN.v0.3": {
      "default_dir_version": "MBTI-CN-v0.3",
      "versions": ["MBTI-CN-v0.3"]
    }
  }
}
```

## GET /api/v0.3/content-packs/{pack_id}/{dir_version}/manifest
说明：读取指定内容包的 manifest.json。

示例：
```bash
curl -sS http://127.0.0.1:8000/api/v0.3/content-packs/MBTI.cn-mainland.zh-CN.v0.3/MBTI-CN-v0.3/manifest
```

响应：
```json
{
  "ok": true,
  "pack_id": "MBTI.cn-mainland.zh-CN.v0.3",
  "dir_version": "MBTI-CN-v0.3",
  "manifest": {"schema_version": "pack-manifest@v1"}
}
```

## GET /api/v0.3/content-packs/{pack_id}/{dir_version}/questions
说明：读取指定内容包的 questions.json。

示例：
```bash
curl -sS http://127.0.0.1:8000/api/v0.3/content-packs/MBTI.cn-mainland.zh-CN.v0.3/MBTI-CN-v0.3/questions
```

响应：
```json
{
  "ok": true,
  "pack_id": "MBTI.cn-mainland.zh-CN.v0.3",
  "dir_version": "MBTI-CN-v0.3",
  "questions": [{"id": 1}]
}
```

## 缓存
- 索引缓存优先走 Redis，TTL 30 秒。
- refresh=1 可强制刷新索引。

## S3 模式
- driver=s3 时，packs_root 指向 content_packs.cache_dir。
- PackCache 负责将 s3 内容拉齐到 cache_dir，再由索引扫描本地缓存。

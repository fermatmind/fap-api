# 内容包本机缓存（Pack Cache）

## 背景
当 `content_packs.driver=s3` 时，对象存储只是内容“源”，线上读取必须落到本机 `cache_dir`。PackCache 会在本机拉齐内容包并维护元数据，确保解析器只读本地目录。

## 目录位置
默认缓存目录：
`backend/storage/app/private/content_packs_cache/<pack>/...`

其中 `<pack>` 形如：`default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST`。

## 元数据
每个 pack 目录包含 `.pack_cache_meta.json`，字段含义：
- `pack`：pack 相对路径
- `fetched_at`：拉取时间（Unix 秒）
- `manifest_etag`：manifest.json 的 etag/sha1（可能为 null）
- `driver`：local 或 s3
- `source`：local 为 `{root: ...}`，s3 为 `{disk: ..., prefix: ...}`

`cache_ttl_seconds` 控制缓存有效期，到期触发一次 etag 校验与刷新。

## 刷新规则
满足任意条件即刷新：
- 目录不存在
- meta 缺失或解析失败
- `now - fetched_at >= cache_ttl_seconds`
- `manifest_etag` 变化

## 运维排障
- `cache_dir` 权限：确保运行用户可读写。
- 锁文件目录：`cache_dir/.locks`（确保可创建/写入）。
- 手动清缓存：删除单个 pack 目录，`ensureCached` 会重建。

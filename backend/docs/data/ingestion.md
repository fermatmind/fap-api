# Ingestion 数据流说明（Migration Truth v0.4）

Status: Active
Last Updated: 2026-02-18
Mirror Policy: This file must be updated in the same PR with `docs/data/ingestion.md`.

---

## 1. 范围与真理源

本文件仅描述当前已落地的数据摄入链路，真理源为：
- `backend/database/migrations/*.php`
- `backend/app/Services/Ingestion/*`
- `backend/app/Services/Content/Publisher/ContentPackPublisher.php`
- `backend/routes/api.php`

本文件不定义未来接口；未落地能力必须标注为 Draft。

---

## 2. 管线 A：Integrations 数据摄入链

## 2.1 入口与认证

### 2.1.1 入口路由
- `POST /api/v0.2/integrations/{provider}/ingest`
- `POST /api/v0.2/integrations/{provider}/replay/{batch_id}`
- `POST /api/v0.2/integrations/{provider}/revoke`
- `GET /api/v0.2/integrations/{provider}/oauth/start`
- `GET /api/v0.2/integrations/{provider}/oauth/callback`

### 2.1.2 认证上下文
- `integrations` 表记录 provider 授权状态与 `ingest_key_hash`
- `auth_mode` 审计写入 `ingest_batches`（schema 约束见下）
- `integration_user_bindings` 维护 `(provider, external_user_id) -> user_id`

## 2.2 数据流步骤
1. OAuth callback 建立/更新 `integrations`，写入 `external_user_id` 与 `ingest_key_hash`。
2. ingest 请求进入 `IngestionService::ingestSamples`。
3. 先写 `ingest_batches`（状态 `received`）。
4. 对每条 sample 计算幂等键，写入 `idempotency_keys`（`provider + external_id + recorded_at + hash`）。
5. 按 domain 落库：
   - `sleep` -> `sleep_samples`
   - `screen_time` -> `screen_time_samples`
   - 其他 -> `health_samples`
6. 批次状态更新为 `processed`。

## 2.3 核心表映射

### 2.3.1 `integrations`
关键字段：
- `user_id`, `provider`, `external_user_id`
- `status`（`pending/connected/revoked`）
- `ingest_key_hash`
- `scopes_json`
- `consent_version`
- `connected_at`, `revoked_at`
- `webhook_last_event_id`, `webhook_last_timestamp`, `webhook_last_received_at`

关键约束：
- `integrations_user_provider_unique`
- `integrations_ingest_key_hash_unique`

### 2.3.2 `integration_user_bindings`
关键字段：
- `provider`
- `external_user_id`
- `user_id`

关键约束：
- `integration_user_bindings_provider_external_unique`

### 2.3.3 `ingest_batches`
关键字段：
- `id`, `provider`, `user_id`
- `range_start`, `range_end`
- `raw_payload_hash`
- `status`（`received/processed/replayed`）
- `actor_user_id`, `auth_mode`, `signature_ok`, `source_ip`

关键索引：
- `ingest_batches_provider_user_idx`

说明：
- `ingest_batches` 不保存 sample JSON；sample JSON 在样本表 `value_json` 字段。

### 2.3.4 样本表
- `sleep_samples.value_json`
- `screen_time_samples.value_json`
- `health_samples.value_json`

共同字段特征：
- `user_id`
- `recorded_at`
- `value_json`
- `confidence`
- `raw_payload_hash`
- `ingest_batch_id`

### 2.3.5 幂等表 `idempotency_keys`
作用：
- 避免重复 ingest/replay 落库。

关键字段：
- `provider`
- `external_id`
- `recorded_at`
- `hash`
- `run_id`
- `ingest_batch_id`

---

## 3. 管线 B：Content Pack 入库/发布链

## 3.1 纠偏前提（必须明确）
- `content_packs` 不是数据库物理表。
- Content Pack 的数据库真相是：
  - `content_pack_versions`（版本元数据）
  - `content_pack_releases`（发布流水）

## 3.2 入库（ingest）
入口：`ContentReleaseController@upload` -> `ContentPackPublisher::ingest`

写入表：`content_pack_versions`
关键字段：
- `region`, `locale`
- `pack_id`
- `content_package_version`
- `dir_version_alias`
- `source_type`（upload/s3）
- `source_ref`
- `sha256`
- `manifest_json`
- `extracted_rel_path`

## 3.3 发布/回滚（publish/rollback）
入口：`ContentReleaseController@publish|rollback` -> `ContentPackPublisher`

写入表：`content_pack_releases`
关键字段：
- `action`（publish/rollback）
- `from_version_id`, `to_version_id`
- `from_pack_id`, `to_pack_id`
- `status`
- `message`
- `probe_ok`, `probe_json`, `probe_run_at`

## 3.4 文件系统与对象存储关系
实体文件不在 DB，位于：
- local：`content_packages/*`
- s3/cos：`FAP_PACKS_DRIVER=s3` + `FAP_S3_PREFIX`

运行时加载路径：
- 读取后落盘到 `FAP_PACKS_CACHE_DIR`（cache_dir）
- `content_pack_versions.extracted_rel_path/source_ref` 只记录路径与来源，不承载文件实体

---

## 4. 旧认知 vs 新真相（差异纠偏）

| 项 | 旧认知 | 新真相 |
|---|---|---|
| 内容包表 | 存在 `content_packs` 表 | 不存在该表 |
| DB 角色 | DB 持有完整内容包 | DB 仅持有元数据与发布流水 |
| 文件存储 | 不明确 | 明确在 `content_packages/*` 或对象存储，运行时经 `cache_dir` |
| 版本管理 | 模糊 | `content_pack_versions` 负责版本元数据，`content_pack_releases` 负责发布流水 |

---

## 5. 审计与回放注意点
- `ingest_batches.status` 必须反映批次生命周期（received -> processed -> replayed）。
- replay 必须依赖 `idempotency_keys`，避免重复落库。
- Content Pack 发布必须记录 release 流水，probe 结果落盘到 `probe_*` 字段。
- 文档/实现中禁止再出现“`content_packs` 表”描述。

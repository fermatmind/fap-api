# Norms Versioning & Frozen Snapshot

## 1. norm_version 规则

- **语义版本**：`MAJOR.MINOR.PATCH`。
- **默认选择**：
  - 优先使用 `scale_norms_versions` 中最新版本（按 version + created_at 排序）。
  - 若 DB 无记录，回退到 content_packages/norms.json 的 `meta.version`。
- **回滚**：
  - 将旧版本插入 `scale_norms_versions`（或提升其排序）即可回滚为默认。

## 2. 冻结快照（不漂移）

attempt 提交时写入并锁定：

- `attempts.pack_id`
- `attempts.dir_version`
- `attempts.scoring_spec_version`
- `attempts.norm_version`
- `attempts.calculation_snapshot_json`

后续读取 `stats/quality/report?include=psychometrics` 时，**一律读取 snapshot**，不再基于最新常模重算。

## 3. 复算策略

- 本 PR 仅冻结 `attempts.calculation_snapshot_json`。
- 复算需要明确产生 **新 attempt** 或引入 `attempt_stats` 表（未来 PR）。
- 复算必须记录：复算触发人、来源版本、复算时间、变更说明。

## 4. 运维规范

### 4.1 新增常模版本

1. 准备 norms.json（更新 meta.version + checksum）。
2. 写入 `scale_norms_versions`：
   - `scale_code`, `norm_id`, `region`, `locale`, `version`, `checksum`, `meta_json`.
3. 运行 `pr11_verify_psychometrics.sh`，确保新 attempt 使用新版本。

### 4.2 checksum 验证

- `content_packages/**/version.json` 中 `checksum` 为 norms/spec 文件组合的 sha256。
- 版本发布前必须对比 checksum 一致性。

### 4.3 变更审计

- 任何常模更新必须记录：
  - 更新原因
  - 样本来源
  - 统计口径
  - 回滚方案


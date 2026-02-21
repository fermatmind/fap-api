# Ingestion Runbook (PR13)

## 监控指标
- 延迟：`v_ingestion_latency.sql`
- 缺失率：`v_ingestion_missing_rate.sql`
- 量级：`v_ingestion_volume_by_source.sql`

## 常见问题排查
1) 延迟飙升
   - 检查 provider webhook 推送是否延迟。
   - 查看 ingest_batches 是否 stuck 在 received。
2) 缺失率升高
   - sleep_samples 是否断流；核对 provider/source。
3) 重复写入
   - 检查 idempotency_keys 是否异常增长。
   - 确认 Replay 调用后是否重复插入。

## 回放流程（Replay）
1) 确认 batch_id：来自 ingest 返回或 webhook 回执。
2) 调用：
   - `POST /api/v0.3/integrations/{provider}/replay/{batch_id}`
3) 预期：
   - inserted=0 且 skipped>0（幂等生效）。

## 紧急止血
- 暂停某 provider：
  - 标记 integrations.status=revoked
  - 停止 webhook 回调或 drop 请求

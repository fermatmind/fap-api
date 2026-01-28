# PR14 勘察结论（agent + memory + vectorstore）

## 相关入口文件
- backend/routes/api.php
  - 已有 v0.2 路由入口（/insights, /integrations, /admin 等），需在同一文件新增 /memory 与 /me/agent 路由。
- backend/app/Http/Middleware/FmTokenAuth.php
  - 负责解析 fm_token 并注入 fm_user_id / anon_id，/me/* 与 /memory/* 需沿用该注入逻辑。
- backend/app/Http/Middleware/CheckAiBudget.php
  - PR12 预算熔断入口，可复用到 embeddings/agent 预算。
- backend/app/Services/AI/BudgetLedger.php
  - 预算记账与超限异常（BudgetLedgerException）。
- backend/app/Services/AI/EvidenceBuilder.php
  - PR12 证据组装能力，可复用 evidence_json 结构。
- backend/app/Services/Audit/AuditLogger.php
  - audit_logs 写入入口，可用于 admin 禁用 trigger / replay 审计。
- backend/app/Http/Controllers/API/V0_2/InsightsController.php
  - 现有 AI insights API 与反馈，结构可复用到 agent 消息反馈。

## 相关 DB 表/迁移
- 已存在关键表/迁移：
  - audit_logs / ai_insights / ai_insight_feedback / events / fm_tokens / attempts / results
  - integrations（含 consent_version）/ ingest_batches / sleep_samples / health_samples / screen_time_samples / idempotency_keys
- 当前不存在（需新增）：
  - user_agent_settings / memories / embeddings_index / embeddings
  - agent_triggers / agent_decisions / agent_messages / agent_feedback

## 相关路由
- 已存在：
  - /api/v0.2/insights/*（generate/show/feedback）
  - /api/v0.2/integrations/*（ingest/replay/revoke/oauth）
  - /api/v0.2/admin/*（audit-logs/events/healthz 等）
- 当前不存在：
  - /api/v0.2/memory/*
  - /api/v0.2/me/agent/*
  - /api/v0.2/admin/agent/*

## 可复用组件
- BudgetLedger + CheckAiBudget：预算熔断/降级主逻辑。
- FmTokenAuth：fm_token -> fm_user_id / anon_id 注入。
- EvidenceBuilder：evidence_json 结构与字段口径。
- AuditLogger：审计日志写入入口。
- Events 表与 PR9 事件字段：可复用 pack/version/channel/request_id 规范。

## 需要新增/修改点
- 新增 config：agent / memory / vectorstore 开关与默认值。
  - 新增 migrations：memory / embeddings / agent 相关表（幂等）。
  - 新增 Services：EmbeddingClient / VectorStore / Memory / Agent Orchestrator + Policies/Triggers。
  - 新增 Controllers：MemoryController / AgentController + admin endpoints。
  - 新增 verify 脚本与 Metabase 视图。

## 开关与默认值（新增）
- config/agent.php
  - enabled=false
  - quiet_hours=22:00-07:00 UTC
  - max_messages_per_day=2
  - cooldown_minutes=240
  - fail_open=false, suppress_on_budget_exceeded=true
- config/memory.php
  - enabled=true
  - max_confirmed_per_user=200
  - max_proposed_per_user=500
- config/vectorstore.php
  - enabled=true
  - driver=mysql_fallback
  - fail_open=true

## 潜在风险与规避
- 迁移幂等：所有表/列/索引创建需 hasTable/hasColumn/hasIndex 判定，避免线上结构冲突。
- 预算熔断一致性：Embeddings 与 Agent 需接入 BudgetLedger，fail_open/close 行为统一。
- 权限边界：/me/* 与 /memory/* 必须走 FmTokenAuth；admin/agent 需审计记录。
- 去重与幂等：agent_triggers/decisions/messages 需 idempotency_key 或 content_hash。
- 高风险内容：RiskPolicy 高风险走固定文案并触发 agent_safety_escalated 事件。

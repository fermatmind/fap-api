> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Acceptance v0.3-B
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/stage2-v0.3-b-overview.md
> - docs/04-stage2/mbti-report-engine-v1.2.md
> - docs/04-stage2/analytics-stage2-funnel-spec.md
> - docs/04-stage2/compliance-stage2-user-rights.md

cat > docs/acceptance-playbook.md <<'EOF'
# Acceptance Playbook（发布验收剧本）— v0.3

目标：每次发布前 15 分钟固定跑完，保证“主流程可用 + 数据一致 + 埋点口径稳定 + 周报可见变化”。

---

## 1) API 手工流程（必跑）

> 建议先设 BASE_URL（本地/线上任选其一）
- 本地示例：`http://localhost:8000`
- 线上示例：`https://fermatmind.com`

### 1.1 拉题（Scale/Questions）
- 访问：`GET /api/v0.3/scales/mbti`
- 验收点：
  - HTTP 200
  - `ok=true`
  - 返回包含 `scale_code=MBTI`、`scale_version`、题目数组（数量符合预期）

### 1.2 提交作答（Attempts Submit）
- 访问：`POST /api/v0.3/attempts`
- 验收点：
  - HTTP 200
  - `ok=true`
  - 返回 `attempt_id`
  - 后端写入 attempts + results（见 DB 检查）

### 1.3 查结果（Result Read）
- 访问：`GET /api/v0.3/attempts/{attempt_id}/result`
- 验收点：
  - HTTP 200
  - `ok=true`
  - 返回 `type_code`
  - 返回 `scores_pct` / `axis_states`（若 v0.3 已接入则必须存在）

### 1.4 查分享（Share Payload）
- 访问：`GET /api/v0.3/attempts/{attempt_id}/share`
- 验收点：
  - HTTP 200
  - `ok=true`
  - 返回包含 `share_id`、`content_package_version`
  - 返回字段能用于前端生成分享卡（type_code/tagline/keywords 等最小字段可用）

### 1.5 查权益说明（User Rights）
- 访问：`GET /api/v0.3/user-rights`（或你实际实现的隐私/权益 summary 端点）
- 验收点：
  - HTTP 200
  - 文案包含：我们记录什么/用途/如何删除/如何导出/联系渠道

---

## 2) DB 检查（attempts / results / events）

> 目标：一次完整流程至少写入 attempts 1 行、results 1 行、events 若干行（取决于你是否已接入埋点上报）。

### 2.1 行数检查
- attempts：新增 1 行（最近 10 分钟）
- results：新增 1 行（与该 attempt_id 关联）
- events：至少包含 `test_submit`（后端强一致事件），其余事件取决于前端是否已接入上报

### 2.2 外键/关联检查
- `results.attempt_id` 必须存在且能 JOIN 到 attempts
- 同一个 attempt_id 不应生成多条 results（除非你明确允许“多版本重算”，v0.3 默认不允许）

---

## 3) 事件检查（口径关键）

### 3.1 强一致规则
- `test_submit`：只能后端触发；必须在 attempts+results 事务成功后才写入 events
- `delete_request_submit / export_request_submit`：只能后端触发；创建成功才写入

### 3.2 关键边界：result_view
- `result_view` 只应新增 **events** 记录
- `GET result` 或前端打开结果页不应导致 **results 表新增/重复写入**

---

## 4) 周报检查（weekly-report 能看到变化）

### 4.1 生成/查看周报
- 运行你项目里约定的 weekly-report（命令或脚本）
- 验收点（至少满足其一）：
  - 事件漏斗 5 个核心事件数有变化
  - `test_submit`、`result_view`、`share_generate` 数能对上你刚才的手工流程
  - `type_code` 分布里出现你刚生成的类型（若周报统计包含该维度）

---

## 附：失败处理（快速回滚判断）
- 如果 API 流程失败：先停止发布，回滚代码或配置
- 如果数据一致性失败（attempts/results 对不上）：立即停止发布，优先修事务与写入逻辑
- 如果事件口径失败（重复/乱触发）：先按事件责任矩阵修 trigger_side/trigger_time/dedup_key，再补发版
EOF
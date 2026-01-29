# PR18 Recon (B2B Org Isolation v0.3)

## 相关入口文件（控制器/服务/中间件/模型）
- v0.3 Attempts: `backend/app/Http/Controllers/API/V0_3/AttemptsController.php`
  - start/submit/result/report 已存在；当前 org_id 固定为 0 或从 attempt->org_id 读取
- v0.3 Scales: `backend/app/Http/Controllers/API/V0_3/ScalesController.php`
- v0.3 Scales lookup: `backend/app/Http/Controllers/API/V0_3/ScalesLookupController.php`
- Scale registry 服务：`backend/app/Services/Scale/ScaleRegistry.php`
- Event 写入：`backend/app/Services/Analytics/EventRecorder.php`
- Report jobs：`backend/app/Models/ReportJob.php`, `backend/app/Jobs/GenerateReportJob.php`, `backend/app/Http/Controllers/MbtiController.php`
- 鉴权中间件：`backend/app/Http/Middleware/FmTokenAuth.php`
  - 从 `fm_tokens` DB 解析 `user_id` 并注入 request attributes `fm_user_id/user_id`
- Token 签发：`backend/app/Services/Auth/FmTokenService.php`

## 相关 DB 表/迁移（attempts/results/events/report_jobs）
- attempts/results 已有 `org_id`（默认 0）：
  - `backend/database/migrations/2026_01_29_120000_v03_attempts_results_fields.php`
- events 无 org_id（create: `2025_12_17_165938_create_events_table.php`，扩展: `2026_01_27_210000_pr9_add_observability_columns_to_events.php`）
- report_jobs 无 org_id（`2026_01_26_090000_create_report_jobs_table.php`）

## 相关路由（v0.3）
- `backend/routes/api.php`
  - `/api/v0.3/attempts/start|submit|{id}/result|{id}/report`
  - `/api/v0.3/scales` `/api/v0.3/scales/lookup` `/api/v0.3/scales/{scale_code}` `/api/v0.3/scales/{scale_code}/questions`

## 必须新增/修改点
- 新增 org tables（organizations / organization_members / organization_invites）
- 新增 ResolveOrgContext + OrgContext（解析 X-Org-Id / token claim / fallback 0）
- v0.3 路由挂载 ResolveOrgContext，并保留 fm_token auth
- attempts/results/events/report_jobs 全链路写入/查询按 org_id 收口
- scales list/show/lookup/questions 按 org_id + is_public 规则隔离
- 新增 v0.3 orgs/invites 控制器 + services + tests + verify script

## 风险与规避
- public org_id=0：强制 is_public=1 访问 scales
- 跨 org 访问统一 404（ORG_NOT_FOUND）
- RBAC 仅 owner/admin/member（最小权限矩阵）

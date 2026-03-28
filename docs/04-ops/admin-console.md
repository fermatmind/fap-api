# Admin Console (Filament)

## 现状
- 后端为 Laravel API；PR9 已落地 `ops_deploy_events` / `ops_healthz_snapshots` / `v_*` 观测视图与 events 增强。
- PR7 已有 `content-releases` 管理 API 与 `FAP_ADMIN_TOKEN` 语义。
- 本 PR 补齐：Filament Admin UI + RBAC + audit_logs + admin 工具 API。

## 功能清单
- Users：Admin 用户管理（创建/禁用/启用）
- Roles：角色管理 + 权限勾选
- Permissions：权限维护（Owner 专属）
- Audit Logs：审计查询/过滤/导出
- Content Releases：发布记录浏览/筛选/Probe
- Deploy Events：发布流水浏览
- Widgets：Healthz 红绿灯、Funnel 关键转化

## 权限矩阵（默认）
| 角色 | 权限 |
| --- | --- |
| Owner | admin.owner（全权限） |
| Ops | admin.ops.read, admin.ops.write, admin.cache.invalidate, admin.audit.read, admin.events.read |
| Content | admin.content.read, admin.content.publish, admin.content.probe, admin.audit.read |
| Analyst | admin.events.read, admin.audit.read, admin.funnel.read |

## 登录方式
- 首次 bootstrap：优先参考 `docs/04-ops/ops-bootstrap.md`
- 官方首个管理员命令：`php artisan admin:bootstrap-owner --email=owner@example.com --password='ChangeMe123!' --name='Owner'`
- 正确登录模型：`App\Models\AdminUser`，不要用 `App\Models\User`
- 打开：`/ops/login`（默认 `http://127.0.0.1:18010/ops/login`）
- 备用：API Token 访问 `/api/v0.3/admin/*`（Header：`X-FAP-Admin-Token`）
- 开关：`FAP_ADMIN_PANEL_ENABLED=true|false`

## 常用路径
- UI：`/ops`
- Login：`/ops/login`
- Select Org：`/ops/select-org`
- API：`/api/v0.3/admin/*`

## 截图位点（待补）
- Admin 登录页（/ops/login）
- Admin Users 列表 + 禁用弹窗
- Roles 权限勾选页
- Audit Logs 过滤 + 导出按钮
- Content Releases 列表 + Probe 动作
- Deploy Events 列表
- Widgets：HealthzStatusWidget、FunnelWidget

## 本机手动验收要点
- 浏览器打开 `http://127.0.0.1:18010/ops/login` 登录 Owner
- 首次登录/无 org 状态下应进入 `Select Org`
- HealthzStatusWidget 与 FunnelWidget 正常显示；无数据时显示 “no data”
- ContentReleaseResource 可看到发布记录，Probe 可执行并写入 audit_logs

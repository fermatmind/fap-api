# 动态常模 v1：更新频率与降级/回滚策略

## 默认状态（未正式上线提醒）
- 默认 **不启用**：`NORMS_ENABLED=0`
- 未满足上线 checklist 前禁止开启（见文末）

## 更新频率（v1）
- 建议：每周一 03:00（本地/线上 cron）
- v1 采用 **全量窗口重算**（简单可靠，可回滚）

## 窗口策略（写死）
- 默认窗口：最近 365 天
- 样本阈值（写死）：`N < 200` → 不产生新版本，保留现有 `active`

## 更新命令（v1）
- `php artisan norms:update --pack_id="<pack_id>"`
- 产物：
  - 新增一条 `norms_versions(status=active)`
  - 写入 `norms_table`
  - 将旧 active（同 pack_id）置为 `archived`

## 失败降级策略（写死）
任一条件不满足时：
- 命令端：
  - 无可用分数源列 → 直接退出（不改动现有 active）
  - `N < 200` → 直接退出（不改动现有 active）
  - DB 事务失败 → 不改动现有 active
- 服务端（report 注入 / percentile API）：
  - 缺表/缺版本/缺行 → 返回 NOT_FOUND 或不注入 norms
  - 不影响 `/attempts/:id/report` 主体结构与 CI 主链路

## 回滚步骤（两种）
### 方案 A：Pin（推荐线上应急）
1. 查出历史版本 `version_id`
2. 设置环境变量：
   - `NORMS_VERSION_PIN=<version_id>`
3. 重启服务

### 方案 B：切换 active（数据层回滚）
1. 将目标历史版本置为 active：
   - `UPDATE norms_versions SET status='active' WHERE id='<version_id>';`
2. 将其他同 pack_id 的 active 置为 archived：
   - `UPDATE norms_versions SET status='archived' WHERE pack_id='<pack_id>' AND id!='<version_id>' AND status='active';`

## 审计记录（v1 最小）
- `norms_versions` 本身即为审计：computed_at/window/sample_n/status
- 如需更强审计（可选）：增加 norms_runs 表记录每次执行与错误栈（v1 可后置）

## 上线 checklist（开启 NORMS_ENABLED 前必须满足）
- [ ] `norms:update` 可稳定产出 `active` 版本（N>=200）
- [ ] percentile API 在 `NORMS_ENABLED=1` 时可命中（至少 1 个 pack）
- [ ] 回滚演练通过（Pin/切 active 任一方式）
- [ ] 监控项（可选）：
  - active 版本 computed_at 距今 > 14 天告警
  - 版本 sample_n 异常波动告警
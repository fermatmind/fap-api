# Queue Runbook（异步任务运维版）

Status: Active  
Last Updated: 2026-02-18  
Truth Source: `config/queue.php`, `app/Jobs/*`, `Phase C snapshot chain`

## 1. 队列架构真值
- 当前无 Horizon（`config/horizon.php` 不存在）。
- 队列由 `config/queue.php` 驱动。
- 生产默认 `QUEUE_CONNECTION=redis`（未显式覆盖时）。

## 1.1 连接/队列映射
| 任务域 | 连接 | 队列 | 代码锚点 |
|---|---|---|---|
| 报告快照生成 | database | reports | `GenerateReportSnapshotJob` |
| Legacy 报告生成 | runtime dispatch | reports | `GenerateReportJob` |
| 审批执行 | database | ops | `ExecuteApprovalJob` |
| 内容探针 | database | content | `RunContentProbeJob` |
| 商业退款 | database | commerce | `RefundOrderJob` |
| 支付事件重放 | database | commerce | `ReprocessPaymentEventJob` |
| AI 洞察 | default + config | insights | `GenerateInsightJob` |
| 通用任务 | default connection | high/default | 平台保底 worker |

## 2. Worker 驻留规范
生产建议使用 Supervisor 分组驻留，不使用 horizon。

### 2.1 推荐 Supervisor 样例
```ini
[program:fap-queue-default-high]
directory=/opt/fap-api/backend
command=/usr/bin/php artisan queue:work redis --queue=high,default --sleep=1 --tries=3 --timeout=120 --max-time=3600
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/log/supervisor/fap-queue-default-high.log
stopwaitsecs=360

[program:fap-queue-reports]
directory=/opt/fap-api/backend
command=/usr/bin/php artisan queue:work database --queue=reports --sleep=1 --tries=3 --timeout=180 --max-time=3600
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/var/log/supervisor/fap-queue-reports.log
stopwaitsecs=360

[program:fap-queue-ops]
command=/usr/bin/php artisan queue:work database --queue=ops --sleep=1 --tries=3 --timeout=120 --max-time=3600

[program:fap-queue-commerce]
command=/usr/bin/php artisan queue:work database --queue=commerce --sleep=1 --tries=3 --timeout=180 --max-time=3600

[program:fap-queue-content]
command=/usr/bin/php artisan queue:work database --queue=content --sleep=1 --tries=2 --timeout=120 --max-time=3600

[program:fap-queue-insights]
command=/usr/bin/php artisan queue:work redis --queue=insights --sleep=1 --tries=3 --timeout=180 --max-time=3600
```

### 2.2 发布后生效
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart fap-queue-default-high:*
sudo supervisorctl restart fap-queue-reports:*
sudo supervisorctl restart fap-queue-ops:*
sudo supervisorctl restart fap-queue-commerce:*
sudo supervisorctl restart fap-queue-content:*
sudo supervisorctl restart fap-queue-insights:*
```

## 3. 报告生成卡住（重点）
现象：用户提交后报告长期 loading，或 `report_snapshots.status` 长时间 `pending`。

排查顺序：
1. 检查 `reports` worker 是否存活。  
2. 检查 `jobs` 表中 `queue=reports` 是否堆积。  
3. 检查 `failed_jobs` 最近失败任务（是否为 `GenerateReportSnapshotJob` / `GenerateReportJob`）。  
4. 检查 `report_snapshots`：
   - `status` 是否长期 `pending`
   - `last_error` 是否持续更新
5. 检查应用日志关键字：
   - `REPORT_SNAPSHOT_GENERATE_FAILED`
6. 若 payment 链触发，额外检查 `payment_events.handle_status` 是否 `reprocess_failed/failed`。

## 4. 失败任务重试标准操作
```bash
cd /opt/fap-api/backend
php artisan queue:failed
php artisan queue:retry <id>
# 谨慎：批量
php artisan queue:retry all

# 清理单条失败记录（确认不再需要重试时）
php artisan queue:forget <id>

# 清空 failed_jobs（高风险，需审批）
php artisan queue:flush
```

使用边界：
- `retry all` 只在根因已修复且已评估幂等性时执行。
- `queue:flush` 只允许在故障复盘后、经审批执行。

## 5. 按队列故障分诊
### 5.1 reports（报告快照/legacy report）
- 关注表：`jobs`, `failed_jobs`, `report_snapshots`, `report_jobs`
- 典型问题：内容包不可读、result 缺失、snapshot 持续 pending

### 5.2 ops（审批执行）
- 关注表：`admin_approvals`, `audit_logs`
- 典型问题：审批 stuck 在 `APPROVED/EXECUTING/FAILED`

### 5.3 commerce（退款/重处理）
- 关注表：`orders`, `payment_events`, `benefit_grants`
- 典型问题：`handle_status=reprocess_failed`

### 5.4 content（探针）
- 关注表：`content_pack_releases`
- 典型问题：`probe_ok=0`、`probe_json` 显示 health/questions 失败

### 5.5 insights（AI）
- 关注表：`ai_insights`
- 典型问题：预算/外部 provider 超时导致 failed

## 6. SLO 与告警建议
- `failed_jobs` 总量 > 0 且持续 5 分钟：warning
- `jobs(queue=reports)` 积压超过阈值（例如 > 100）且 10 分钟无下降：critical
- `report_snapshots.status=pending` 超时（例如 > 5 分钟）占比异常：warning
- `payment_events.handle_status in (failed,reprocess_failed)` 激增：warning

## 7. 演练清单
1. 人工制造一条失败任务（测试环境）。
2. 在 `QueueMonitor` 与 CLI 同步确认失败记录。
3. 执行 `php artisan queue:retry <id>`。
4. 验证：
   - 任务状态变化
   - 业务状态恢复（例如 snapshot 变为 ready）
   - 审计日志有对应记录
5. 记录演练时间、执行人、结论。

## 8. 常用诊断命令
```bash
cd /opt/fap-api/backend
php artisan queue:failed
php artisan queue:work --once --queue=reports
php artisan queue:work --once --queue=ops
php artisan tinker
# 在 tinker 中可查询 jobs/failed_jobs/report_snapshots/admin_approvals
```

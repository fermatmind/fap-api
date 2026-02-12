# Secret Rotation Runbook (backend/.env Exposure)

## 1. 事件触发与目标
触发条件：发现 `backend/.env` 被纳入产物或疑似泄露。
目标：在最短时间内完成密钥轮换、部署收口、可审计留痕。

## 2. 立即遏制（T+0）
- [ ] 冻结发布窗口（暂停 deploy/publish/rollback 手工触发）。
- [ ] 删除工作区 `backend/.env`，确认不再进入归档/产物。
- [ ] 触发全量 CI，确认 `cae_gate.sh` 通过。
- [ ] 排查泄露面：仓库快照、CI artifacts、对象存储、聊天工具、工单附件。

## 3. 强制轮换清单（必须全部完成）
- [ ] APP_KEY：在 Secrets Manager 生成新值，更新运行环境后滚动重启；评估并记录对历史加密数据/会话的影响。
- [ ] EVENT_INGEST_TOKEN、FAP_ADMIN_TOKEN：全部吊销旧值并替换为新值，确认旧值不可再用。
- [ ] WEBHOOK_*（含 `STRIPE_WEBHOOK_SECRET`、`BILLING_WEBHOOK_SECRET`、`INTEGRATIONS_WEBHOOK_*_SECRET`）：在各提供方控制台重新签发，更新环境变量并验证签名。
- [ ] 任意第三方 `*_SECRET` / `*_TOKEN`：逐项吊销旧值并替换为新值（短信、邮件、对象存储、监控、支付扩展等）。
- [ ] DB 密码：重置应用账号密码，更新连接串并验证读写；旧密码立即失效。
- [ ] Redis 密码：重置 Redis ACL/密码，更新客户端连接串并验证队列/缓存恢复。

### 3.1 APP_KEY 轮换影响面与回滚策略（必须确认）
- 影响面（事实）：轮换后旧 `session/cookie` 与历史加密载荷（`Crypt::encrypt`、加密 token、加密字段）将无法解密，用户会被迫重新登录。
- 发布要求：必须在注入新 APP_KEY 后立即重新部署应用，并重启队列/长驻进程，避免新旧 key 混跑。
- 验证清单：
  - [ ] 新请求可正常登录、下发 cookie、访问受保护接口。
  - [ ] 关键写路径（attempt submit/report/share）无异常解密错误。
  - [ ] 日志中无批量 `DecryptException` 持续告警。
- 回滚策略：仅允许在受控窗口内回滚到“上一把已知安全 APP_KEY”，并记录回滚开始/结束时间、影响范围与后续再轮换计划。

## 4. 发布流程收口动作
- [ ] 先在 staging 注入新密钥并部署，完成 healthcheck 与核心接口冒烟。
- [ ] staging 验证通过后再生产发布，执行同样冒烟。
- [ ] 清理配置缓存与重启队列工作进程，确保新密钥生效。
- [ ] 回看最近 24h 认证/支付/队列错误率，确认无异常抬升。
- [ ] 将本次轮换编号、执行人、时间戳、验证结果写入变更记录。

## 5. 完成判定（Exit Criteria）
- [ ] `backend/.env` 不存在于 workspace、Git 跟踪、归档产物。
- [ ] 所有 workflow 都包含并通过 `bash backend/scripts/cae_gate.sh`。
- [ ] 受影响密钥全部轮换并完成功能验证。
- [ ] 形成书面复盘与审计证据（命令输出、CI 链接、发布记录）。

# Secret Rotation Runbook

## Purpose
- 处理疑似密钥泄露、误打包或合规轮换。
- 目标：新密钥生效、旧密钥失效、关键链路验证通过。

## Rotation Inventory
- `APP_KEY`
- 支付 Webhook Secret（Stripe/Billing 等）
- 第三方 API Key（AI、短信、云服务）
- JWT/Token Signing Secret（含内部签名密钥）
- SMTP 凭据（用户名/密码/API key）
- 数据库凭据（DB）
- Redis 凭据
- 管理员/服务间令牌（Admin token, ingest token）

## Preconditions
- 在 Secret Manager（或部署平台加密变量）中预置新值。
- 记录本次轮换窗口、执行人、回滚负责人。
- 提前通知业务方会有短时重启/灰度切换。

## Standard Procedure
1. 生成新密钥
   - 使用安全随机源生成新值，不复用历史值。
2. 配置新密钥
   - 将新值写入生产 Secret Manager / Env，不写入仓库文件。
3. 灰度发布/重启
   - 先灰度节点，观察 5-10 分钟，再全量重启服务与 worker。
4. 功能验证
   - 执行下方 Smoke Test，记录结果。
5. 回收旧密钥
   - 旧值从 Secret Manager 删除或标记禁用。
   - 禁用旧 webhook secret。
   - 拒绝旧 token 签名。
6. 审计留痕
   - 在 PR/变更单写明：已轮换项、验证项、回收项、执行时间。

## Smoke Test Checklist
- 登录流程（手机号验证码/令牌签发）
- 下单流程（创建订单 -> 查询订单）
- 支付回调（签名验证 -> 订单状态落库）
- 权益发放（benefit grant / wallet consume）
- 队列 worker（report/job 正常消费）
- 报表生成（report snapshot / report API）

## Recovery and Revocation Checklist
- 旧 `APP_KEY` 不再用于新请求签名。
- 旧支付 webhook secret 已禁用并验证 401/403。
- 旧 JWT/Token secret 产生的签名被拒绝。
- 旧 SMTP/API key 调用已失败或被平台吊销。

## PR Record Template
```md
### Secret Rotation Record
- Window: YYYY-MM-DD HH:mm (TZ)
- Rotated:
  - APP_KEY: done
  - Stripe webhook secret: done
  - Billing webhook secret: done
  - JWT/token secret: done
  - SMTP credential: done
- Smoke:
  - login: pass
  - order/create: pass
  - webhook: pass
  - worker: pass
  - report generation: pass
- Revoked:
  - old webhook secret: revoked
  - old token signing secret: revoked
```

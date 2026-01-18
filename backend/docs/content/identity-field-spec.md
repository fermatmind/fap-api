# Identity 字段协议与合规说明（phone / wechat / email 等）

版本：v0.1  
更新时间：2026-01-18

---

## 0. 核心原则（写死）
1) 手机号是唯一跨端主键（SSOT）：phone verified → user_id 唯一确定  
2) wechat/douyin/baidu 等第三方 uid 只作为 identities（登录方式），不能作为跨端主键  
3) 邮箱仅用于找回/通知通道，不作为账号主键  
4) 所有敏感动作（获取手机号/发送验证码/提交邮箱）必须 PIPL 手动勾选后才能进行

---

## 1. 数据实体（口径）

### 1.1 users（主账号）
- user_id（UUID / snowflake）
- phone_e164（唯一，建议存 E.164）
- verified_at
- status（active/disabled/deleted）
- created_at / updated_at

### 1.2 identities（多登录方式）
- id
- provider：phone / wechat / douyin / baidu / email
- provider_uid：各 provider 下唯一（phone/email 为 hash 或规范化值）
- user_id（FK）
- linked_at
- meta_json（可选：unionid/openid 等）

### 1.3 attempts（资产）
- attempt_id（UUID）
- ticket_code（唯一：FMT-XXXXXXXX）
- user_id（nullable，绑定后写入）
- anon_id（L0 标识，用于匿名资产归集）
- device_key_hash（可选）
- phone_hash/email_hash（可选）
- order_no（可选）
- created_at

---

## 2. 字段规范

### 2.1 phone
- 规范化：建议存 E.164（如 +86xxxxxxxxxxx）
- 唯一约束：phone_e164 unique
- 用途：登录、资产归集、售后通知
- 合规：获取手机号/发送验证码必须用户勾选协议

### 2.2 wechat / douyin / baidu
- provider_uid：openid/uid（每个 provider 下唯一）
- 不作为跨端主键，只作为登录入口
- 绑定手机号后才能实现跨端共享

### 2.3 email（MVP）
- 规范化：trim + lower
- 建议存 email_hash = sha256(lower(email))（减少明文风险）
- 用途：找回/通知通道（MVP 可只收集不发信）
- 风控：必须限频 + 防枚举；正式版建议盲回应

---

## 3. 用户告知文案（可直接用于页面）

### 3.1 手机号
“手机号仅用于登录、报告找回、跨端同步与售后通知，不会用于营销（除非您另行同意）。”

### 3.2 邮箱
“邮箱仅用于报告找回/通知，不会用于营销（除非您另行同意）。您可随时删除或解绑。”

---

## 4. PIPL 强制勾选红线（必须实现）
- 不允许默认勾选
- 不允许点击按钮自动勾选
- 未勾选时必须阻止动作，并提示“请先阅读并同意《隐私政策》《用户服务协议》”

适用动作：
- 小程序一键获取手机号
- 发送短信验证码
- 提交邮箱
- 任何新增身份绑定/导入资产操作（如 /me/import/*）

---

## 5. 数据保留与删除策略（口径）
- 默认保留期：建议 12 个月可配置
- 用户注销：
  - users 标记 deleted
  - identities 删除或脱敏
  - attempts 保留但匿名化（user_id 清空、仅保留不可逆 hash）
- 用户解绑手机号/邮箱：
  - identities unlink（保留审计记录 lookup_events）

---

## 6. 审计（lookup_events）
建议记录关键动作：
- method：ticket/device/phone/email/order/manual
- success：true/false
- risk_flags：枚举/频率异常/重复失败
- ip / ua
- created_at
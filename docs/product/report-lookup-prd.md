# Product Requirement Document: Report Lookup & Cross-Platform Asset Management
> Doc: docs/product/report-lookup-prd.md  
> Version: 1.0  
> Status: Ready for Dev (Task Package 5)  
> Scope: Report Lookup + Email Hook + Phone SSOT + Cross-platform Sharing  
> Platforms: WeChat Mini Program / Douyin Mini Program / Baidu Mini Program / Web / App

---

## 1. 背景与问题定义

心理测试行业高频投诉之一是：**用户第二天找不到报告**（清理缓存、换设备、在小程序做过却忘了入口、付费后丢失）。

本任务包目标不是“做一个找回页”，而是建立一套：
- **默认匿名可用**（不强迫注册）
- **跨端不丢失**（至少两种自助找回）
- **跨平台共享**（微信/抖音/百度/官网/App 资产一致）
- **售后可执行**（SOP 可复盘、可止损）
- **合规可上线**（PIPL 明确同意与用途说明）

---

## 2. 目标与验收

### 2.1 目标（对标口径）
1) 分享出去的链接/入口、用户再次访问时，系统能**尽量无感召回**（Smart Resume）。  
2) 即使用户换设备，也能通过**强兜底凭证**找回（Ticket Code / Phone）。  
3) 通过“绑定手机号”实现**多平台账户共享**：同手机号 -> 同一 user_id -> 同一资产库。  
4) 找回流程既降低售后，也能作为二次转化节点（找回即营销）。

### 2.2 验收（必须满足）
- 用户可通过至少两种方式找回报告（P0 推荐：Ticket Code + Phone；Device 作为默认无感召回）
- 有最小售后 SOP（收到请求怎么处理）
- 邮箱字段协议与合规说明（可先不发邮件，但可收集、用途写清）
- 多平台账户共享通过“绑定手机号”实现：同手机号在不同端登录/绑定后资产一致
- 合规：所有“获取手机号/发送验证码”动作前必须显式勾选同意协议（PIPL 红线）

---

## 3. 核心策略：三层身份模型（Identity Model）

### 3.1 L0 匿名资产层（默认启用）
- **Ticket Code（票据码）**：跨设备、匿名最强兜底（对标 123test）
- **Device Resume（设备召回）**：同设备无感继续（LocalStorage/Cookie）

> 所有人不注册也能用，最大化转化与隐私偏好覆盖。

### 3.2 L1 轻绑定层（可选增强）
- **Phone Binding（手机号绑定）**：跨端共享总线（SSOT），售后强兜底
- **Email Binding（邮箱绑定）**：仅作为找回/售后通知通道（非账号主键）

### 3.3 L2 账号层（面向付费/重度用户）
- 手机号验证码即登录/注册（Web/App）
- 第三方登录（微信/抖音/百度）作为 identities，登录后强引导绑定手机号
- “我的报告 /me”资产中心：聚合 reports/orders

---

## 4. 多平台账户共享：硬规则（SSOT）

### 4.1 Rule A：手机号是唯一跨端强身份（Single Source of Truth）
- 手机号验证成功 -> 确定唯一 `user_id`
- 微信/抖音/百度的 openid/uid 仅作为 `identities`（登录方式），不能作为跨端主键

### 4.2 Rule B：绑定手机号 = 资产归集开关
任意端完成手机号绑定后：
1) 当前会话匿名资产（device/ticket/最近 attempts）-> 归集到该 `user_id`
2) 当前平台 identity -> 写入 identities 并关联同一 `user_id`
3) 同手机号在任意端登录 -> 看到同一份资产库（reports/orders）

### 4.3 Rule C：MVP 冲突处理简化（开发减负）
- MVP 只做“匿名 -> 手机号账号”的**单向归集（APPEND/追加）**
- MVP 不做复杂“账号合并/双向同步/两个已绑手机号账号合并”
- 第三方 identity 已绑定他人：MVP 禁止静默改绑，提示走售后/SOP

---

## 5. 用户体验与入口设计（关键路径）

### 5.1 结果页（Result Page）——核心防线
#### 5.1.1 Ticket Code 视觉隐喻（强制）
- Ticket Code 在结果页顶部常驻（Sticky）
- 视觉：登机牌/取餐小票风格
- 文案：**“这是您通往内心世界的唯一票据，请截图保存。”**
- 交互：复制按钮 + Toast“已复制，凭此码可随时找回报告”

#### 5.1.2 防丢失浮层（Anti-loss Modal）
触发时机（至少做 1 个强触发，建议做 3 个）：
- 结果生成完成（Loading 结束）
- 支付成功
- Exit Intent（PC 鼠标移向关闭；移动端停留>30秒无操作）

浮层 CTA 顺序：
1) 复制票据码（P0）
2) 绑定手机号永久保存（P0.5）
3) 填写邮箱（P1，可选）

### 5.2 首页 Smart Resume（无感召回）
逻辑：检测 LocalStorage/Cookie 是否存在 latest_attempt_ids 或 last_attempt_id  
UI：若存在，首页 Hero 替换为 Resume Banner：
- “检测到您有未保存/已生成的报告：继续查看 / 绑定手机号永久保存 / 重新开始”

### 5.3 找回中心 `/lookup`（多 Tab）
Tab 顺序按优先级（默认 Tab = 本设备）：
1) 本设备（Device）
2) 票据码（Ticket Code）
3) 手机号（Phone）
4) 订单号（Order）
5) 邮箱（Email）

### 5.4 我的报告 `/me`（资产中心）
登录/绑定手机号后：
- 列表展示历史报告（付费/未付费、时间、类型、摘要）
- 支持导入资产（device/ticket/order）
- 支持升级购买（二次转化入口）

---

## 6. 找回方式设计（验收关键）

### 6.1 P0：必须支持
#### A) Ticket Code 找回（匿名跨设备兜底）
- 输入票据码（FMT-...）即可查到报告
- 命中后：已付费直达完整版；未付费展示摘要+解锁按钮

#### B) Device Resume（本设备无感召回）
- 展示本设备最近记录列表（至少 latest_attempt）
- 一键继续查看

### 6.2 P0.5：强烈建议（跨端共享成立）
#### C) 手机号找回/登录（Phone SSOT）
- 小程序优先“一键获取手机号”
- Web/App 使用短信验证码
- 登录后进入 /me，资产库一致（跨端共享）

#### D) 订单号找回（付费兜底）
- 输入订单号/交易号查找
- 强限频 + 审计

### 6.3 P1：邮箱找回（可先不发邮件）
- MVP：输入邮箱 -> 返回脱敏列表（伪 magic link），不发送邮件
- V1：Magic Link / 邮箱验证码 + 盲回应

---

## 7. 手机号获取策略（微信/抖音/百度小程序体验优化）

### 7.1 小程序端优先：一键获取手机号（强烈推荐）
策略：在微信/抖音/百度小程序环境，优先使用平台能力获取手机号（无需手输，无需短信费）。
- WeChat Mini Program：getPhoneNumber
- Douyin Mini Program：getPhoneNumber
- Baidu Mini Program：对应手机号授权能力（以平台为准）

优势：
- 用户体验极佳（不输入 11 位 + 不等短信）
- 降低短信成本
- 转化更高

兜底：
- 用户拒绝授权平台手机号 -> 降级到“输入手机号 + 发送验证码”（若该端允许）
- H5/Web/App -> 直接走短信验证码

### 7.2 PIPL 合规红线：强制勾选同意协议（必须）
交互序列强制：
- 用户必须先手动勾选《隐私协议》《用户服务协议》
- 才能点击“一键获取手机号”或“发送验证码”
红线：
- 不允许默认勾选
- 不允许点击按钮时自动勾选
- 未勾选点击按钮：弹窗/抖动提示“请先阅读并同意...”

---

## 8. 资产归集与冲突处理（MVP 简化）

### 8.1 资产归集（匿名 -> 手机号）
触发：用户在存在匿名资产的设备上完成手机号登录/绑定  
动作（MVP APPEND/追加模式）：
- 将本设备匿名 attempts 追加归属到当前 user_id
- 写入审计日志 lookup_events / merge_events

### 8.2 冲突处理（MVP）
- 若匿名 attempt 已归属其他手机号账号：不自动合并，提示“该记录已属于其他账号”，引导用票据码或走售后
- 若第三方 identity 已绑定他人：禁止静默改绑，提示走售后/SOP（后续版本再做迁移）

---

## 9. 数据模型（字段口径，不写代码）

### 9.1 核心实体
- users（主账号）
- identities（多渠道登录方式）
- attempts / reports / orders（资产）
- lookup_events（找回审计）

### 9.2 字段建议（最小集）
users
- user_id
- phone_e164（唯一）
- phone_verified_at
- status（active/disabled）
- created_at

identities
- identity_id
- user_id
- provider（phone / wechat / douyin / baidu / email）
- provider_uid（provider 内唯一）
- verified_at / linked_at
- meta（可选）

attempts
- attempt_id
- ticket_code（唯一索引）
- user_id（nullable，后续绑定）
- device_key_hash（nullable）
- phone_hash / email_hash（nullable）
- order_no（nullable）
- created_at / updated_at

lookup_events
- event_id
- method（device/ticket/phone/email/order）
- success（bool）
- ip / ua / risk_flags
- created_at

---

## 10. 接口清单（协议级定义，不写代码）

Auth / Bind
- POST /auth/phone/send_code (scene: login/bind/lookup)
- POST /auth/phone/verify (scene: login/bind/lookup)
- POST /auth/platform_phone/exchange (miniapp token -> phone e164)
- POST /auth/{provider}/login (wechat/douyin/baidu)
- POST /auth/{provider}/bind (logged-in bind identity)

Lookup
- POST /lookup/device
- POST /lookup/ticket
- POST /lookup/phone
- POST /lookup/email
- POST /lookup/order

Asset Import / Merge
- POST /me/import/device
- POST /me/import/ticket
- POST /me/import/order

Audit
- all lookup/bind/import actions -> write lookup_events

---

## 11. 风控与安全（Anti-Abuse）

- Rate limit（建议口径）
  - ticket lookup：per IP 10/min
  - phone send_code：per phone 1/min, 5/hour; per IP 10/hour
  - verify fail：5 次锁定 30 min
  - email lookup：per email 5/hour; per IP 10/min
  - order lookup：更严格（per IP 3/min）
- 防枚举：
  - email/phone 建议盲回应（MVP 可放宽但必须限频+审计）
- 所有找回行为必须写 lookup_events 便于售后复盘与风控

---

## 12. 找回即营销（商业化补完）

当找回命中未付费报告：
- 展示“已找到：日期 + 测试类型 + 结果摘要/封面”
- 主要 CTA：解锁完整版（可附限时优惠）
- 次 CTA：仅查看免费简版
- 目标：利用用户的“沉没成本”提高付费转化

---

## 13. 交付物（Task Package 5）

本任务包最终必须提交：
1) docs/product/report-lookup-prd.md（本文件）
2) docs/support/sop.md
3) docs/content/identity-field-spec.md

（实现可分阶段，文档必须先齐全，支付前完成更优）

---
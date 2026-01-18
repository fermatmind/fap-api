# Report Lookup（找回）+ 身份体系（SSOT）PRD（Phase A/B）

版本：v0.1  
作者：FermatMind  
更新时间：2026-01-18

---

## 0. 一句话目标

在不强制注册的前提下，让用户随时找回报告，并通过**手机号主账号（SSOT）**实现跨端共享资产，同时把“找回”变成留存与转化的关键环节。

---

## 0.2 验收口径（必须满足）

### A. 找回方式（至少两种可用）
- P0：Ticket Code 找回（匿名最强兜底）
- P0：Device Resume（本设备无感继续/找回）
- P0.5：手机号登录/绑定后进入“我的报告”（/me 资产库）

### B. 跨端共享成立（Phase B）
- 同手机号在不同端登录/绑定后，能看到同一份 reports / orders（同一 user_id）

### C. 最小售后 SOP
- 提供客服决策树、红线与工单字段

### D. 邮箱字段协议与合规说明
- 可先不发邮件，但能收集并说明用途（仅找回/通知，不用于营销，除非额外同意）

### E. 合规：PIPL 强制勾选
- 所有“获取手机号/发送验证码/提交邮箱”等动作前必须显式勾选协议
- 不能默认勾选、不能点击按钮自动勾选
- 未勾选必须阻止动作并提示

---

## 1. 总体策略：三层身份模型（核心）

### L0 匿名资产层（默认永远可用）
- Ticket Code（票据码）：匿名、跨设备、最强兜底（对标 123test）
- Device Resume（设备召回）：同设备无感继续（localStorage/device ids）

### L1 轻绑定层（可选增强）
- 手机号绑定：跨端共享总线（SSOT），售后兜底
- 邮箱绑定：仅做找回/通知通道（不作为账号主键）

### L2 账号层（重度/付费用户）
- 手机号验证码即登录/注册（Web/App 必备）
- 第三方登录（微信/抖音/百度）+ 绑定手机号
- “我的报告 /me”资产库（长期留存载体）

**强制原则：匿名可用永远成立；但在关键节点引导绑定。**

---

## 2. 多平台账户共享：硬规则（SSOT）

### Rule A：手机号是唯一跨端强身份（Single Source of Truth）
- 手机号验证成功 → 唯一确定 user_id
- wechat/douyin/baidu 的 openid/uid 仅作为 identities（登录方式），不是跨端主键

### Rule B：绑定手机号 = 资产归集开关
在任意端完成手机号绑定后：
1) 当前会话匿名资产（device/ticket/最近 attempts）→ 归集到该 user_id  
2) 当前平台 identity（wechat/douyin/baidu）→ 写入 identities，绑定到同一 user_id  
3) 后续同手机号在任意端登录 → 看到同一份资产

### Rule C：MVP 冲突策略简单化
- MVP 只做“匿名 → 手机号账号”的单向归集（APPEND）
- 不做复杂账号合并/双向同步
- 冲突：禁止静默改绑，提示走人工/SOP

---

## 3. 用户侧入口与体验闭环（Phase A 最小闭环）

### 3.1 结果页（最关键）
必须展示 Ticket Code，并强调保存：
- 文案：这是您通往内心世界的唯一票据，请截图保存
- 交互：复制 + toast

防丢失钩子（至少做 1 个）：
- 结果生成完成（loading 结束）
- 支付成功（未来）
- Exit Intent（未来）

### 3.2 首页 Smart Resume（建议）
若本地有 latest_attempt_ids：
- Banner：继续查看 / 绑定手机号保存 / 复制票据码 / 重新开始

### 3.3 找回中心 /lookup（可选 UI）
Phase A 可以不做 UI，但后端必须有接口支持：
- /lookup/ticket/{code}
- /lookup/device

### 3.4 我的报告 /me（Phase A 核心验收）
登录后能看到报告列表 기억：
- 数据源：GET /api/v0.2/me/attempts（fm_token 门禁）
- 点击进入：/pages/result/result?attempt_id=...

---

## 4. 当前实现状态（Phase A 已完成项）

### 4.1 后端接口（已上线 / 已门禁）
- GET /api/v0.2/attempts/{id}/result   ✅ fm_token 门禁
- GET /api/v0.2/attempts/{id}/report   ✅ fm_token 门禁
- POST /api/v0.2/lookup/device         ✅ fm_token 门禁
- GET /api/v0.2/me/attempts            ✅ fm_token 门禁（使用 middleware 注入 identity）

### 4.2 小程序（已完成项）
- 401 自动跳 login 并回跳原页 ✅
- result 页：优先后端 result/report 渲染 ✅
- history 页：使用 /me/attempts 作为数据源 ✅
- ticket_code 在 result 页展示与复制（带 PIPL 勾选）✅

---

## 5. Phase A（本周可 demo，必须过验收）

### Phase A 要交付
1) 后端最小闭环：ticket_code 可查 attempt/report（lookup/ticket）  
2) 结果页展示 + 复制 ticket_code  
3) “我的报告”列表（/me/attempts）  
4) 文档交付：PRD + SOP + identity/email spec  
5) 合规：PIPL 强制勾选写死口径（并在关键动作实现）

---

## 6. Phase B（手机号 SSOT + 跨端共享）

### Phase B 要交付
- 手机号一键取号（小程序）+ Web/App OTP（至少一个端可用）
- users / identities 数据模型落地
- 登录后把匿名 attempts 归集到 user_id（APPEND）
- /me/attempts 从 user_id 拉历史（不靠 anon/device）

---

## 7. 风控与合规（写死口径）

### 7.1 风控
- OTP/SMS 限频：手机号/IP 分级限频
- 验证码错误次数锁定
- lookup 限频：ticket/phone/email/order 分开限频
- 防枚举：email/phone 建议盲回应（MVP 可弱化但必须限频+审计）

### 7.2 合规
- 明确用途：仅用于登录、资产同步、找回、售后通知
- 不用于营销（除非用户另行勾选）
- 数据保留期：建议 12 个月可配置
- 删除/解绑：注销后 attempts 匿名化处理策略

---

## 8. 接口清单（协议级，不写代码）

### Auth / Bind
- /auth/wx_phone（小程序一键取号换 token 的入口，Phase A 为 dev stub）
- /me/phone/bind（Phase B）
- /me/email（Phase A 允许只收集，不发信）

### Lookup
- /lookup/ticket/{code}
- /lookup/device
- /lookup/phone（Phase B）
- /lookup/order（Phase B/P0.5）
- /lookup/email（Phase D）

### /me（资产中心）
- GET /me/attempts
- POST /me/import/device（Phase B）
- POST /me/import/ticket（Phase B）
- POST /me/import/order（Phase B）

---

## 9. 最短演示脚本（Phase A）
1) 测评生成 attempt → 进入 result 页  
2) result 页显示 ticket_code，复制成功  
3) 清除 token：wx.removeStorageSync('fm_token')  
4) 再进 history/result → 401 → 自动跳 login → 回跳  
5) history 展示 /me/attempts 列表 → 点开进入 result/report（200）
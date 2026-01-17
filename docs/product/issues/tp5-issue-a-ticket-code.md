# TP5 Issue A: Ticket Code 生成 + 展示 + Lookup（P0）
> Doc: docs/product/issues/tp5-issue-a-ticket-code.md  
> Related PRD: docs/product/report-lookup-prd.md (Section 0.2 / 5.1 / 6.1)  
> Priority: P0 (Phase A)

---

## 1. Scope（做什么）
实现 Ticket Code 的 **端到端闭环**：
- 生成：Attempt/Result 创建时生成 ticket_code（FMT-...）
- 展示：结果页显著展示（登机牌/小票视觉）+ 可复制
- 找回：/lookup 的 Ticket 入口支持输入 ticket_code 找回并打开报告/结果入口

> Phase A 只要求 “可演示通过”，不要求与支付/邮箱/手机号打通。

---

## 2. Entry（入口）
- 结果页：用户完成测试后生成结果时（Result Created）
- /lookup：Ticket Code Tab（或卡片入口）

---

## 3. Output（输出）
- 用户在结果页能看到 ticket_code，且能复制成功
- 用户在 /lookup 输入 ticket_code 能命中并进入同一份报告/结果入口
- 命中态区分：
  - 已付费：可直达完整版（Phase A 可先同页标识/占位）
  - 未付费：展示摘要卡 + 解锁入口（Phase A 可先简化）

---

## 4. Edge Cases（边界条件）
- code 格式不合法：提示“格式错误”
- code 不存在：提示“未找到记录”
- 频繁查询：触发限频提示（文案/策略即可，Phase A 可先记录到审计）
- 结果未生成/报告不存在：提示“记录存在但报告未就绪”，引导稍后重试

---

## 5. Acceptance（验收步骤：必须能 Demo）
1) 完成一次测试，进入结果页
2) 看到 Ticket Code（FMT-...）并点击复制
3) 新开隐身窗口（无 localStorage/cookie）
4) 打开 /lookup → Ticket Code → 输入该 code
5) 命中后打开同一份报告/结果入口 ✅

---

## 6. Non-goals（本 Issue 不做）
- 手机号绑定/登录
- 邮箱发送 Magic Link
- 复杂账号合并
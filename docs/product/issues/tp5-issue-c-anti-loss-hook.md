# TP5 Issue C: 防丢失 Hook（结果/支付/离场）+ “复制票据码/引导绑定”UI（P0）
> Doc: docs/product/issues/tp5-issue-c-anti-loss-hook.md  
> Related PRD: docs/product/report-lookup-prd.md (Section 0.2 / 5.1.2 / 7.2)  
> Priority: P0 (Phase A)

---

## 1. Scope（做什么）
实现“防丢失 Hook”的最小闭环：
- 结果生成完成后（必做）：弹出/浮层提示用户“复制票据码”
- 主 CTA：复制 Ticket Code
- 次 CTA：引导绑定手机号/邮箱（Phase A 可只做按钮与门禁位置，不要求打通）
- PIPL 门禁：任何“手机号相关动作”必须先勾选协议（Phase A 落文案+阻断）

> Phase A 只要求：强提示出现 + 可复制 + 门禁逻辑位置明确且可验收。

---

## 2. Triggers（触发点）
- 必做：Result Created（loading 结束后的第一次进入结果页）
- 可选（Phase A 可不实现但应在 UI/文档预留）：
  - 支付成功
  - Exit Intent（PC 关闭意图；移动端停留>30s 无操作）

---

## 3. Output（输出）
- 用户明确看到“防丢失提示”
- 点击“复制票据码”成功（toast/反馈）
- UI 中清晰展示：绑定手机号/发送验证码前必须先勾选《隐私协议》《用户服务协议》

---

## 4. PIPL Gate Acceptance（门禁验收口径）
- 未勾选协议时：
  - 点击“绑定手机号/获取手机号/发送验证码” → 必须被阻断
  - 出现提示（弹窗/抖动/inline error）“请先阅读并同意…”
- 已勾选协议时：
  - 允许进入下一步（Phase A 允许只进入“下一步 UI”，不要求后端成功）

---

## 5. Acceptance（验收步骤：必须能 Demo）
1) 完成一次测试 → 进入结果页
2) 看到防丢失 Hook（强提示）
3) 点击复制票据码 → 成功反馈 ✅
4) 未勾选协议时尝试触发手机号动作 → 被阻断且提示 ✅
5) 勾选协议后再次触发 → 进入下一步 UI（Phase A 允许不联通后端）✅

---

## 6. Non-goals（本 Issue 不做）
- 短信服务接入与真实验证码验证（Phase B）
- 平台一键取号真实换取手机号（Phase B）
- 邮箱 Magic Link 发送（Phase D）
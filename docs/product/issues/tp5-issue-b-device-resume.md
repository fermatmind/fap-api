# TP5 Issue B: Device Resume + 首页 Banner + Lookup(Device)（P0）
> Doc: docs/product/issues/tp5-issue-b-device-resume.md  
> Related PRD: docs/product/report-lookup-prd.md (Section 0.2 / 5.2 / 5.3 / 6.1)  
> Priority: P0 (Phase A)

---

## 1. Scope（做什么）
实现同设备“无感召回”闭环：
- 记录本设备 latest_attempt（或 latest_attempt_ids）
- 首页 Smart Resume Banner：检测到记录则展示“继续查看”
- /lookup 的 Device 入口：展示本设备历史（至少 latest_attempt）
- 一键继续：从列表跳转到报告/结果入口

---

## 2. Entry（入口）
- 首页（/）：Smart Resume Banner
- /lookup：Device Tab（默认）

---

## 3. Output（输出）
- 同设备二次访问时，用户能看到“继续查看/历史记录”
- 用户点一次即可回到报告/结果入口
- /lookup 的 Device Tab 至少能展示 latest_attempt

---

## 4. Edge Cases（边界条件）
- 本地记录为空：显示默认“开始测试”态
- 本地记录存在但后端查不到：提示“记录已失效/已清理”，并提供重新开始
- 多条记录：展示最近 N 条（N 可先固定，比如 5）
- 用户主动清除缓存：自然回到默认态

---

## 5. Acceptance（验收步骤：必须能 Demo）
1) 在同设备完成一次测试，进入结果页
2) 关闭页面/浏览器标签
3) 重新打开首页（/）或 /lookup
4) 看到 Resume Banner 或 Device 列表
5) 点击继续查看 → 打开报告/结果入口 ✅

---

## 6. Non-goals（本 Issue 不做）
- 跨设备同步（手机号/邮箱）
- 复杂历史筛选/排序（后置）
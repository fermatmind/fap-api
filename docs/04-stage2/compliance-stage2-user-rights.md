> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Compliance CN v0.2
> Related Docs:
> - docs/04-stage2/README.md
> - docs/03-stage1/compliance-basics.md

# Stage 2 合规：用户权益最小通道（CN v0.2）
目标：能上线、能解释、能处理请求（先人工流程）

## 1. 对外页面（网站）
建议新增：
- /privacy/mbti-v0.2 或 /user-rights
包含：
1) 我们记录什么数据（anon_id/attempts/results/events）
2) 数据用途（生成结果/改进产品；不售卖）
3) 用户可请求：
- 删除测评数据
- 导出/查看测评记录（邮件附件）
4) 联系方式：
- privacy@fermatmind.com（建议）

## 2. 人工处理 SOP（写死步骤）
1) 收到请求 → 要求对方提供：anon_id + 测评时间范围/设备信息
2) 在后台检索 anon_id → 找到相关 attempts/results/events
3) 执行：
- 导出：打包 csv/json
- 删除：按 anon_id 删除（或软删）并记录操作日志
4) 回执邮件：确认完成 + 时间戳

## 3. 验收
- 模拟发起一次“删除 anon_id 数据”请求
- 按 SOP 完成检索、处理、回执
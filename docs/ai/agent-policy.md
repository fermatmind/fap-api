# Agent Policy (PR14)

## 触发器
- sleep_volatility：近 7 天睡眠波动（stddev）超阈值
- low_mood_streak：连续低情绪天数 >= 阈值
- no_activity：N 天无 events 记录

## 策略
1) ConsentPolicy
- integrations.consent_version 为空则 suppress

2) ThrottlePolicy
- quiet_hours + cooldown + max_messages_per_day

3) RiskPolicy
- 高风险触发固定文案，不允许自由生成
- 触发 agent_safety_escalated 事件

4) Budget policy
- BudgetLedger 超限或不可用且 fail_open=false
- 降级为固定文案，或 suppress 并记录 agent_message_failed / agent_suppressed_by_policy

## 触发后的动作
- 生成 why_json + evidence_json（不可为空）
- InAppNotifier 落库发送

## 固定文案
- 高风险与预算降级：
  - title/body 固定模板（见 config/agent.php risk_templates.high）

## 幂等与去重
- agent_triggers / agent_decisions / agent_messages 均使用 idempotency_key
- 再次触发时复用已有记录

## 事件与审计
- agent_trigger_fired
- agent_decision_made
- agent_message_queued
- agent_message_sent
- agent_message_view
- agent_message_feedback
- agent_safety_escalated
- agent_suppressed_by_policy
- agent_message_failed

## 开关
- config('agent.enabled')
- config('agent.breaker.*')

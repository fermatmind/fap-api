# Agent Metrics

## 触发与发送
- agent_trigger_fired：触发次数/用户数
- agent_decision_made：决策次数（send/suppress）
- agent_message_queued / agent_message_sent

## 用户互动
- agent_message_view（ack）
- agent_message_feedback（反馈）

## 风险与预算
- agent_safety_escalated
- agent_suppressed_by_policy
- agent_message_failed

## 成本
- v_agent_cost_daily.sql：insights + embeddings + agent 组合成本

## 视图
- v_agent_trigger_rate
- v_agent_message_funnel
- v_agent_message_feedback
- v_agent_safety_flags
- v_memory_growth
- v_agent_cost_daily

# Agent Ops

## 运行方式
- Job: AgentTickJob (定时执行或队列)
- SendAgentMessageJob: 对单个用户执行

## 幂等与去重
- agent_triggers / agent_decisions / agent_messages 使用 idempotency_key
- 重复触发时复用记录

## 失败与重试
- 表缺失或外部依赖不可用：记录事件并返回 suppress
- 预算不可用且 fail_open=false：降级或抑制

## 熔断联动
- 预算超限/不可用：agent_suppressed_by_policy 或 agent_message_failed

## 禁用与回滚
- admin/agent/disable-trigger：将触发器状态置为 disabled（审计记录）
- admin/agent/replay/{user_id}：回放最近触发逻辑，仅返回 decision

## 监控
- 查看 tools/metabase/views 下 agent 相关视图

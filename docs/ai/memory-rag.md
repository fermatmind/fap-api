# Memory RAG (PR14)

## 目标
- 引入“用户确认”的长期记忆：所有写入必须经过 propose -> confirm。
- 检索可解释：返回 why/evidence + source_refs。
- 可关闭/可删除：支持禁用、软删、导出。

## 数据分级
- proposed：系统候选记忆，等待用户确认。
- confirmed：用户确认后的记忆，可被检索使用。
- deleted：用户删除（软删），不可被检索。

## 流程
1) propose
- 输入：content/title/kind/tags/evidence/source_refs
- 输出：memory_id
- 事件：memory_proposed

2) confirm
- 确认后写入 confirmed_at
- 触发向量化与向量索引 upsert
- 事件：memory_confirmed

3) delete
- 软删：status=deleted + deleted_at
- 事件：memory_deleted

4) export
- 仅导出 confirmed
- 用于用户数据导出或审计

## 检索策略
1) 结构化过滤
- user_id + status=confirmed
- kind/tag/time 过滤（可扩展）

2) 向量召回
- 先生成 query embedding
- vectorstore 查询 top_k
- 回补 memory 记录

## Evidence 结构
- why_json：
  - trigger_type
  - metrics（摘要）
  - policies
  - generated_at
- evidence_json / source_refs：
  - [{"type": "sleep_samples", "days": 7}, {"type": "ai_insights", "id": "..."}]

## 安全与红线
- Redactor 过滤敏感字段（证件/银行卡等）
- 未确认内容不会进入检索
- 可通过 config('memory.enabled') 一键关闭

## 相关配置
- config/memory.php
- config/vectorstore.php

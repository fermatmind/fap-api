> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Analytics v0.2-B
> Related Docs:
> - docs/04-stage2/README.md
> - docs/04-stage2/event-responsibility-matrix.md
> - docs/04-stage2/stage2-acceptance-playbook.md

# Stage 2 数据与漏斗规范（Funnel + Weekly Ritual）
与 Stage 1 的 events 词典口径一致

## 1. Stage 2 最小事件集（建议）
- scale_view
- test_start
- test_submit（后端写入）
- result_view
- report_view（可选：深读页）
- share_generate
- share_click
- feedback_submit（可选）
- payment_intent（占位）

## 2. 事件字段口径（摘要，详见 Stage 1 events 规范）
必带：
- anon_id / user_id（预留）/ openid（预留）
- scale_code（MBTI）
- attempt_id
- region / locale
- source / channel
可选：
- type_code、payload_json（duration_ms/error_code/share_target 等）

## 3. 漏斗指标（Stage 2 核心）
- UV/PV：scale_view
- Starts：test_start
- Completes：test_submit
- Result Rate：result_view / test_submit
- Share Rate：share_generate / result_view
- Share CTR：share_click / share_generate
- Quality：error_rate、avg_duration（来自 payload_json）

## 4. Weekly Ritual（周仪式）
每周一固定：
1) 运行 weekly-report（7 天）
2) 填 D1 指标（本周值 + WoW）
3) 记录本周动作（投放/渠道/内容）
4) 写 3 行结论：
- best metric
- worst metric
- next experiment

输出归档：
docs/weekly/2025-Wxx.md
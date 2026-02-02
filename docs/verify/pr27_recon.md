# PR27 Recon

- 目标：修复 v0.3 ReportPaywallTeaserTest（upgrade_sku 兼容锚点）并定稿“长期运营 SKU 合约”。
- 相关入口文件（后续在实现时补齐路径）：
  - 报告 paywall 组装：ReportGatekeeper / ReportController / AttemptReportHandler
  - 商业化：create_order / webhook / entitlement grant / wallet consume
  - ScaleRegistry：view_policy_json / commercial_json
  - 内容包：commercial_spec.json（v0.2.2）
- 需要新增/修改点：
  1) upgrade_sku 固定为兼容锚点（MBTI_REPORT_FULL）
  2) 新增 upgrade_sku_effective（MBTI_REPORT_FULL_199）+ offers[]
  3) inbound 订单保存 requested_sku + effective_sku，权益按 entitlement_id 统一发放
  4) 单测升级为“双锚点断言”
- 风险点与规避：
  - 兼容锚点与真实 SKU 解耦，旧端/旧脚本不改继续可用
  - 定价与套餐只改 offers/variants，不改锚点字段
  - sqlite fresh migrate 可跑通，脚本不使用 jq/rg
  - 跨 org/越权统一 404

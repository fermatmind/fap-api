# FermatMind 系统模块图

- Scope: `/Users/rainie/Desktop/GitHub/fap-api`
- Generated: 2026-03-05

## 1) Core Module Map

```mermaid
flowchart LR
    FE[Frontend fap-web Next.js]
    API[API backend Laravel]
    OPS[Ops Console Filament /ops]
    DB[(Database)]
    CP[Content System content_packages + baselines]
    PAY[Payments stripe billing lemonsqueezy wechatpay alipay]
    RPT[Reports snapshot + pdf]

    FE --> API
    OPS --> API
    API --> DB
    API --> CP
    API --> PAY
    PAY -->|webhooks| API
    API --> RPT
    RPT --> DB
    RPT --> CP
    OPS --> DB
```

## 2) Runtime Flow Map

```mermaid
flowchart TD
    U[User] --> FE[Frontend]
    FE --> A1[Start Attempt API]
    A1 --> DB1[(attempts)]

    FE --> A2[Submit Attempt API]
    A2 --> Q[Queue / ProcessAttemptSubmissionJob]
    Q --> DB2[(results)]

    DB2 --> R1[ReportSnapshotStore]
    R1 --> DB3[(report_snapshots)]
    R1 --> PDF[GenerateReportPdfJob]

    FE --> C1[Checkout API]
    C1 --> DB4[(orders)]
    PAY[Payment Provider] --> WH[Webhook API]
    WH --> DB5[(payment_events)]
    WH --> ENT[Entitlement + Report Trigger]
    ENT --> DB3

    OPS[Ops /ops] --> MGMT[Org Order Content Audit Management]
    MGMT --> DB
```

## 3) Module Responsibility Summary

- Frontend
  - C 端入口与 SEO 出口（当前仓库仅见 robots/sitemap 壳）。
- API
  - 统一承载认证、测评、结果、报告、支付、组织与合规。
- Ops
  - 组织治理、订单/支付审计、内容发布、运维监控。
- Database
  - 业务主数据、事件、权限、快照与审计。
- Content
  - 量表题库、报告卡片、规则资产。
- Payments
  - 多支付渠道接入与回调处理。
- Reports
  - 分量表报告组合、free/full 变体、PDF 产出。

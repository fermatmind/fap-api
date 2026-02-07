# PR42 Recon

- Keywords: AuditLogger|SensitiveDataRedactor|redact
- 相关入口文件：
  - backend/app/Services/Audit/AuditLogger.php
- 相关路由：
  - N/A（审计日志工具类）
- 相关 DB 表/迁移：
  - audit_logs（现有）
- 需要新增/修改点：
  - 新增递归脱敏工具（支持嵌套数组）
  - AuditLogger 统一走新脱敏逻辑
- 风险点与规避（端口/CI 工具依赖/pack-seed-config 一致性/sqlite 迁移一致性/404 口径/脱敏）：
  - 脱敏误杀：以 key 规则为主，单测覆盖典型 key

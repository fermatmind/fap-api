# PR39 Recon

- Keywords: ContentLoaderService|ContentPackResolver|filemtime

## 相关入口文件
- backend/app/Services/Content/ContentLoaderService.php
- backend/app/Services/ContentPackResolver.php

## 相关路由
- 无（内部服务改动）

## 相关 DB 表/迁移
- 无

## 需要新增/修改点
- ContentLoaderService 缓存 key 纳入 filemtime，避免 JSON 更新后缓存不失效
- Redis 不可用时自动降级，避免 CI/本地无 Redis 导致 500

## 风险点与规避
- 性能：仅引入 filemtime stat 调用；避免读取文件内容来计算 key
- 稳定性：捕获 Redis 连接异常并回退到默认 cache store
- 脱敏：key 与 artifacts 不写入绝对路径

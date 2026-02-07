# PR44 Recon

- Keywords: EventController|EventPayloadLimiter|props_json
- 相关入口文件：
  - backend/app/Http/Controllers/EventController.php
  - backend/app/Services/Analytics/EventNormalizer.php
- 风险点：
  - 大 payload 嵌套数组/长字符串导致内存膨胀与 DB 写入压力
- 目标：
  - 以配置化的方式限制 event props/meta 的 depth/keys/list/string 长度

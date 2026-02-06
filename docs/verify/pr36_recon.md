# PR36 Recon

- Keywords: GenericLikertDriver|scoring_spec|items_map

- 相关入口文件：
  - backend/app/Services/Assessment/Drivers/GenericLikertDriver.php

- 相关测试：
  - backend/tests/Unit/Services/Assessment/GenericLikertDriverTest.php

- 需要新增/修改点：
  - 支持 scoring_spec 风格的 reverse/weight 规则：
    - items_map[qid] 允许 number 或 object({weight, reverse})
  - Likert 映射（options_score_map/option_scores）缺省与边界处理
  - reverse 采用 min+max-raw 的通用反向映射
  - 输出保留维度累加结果，同时提供可调试明细字段

- 风险点与规避：
  - 仅改动 driver + 单测 + 本 PR 验收脚本与文档
  - CI 回归：跑 ci_verify_mbti.sh 确保无旁路影响

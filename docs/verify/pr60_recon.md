# PR60 Recon

- Keywords: AttemptsController|OrderManager|PsychometricsController
- 相关入口文件：
  - backend/app/Http/Controllers/API/V0_3/AttemptsController.php
  - backend/app/Http/Controllers/API/V0_3/CommerceController.php
  - backend/app/Services/Commerce/OrderManager.php
  - backend/app/Http/Controllers/API/V0_2/PsychometricsController.php
- 相关 DB 表：
  - attempts
  - results
  - orders
  - attempt_quality
- 目标风险：
  - Attempt/Order/Psychometrics 查询阶段缺少 owner WHERE，存在跨身份读取风险
  - 非法访问与不存在返回口径不一致可能导致资源存在性侧信道
- 改造策略：
  - 在 Attempt/Order/attempt_quality 查询阶段加入 `(user_id = ? OR anon_id = ?)` owner 条件
  - 身份缺失时强制 `whereRaw('1=0')`
  - 越权与不存在统一 404（ModelNotFound/abort(404)）
- 风险规避：
  - verify/accept 脚本禁用 jq/rg，仅使用 grep/sed/awk/php -r
  - 验收脚本端口统一使用 1860 且清理 1860/18000
  - answers payload 从 `/api/v0.3/scales/{code}/questions` 动态生成
  - pack/seed/config 一致性在 verify 脚本中闭环校验
  - artifacts 统一执行 `sanitize_artifacts.sh 60`

## 路由摘录（来源：/tmp/pr60_route_list.txt）

```text
38:  GET|HEAD  api/v0.2/attempts/{id}/quality API\V0_2\PsychometricsController@q…
39:  GET|HEAD  api/v0.2/attempts/{id}/report ........... MbtiController@getReport
43:  GET|HEAD  api/v0.2/attempts/{id}/stats API\V0_2\PsychometricsController@sta…
95:  POST      api/v0.3/attempts/start ........ API\V0_3\AttemptsController@start
96:  POST      api/v0.3/attempts/submit ...... API\V0_3\AttemptsController@submit
99:  GET|HEAD  api/v0.3/attempts/{id}/report v0.3.attempts.report › API\V0_3\Att…
100: GET|HEAD  api/v0.3/attempts/{id}/result . API\V0_3\AttemptsController@result
104: POST      api/v0.3/orders .......... API\V0_3\CommerceController@createOrder
105: GET|HEAD  api/v0.3/orders/{order_no} .. API\V0_3\CommerceController@getOrder
```

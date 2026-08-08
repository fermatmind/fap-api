# DETAIL_READY_1046_ROLLOUT_APPLY_PREFLIGHT-02

## 结论

本任务只建立受保护的零写生产预检控制，不执行该控制，也不生成发布授权。当前可确认的公共事实是：job index 为 1046/locale，directory read model 仍为 30/locale，sitemap 为 613 个 URL、其中职业详情 60 个且不含 AA。AA 的 EN/ZH SEO authority 当前均返回 500，页面外层 HTTP 均为 200。

这意味着“1046 已存在于 job index”不能推导为“1046 已进入 directory、详情发布或 discoverability”。AA 也不能因为 Search Entry quality review 中存在记录而自动获得 canonical rollout 的 review/release authority。

## 控制边界

新 workflow 只能从最新 `main` 手动触发，并绑定：

- exact control-plane SHA；
- exact active production release SHA 与 release name；
- exact 1046 manifest SHA-256；
- protected `production` environment；
- pinned SSH known hosts；
- 仅授权本次零写预检的 exact operator phrase。

远端 runner 通过 stdin 执行，不落地远端脚本或证据文件。它只读取 active release、manifest、公共 API、`/proc/meminfo`。它直接从现有 `CareerFullReleaseLedgerProjectionService`、`CareerRuntimePublishProjectionService` 和 `CareerCanonicalRuntimeTruthExporter` 生成内存态 authority snapshot，只输出 AA 白名单字段与聚合计数；同时调用三个只读命令：

- `career:audit-detail-ready-1048-candidates --json`
- `career:audit-canonical-eligibility --scope=slugs --slugs=accountants-and-auditors --locales=en,zh --json`
- `career:execute-canonical-rollout-batch ... --dry-run --no-audit-write --json`

输出只保留聚合计数、AA 的公开 slug 状态、失败 reason code 和资源快照，不读取原始日志或用户数据。失败、timeout 或非 JSON 输出会得到 `HOLD_ZERO_WRITE_PREFLIGHT_INCOMPLETE`，不会自动进入 apply。

## 新旧管线判定

Canonical rollout 的 authority 链是 full release ledger → runtime projection → canonical truth → rollout gate。Search Entry quality pipeline 只负责 search-entry review/eligibility；它不是 publication、indexability 或 sitemap authority。两条管线之间禁止推导批准。

因此 B18 旧命令出现的 `candidate_truth_row_missing` 仍是有效边界；AA 是否具备 projection/truth/review authority，必须以本 workflow 的 AA eligibility 与 no-audit dry-run receipt 为准。

## 内存与分批

本任务不执行 full rollout 或 warm，也不声称 8 GB 是已证明的最低配置。runner 将每个 PHP 只读命令限制为 768 MB、180 秒，并根据运行时 `MemAvailable` 只生成 10/25/50/100 中的保守建议批次。先固定 AA=1 canary；任何 timeout、OOM 或无效 JSON 均停止。该快照不是 peak RSS 测量，扩容结论保持 unknown。

## 明确禁止

本 PR 不执行 production apply、数据库/CMS 写入、publication、warm、扩容、deploy、sitemap/llms 修改、Search Channel 操作或 URL submission。合并后若要取得生产只读证据，需要另行给出 exact workflow dispatch 授权；该授权也不等于未来 apply 授权。

机器可读控制证据见 `backend/docs/seo/generated/detail-ready-1046-rollout-apply-preflight-02.v1.json`。

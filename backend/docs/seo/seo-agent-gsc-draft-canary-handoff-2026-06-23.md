# SEO Agent GSC Draft Canary Handoff - 2026-06-23

## Purpose

This document records the current FermatMind SEO Agent GSC cohort draft canary
state after the June 22-23 production runs. It is a handoff for the next Codex
or operator session.

It summarizes what is already closed, what remains gated, and what must not be
combined. It does not add runtime code, mutate CMS, publish articles, write URL
Truth, enqueue Search Channel items, submit URLs, change sitemap or `llms.txt`,
enable scheduler, start queue workers, deploy, or call live GSC/model APIs.

## Current Capability Boundary

The SEO Agent is now usable as a controlled, evidence-backed semi-automated
pipeline:

1. Ingest sanitized GSC cohort artifacts.
2. Convert them into SEO Agent source, aggregate, Codex handoff, Codex verdict,
   and CMS draft package dry-run artifacts.
3. Write bounded CMS article draft revisions only after exact human approval.
4. Run read-only readback QA, claim-risk QA, preview/runtime QA, and publish
   gate readiness.
5. Publish one article canary only after separate exact publish approval.
6. Run post-publish propagation dry-run, URL Truth gate, Search Channel enqueue
   gate, approval gate, and bounded IndexNow live submit gate separately.

The system is not a fully automatic publisher. It must not automatically publish,
automatically write URL Truth, automatically enqueue/search-submit, activate
scheduler, or mutate frontend code.

## Upstream GSC Readmodel Foundation Status

The separate GSC readmodel foundation loop is closed for the first controlled
artifact. This is upstream infrastructure for SEO Agent decision evidence, not
CMS drafting or publishing work.

Artifact:

- Sanitized GSC live-read artifact sha256:
  `66833c07bafe5a5a0c23c6870cfed713057e0349c6800caee02ae38b92b934c0`

Backend deployment:

- Production backend was updated to include PR `#2357`.
- Deployed backend SHA:
  `2743f39309a5fe3e35d544a82763789ef20bfa5f`
- New production command registered:
  `seo-intel:gsc-readmodel-canary-readback`

Readmodel results:

- Target connection/table:
  `seo_intel` / `seo_gsc_daily`
- Dry-run importer consumed the sanitized artifact successfully.
- Canary readback confirmed all previewed idempotency keys were present.
- Controlled canary execute was rerun with exact approval and completed
  idempotently:
  - `rows_previewed=3`
  - `rows_inserted=0`
  - `rows_skipped_existing=3`
  - `rows_missing=0`
  - `all_rows_already_present=true`
  - `data_quality_gate=pass`

Local evidence artifacts:

- `/tmp/gsc-readmodel-canary-readback-evidence-20260623.json`
- `/tmp/gsc-readmodel-bounded-import-readiness-20260623.json`
- `/tmp/gsc-readmodel-controlled-canary-write-evidence-20260623.json`

Readmodel boundary:

- The current 3-row artifact should not be reused for further write tests.
- The readmodel lane should pause until a new, larger sanitized GSC artifact is
  produced.
- Future readmodel work should remain limited to GSC artifact ingestion,
  read-only dry-run, bounded controlled import, and readback/idempotency
  evidence.
- It must not write CMS drafts, publish articles, enqueue Search Channel items,
  submit URLs, mutate sitemap or `llms.txt`, enable scheduler, or change
  frontend code.

## Source Cohort

The active package is the PR-B GSC cohort draft package:

- Package path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-cohort-handoff/20260622T-prb-d5078205-real-dryrun/seo-agent-cms-draft-package-dry-run-20260622T092803Z.json`
- Package sha256:
  `889108891858699267f825351335cb8094c7733dcec169966f50bba2e0bdf416`
- Candidate order:
  1. `article:41:en`
  2. `article:37:zh-CN`
  3. `article:34:en`
  4. `article:3:zh-CN`
  5. `article:40:zh-CN`
  6. `article:8:zh-CN`
  7. `article:51:zh-CN`

## Closed Article 41 Loop

`article:41:en` is the first fully executed article canary.

Final article state:

- Target: `article:41:en`
- Published revision: `71`
- Canonical URL:
  `https://fermatmind.com/en/articles/what-is-riasec-holland-code-career-interest-test`
- Search queue item: `256`
- Channel: `indexnow`
- IndexNow live submit result: `accepted`, HTTP `200`

Closeout artifact:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article41-closeout/20260623T-article41-full-closeout/seo-agent-article41-post-publish-search-closeout-20260623T045500Z.json`
- Sha256:
  `2138d2785353b1de033f4ad84def994d4de52619b5c366535af7da22b4b55ad2`
- Status:
  `success_with_current_url_truth_db_gap`

Important caveat:

- URL Truth import evidence for article 41 was successful at import time and
  recorded one row written/read back.
- Current production DB readback later showed `seo_urls=0` and
  `seo_url_entities=0`.
- Treat this as a follow-up URL Truth persistence/readback gap before relying on
  current URL Truth rows for more article propagation.

## Drafts Already Written

### `article:37:zh-CN`

- Draft revision: `69`
- Write evidence:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/cms-draft-write/20260623T-gsc-next-batch-limit3/seo-agent-controlled-cms-draft-write-20260622T163133Z.json`
- Write evidence sha256:
  `ec4837ed026e1ad3e133b883b1c351814a70103e6da12d4f7646944c7dca4974`
- Readback QA: success
- Claim-risk QA: success
- Preview/runtime QA: initial blocked artifact existed, later PR-J rerun publish
  gate treated the draft as ready.
- Publish gate rerun for batch 37/34:
  `publish_ready_count=2`

### `article:34:en`

- Draft revision: `70`
- Same write evidence as article 37:
  `ec4837ed026e1ad3e133b883b1c351814a70103e6da12d4f7646944c7dca4974`
- Readback QA: success
- Claim-risk QA: success
- Preview/runtime QA: initial blocked artifact existed, later PR-J rerun publish
  gate treated the draft as ready.
- Publish gate rerun for batch 37/34:
  `publish_ready_count=2`

### `article:3:zh-CN`

- Draft revision: `72`
- Write evidence:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/cms-draft-write/20260623T-gsc-next-batch-limit5-execute/seo-agent-controlled-cms-draft-write-20260623T070634Z.json`
- Write evidence sha256:
  `754967ad474898a49408827c02f831187232ef8c089f4332a2bc53bbdac93bd5`
- Readback QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/readback/article3/seo-agent-cms-draft-readback-qa-20260623T070719Z.json`
- Readback QA sha256:
  `c4878d4a581634ddbb449eab8c90bbed64b86f7c801c573a99bffe9d83d050e4`
- Claim-risk QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/claim-risk/article3/seo-agent-article-draft-claim-risk-qa-20260623T070720Z.json`
- Claim-risk QA sha256:
  `7a1ac7c5fe7aded90623c98a95a813dd23068549892d5152632df7bf3e147bed`
- Preview/runtime QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/preview-runtime/article3/seo-agent-article-draft-preview-runtime-qa-20260623T070722Z.json`
- Preview/runtime QA sha256:
  `3e0e9e71f85f24ae8ecde183ca8d324f9026614dfc825adfb69803ffb924939c`

TDK review:

- Proposed SEO title:
  `大五人格测试是什么？OCEAN 五大特质与 MBTI 区别 | FermatMind`
- Title length: 42 characters.
- Proposed SEO description:
  `了解大五人格测试和 OCEAN 五大特质如何帮助你观察长期行为倾向。大五适合回答连续维度问题，但不能诊断、预测职业成功或替代真实反馈。`
- Description length: 67 characters.
- Claim risk: low. The description explicitly blocks diagnosis and career
  success prediction.
- FAQ status: not publish-ready. The current `proposed_faq_items` are internal
  review placeholders, not user-facing FAQ content.

### `article:40:zh-CN`

- Draft revision: `73`
- Write evidence:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/cms-draft-write/20260623T-gsc-next-batch-limit5-execute/seo-agent-controlled-cms-draft-write-20260623T070634Z.json`
- Write evidence sha256:
  `754967ad474898a49408827c02f831187232ef8c089f4332a2bc53bbdac93bd5`
- Readback QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/readback/article40/seo-agent-cms-draft-readback-qa-20260623T070720Z.json`
- Readback QA sha256:
  `c0dae25032db7a7cc46986842b101a6ecd5194d167b8a13dfb6d43bb819c6ace`
- Claim-risk QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/claim-risk/article40/seo-agent-article-draft-claim-risk-qa-20260623T070721Z.json`
- Claim-risk QA sha256:
  `3f23722669c1fe60609210a30bb98dbb939ba4de7aa1d0d5857f36bb4998df6e`
- Preview/runtime QA:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/preview-runtime/article40/seo-agent-article-draft-preview-runtime-qa-20260623T070723Z.json`
- Preview/runtime QA sha256:
  `f1a59ca3acfef520f5e3ad900dce0bd3821a24f2ee5d06636f4ca6f28ccc1e00`

TDK review:

- Proposed SEO title:
  `霍兰德职业兴趣测试准吗？RIASEC六型和职业选择 | FermatMind`
- Title length: 38 characters.
- Proposed SEO description:
  `用一篇看懂 RIASEC 六型、霍兰德代码和职业兴趣测试结果：它能帮你发现适合探索的工作环境，但不能直接决定职业。`
- Description length: 57 characters.
- Claim risk: low to medium. The title uses the query-intent phrase `准吗`,
  while the description keeps the boundary with `不能直接决定职业`.
- Recommended wording improvement:
  replace `发现适合探索的工作环境` with `发现值得探索的工作环境` to reduce
  certainty around fit.
- FAQ status: not publish-ready. The current `proposed_faq_items` are internal
  review placeholders, not user-facing FAQ content.

## Batch 3/40 QA Summary

Batch write:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/cms-draft-write/20260623T-gsc-next-batch-limit5-execute/seo-agent-controlled-cms-draft-write-20260623T070634Z.json`
- Sha256:
  `754967ad474898a49408827c02f831187232ef8c089f4332a2bc53bbdac93bd5`
- Result:
  `rows_created=2`, `rows_skipped_existing=3`, `rows_failed=[]`

Batch QA:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/batch-qa/seo-agent-gsc-batch-draft-qa-support-20260623T070718Z.json`
- Sha256:
  `65162490b2eac4fc0c9744e1669d4b4182456e6df8378b1e86979c63ea9eb1c8`
- Status:
  `review_required`

Publish gate:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-closeout-postdeploy/20260623T-next-batch-limit5/publish-gate/seo-agent-gsc-draft-publish-gate-readiness-20260623T070723Z.json`
- Sha256:
  `cb7f694b7d214dfb5b32dbb5de28a216305654c84894fe97378d7495942e8a6e`
- Result:
  `publish_ready_count=2`
- Gate status:
  `review_required`

Reason for review hold:

- `article:3` and `article:40` pass readback, claim-risk, and preview/runtime
  QA.
- Their FAQ payloads are still placeholder review hints and should not be
  published as public FAQ/schema content.
- The gate artifact emits publish approval phrases, but operator policy should
  hold publish until FAQ is repaired or explicitly excluded from publish.

## Remaining Unwritten Candidates

The package still has two candidates that have not received SEO Agent draft
write canaries in this GSC cohort:

- `article:8:zh-CN`
- `article:51:zh-CN`

Recommended write strategy:

- Use a bounded canary that does not publish.
- Because the writer is package-order based and existing candidates are skipped,
  a future writer run may need a higher limit to reach candidates 6 and 7.
- Preflight must explicitly verify expected skips and expected new rows before
  execute approval.

## Recommended Next Task Order

### 0. Keep readmodel and SEO Agent CMS lanes separate

Do not merge the GSC readmodel foundation lane with the SEO Agent CMS draft or
publish lane in the same execution window.

Recommended ownership:

- GSC readmodel window:
  - only generate or consume new sanitized GSC artifacts;
  - run read-only bounded import readiness;
  - run exact-approved controlled import canaries;
  - run readback/idempotency evidence;
  - stop before CMS, publish, Search Channel, IndexNow, sitemap, `llms.txt`, or
    scheduler actions.
- SEO Agent window:
  - continue CMS draft write/readback/claim-risk/preview QA;
  - repair or exclude FAQ payloads;
  - run publish gate readiness;
  - request separate exact approval for any publish, URL Truth, Search Channel,
    IndexNow, or scheduler step.

Current readmodel recommendation:

- Do not run another write against artifact
  `66833c07bafe5a5a0c23c6870cfed713057e0349c6800caee02ae38b92b934c0`.
- If more readmodel evidence is needed, first produce a new sanitized GSC
  artifact with a larger bounded preview, then run dry-run/readback before any
  controlled write approval.

### 1. Repair or exclude FAQ for `article:3` and `article:40`

Do not publish these two revisions while their FAQ items are placeholder review
text.

Preferred options:

1. Append repaired draft revisions with user-facing, claim-safe Chinese FAQ.
2. Or create a scoped publish path that excludes FAQ from public write/schema if
   the intent is TDK-only publication.

After repair or exclusion:

1. Run `seo-agent:cms-draft-readback-qa`.
2. Run `seo-agent:article-draft-claim-risk-qa`.
3. Run `seo-agent:article-draft-preview-runtime-qa`.
4. Run `seo-agent:gsc-draft-publish-gate-readiness`.
5. Request separate exact publish approval for one article only.

### 2. Publish next single article canary

Do not batch publish.

Lowest-risk next publish candidate:

- `article:34:en` revision `70`

Rationale:

- English page, lower locale/claim nuance risk.
- Already has single-target readback success and claim-risk success.
- Previously passed publish gate rerun as part of the 37/34 pair.

Second candidate:

- `article:37:zh-CN` revision `69`

Rationale:

- It passed readback and claim-risk, but Chinese TDK/FAQ should receive one more
  human review before publish.

Hold until FAQ repair or exclusion:

- `article:3:zh-CN` revision `72`
- `article:40:zh-CN` revision `73`

### 3. Continue draft write canary for remaining candidates

After the 3/40 FAQ decision, continue the remaining two candidates:

- `article:8:zh-CN`
- `article:51:zh-CN`

Required steps:

1. Run writer dry-run and save artifact.
2. Confirm expected skips and expected new rows.
3. Request exact CMS draft write approval.
4. Execute write.
5. Run readback QA, claim-risk QA, preview/runtime QA, and publish gate.
6. Keep publish, URL Truth, sitemap, Search Channel enqueue, and live submit as
   later separate gates.

### 4. Investigate article URL Truth persistence/readback gap

Before using URL Truth as a reliable follow-on for more article search
propagation, investigate why the article 41 URL Truth import evidence recorded
one row but current production `seo_urls` / `seo_url_entities` readback is empty.

This should be a read-only root-cause task first. Do not re-import or rewrite
URL Truth until the gap is understood.

### 5. Observation after IndexNow

For article 41, do not re-submit IndexNow.

Next observation should be read-only:

- GSC page/query observation after enough lag.
- Runtime canonical and metadata smoke.
- Search Channel duplicate-state check only if needed.

## Gates That Must Stay Separate

The following actions require separate exact approval phrases:

- CMS draft write.
- Draft payload repair.
- CMS publish.
- URL Truth import/write.
- Sitemap or `llms.txt` release.
- Search Channel enqueue.
- Search Channel queue approval.
- IndexNow or any live search submission.
- Google Indexing API or GSC URL Inspection action.
- Scheduler or queue worker activation.
- Production deploy.

## Negative Guarantees for This Handoff

This document creation performs no production operation and no runtime mutation:

- no CMS write
- no CMS publish
- no URL Truth write
- no sitemap or `llms.txt` mutation
- no Search Channel enqueue
- no live search submission
- no indexing request
- no scheduler activation
- no queue worker start
- no external model API call
- no live GSC API call
- no frontend mutation

## Suggested Follow-up Prompts

FAQ repair / exclusion planning:

```text
Prepare a bounded plan for article:3:zh-CN revision 72 and article:40:zh-CN
revision 73 FAQ cleanup. Keep it backend-only. Do not publish. Do not enqueue
search. Decide whether to append repaired draft revisions with real claim-safe
Chinese FAQ or to exclude FAQ from publish scope, then run read-only QA.
```

Next English publish canary:

```text
Run production publish canary dry-run for article:34:en revision 70 using write
evidence sha256 ec4837ed026e1ad3e133b883b1c351814a70103e6da12d4f7646944c7dca4974.
Dry-run only. No URL Truth, sitemap, IndexNow, search, indexing, or scheduler.
Stop for separate execute approval.
```

Remaining draft write canary:

```text
Run production dry-run/preflight for the remaining GSC cohort draft candidates
article:8:zh-CN and article:51:zh-CN from package sha256
889108891858699267f825351335cb8094c7733dcec169966f50bba2e0bdf416. Confirm
expected skips and expected new draft rows. Do not execute write until separate
exact approval.
```

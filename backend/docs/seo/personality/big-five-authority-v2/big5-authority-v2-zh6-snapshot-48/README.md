# Big Five Authority V2 — ZH6 final public snapshot 48

本包锁定一个且仅一个 cohort：中文 Big Five Hub，以及开放性、尽责性、外向性、宜人性、神经质 5 个维度页。它是后续 working revision 的内容输入，不是公开运行时权威，也不授权任何 CMS、数据库、媒体、发布或 discoverability 动作。

## 结果

- 6 个 public snapshot，顺序与 canonical identity 固定。
- FAQ 只保存在每页独立 `faq` 字段：Hub 5 条，5 个维度页各 6 条，共 35 条。
- FAQ 去重按 `asset_id + normalized question` 执行；同一页面内规范化后不得重复。Hub 普通正文中不得存在 FAQ section，也不得重复完整 FAQ answer。
- 每页恰好 3 个读者可见来源，共 18 个 source rows；URL、引用标签、限制说明和 claim mapping 均锁定。
- 公开 title、summary、正文、FAQ 和来源字段中，`资产地图`、`CMS`、`backend`、`schema`、`JSON-LD`、`sitemap`、`llms`、working revision、promotion 与审核流程措辞命中数为 0。
- 旧 PR47 的 5 个维度页映射了被 PR05 ledger 禁止公开使用的 `claim.big_five.taxonomies_not_interchangeable`。新 snapshot 删除该 claim 和面向读者的 BFI-2 taxonomy comparison，并把 IPIP 官方导航来源绑定到已允许、适用于 domain 的 `claim.big_five.hierarchical_domains_and_facets`。最终来源权威完成度为 6/6。
- 旧候选页、旧签核与历史生成物保持不可变。旧人工签核只作为 predecessor evidence；由于正文边界和 FAQ snapshot 发生变化，必须对新 SHA 重新确认一次。

## SHA

| 锁 | SHA-256 |
| --- | --- |
| cohort snapshot | `f724913f5cdd5fcd33b7e899e3bd8c7f9f003919c12d5631572d5ddebc4265fa` |
| package payload | `0c009c77310fb6ca8d67cf3fac2b85a56ecb892e5b6b20d56ee41de103e910d7` |
| package file | `b8206a045e100aed1016e24d4266ee8d75fb82b38496213f892a9dff0ed7eb5d` |

`package_payload_sha256` 是包核心对象的确定性 JSON hash；`package_file_sha256` 另外锁定带格式和换行的完整文件。人工确认必须同时绑定三者。

## 精确人工确认

在 `exact-snapshot-confirmation.json` 仍为 `pending_exact_human_confirmation` 时，Task 48 不得提交、推送或进入 working revision。要求 CMS 操作者 `admin_user:1` 原样回复：

> 我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；cohort_snapshot_sha256=f724913f5cdd5fcd33b7e899e3bd8c7f9f003919c12d5631572d5ddebc4265fa；package_payload_sha256=0c009c77310fb6ca8d67cf3fac2b85a56ecb892e5b6b20d56ee41de103e910d7；package_file_sha256=b8206a045e100aed1016e24d4266ee8d75fb82b38496213f892a9dff0ed7eb5d；CMS reviewer_admin_user_id=1。

该确认只批准 6 页 snapshot、35 条 FAQ、18 个可见 source rows 和 claim boundaries。它不批准 CMS/database write、working revision、媒体、promotion、publication、indexability、sitemap、llms、schema、deploy、cache 或 Search。

## 验证

人工确认前：

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/build-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/validate-package.mjs
```

人工确认写入独立 confirmation record 后：

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/validate-confirmation.mjs
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/SEO/BigFiveAuthorityV248Test.php --no-ansi
cd backend && vendor/bin/pint --test tests/Feature/SEO/BigFiveAuthorityV248Test.php
ruby -e "require 'yaml'; YAML.load_file('docs/codex/pr-train.yaml'); puts 'yaml ok'"
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
git diff --check
```

## Repository rule impact

无 runtime authority 变化。CMS/backend 继续是公开内容、revision、审核、媒体、promotion 和 discoverability 的唯一权威；本 PR 只增加生成式内容 snapshot、独立确认记录、验证器和 focused contract，不执行任何 production/CMS write。

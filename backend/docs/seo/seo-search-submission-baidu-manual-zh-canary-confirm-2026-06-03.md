# SEO Search Submission Baidu Manual zh Canary Confirmation

Date: 2026-06-03

PR train item: `SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-CONFIRM-01`

Related prior item: `SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01`

## Scope

This docs-only follow-up records the post-operator visible success state for the zh-CN canary manual URL submission in Baidu Search Resource Platform.

The prior report remains historically accurate for the earlier Codex-run attempt: Baidu security verification blocked confirmation at that time. After that report and PR were merged, the human operator completed the Baidu verification and submission flow.

## Confirmed URL

Visible submitted value:

```text
fermatmind.com/zh/articles/mbti-vs-holland-career-choice
```

Canonical zh-CN canary URL represented by that value:

```text
https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice
```

## Visible Baidu State

At approximately `2026-06-03T19:27:00+08:00`, Codex rechecked the already logged-in Chrome Baidu Search Resource Platform page after the human operator completed the verification/submission flow.

The visible Baidu dialog showed:

```text
完成
链接提交成功
确定
```

Result:

```text
GO: Baidu manual zh-CN canary URL submission confirmed.
```

## Boundary Verification

- Submitted zh-CN canary URL: yes, confirmed by visible Baidu success dialog.
- Submitted English canary URL: no.
- Used Baidu API token: no.
- Called IndexNow: no.
- Used Google URL Inspection request indexing: no.
- Created Search Channel queue records: no.
- Mutated CMS data: no.
- Published or unpublished content: no.
- Modified content: no.
- Modified runtime code: no.
- Deployed: no.
- Read, printed, stored, or exposed cookies/browser storage/account email/token/API credential/secret values: no.

## Current Train State

The current SEO canary search-submission state is:

- GSC sitemap submission: confirmed successful in the prior GSC sitemap report.
- Baidu zh-CN manual URL submission: confirmed successful by this follow-up.
- Baidu English URL submission: not performed and remains out of scope.
- IndexNow: not performed and remains blocked until separately authorized.
- Backend Search Channel queue: not used; CMS article canonical candidacy remains a separate follow-up.

## Next Step

The recommended next item is a post-submit signals review after a reasonable search-platform processing window:

```text
SEO-SEARCH-SUBMISSION-CANARY-POSTSUBMIT-SIGNALS-01
```

Do not perform additional search submissions, IndexNow, Search Channel queue writes, CMS mutations, publish/unpublish actions, runtime edits, or deploys without separate authorization.

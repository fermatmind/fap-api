# SEO Search Submission Baidu Manual zh Canary

Date: 2026-06-03

PR train item: `SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01`

## Scope

This run used the already logged-in Chrome Baidu Search Resource Platform session to attempt a manual submission for the zh-CN SEO canary URL only.

Allowed URL:

- `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`

Explicitly forbidden and not performed:

- English canary URL submission.
- Baidu API token usage.
- IndexNow calls.
- Google URL Inspection request indexing.
- Search Channel queue record creation.
- CMS mutation.
- Publish or unpublish.
- Runtime code edits.
- Deployment.

## Platform

- Platform: Baidu Search Resource Platform.
- Visible product area: link submission / normal inclusion.
- Visible site context: FermatMind site account label.
- No account email, cookie, browser storage, token, API credential, or secret value is recorded in this report.

## Attempt

At approximately `2026-06-03T19:07:00+08:00`, Codex filled the manual link submission input with the zh-CN canary URL:

```text
https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice
```

Codex clicked the visible submit button once for that URL. A previous attempt earlier in the same task had also reached the same Baidu security verification gate after submit.

## Visible Result

NO-GO: Baidu manual zh URL submission is not confirmed.

After submit, Baidu displayed a security verification challenge requiring a human slider action before the operation could continue. The visible challenge text was a Baidu security verification prompt asking the operator to complete the verification before continuing.

Because the security verification was not completed by a human operator during this run, there was no visible Baidu success state, no submitted timestamp, and no accepted-result confirmation for the zh-CN canary URL.

## Boundary Verification

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

## Follow-Up

The next Baidu action must be human-operator gated:

1. A human operator completes the Baidu security verification in the logged-in Chrome session.
2. The zh-CN canary URL is submitted through the Baidu Search Resource Platform UI.
3. The visible success or error result is recorded in a follow-up docs-only report.

Do not treat the zh-CN canary URL as successfully submitted to Baidu until a visible Baidu success state is observed.

The English canary URL remains out of scope for Baidu manual submission unless separately authorized.

# Codex Review Checklist

Use this before merging or handing off any PR card.

## Scope

- One PR equals one card.
- Changed files are only under the declared output path unless explicitly authorized.
- No neighboring lane is implemented early.

## Safety

- No CMS write/import/publish.
- No Search Channel or provider submission.
- No sitemap, `llms`, schema, hreflang, URL Truth, runtime/API, DB, fap-web, or deploy mutation.
- No private/result/order/payment/share/history/token/session/admin/ops URLs as public SEO targets.
- No raw GSC payload, credentials, raw query, raw URL, or screenshot-derived metric as formal evidence.

## Evidence

- Runtime evidence is direct, bounded, and marked missing when incomplete.
- `llms-full` timeout or partial output is not treated as pass.
- GSC/seo_intel evidence is blocked unless `GscDataQualityGate` pass is proven.
- PR merge is not treated as runtime completion.

## Claim Boundaries

- No medical, diagnosis, hiring, admission, salary, employment, career-success, official-certification, ranking, price, review, endorsement, or superiority claims are introduced.
- IQ/EQ/gaokao/career/competitor tasks require Codex review.

## Required Checks

- `python3 -m json.tool <output>/scan_manifest.json >/dev/null`
- `git diff --check`
- `git diff --cached --check`
- GitHub required checks green before merge.

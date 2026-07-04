# IQ V1 Landing Copy Claim Safety Check

Status: `draft_review_only`
PR: `IQ-V1-LANDING-COPY-CMS-DRY-RUN-01`

## Result

`PASS_DRAFT_REVIEW_ONLY`

## Checked Claims

- Free IQ-style reasoning practice: present.
- 30 original items: present.
- About 20 minutes: present.
- Raw score, accuracy, completion time, and dimension performance: present.
- Free complete V1 result: present.
- Formal intelligence credential: blocked.
- Population ranking: blocked.
- Clinical or diagnostic interpretation: blocked.
- External high-IQ society affiliation: blocked.
- School, hiring, salary, or career decision evidence: blocked.
- Paid report or downloadable credential promise: blocked.

## Authority

The dry-run package is not runtime authority. Runtime copy must come from backend CMS/public API only after separate owner approval and controlled CMS workflow.

## Validation Commands

```bash
git diff --check
python3 -m json.tool backend/docs/seo/iq-v1/landing-copy-cms-dry-run/iq-v1-landing-copy-cms-dry-run.v1.json >/tmp/iq-v1-landing-copy-cms-dry-run.pretty.json
rg -n "draft_review_only" backend/docs/seo/iq-v1/landing-copy-cms-dry-run
rg -n "cms_write_allowed\": false" backend/docs/seo/iq-v1/landing-copy-cms-dry-run/iq-v1-landing-copy-cms-dry-run.v1.json
```

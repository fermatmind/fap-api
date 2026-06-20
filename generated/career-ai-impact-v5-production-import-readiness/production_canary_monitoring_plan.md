# AI Impact v5 Production Canary And Monitoring Plan

## API Canary

After import, sample at least these slugs in zh-CN and en:

- accountants-and-auditors
- actuaries
- air-traffic-controllers
- advanced-practice-psychiatric-nurses
- administrative-law-judges-adjudicators-and-hearing-officers
- airline-pilots-copilots-and-flight-engineers
- writers-and-authors
- zoologists-and-wildlife-biologists
- wind-turbine-technicians
- woodworking-machine-setters-operators-and-tenders-except-sawing

For each endpoint, verify HTTP 200, reader-safe payload, no `evidence_id`, no `row_hash`, no `source_id`, no `search_projection`, and no AI job-loss/income-prediction wording.

## Frontend Canary

- Check the same 10 slugs on zh/en career detail pages.
- Verify the AI Impact block renders where status allows it.
- Verify API 404 or flag off still fails closed.
- Verify no sitemap, llms.txt, canonical, noindex, or JSON-LD changes are introduced by the import.

## Monitoring

- Watch API 4xx/5xx for `/api/v0.5/career/jobs/*/ai-impact-asset`.
- Watch page render errors for career job detail pages.
- Watch logs for projection leakage guards.
- Keep rollback owner available until post-import SEO safety audit passes.

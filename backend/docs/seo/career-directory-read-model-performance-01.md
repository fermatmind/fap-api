# CAREER-DIRECTORY-READ-MODEL-PERFORMANCE-01

## Outcome

The public Career directory now consumes a versioned, backend-authoritative read model instead of rebuilding family joins, eligibility projection, localized rows, sort order, and default facets during every HTTP request.

The read model is derived only from the existing public Career job-index authority. It contains lightweight directory fields and a normalized internal search string; the internal search field is removed before public API serialization.

## Performance gate

The focused test generates real in-memory fixtures at both 1,046 and 10,000 rows. It records and asserts:

- warm p95 and p99;
- database query count;
- first-page response bytes;
- peak memory delta;
- offline read-model build duration;
- first-page item count and public authority count.

The warm p95 budgets are 300 ms for 1,046 rows and 500 ms for 10,000 rows. The first-page response remains capped at 50 lightweight rows.

## Boundaries

- CMS/backend remains the content and publication authority.
- No public slug, indexability, canonical, sitemap, llms, JSON-LD, or held-slug rule changes.
- No production migration, cache mutation, import, publishing, deployment, Search Channel, or URL submission action.
- Cache single-flight, LKG, atomic active-version switching, scheduler warming, and runtime alerts remain deferred to their declared follow-up PRs.

## Repository rule impact

Career directory content ownership is unchanged. This PR changes only the backend read path used to project already-authorized public Career rows.

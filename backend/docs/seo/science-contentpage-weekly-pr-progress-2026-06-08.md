# Science ContentPage Weekly PR Progress

Date: 2026-06-08
Mode: docs-only progress closeout

## Decision

**Science ContentPage non-public draft import is complete. Publish and discoverability remain NO-GO.**

The recent PR train moved the six-page Science package from planning and route authority scans into backend-safe CMS draft storage. Five pages now exist as non-public, non-indexable CMS drafts. `/method-boundaries` remains an existing-authority page and was not created or overwritten.

## Backend PR Progress

| PR | Status | Progress meaning |
|---|---|---|
| fap-api #1922 | Merged | Planned the ContentPage field mapping and draft-only constraints. |
| fap-api #1944 | Merged | Added Science ContentPage dry-run validation with no writes. |
| fap-api #1948 | Merged | Added operator review readiness gate. |
| fap-api #1955 | Merged | Added pre-import QA gate for claims, routes, FAQ, private URLs, and exposure defaults. |
| fap-api #1958 | Merged | Reconciled `/method-boundaries` as existing-authority revision-only. |
| fap-api #1959 | Merged | Added first-class publish/operator/claim/schema safety fields. |
| fap-api #1960 | Merged | Locked pre-real-import contract as dry-run-only until a later approved command. |
| fap-api #1967 | Merged | Summarized Science line status before real import enablement. |
| fap-api #1973 | Merged | Packaged the GPT-5.5 Pro review draft into review audit, six page candidates, and operator review artifacts. |
| fap-api #1976 | Merged | Confirmed Science reliability route governance and canonical route selection. |
| fap-api #1978 | Merged | Recorded production no-write dry-run gate. |
| fap-api #1981 | Merged | Enabled controlled non-public draft import command with exact approval phrase. |
| fap-api #1983 | Merged | Fixed production parser blocker by removing the missing Symfony YAML runtime dependency. |

## Frontend PR Progress

| PR | Status | Progress meaning |
|---|---|---|
| fap-web #1060 | Merged | Reconciled method-boundaries authority from the frontend side. |
| fap-web #1062 | Merged | Added guarded root route wrappers for Science ContentPage slugs. |
| fap-web #1064 | Merged | Added Science claim boundary gate. |
| fap-web #1065 | Merged | Added visible-FAQ-only schema gate. |
| fap-web #1066 | Merged | Added Science discoverability gate tests. |
| fap-web #1068 | Merged | Kept Science drafts out of footer, sitemap, and llms until eligibility gates pass. |
| fap-web #1069 | Merged | Converged static sitemap generation to backend source; Science drafts remain excluded by backend state. |

## Production Closeout Evidence

Production no-write dry-run after deploy:

```text
ok=1
mode=dry_run
dry_run=1
writes_committed=0
pages_seen=6
planned_create_count=5
skipped_existing_count=0
authority_revision_only_count=1
blocked_count=0
created_count=0
publish_allowed=0
discoverability_allowed=0
```

Controlled execute after exact approval phrase:

```text
ok=1
mode=execute
dry_run=0
writes_committed=1
pages_seen=6
planned_create_count=5
skipped_existing_count=0
authority_revision_only_count=1
blocked_count=0
created_count=5
publish_allowed=0
discoverability_allowed=0
```

Idempotency dry-run after execute:

```text
ok=1
mode=dry_run
dry_run=1
writes_committed=0
pages_seen=6
planned_create_count=0
skipped_existing_count=5
authority_revision_only_count=1
blocked_count=0
created_count=0
publish_allowed=0
discoverability_allowed=0
```

## Imported Draft Rows

| Slug | Status | Public | Indexable | Publish allowed | Claim gate | Schema |
|---|---|---:|---:|---:|---|---|
| `/science` | draft | false | false | false | not_reviewed | disabled |
| `/item-design-notes` | draft | false | false | false | not_reviewed | disabled |
| `/reliability-validity` | draft | false | false | false | not_reviewed | disabled |
| `/data-privacy` | draft | false | false | false | not_reviewed | disabled |
| `/common-misconceptions` | draft | false | false | false | not_reviewed | disabled |

`/method-boundaries` was skipped as existing-authority revision-only.

## Adjacent PR Lines

| Line | Recent PRs | Status |
|---|---|---|
| Help ContentPage service fields and draft review | fap-api #1947, #1956, #1962, #1966, #1975, #1984, #1985-#1995 | Progressed through service-field production verification, policy sync, operator review R2, approval R2, and publish preflight blocker repair. This is adjacent but not a Science publish gate. |
| RIASEC article/CMS media line | fap-api #1937, #1943, #1949, #1957, #1961, #1965, #1968, #1971, #1974, #1977, #1980; fap-web #1070, #1071 | Progressed through review, media, locale, publish smoke, and search-submission preflight. This does not authorize Science discoverability. |
| DailyGiving proof/foundation line | fap-api #1941, #1950, #1969, #1992; fap-web #1059, #1063, #1067 | Progressed proof handling and line summaries while preserving noindex/search-amplification boundaries. This remains separate from Science ContentPage. |

## Remaining NO-GO Gates

- No public publish.
- No sitemap inclusion.
- No llms inclusion.
- No footer exposure.
- No search submission.
- No social distribution.
- No FAQ schema until visible FAQ/schema eligibility review is approved.
- No publish until operator, claim, science, and legal review decisions are recorded.

## Next Allowed Work

1. CMS operator review for the five imported drafts.
2. Claim/science/legal review closeout for each draft.
3. Publish preflight only after approvals.
4. Discoverability gate only after publish eligibility is true and public exposure is explicitly approved.

# Directory Detail Pending Display Asset Authority

## Purpose

The career jobs list must not keep a directory draft occupation as a public directory stub when the backend can already serve a validated display-asset-backed detail bundle for that same slug.

This authority separates two states:

- Directory draft without validated detail evidence remains a noindex `public_directory_stub`.
- Directory draft with a valid display asset, runtime projection indexability, and release gate pass is listed as a detail-ready career item.

## Required evidence

A directory draft item can be upgraded from `detail_page_unavailable` only when all checks pass:

- The runtime projection enables the detail route.
- The runtime projection marks the slug robots-indexable.
- The runtime projection release gate passes.
- The slug is not an explicit manual hold such as `software-developers`.
- The occupation has exactly one `us_soc` and exactly one `onet_soc_2019` crosswalk row with non-empty source codes.
- Exactly one ready `career_job_public_display` asset exists for the occupation and canonical slug.
- The display asset is `display.surface.v1` / `v4.2`, has 24 ordered components, and has both `zh` and `en` page payloads.
- The display surface builder accepts the public page contract without forbidden public keys or product schema leakage.

## Non-goals

This change does not publish new rows, mutate production data, weaken rollout gates, or turn CN proxy / manual-hold rows into canonical jobs. It only makes the list surface use the same validated display-asset authority that the detail route already enforces.


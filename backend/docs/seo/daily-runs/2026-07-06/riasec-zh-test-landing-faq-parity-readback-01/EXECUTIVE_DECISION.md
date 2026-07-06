# RIASEC ZH Test Landing FAQ Parity Readback Decision

Date prepared: 2026-07-06

PR/task: `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01`

Final verdict: `OPERATOR_HELD_REAUTH_REQUIRED`

## Decision

Do not run the RIASEC zh test landing FAQ parity readback in this train without fresh authorization.

This card was included in the 33-card train, but prior task direction marked it as temporarily deferred / not the current next step. To preserve the train's "do not stop" rule, this PR records a generated-only held report and sidecar entry, then stops implementation for this scope.

## Required Before Readback

Fresh authorization must specify:

1. exact route
2. whether runtime public fetch is allowed
3. whether API/CMS readback is allowed
4. whether side-by-side visible FAQ vs FAQPage JSON-LD parity is the only allowed check
5. whether any mismatch should stop or only be reported

## Explicit Non-Actions

This PR does not fetch production, query CMS/API, edit FAQ, edit JSON-LD, change sitemap/`llms`, modify canonical/noindex, write CMS, edit frontend, import production data, or deploy.

# Next Exact Authorization Prompts

Use only after GSC live read-model quality is proven.

## CTR Eligibility Recheck

Authorize:

`CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-02`

Scope:

- read only from passable `seo_intel` GSC read-model rows;
- prove `GscDataQualityGate.status=pass`;
- list eligible pages without writing CMS or Search state.

Forbidden:

- no title/meta/H1/FAQ/CTA edits;
- no Search submission;
- no deploy.

## CTR Dry-Run Queue

Authorize only after eligibility passes:

`CTR-TDK-REPAIR-DRYRUN-QUEUE-01`

Scope:

- generated-only candidate queue;
- no CMS writes.

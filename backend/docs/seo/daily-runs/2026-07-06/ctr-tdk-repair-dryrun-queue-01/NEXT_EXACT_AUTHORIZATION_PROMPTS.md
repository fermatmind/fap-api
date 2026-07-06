# Next Exact Authorization Prompts

Use only after GSC live read-model quality and CTR eligibility pass.

## TDK Dry-Run Queue Recheck

Authorize:

`CTR-TDK-REPAIR-DRYRUN-QUEUE-02`

Scope:

- generate a dry-run-only queue from passable `seo_intel` rows;
- include candidate evidence and no-write TDK proposals;
- generated docs only.

Forbidden:

- no CMS write;
- no title/meta/H1/FAQ runtime mutation;
- no Search submission;
- no deploy.

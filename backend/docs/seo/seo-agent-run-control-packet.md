# SEO Agent Run Control Packet

Task: `SEO-AGENT-RUN-CONTROL-PACKET-02`

This contract defines the standard JSON packet for each FermatMind SEO Agent run. It is a control-plane artifact only. It does not enable runtime execution, CMS writes, Search Channel submissions, indexing requests, queue workers, scheduler jobs, migrations, or production environment changes.

## Purpose

Each SEO Agent run must preserve a durable packet that explains:

- why the run started
- what evidence was used
- which model or reviewer produced recommendations
- which actions are forbidden
- what approval state the run is in
- which output artifacts were generated

The packet is the handoff boundary between read-only discovery, Codex review, CMS draft generation, and any later controlled execution.

## Required Shape

The packet must use schema `seo-agent-run-control-packet.v1` and include:

- `run_id`
- `run_mode`
- `trigger`
- `scope`
- `input_refs`
- `evidence_refs`
- `model_review`
- `approval`
- `forbidden_actions`
- `allowed_actions`
- `output_artifacts`
- `negative_guarantees`
- `next_step`

## Approval States

Allowed approval states:

- `not_requested`
- `review_requested`
- `approved_for_dry_run_only`
- `approved_for_single_canary_write`
- `approved_for_publish_or_submit`
- `rejected`

Default state must be `not_requested`.

## Forbidden by Default

The following actions must stay forbidden unless a later task adds a separate exact approval gate:

- CMS write or publish
- Search Channel enqueue or submit
- indexing request
- sitemap submission
- scheduler activation
- queue worker activation
- production environment update
- direct source-code mutation
- bulk import

## Output Artifacts

Output artifacts must be identified by path, byte size, SHA256, schema version, and sanitized summary. Artifacts must not include raw query, raw URL, credential path, service-account JSON, client email, private key, token, cookie, or session values.

## Next Step

After this contract, future PRs may generate concrete packets for Codex review and CMS draft dry-run packages. Execution remains held until separate approvals are implemented.

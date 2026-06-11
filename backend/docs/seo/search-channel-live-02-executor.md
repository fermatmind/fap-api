# Search Channel Live 02 Executor

Task: `SEARCH-CHANNEL-LIVE-02-EXECUTOR`

This task adds the guarded executor needed before rerunning
`SEARCH-CHANNEL-LIVE-02-PREFLIGHT`. It does not authorize or perform a live
submission by itself.

## Runtime Contract

- Command: `seo-intel:search-channel-submit`
- Scope: exactly one existing queue item selected by `--queue-item-id`
- Supported live channels in this task: `indexnow`, `baidu_push`
- Allowed host in this task: `fermatmind.com`
- Default mode: blocked unless every live gate is explicitly enabled
- Scheduler: not registered
- Bulk submission: not supported
- Idempotency: the item is atomically claimed from `pending/dry_run_ready`
  before any provider request
- Secrets: not printed in command output or audit event payloads
- Sitemap and `llms.txt` behavior: unchanged

Dry-run command:

```bash
php artisan seo-intel:search-channel-submit --queue-item-id=1 --dry-run --json
```

Live command shape, for a later human-approved canary only:

```bash
php artisan seo-intel:search-channel-submit --queue-item-id=1 --approval-phrase="<exact phrase>" --actor=operator --json
```

The exact phrase is generated from the queue item id, channel, and canonical URL:

```text
I explicitly approve SEARCH-CHANNEL-LIVE-02 live submission for queue item <id> channel <channel> URL <canonical_url>.
```

## Required Gates

Actual live submission requires these shared gates:

- `SEO_INTEL_SEARCH_CHANNEL_LIVE_SUBMISSION_ENABLED=true`
- `SEO_INTEL_SEARCH_CHANNEL_EXTERNAL_API_CALLS_ENABLED=true`
- The exact human approval phrase is supplied

IndexNow additionally requires:

- `SEO_INTEL_INDEXNOW_LIVE_API_ENABLED=true`
- `SEO_INTEL_INDEXNOW_KEY` is present
- `SEO_INTEL_INDEXNOW_KEY_LOCATION` is present

Baidu push additionally requires:

- `SEO_INTEL_BAIDU_LIVE_API_ENABLED=true`
- `SEO_INTEL_BAIDU_SITE` is present
- `SEO_INTEL_BAIDU_PUSH_TOKEN` is present
- `SEO_INTEL_BAIDU_PUSH_ENDPOINT` defaults to `https://data.zz.baidu.com/urls`

Default config keeps these gates disabled or empty.

## Queue Item Requirements

The executor rejects the item unless it is:

- `eligibility_state=eligible`
- `approval_state=pending`
- `execution_state=dry_run_ready`
- `indexability_state=indexable`
- `claim_boundary_state=claim_safe`
- `private_flow=false`
- `source_authority` is backend-approved
- HTTPS URL under an allowed host

After an accepted IndexNow or Baidu push response, the executor sets:

- `approval_state=approved`
- `execution_state=submitted`
- `approved_by=<actor>`
- `approved_at=<timestamp>`

Baidu push sends exactly one canonical URL as `text/plain` to the configured
endpoint with `site` and `token` query parameters. A Baidu response is accepted
only when the HTTP response is successful and the JSON `success` count is at
least 1.

Failed provider calls are recorded as `execution_state=submit_failed`.

If another process has already claimed or submitted the item, the executor
blocks before any external call.

## Audit Events

The executor writes only Search Channel Queue audit events:

- `live_submission_approved`
- `live_submission_response`

Event payloads use `url_hash`, endpoint host, HTTP status, normalized submission
status, and optional exception class. They do not contain raw keys or the full
submitted URL.

## Deferred

Next task: rerun `SEARCH-CHANNEL-LIVE-02-PREFLIGHT` after this executor is
merged. The later preflight must verify the production queue item, emit the
exact approval phrase, and still perform no live submission.

# SEO Agent Weekly Draft Write Auto

Task: `SEO-AGENT-WEEKLY-DRAFT-WRITE-AUTO-BATCH10-01`

This command runs the existing weekly readonly SEO Agent chain, evaluates the generated CMS draft proposals with `seo-agent-auto-approval-policy.v1`, filters to low-risk proposals, and writes at most 10 CMS draft/revision rows.

## Command

```bash
php artisan seo-agent:weekly-draft-write-auto \
  --sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap \
  --limit=100 \
  --draft-limit=10 \
  --artifact-dir=/path/to/artifacts \
  --json
```

## Chain

1. `seo-agent:weekly-readonly-runner`
2. Read the generated `seo-agent-cms-draft-package-dry-run.v1`
3. Evaluate proposals with `seo-agent-auto-approval-policy.v1`
4. Write a filtered low-risk package artifact
5. `seo-agent:cms-draft-write --auto-approve-low-risk --execute`
6. Write `seo-agent-weekly-draft-write-auto.v1` evidence

## Boundaries

- Writes only bounded CMS draft/revision rows.
- Does not publish CMS content.
- Does not mutate published revision pointers.
- Does not enqueue Search Channel items.
- Does not submit Search Channel or Google Indexing requests.
- Does not start queue workers.
- Does not enable Laravel scheduler.
- Does not call external model APIs.
- Does not mutate frontend code.

No-op weeks are allowed: if there are no auto-approved low-risk proposals, the command writes evidence and exits successfully without CMS writes.

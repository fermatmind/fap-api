# SEO Agent Weekly Readonly Runner

`SEO-AGENT-WEEKLY-READONLY-RUNNER-01` adds a weekly-safe entrypoint for the FermatMind SEO Agent L4 chain.

## Command

```bash
php artisan seo-agent:weekly-readonly-runner \
  --sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap \
  --limit=100 \
  --artifact-dir=/path/to/artifacts \
  --json
```

## Chain

The command delegates to `php artisan seo-agent:run` and writes a wrapper evidence artifact:

1. readonly scanner artifacts
2. opportunity aggregate artifact
3. run control packet
4. Codex review handoff
5. deterministic Codex review verdict
6. CMS draft package dry-run
7. final run evidence
8. weekly readonly runner evidence

## Boundaries

This is a manual or external automation entrypoint. The PR does not enable Laravel production scheduler, start queue workers, write CMS drafts, publish CMS, enqueue Search Channel, submit Google Indexing requests, call GSC live APIs, or call external model APIs.

Activation through a real production scheduler remains a separate approval.

# SEO Agent Run Orchestrator

`SEO-AGENT-RUN-ORCHESTRATOR-01` adds the first manual L4 chain runner for FermatMind SEO Agent.

## Command

```bash
php artisan seo-agent:run \
  --sources=cms-tdk-gap,runtime-seo-qa,cms-faq-gap \
  --limit=100 \
  --artifact-dir=/path/to/artifacts \
  --json
```

## Chain

The command runs the safe local chain:

1. readonly scanner artifacts
2. opportunity aggregate artifact
3. run control packet
4. Codex review handoff
5. deterministic Codex review verdict
6. CMS draft package dry-run
7. final run evidence

## Boundaries

The orchestrator is a manual CLI entrypoint. It does not enable production scheduler, start queue workers, write CMS, publish CMS, enqueue Search Channel, submit indexing requests, call GSC live APIs, or call external model APIs.

Runtime SEO QA may perform read-only HTTP checks against configured public CMS paths when that source is selected. GSC data must enter through existing sidecar/readmodel/artifact boundaries.

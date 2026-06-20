# SEO Agent CMS TDK Gap Readonly Scanner

`SEO-AGENT-CMS-TDK-GAP-READONLY-SCANNER-01` is the first runtime opportunity source for the FermatMind SEO Agent control loop.

It scans public, published CMS article and content-page surfaces for missing SEO title, description, canonical, and indexability metadata. It only emits sanitized opportunity candidates plus run-control and Codex review handoff artifacts.

## Command

```bash
php artisan seo-agent:cms-tdk-gap-scan \
  --surface=all \
  --limit=100 \
  --artifact-dir=/opt/fermatmind/seo-agent/artifacts \
  --json
```

Supported surfaces:

- `articles`
- `content-pages`
- `all`

The command writes three sanitized JSON artifacts:

- `seo-agent-cms-tdk-gap-readonly-scanner.v1`
- `seo-agent-run-control-packet.v1`
- `seo-agent-codex-review-handoff.v1`

## Boundary

The scanner is read-only. It must not write CMS rows, publish content, enqueue search work, submit indexing requests, start queues, activate scheduler jobs, or mutate source code.

The Codex handoff is review-only. It can recommend whether a candidate is worth optimizing and what dry-run action should be considered next, but it has no execution permission.

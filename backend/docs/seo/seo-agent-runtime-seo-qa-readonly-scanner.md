# SEO Agent Runtime SEO QA Readonly Scanner

`SEO-AGENT-RUNTIME-SEO-QA-READONLY-SCANNER-01` adds a read-only runtime QA opportunity source for the FermatMind SEO Agent L4 loop.

Command:

```bash
php artisan seo-agent:runtime-seo-qa-scan \
  --source=cms-indexable \
  --limit=50 \
  --artifact-dir=/path/to/artifacts \
  --json
```

The scanner reads published, public, indexable CMS article and content-page rows, builds public URLs only in memory, and performs HTTP QA with redirects disabled. Output artifacts contain only safe paths, stable subject refs, issue codes, severity, and masked evidence.

Checks:

- HTTP status.
- Redirect status.
- Canonical tag presence and safe-path match.
- Meta robots noindex.
- X-Robots-Tag noindex.
- JSON-LD presence.

Boundaries:

- No CMS write.
- No DB write.
- No queue enqueue.
- No scheduler activation.
- No Search Channel submit.
- No indexing request.
- No GSC API call.
- No raw HTML, full URL, cookie, token, credential, or private key in artifacts.

The output candidate family is `runtime_seo_qa`. Candidates are review-only and require Codex review before any later dry-run package can be considered.

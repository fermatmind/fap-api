# SEO core entry SLO observability

`SEO-CORE-ENTRY-SLO-OBSERVABILITY-01` adds a read-only public-entry probe for the fixed FermatMind priority order:

1. L1: EN/ZH MBTI test detail.
2. L2: EN/ZH Big Five test detail.
3. L3: EN/ZH RIASEC, Enneagram, IQ, EQ, Career, and Articles entries.

The deterministic manifest is owned by `config/seo_intel.php`. It contains 16 public canonical paths and rejects query strings, fragments, non-EN/ZH paths, duplicate targets, private IP/host targets, and any path containing private-flow segments such as result, attempt, order, recovery, payment, checkout, report, or share.

## Command

From `backend/`:

```bash
php artisan seo-intel:core-entry-slo-observe \
  --concurrency=4 \
  --timeout=10 \
  --artifact-dir=storage/app/seo-intel/core-entry-slo \
  --json
```

The command performs one redirect-disabled public `GET` per manifest entry. Concurrency is bounded to 1–4 and timeout is bounded to 1–15 seconds. It writes one local sanitized JSON artifact; an unhealthy SLO returns a non-zero exit code after the artifact is written.

No scheduler is registered by this PR.

## Checks and classifications

Each result records only a safe public path, path hash, status code, derived TTFB, and derived state:

- HTTP: pass, 5xx, 4xx, redirect, unexpected, or transport error.
- TTFB: pass, breach, or unavailable, using client handler stats first.
- visible SSR: page-family marker plus non-empty H1.
- self-canonical.
- index/follow robots, including `X-Robots-Tag`.
- expected locale hreflang.
- primary CTA marker.
- delivery: fresh, last-known-good, minimal shell, or unknown.
- upstream/CMS/API state derived from delivery and transport evidence.

`http_5xx`, `thin_shell`, `canonical_drift`, and `robots_drift` are separate incident categories. The ops read model aggregates each category by L1/L2/L3 and always makes the highest-priority affected tier the alert priority.

## Artifact privacy and write boundary

The artifact does not retain response HTML, response headers, cookies, auth, query strings, secrets, private URLs, or full request URLs. Transport exceptions are reduced to the safe `transport_error` category.

The only write is the requested local JSON artifact. The observer does not:

- write database or CMS authority;
- publish content;
- change sitemap or llms authority;
- enqueue or submit Search Channel URLs;
- call GSC or Google Indexing APIs;
- activate a scheduler or queue worker;
- probe result, attempt, order, recovery, payment, checkout, report, share, or account URLs.

## Repository rule impact

This is backend read-only operational observability. CMS/backend remains the content and indexability authority; no frontend fallback, publishing authority, public URL membership, or production execution boundary changes.

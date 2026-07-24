# Query Owner URL Truth

`SEO-QUERY-OWNER-URL-TRUTH-01` adds a backend-authoritative, read-only
reconciliation contract for priority search intents. It does not select owners
from GSC, sitemap XML, frontend routes, crawler logs, or search-engine results.

## Data model

The `seo_intel` connection owns three normalized tables:

- `seo_query_families`: one stable family key per locale, with a backend/CMS
  authority reference and `active` or `hold` state.
- `seo_query_family_queries`: privacy-safe GSC `query_hash` mappings. A query
  hash can belong to only one family for a source engine.
- `seo_query_url_bindings`: URL hashes and one of six explicit roles:
  `primary_owner`, `supporting_url`, `alternate_locale`, `redirect_alias`,
  `conflict`, or `hold`.

The tables do not store raw queries, attempt IDs, result URLs, order/payment
identifiers, or other private-flow identifiers.

## Fail-closed rules

An active family passes only when:

1. exactly one `primary_owner` exists;
2. that owner resolves to a non-private, indexable `seo_urls` row from an
   allowed backend/CMS authority, including the backend scale catalog for test
   detail routes;
3. the owner is an existing backend sitemap member;
4. every supporting URL and redirect alias points to that owner;
5. every required alternate-locale binding resolves to the one primary owner
   of the same family in the paired locale and the pair is reciprocal; and
6. every active query hash and URL binding is backend/CMS authoritative.

More than one primary owner, an explicit conflict binding, a private URL,
authority drift, a missing sitemap owner, or a mismatched internal-link or
hreflang target blocks the family. A deliberate `hold` remains distinct from a
conflict and also prevents the report from returning success.

Redirect aliases may be absent from `seo_urls`, but they must provide a safe
public path, come from `backend_redirect_catalog`, target the primary owner,
and remain outside sitemap membership.

## Read-only report

```bash
cd backend
php artisan seo-intel:query-owner-url-truth-report --json
php artisan seo-intel:query-owner-url-truth-report \
  --family=mbti-direct \
  --json
```

The report emits only family identifiers, role counts, URL hashes, convergence
states, and issue codes. It performs no database write, CMS mutation,
canonical/publication/indexability change, sitemap or internal-link mutation,
Search Channel enqueue, URL submission, or external API call.

## Migration and release boundary

The migration in this PR is code-only. It is exercised only against local/test
SQLite during PR validation. This task does not run a production migration,
seed production query owners, deploy code, change CMS content, or alter public
URLs. Production migration/deployment and any future authority data import
remain separately controlled operations.

## Repository rule impact

This adds a backend/CMS-authoritative SEO governance surface. It does not move
content authority, publication authority, canonical generation, sitemap
membership, hreflang generation, or internal-link mutation into `seo_intel`;
the new read model only reconciles those existing backend-owned signals.

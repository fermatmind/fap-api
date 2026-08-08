# Greenfield current-published baseline transfer

This runbook transfers the current public FermatMind product/content baseline from the retiring production database without carrying historical operations data.

## Boundary

The package is an allowlisted snapshot of current published Articles, Content Pages, Landing Surfaces, Personality content, Topics, Career authority/CMS rows, public media metadata and objects, aliases, and active SKUs. Test definitions, questions, scoring rules, roles, permissions, email templates, and other repository-owned system configuration are rebuilt from the exact backend release through migrations, seeds, and config.

The package permanently excludes users, administrators, orders, payments, refunds, subscriptions, attempts, answers, results, reports, invitations, queues, failed jobs, sessions, cache, locks, email outbox, analytics/audit/log data, experiment assignments, DailyGiving records, private proof/media paths, and non-current revisions. Administrator actor IDs are cleared; public author display names and publication timestamps remain.

The source step opens a direct MySQL `READ ONLY` transaction with a consistent snapshot. The audited PHP exporter is streamed to `php` over SSH stdin and streams JSONL back to the runner. It creates no source-server file and never boots Laravel or runs Artisan on the source host.

## Protected workflow

Workflow: `Backend Greenfield Current Baseline`

Both modes require:

- the exact latest `main` control-plane SHA;
- the exact source active release SHA;
- the exact current Career runtime projection SHA-256;
- the workflow-generated exact operator approval sentence.

The production Environment provides a dedicated, pinned read-only source identity:

- `GREENFIELD_SOURCE_SSH_PRIVATE_KEY`
- `GREENFIELD_SOURCE_SSH_KNOWN_HOSTS`
- `GREENFIELD_SOURCE_USER`
- `GREENFIELD_SOURCE_PORT`
- `GREENFIELD_SOURCE_HOST`
- `GREENFIELD_SOURCE_PATH`

These values are intentionally separate from generic production deployment SSH secrets. Baseline export remains bound to the verified current-published source while deployment targets move between cloud providers; changing the Greenfield source identity cannot redirect or authorize a production deployment.

`preflight` builds and verifies the package without downloading media. It uploads only a sanitized receipt containing the package identity, dataset counts, Career projection summary, public-media count/size summary, and the opaque public-media host-set SHA-256.

`export` requires that exact host-set SHA-256 and downloads every selected public media object through credential-free HTTPS with redirects disabled. It verifies object bytes and SHA-256, rebuilds the deterministic package, and uploads the package plus sanitized receipt. Artifacts expire after three days.

The workflow fails closed unless the frozen public baseline counts and Career boundaries match, including 1046 occupations, 2092 rows for each bilingual Career asset family, and the exact 342 tracked / 30 public / 622 blocked / 2 quarantined runtime projection.

## Package verification

```bash
cd backend
php artisan greenfield:baseline:verify \
  --package=/absolute/path/to/package \
  --expected-package-sha256=<package_sha256> \
  --json
```

Verification checks the schema, manifest identity, complete physical-file inventory, checksums, allowlisted dataset counts, forbidden fields and actor IDs, current-revision uniqueness, Career projection boundary, and media object bytes/hashes.

## Isolated target dry-run

Run migrations and repository baseline seeds against a new isolated MySQL database first. Then run:

```bash
cd backend
php artisan greenfield:baseline:import \
  --package=/absolute/path/to/package \
  --json
```

Dry-run performs no writes and fails if the target package tables or forbidden historical tables contain rows. It also rejects a database whose name hash matches the source database identity.

Apply is intentionally disabled by default. It requires all of the following in a separately approved target-environment action:

- `GREENFIELD_BASELINE_IMPORT_ENABLED=true`;
- a migrated empty MySQL target;
- `--apply`;
- `--confirm=IMPORT_GREENFIELD_BASELINE:<package_sha256>`;
- `--expected-database-sha256=<sha256-of-target-database-name>`.

An export or dry-run never authorizes RDS creation, target import, deployment, Tencent shutdown/deletion, Metabase stop, DNS changes, publication changes, broad Career warm, or traffic cutover.

## Acceptance after isolated import

Verify current Article, Content Page, Landing, Personality, Career, public-media, SKU, alias, canonical/hreflang, sitemap, and llms surfaces against the frozen 613-URL manifest. Preserve the exact Career runtime projection so cutover still exposes only 30 public Career slugs. Any unexplained URL expansion, private-route leakage, missing public media object, historical record, old revision, or degraded 19-URL result blocks Greenfield readiness.

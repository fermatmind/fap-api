# Big Five zh-CN V3 52-page controlled release

This directory is a backend baseline importer fixture, not a runtime frontend content source.

## Locked inputs and outputs

- Source content tree SHA-256: `056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5`
- Package payload SHA-256: `edfdaea72705e205c3e126dbf04b2d4b0a84da536a871be37f0c5e225f25f4fb`
- Release package file SHA-256: `83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d`
- Inventory: 52 zh-CN Big Five pages (`1 hub + 5 domains + 15 ranges + 1 facet hub + 30 facet details`)
- Evidence: 170 reviewed claims and 261 visible FAQs
- Media: permanently unsupported for Big Five public content assets

The compiler validates the external reviewed Markdown/evidence package and writes this deterministic JSON artifact without accessing the database:

```bash
cd backend
php artisan personality:big-five-zh-v3-package-build \
  --source-root=/absolute/path/to/FermatMind-Big-Five-ZH-V3-content-package \
  --expected-content-sha256=056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5 \
  --output=../generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json \
  --json
```

## Publishing boundary

The publishing command is read-only unless `--execute` is supplied. Preflight verifies the exact file SHA, payload SHA, 52 existing CMS authority rows, canonical identities, collisions, contract validity, source mappings, and schema without writing:

```bash
cd backend
php artisan personality:big-five-zh-v3-content-publish --json
```

Production execution is separately controlled and requires the two exact hashes and `admin_user:1`. It creates immutable revision snapshots and updates the exact 52 existing zh-CN Big Five rows in one transaction. It never creates public asset rows, writes media, changes English content, or submits search URLs. Re-running the same package is idempotent and creates no additional revisions.

Do not execute the production form without the operator's exact production authorization and the repository deployment-readiness runbook.

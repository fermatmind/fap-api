# CAREER-SITEMAP-EXPOSURE-DIRECTORY-AUTHORITY-01

## Summary

Career detail sitemap exposure now enumerates public/indexable career detail URLs from the backend career directory authority service introduced by `CAREER-DIRECTORY-AUTHORITY-ARTIFACT-API-01`.

## Authority Boundary

- Backend career directory authority remains the public career detail source for discoverability enumeration.
- Sitemap generation no longer derives career detail URLs directly from display-asset-only rows.
- Runtime projection filtering in the sitemap source API remains in place as a final safety gate.

## Safety Rules

- Held slugs remain excluded:
  - `software-developers`
  - `digital-forensics-analysts`
  - `computer-occupations-all-other`
- Draft, noindex, non-public, display-only, fallback-only, and projection-blocked career detail URLs remain excluded.
- No frontend, CMS mutation, DB mutation, Search Channel action, URL submission, or deployment was performed.

## Validation Intent

The focused regression test verifies that:

- Directory-authority career jobs produce EN/ZH sitemap URLs.
- Display-asset-only career jobs are not enough to enter the sitemap.
- Held slugs are not emitted.
- The generated sitemap XML follows the same directory-authority career detail exposure.

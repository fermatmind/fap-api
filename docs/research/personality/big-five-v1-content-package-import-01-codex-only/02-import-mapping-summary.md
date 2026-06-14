# Import Mapping Summary

## Mapping

The backend seed was updated from 34 fap-web content packages.

Key mapping decisions:

- `sections[].body` from package JSON maps to backend `sections[].body_md`.
- `evidence_notes` string entries map to objects with `source_type=source_ledger`.
- `internal_links[]` preserve labels, hrefs, relationships, and add `target_code` where derivable.
- `media` keeps explicit placeholders and exposes `hero_image_url` / `hero_image_asset_key`.
- `canonical.path`, `hreflang`, `seo`, `faq`, `schema`, and `method_boundary` are preserved.

## Date Normalization

`last_reviewed_at` was normalized from `YYYY-MM-DD` to `YYYY-MM-DDT00:00:00+00:00`.

Reason: Eloquent casts `last_reviewed_at` as datetime. Date-only seed values were written as midnight UTC and caused the second import run to report `will_update=34`. Full ISO timestamps make the import idempotent.

## Non-Changes

No import command behavior change was needed.

No contract service behavior change was needed.

No OpenAPI change was needed because this task changes seed content and test coverage only.

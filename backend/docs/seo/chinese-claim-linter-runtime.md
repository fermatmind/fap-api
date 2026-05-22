# Chinese Claim Linter Runtime

Task: `CLAIM-LINT-01B`

This PR adds a reusable backend Chinese claim linter and a CI-readable fixture command:

- `App\Services\SeoIntel\ClaimLint\ChineseClaimLinter`
- `php artisan seo-intel:claim-lint --fixture --json`

The runtime scans candidate public-copy inputs supplied by tests or explicit fixtures. It does not scan production content by default and does not read private user data.

## Output

The report includes:

- `status`
- `lint_state`
- `severity`
- `matched_rules`
- `bounded_context_detected`
- `blocked_phrases`
- `needs_review_phrases`
- `allowed_bounded_phrases`
- `auto_rewrite_attempted=false`
- `cms_mutation_attempted=false`
- `production_scan_attempted=false`

## States

- `safe`: no forbidden or review phrase, or bounded wording in safe context.
- `needs_review`: caution phrase in a claim-sensitive surface.
- `blocked`: forbidden or flagged phrase present.

## Severity

- `P0`: blocked public/indexable claim risk.
- `P1`: blocked high-risk SEO metadata, FAQ, `llms`, AI answer, or JSON-LD claim risk.
- `P2`: blocked or needs-review draft/body/content-package risk.
- `P3`: safe or informational wording drift.

## Fixture Coverage

The bundled CI fixture covers:

- forbidden career recommendation claim
- bounded career direction reference
- MBTI salary guarantee claim
- model-index salary/turnover bounded phrasing
- clinical diagnosis claim
- non-diagnostic safe phrasing
- Big Five / RIASEC overclaim
- snapshot-based support phrasing

## Safety

This runtime:

- does not auto-rewrite content
- does not auto-publish
- does not mutate CMS content
- does not scan production content without explicit scope
- does not modify fap-web
- does not write `seo_intel`
- does not enqueue Search Channel rows
- does not submit URLs
- does not activate scheduler
- does not edit env
- does not deploy

## Next Task

Next task: `CONTENT-OPS-CLAIM-LINK-OPS-READINESS`

# PR5 Notes — SVG Freeze And Provenance Verification

## Scope

- freeze the current IQ legacy demo SVG corpus without changing visual output
- add deterministic per-item / per-option hash manifests for both IQ pack directories
- add verifier scripts that enforce explicit legacy-demo allowlisting and future production asset metadata requirements

## Added files

- `backend/scripts/iq/iq_svg_provenance_lib.php`
- `backend/scripts/iq/build_legacy_svg_provenance_manifest.php`
- `backend/scripts/iq/verify_legacy_svg_provenance.php`
- `backend/tests/Feature/Console/IqSvgProvenanceVerificationTest.php`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/svg_provenance_manifest.json`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/svg_provenance_manifest.json`

## What changed

- IQ legacy demo questions remain inline SVG payloads inside `questions.json`; no SVG geometry was edited.
- The new provenance manifests freeze:
  - `question_sha256`
  - `stem_asset.sha256`
  - `option_assets[*].sha256`
  - source file hashes for `questions.json`, `scoring_spec.json`, `manifest.json`, `meta/landing.json`, `version.json`
- The verifier now fails if:
  - a committed provenance manifest drifts from the current inline SVG payloads
  - a production IQ pack uses `IQ_RAVEN` as active identity outside explicit `legacy_demo` allowlisting
  - a production IQ scored item lacks `asset_hashes`
  - a production IQ scored item lacks `generator_metadata`

## Explicit non-changes

- no scoring math changes
- no answer key added to legacy 30
- no report builder changes
- no payment / unlock changes
- no frontend changes

## Remaining external blocker

- The original prototype zip source remains external to the repo. This PR records the source path and freezes deterministic hashes, but it does not vendor the prototype archive into git.

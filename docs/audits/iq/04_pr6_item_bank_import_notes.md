# PR6 Notes — Showcase 12 Beta Bank Import

## Scope

- import a canonical `IQ_SHOWCASE_12_BETA` bank under `IQ_INTELLIGENCE_QUOTIENT`
- keep the legacy 30-item demo untouched and unpromoted
- keep commerce deferred
- keep frontend out of scope

## Imported bank

- bank id: `IQ_SHOWCASE_12_BETA`
- status: `beta`
- activation: `runtime_bound=false`
- item count: `12`
- dimensions:
  - `VSPR`: 4
  - `VSI`: 4
  - `NPR`: 4
- answer distribution:
  - `A`: 3
  - `B`: 3
  - `C`: 3
  - `D`: 3

## Files

- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_SHOWCASE_12_BETA/manifest.json`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_SHOWCASE_12_BETA/items.json`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_SHOWCASE_12_BETA/answer_key.json`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_SHOWCASE_12_BETA/scoring_spec.json`
- `backend/scripts/iq/build_showcase12_beta_bank.php`
- `backend/scripts/iq/verify_showcase12_beta_bank.php`
- `backend/tests/Feature/Content/IqShowcase12BetaBankImportTest.php`

## Guarantees

- every imported item has:
  - canonical `scale_code`
  - `item_id`
  - `dimension`
  - `item_family`
  - `difficulty_level`
  - `correct_answer`
  - `solution_rule`
  - `distractor_logic`
  - `assets`
  - `asset_hashes`
  - `generator_metadata`
- `Beta 50` is still future work and is **not** claimed as imported in this PR
- no legacy IQ demo SVG geometry was changed
- no payment / unlock implementation was added

## Remaining gap

- `IQ_BETA_50` remains future work.
- no production norm table was added.
- runtime is still not switched from legacy demo to showcase bank.

# PR67 Recon

- Keywords: GenericLikertDriver|reverse|weight
- Scope:
  - `backend/app/Services/Assessment/Drivers/GenericLikertDriver.php`
  - `backend/tests/Unit/Services/Assessment/Drivers/GenericLikertDriverReverseAndWeightTest.php`
  - `backend/tests/Unit/Psychometrics/GenericLikertDriverTest.php`

## Driver Path
- `backend/app/Services/Assessment/Drivers/GenericLikertDriver.php`

## Current State (before PR67)
- Driver entry methods are `compute()` and `score()` (no `calculate()`).
- Reverse scoring already follows `(min + max) - raw`.
- Weighting already follows `effective * weight`.
- Invalid answer was zero-scored but warning contract used legacy message/context.

## PR67 Target Contract
- Reverse scoring remains: `Score = (Max + Min) - RawScore`.
- Weighting remains: `Score * Weight` with default weight `1.0`.
- Invalid answer defense:
  - Log exactly `Log::warning('Invalid answer option', ['question' => $qId, 'answer' => $answer])`.
  - Invalid option contributes `0`.
  - No undefined-index warnings, no crash, no structure corruption.
- Support nested item rule format:
  - `['rule' => ['weight' => <number>, 'reverse' => <bool>]]`.

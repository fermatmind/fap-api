# Content Packages

`/Users/rainie/Desktop/GitHub/fap-api/content_packages` is the single content root.

## Runtime Structure (Authoritative)

Only the `default` tree is allowed as online runtime source:

- `default/<REGION>/<locale>/<DIR_VERSION>/`

Examples:

- `default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2`
- `default/CN_MAINLAND/zh-CN/BIG5-CN-v0.1.0-TEST`

## Non-Runtime Structure

- `_deprecated/` is archive-only and must not be used as runtime source.
- Top-level pack folders (for example `content_packages/<pack_id>`) are not allowed.

## Versioning Rules

- Do not modify already-released pack content in place.
- Any content update must create a new directory version under `default/...`.
- Runtime default should be switched by config/env (`FAP_DEFAULT_PACK_ID`, `FAP_DEFAULT_DIR_VERSION`).

## Migration Rules

- When migrating old packs, move them into `default/<REGION>/<locale>/<DIR_VERSION>/`.
- After successful verification and reference cleanup, remove legacy top-level folders.
- If historical retention is needed, move old versions into `_deprecated/...`.

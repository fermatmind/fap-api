# Assets Rules + CDN URL Mapping (PR16)

## 1) Relative path rules (strict)
`assets.*` must be a **string relative path** with the following constraints:
- Must start with `assets/`
- Must **not** contain `..`
- Must **not** start with `http://` or `https://`
- Must **not** start with `/`

Examples:
- ✅ `assets/images/raven_opt_a.png`
- ❌ `../assets/images/a.png`
- ❌ `https://cdn.example.com/a.png`
- ❌ `/assets/images/a.png`

## 2) URL assembly
Full URL is constructed as:
```
{assets_base_url}/{pack_id}/{dir_version}/{relative_path}
```
Example:
```
https://cdn.example.com/content/default/IQ-RAVEN-CN-v0.3.0-DEMO/assets/images/raven_opt_a.png
```

## 3) assets_base_url priority
Resolution order (highest to lowest):
1) **Request/Context override** (PR22 input slot, injected by middleware/service)
2) `version.json.assets_base_url`
3) `APP_URL + "/storage/content_assets"`

> PR16 only reads the override input slot; PR22 will provide the region CDN value.

## 4) Output guarantees
- Output stage **only** maps relative assets to full URLs.
- External links are **not** passed through.
- Missing `assets` fields are left untouched.

## 5) Common failures
- `strict-assets` fails: path does not start with `assets/` or contains `..`.
- Runtime mapping fails: invalid `assets` types (non-string) or illegal paths.
- assets_base_url empty: falls back to `APP_URL/storage/content_assets`.

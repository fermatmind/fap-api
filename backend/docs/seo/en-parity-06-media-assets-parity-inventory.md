# EN-PARITY-06 Media Assets / Alt / OG Image / Locale Variant Parity

## Executive Summary

EN-PARITY-06 lands a repository-backed media parity inventory for public EN/ZH content surfaces. It does not upload, generate, replace, or publish images.

Decision: `en_parity_06_media_inventory_landed_sidecar_visual_review_required`

The committed authority evidence shows:

- Media baseline assets: 3, all with alt and caption metadata.
- Article baselines: 20 EN articles and 25 zh-CN articles, all with cover URL, alt, and variants.
- Existing EN article alt metadata contains no Chinese characters.
- 19 EN/ZH article pairs share the same cover URL and therefore need human or OCR review before we can claim embedded-text parity.
- Career guide baselines have complete EN/ZH guide-code parity, but 36 EN and 36 zh-CN guide rows have no `og_image_url` or `twitter_image_url`.
- Content pages, MBTI topic profiles, and MBTI personality profiles do not currently provide usable public media authority in the inspected baseline fields.

## Scope

This PR is inventory and gate evidence only.

Files landed:

- `backend/docs/seo/generated/en-parity-06-media-assets-parity-inventory.v1.json`
- `backend/tests/Feature/SeoIntel/EnParity06MediaAssetsParityInventoryTest.php`
- `backend/docs/seo/en-parity-06-media-assets-parity-inventory.md`
- current PR-train manifest/state entry

## Authority Boundary

Backend/CMS Media Library metadata, Article cover metadata, CareerGuide SEO metadata, and repo-backed baseline import packages remain authority.

Frontend fallback content, sitemap, llms, runtime fetches, and visual observation do not become media authority.

## What Was Not Done

- No production CMS mutation.
- No production media upload.
- No image generation.
- No image replacement.
- No production migration.
- No deploy.
- No Search Channel or URL submission.
- No fap-web commit.
- No OCR or full human design review was performed.

## Findings

### Article Covers

The EN article baseline has complete cover metadata for all 20 rows:

- `cover_image_url`: 20 / 20
- `cover_image_alt`: 20 / 20
- `cover_image_variants`: 20 / 20
- EN alt text containing Chinese characters: 0

The zh-CN article baseline has complete cover metadata for all 25 rows. Six zh-CN editorial articles remain without EN article counterparts from EN-PARITY-04 and stay deferred.

Nineteen EN/ZH article pairs share cover URLs. This is acceptable as metadata evidence, but it is not enough to prove embedded-text visual parity. Those covers are sidecarized for OCR or human review.

### Career Guide Social Images

Career guide baselines have 36 EN and 36 zh-CN rows with complete `guide_code` parity from EN-PARITY-05. They do not currently carry authority-backed social image fields:

- EN `og_image_url`: 0 / 36
- zh-CN `og_image_url`: 0 / 36
- EN `twitter_image_url`: 0 / 36
- zh-CN `twitter_image_url`: 0 / 36

Career guide public SEO exposure must remain controlled by EN-PARITY-07 until valid authority-backed OG / JSON-LD image policy is enforced.

### Media Library Baseline

`content_baselines/media_assets/default_media_assets.json` contains three default assets:

- `share.mbti.default`
- `social.wechat.official_qr`
- `social.wechat.qr`

All three have alt and caption metadata. `share.mbti.default` still needs visual embedded-text review evidence before it can be used as proof of bilingual visual parity.

## Sidecar Items

1. `en_parity_06_shared_article_cover_visual_text_review`
   - Scope: 19 shared EN/ZH article covers.
   - Reason: metadata is complete, but embedded text was not OCR/human reviewed.

2. `en_parity_06_career_guide_social_images`
   - Scope: 36 EN and 36 zh-CN career guide detail rows.
   - Reason: no authority-backed OG/Twitter image URLs exist yet.

3. `en_parity_06_mbti_default_share_visual_review`
   - Scope: `share.mbti.default`.
   - Reason: media metadata exists, but visual text review evidence is not recorded.

## Validation

Required local validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=EnParity06 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/en-parity-06-media-assets-parity-inventory.v1.json >/dev/null
python3 - <<'PY'
import yaml, json
yaml.safe_load(open('docs/codex/pr-train.yaml'))
json.load(open('docs/codex/pr-train-state.json'))
print('manifest/state parse ok')
PY
git diff --check
git diff --cached --check
```

## Next Task

EN-PARITY-07 should use this inventory to enforce sitemap, llms, JSON-LD, FAQ, canonical, and hreflang gates so public SEO surfaces do not expose draft, placeholder, fallback-only, missing-authority, or broken media/content metadata.

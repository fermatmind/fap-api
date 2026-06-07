# RIASEC Explanation V2 CMS Media Upload / Import Postcheck

Task: `RIASEC-EXPLANATION-CMS-MEDIA-UPLOAD-IMPORT-01`

Decision: **CONDITIONAL GO: CMS media was imported, but publishing remains blocked.**

This task performed a CMS Media Library mutation only. It did not mutate article drafts, rewrite article body/title/H1/meta/FAQ/CTA, approve revisions, publish, submit search URLs, deploy, or access private result/order/share/pay/payment/history URLs.

## Imported Media

| Field | Value |
| --- | --- |
| CMS media asset ID | `6` |
| Asset key | `article.riasec.explanation.cover.v1` |
| Status | `published` |
| Public | `true` |
| CDN status | `verified` |
| Source MIME | `image/webp` |
| Source dimensions | `1672x941` |
| Source SHA-256 | `fe357787ce75635f38aeca7af3d46a9ece27d0074d9b4427e5149317d386a4fc` |

Alt text recorded in CMS:

`Abstract career-interest map with six work activity icons around a compass.`

The visual review found no visible competitor branding, official institution branding, diagnostic or medical imagery, or career guarantee/job outcome imagery.

## Variant Readiness

| Variant | Dimensions | MIME | Status |
| --- | ---: | --- | --- |
| `hero` | `1600x900` | `image/jpeg` | `verified` |
| `card` | `800x450` | `image/jpeg` | `verified` |
| `thumbnail` | `400x225` | `image/jpeg` | `verified` |
| `og` | `1200x630` | `image/jpeg` | `verified` |
| `preload` | `64x36` | `image/jpeg` | `verified` |

Public API postcheck passed for:

- Media asset API: `https://api.fermatmind.com/api/v0.5/media-assets/article.riasec.explanation.cover.v1?org_id=0`
- Source image and all required variant URLs: HTTP 200 with image MIME

## Runtime Notes

- Production already had the Media Library upload route and variant generator.
- Production media CDN verification is disabled by default, while ArticleResource requires `cdn_status=verified`.
- `assets.fermatmind.com/storage/...` does not currently mirror local storage files, so this import uses verified `api.fermatmind.com/storage/...` public media URLs.
- The production `storage/app/public/media-library` directory needed a permission correction for the operator SSH user to create upload subdirectories.

## Remaining Blockers

| Blocker | Status | Required input |
| --- | --- | --- |
| Draft revisions not approved | blocked | Approve zh revision 45 and en revision 46, or request a GPT revision loop. |
| Article cover not attached to drafts | blocked | Separately authorize attaching CMS media asset 6 to the zh/en Article draft cover fields. |
| Publish preflight not run | blocked | Run only after media attachment and revision approval pass. |

## Next Step

Recommended next step: use CMS media asset `6` as the reviewed cover candidate, then separately authorize either article draft cover attachment or draft approval preflight.

Still not authorized:

- article draft mutation
- revision approval
- publish preflight
- publish
- search submission
- deploy

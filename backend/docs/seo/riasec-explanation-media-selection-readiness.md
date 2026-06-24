# RIASEC Explanation V2 Media Selection Readiness

Task: `RIASEC-EXPLANATION-MEDIA-SELECTION-READINESS-01`

Decision: **NO-GO: no reusable CMS media candidate is available from repository baselines.**

This ad-hoc PR records the media-readiness blocker after the operator input recording PR. It does not upload media, mutate CMS records, approve revisions, publish, submit search URLs, deploy, read secrets, or access user-specific URLs.

## Result

The current repository baseline has no suitable reusable media asset for the RIASEC explanation article cover.

Existing baseline assets are MBTI, IQ, or QR-code assets. They should not be reused as a RIASEC / Holland career-interest article cover because that would create topical mismatch and weaken the CMS media authority boundary.

## Article Cover Requirements

An acceptable Article cover media asset must satisfy the backend ArticleResource readiness rules:

- MediaAsset exists in `org_id=0` context.
- MediaAsset status is `published`.
- MediaAsset is public.
- MediaAsset CDN status is `verified`.
- Source URL is public-safe.
- Alt text is non-empty and reviewed.
- Required variants exist: `hero`, `card`, `thumbnail`, `og`, `preload`.
- Every required variant is CDN-verified.
- Every required variant has a public-safe URL.
- Every required variant has positive width and height.
- Every required variant MIME type is an image.
- The image is topically appropriate for a RIASEC / Holland career-interest article.

## Rejected Existing Baseline Assets

| Asset key | Decision |
| --- | --- |
| `share.mbti.default` | reject: MBTI-specific |
| `social.wechat.official_qr` | reject: QR code, not article cover |
| `social.wechat.qr` | reject: QR code, not article cover |
| `iq-owner-original-30-card` | reject: IQ-specific |
| `iq-owner-original-30-og` | reject: IQ-specific |
| `iq-full-report-cover` | reject: IQ-specific |

## Recommended Media Request

Recommended asset key: `article.riasec.explanation.cover.v1`

Visual direction:

- career-interest exploration
- six-area structure or neutral work-environment exploration
- no official O*NET, DOL, APA, Holland, or MBTI branding
- no diagnostic or medical imagery
- no guarantee, ranking, or job-outcome implication

Required operator inputs:

- `cms_media_id`
- `cover_image_url`
- `cover_image_alt_reviewed`
- `og_image_ready`
- `twitter_image_ready`
- `media_reviewed_by`
- `media_reviewed_at`

Alt text must describe the image plainly and must not promise career fit, diagnosis, certification, or official endorsement.

## Next Step

Next step is **CMS media input**, not publish preflight.

The operator should either:

- supply a reviewed CMS Media Library asset ID and readiness fields, or
- separately authorize a CMS media upload/import step.

Hard gates still closed:

- no CMS mutation
- no media upload
- no publish
- no search submission
- no deploy
- no article content rewrite
- no user-specific URL access

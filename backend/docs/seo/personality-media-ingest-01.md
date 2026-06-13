# PERSONALITY-MEDIA-INGEST-01

## Scope

This PR registers the legacy MyFunQuiz 16 base MBTI type illustrations as backend-owned FermatMind media assets for the public personality hub.

## Authorization

Operator authorization was provided in the 2026-06-13 task context: MyFunQuiz was identified as the previous owned site, and the requested scope explicitly allowed old-site base personality image reuse for FermatMind after backend Media Library/storage registration.

## Source And Registration

- Source pattern: `https://static.lingcecdn.com/personality/v1/type/{TYPE}.png`
- Committed static path: `backend/public/static/personality/type-icons/{type}.png`
- Public URL pattern: `https://assets.fermatmind.com/static/personality/type-icons/{type}.png`
- Media Library baseline: `content_baselines/media_assets/personality_type_icons.v1.json`
- Personality baseline fields: `content_baselines/personality/mbti.en.json` and `content_baselines/personality/mbti.zh-CN.json`

## Boundaries

- No fap-web changes.
- No 32-variant hub change.
- No production upload, CMS mutation, deploy, sitemap, llms, or search submission in this PR.
- A/T variants may reuse the same base type icon only after frontend/backend variant directory work lands separately.

# TAKE-EN-QUESTION-LOCALE-SCAN-00 Report

## Executive Summary

The English take-page Chinese question issue is real and is not limited to MBTI. The scan found five visible English-locale failures across twelve question surfaces:

- MBTI `mbti_93` and `mbti_144` return Chinese displayed question text and Chinese displayed option labels for `locale=en`.
- RIASEC `riasec_60` and `riasec_140` return Chinese displayed question stems for `locale=en`; options are already English.
- Enneagram `enneagram_forced_choice_144` returns Chinese displayed forced-choice option text for `locale=en`.

Big Five, IQ, EQ, Clinical Combo, SDS, and Enneagram `likert_105` do not show the same visible English take-page failure in the scanned displayed fields.

No API behavior, content pack, CMS record, publish state, deploy state, sitemap, llms, footer/nav, or Search Channel state was changed.

## Scan Scope

Read-only surfaces inspected:

- Public questions API behavior for `locale=en`.
- Public lookup API form resolution.
- Backend `ScalesController` question-loading branches.
- Backend content pack loader boundaries.
- fap-web take clients and question normalizers as reference-only consumer context.

Scanned cases:

- MBTI `mbti_93`, `mbti_144`
- Big Five `big5_90`, `big5_120`
- RIASEC `riasec_60`, `riasec_140`
- Enneagram `enneagram_likert_105`, `enneagram_forced_choice_144`
- IQ Raven
- EQ 60
- Clinical Combo 68
- SDS 20

## Findings

| Scale | Form | EN displayed status | Required action |
| --- | --- | --- | --- |
| MBTI | `mbti_93` | FAIL: Chinese question and options | Backend MBTI locale projection |
| MBTI | `mbti_144` | FAIL: Chinese question and options | Backend MBTI locale projection |
| Big Five | `big5_90` | PASS | None |
| Big Five | `big5_120` | PASS | None |
| RIASEC | `riasec_60` | FAIL: Chinese question stems | Backend RIASEC English pack translation |
| RIASEC | `riasec_140` | FAIL: Chinese question stems | Backend RIASEC English pack translation |
| Enneagram | `enneagram_likert_105` | PASS | None |
| Enneagram | `enneagram_forced_choice_144` | FAIL: Chinese forced-choice options | Backend Enneagram forced-choice English pack translation |
| IQ Raven | default | PASS | None |
| EQ 60 | default | PASS | None |
| Clinical Combo 68 | default | PASS | None |
| SDS 20 | default | PASS | None |

## Root Cause

### MBTI

The backend MBTI branch resolves the form code and accepts the requested locale, but then falls through to `QuestionsService::loadByPack()`, which returns raw pack question fields. The response reports `locale=en`, but displayed fields remain Chinese. fap-web also has a generic take-flow gap: `QuizTakeClient` passes `locale` only for RIASEC today, so MBTI needs a frontend contract guard after the backend authority fix.

### RIASEC

The backend already uses `RiasecPackLoader::loadQuestionsDoc($locale, $version)`, and the response resolves to `locale=en`. The English questions document is present, but displayed `text` / `text_en` values are Chinese. This is a content-pack authority issue, not a frontend translation issue.

### Enneagram forced-choice

The backend already uses `EnneagramPackLoader::loadQuestionsDoc($locale, $version)`, and the likert 105 English form is clean. The forced-choice 144 English document contains Chinese option display text. This should be fixed in the Enneagram forced-choice pack only.

## Unaffected Surfaces

Big Five uses explicit backend locale projection and returns English displayed fields for `big5_90` and `big5_120`.

IQ includes Chinese fallback fields in the payload, but the English displayed prompt is `prompt_en` and is rendered in English.

EQ, Clinical Combo, SDS, and Enneagram likert 105 returned English displayed fields in the scanned cases.

## Recommended PR Train

1. `MBTI-EN-QUESTIONS-PACK-PROJECTION-01`
   - Fix MBTI `mbti_93` and `mbti_144` English displayed question and option fields from backend/content-pack authority.
   - Add API tests for `locale=en` and `locale=zh-CN`.

2. `RIASEC-EN-QUESTIONS-PACK-TRANSLATION-02`
   - Fix RIASEC `riasec_60` and `riasec_140` English question stems in backend/content-pack authority.
   - Keep option scoring, order, dimensions, and Chinese locale unchanged.

3. `ENNEAGRAM-FC144-EN-QUESTIONS-PACK-03`
   - Fix Enneagram forced-choice 144 English option texts.
   - Preserve Enneagram likert 105 behavior.

4. Follow-up after backend fixes: `TAKE-FRONTEND-LOCALE-CONTRACT-04`
   - fap-web only.
   - Pass locale for generic scale question fetches and add a no-CJK contract guard.
   - Do not add frontend hardcoded translation fallback content.

## What Was Not Done

- No backend API runtime behavior changed.
- No content pack translations were changed.
- No CMS mutation occurred.
- No publish, deploy, Search Channel, sitemap, llms, footer/nav, or URL submission action occurred.
- No fap-web runtime changes were made.

## Validation

Required validation for this scan PR:

```bash
cd backend && php artisan test --filter=TakeEnQuestionLocaleScan00 --no-ansi
cd backend && php artisan route:list --no-ansi
cd backend && vendor/bin/pint --test
cd backend && composer validate --strict
cd backend && composer audit --locked --no-interaction --ignore-unreachable
python3 -m json.tool backend/docs/seo/generated/take-en-question-locale-scan-00.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 - <<'PY'
import yaml, pathlib
yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())
print('yaml ok')
PY
git diff --check
git diff --cached --check
```

## Final Decision

`question_locale_scan_completed_ready_for_content_pack_fixes`

## Next Task

`MBTI-EN-QUESTIONS-PACK-PROJECTION-01`

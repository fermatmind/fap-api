# GLOBAL-EN-ZH-PARITY-ARTICLE-COUNTERPART-BATCH-01 Article Counterpart Controlled Batch

## Executive Summary

This PR records the controlled article counterpart batch for the remaining full-site EN/ZH parity train. It does not publish articles, mutate production CMS, deploy, submit URLs, or generate substantial English prose.

The batch uses the existing EN-PARITY-04 repo-backed Article baseline as authority. Ten target counterparts are import-ready and require review before exposure; six longer editorial counterparts remain deferred for human-reviewed English drafts.

## Authority Boundary

- Backend Article resources remain authority.
- `content_baselines/articles` is an import/recovery package, not frontend runtime authority.
- fap-web hardcoded or fallback article content is not authority.
- Draft/import-only or missing-counterpart article URLs must not enter sitemap, llms, hreflang, URL Truth, or public runtime.

## Import-Ready Target Counterparts

- `big-five-growth-guide`
- `big-five-narrative-portrait`
- `big-five-tool-guide`
- `eq-test-tool-guide`
- `iq-test-growth-guide`
- `iq-test-narrative-portrait`
- `iq-test-tool-guide`
- `mbti-basics`
- `mbti-growth-guide`
- `mbti-narrative-portrait`

These are not published by this PR.

## Deferred Human-Review Drafts

- `are-infj-men-rare-or-socially-silenced`
- `best-valentines-date-by-personality-and-relationship-science`
- `childhood-dream-job-still-shapes-career-choice`
- `how-16-personality-types-talk-to-an-ai-coach`
- `how-personality-shapes-attitude-toward-ai`
- `which-love-script-fits-you-best`

## Validation

```bash
cd /private/tmp/fap-api-global-en-zh-remaining-train/backend
php artisan test --filter=GlobalEnZhArticleCounterpartBatch01 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /private/tmp/fap-api-global-en-zh-remaining-train
python3 -m json.tool backend/docs/seo/generated/global-en-zh-article-counterpart-batch-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 - <<'PY'
import yaml, pathlib
yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())
print('yaml ok')
PY
git diff --check
git diff --cached --check
```

## Next Task

`GLOBAL-EN-ZH-PARITY-CAREER-ASSET-BATCH-01`.

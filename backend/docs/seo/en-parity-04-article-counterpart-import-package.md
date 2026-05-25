# EN-PARITY-04 Article English Counterpart Import Package

## Executive Summary

EN-PARITY-04 lands a repository-backed article counterpart inventory and import-readiness gate. It does not publish articles, mutate production CMS, submit URLs, deploy, or use fap-web fallback content as authority.

The current article baseline contains 25 zh-CN rows and 20 en rows. The 10 EN-PARITY-00 target article counterparts are already present in the repository baseline as English Article authority rows and can be imported through the controlled backend Article baseline importer after operator review. No new long-form English prose was generated in this PR.

## Scope

- Strengthen `articles:import-local-baseline` so imported Article rows receive stable translation metadata:
  - `translation_group_id`
  - `source_locale`
  - `translation_status`
  - `source_article_id`
- Add a generated import-readiness JSON artifact for article parity.
- Add a focused test proving the target 10 article counterparts are repo-backed, paired by backend translation authority, and not satisfied by frontend fallback.

## Current Baseline

- English article baseline rows: 20
- Chinese article baseline rows: 25
- EN-PARITY-00 target English counterparts present in repo baseline: 10
- zh-CN article rows still missing English counterpart in repo baseline: 6

## Target Counterparts Ready For Review

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

These rows are not auto-published by this PR. They remain repo-backed baseline content requiring the existing controlled import/publish workflow.

## Deferred Article Counterparts

The following zh-CN long-form/editorial articles do not have English repo-backed counterparts in the baseline and are deferred for human translation or a separately reviewed draft package:

- `are-infj-men-rare-or-socially-silenced`
- `best-valentines-date-by-personality-and-relationship-science`
- `childhood-dream-job-still-shapes-career-choice`
- `how-16-personality-types-talk-to-an-ai-coach`
- `how-personality-shapes-attitude-toward-ai`
- `which-love-script-fits-you-best`

## Controls

- No fap-web files changed.
- No frontend fallback used as Article authority.
- No production CMS mutation performed.
- No production migration performed.
- No deploy performed.
- No Search Channel action or URL submission performed.
- No mass English article generation performed.
- No auto-publish performed.

## Validation

- `php artisan test --filter=EnParity04 --no-ansi`
- `php artisan test --filter=ArticleBaselineImportTest --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`
- `python3 -m json.tool backend/docs/seo/generated/en-parity-04-article-counterpart-import-package.v1.json >/dev/null`

## Next Task

EN-PARITY-05 should prepare a career-guide detail inventory/template/import package or a small reviewed batch only. It must not mass-generate or auto-publish English career guide prose.

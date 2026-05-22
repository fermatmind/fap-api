# MBTI Scale Decision and Entity Playbook Closeout

Task: SEO-GROWTH-MBTI-07

Train: SEO-GROWTH-MBTI-PR-TRAIN-01

This is a docs / generated JSON / tests / train-ledger closeout. It does not implement runtime code, does not run migrations, does not perform production operations, does not mutate CMS, does not modify fap-web, does not mutate Search Channel, and does not send Digital PR.

## Train Summary

- SEO-GROWTH-MBTI-00: baseline snapshot and telemetry contract.
- SEO-GROWTH-MBTI-01: entity map and URL Truth review.
- SEO-GROWTH-MBTI-02: content and internal link Wave 1 dry-run plan.
- SEO-GROWTH-MBTI-03A: MBTI claim lint gate.
- SEO-GROWTH-MBTI-03B: Search Channel canary wave plan.
- SEO-GROWTH-MBTI-04: Digital PR Wave 2 plan.
- SEO-GROWTH-MBTI-05: human-only funnel and revenue review contract.
- SEO-GROWTH-MBTI-06: 7/14/28-day growth review plan.

## Entity Playbook Template

Every future entity growth loop must define:

- Entity key rules.
- URL Truth rules.
- Content and internal link rules.
- Claim lint rules.
- Search Channel rules.
- Digital PR rules.
- Funnel telemetry rules.
- Bot/human separation.
- Brand lift proxy.
- `/ops/seo` review cadence.
- Repair action rules.
- Replication checklist.

## Scale / No-scale Criteria

Scale only when the 7/14/28-day review window produces a clear scale decision and all safety checks pass:

- No claim-unsafe public page.
- No private-flow leak.
- No forbidden-authority URL Truth.
- No uncontrolled Search Channel live gate.
- No bulk outreach.
- No pSEO.
- Human-only funnel and backend revenue truth are separated from frontend observation.

## Replication Order

1. MBTI.
2. Big Five.
3. Enneagram.
4. RIASEC.
5. Career Guides.
6. Research Hub.
7. Topic Clusters.
8. Multi-language.

Do not replicate until the MBTI review window produces a clear scale decision.

## Hard Boundaries

- Do not overclaim Big Five, RIASEC, Career, or MBTI as precise recommender authority.
- Do not describe personality/career systems as precise hiring suitability, salary prediction, or career-success prediction.
- Do not bulk submit URLs.
- Do not bulk outreach.
- Do not generate pSEO.
- Do not auto-publish.
- Do not auto-link.

## Sidecar Issues

- `translation_group_uuid` missing globally.
- `translation_group_id` remains transitional and partial.
- Backend deploy public smoke / local TLS remains a sidecar.
- fap-web fallback authority risk remains outside this train.
- GSC/GA4/referral data are not fully implemented growth inputs.
- Bot/human funnel separation remains partially proven.
- Public runtime source ambiguity exists for some topic/personality/article rows.
- Search Channel live gates must remain closed except exact approved live canary.
- Claim linter has fixtures but no verified production MBTI surface scan.
- `/ops/seo-operations` is write-capable and outside this train.
- `scripts/post_merge_cleanup.sh` is absent in this clone; manual cleanup verification was used.

## What Was Not Done

This train did not execute live growth actions, publish content, enqueue Search Channel URLs, submit URLs, send Digital PR, mutate CMS, write URL Truth, create internal links, query production databases, expose PII, deploy, run migrations, or modify fap-web.

## Next Execution Task

SEO-GROWTH-MBTI-ACTION-01

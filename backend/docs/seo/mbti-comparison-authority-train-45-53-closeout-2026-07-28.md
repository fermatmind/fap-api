# MBTI Comparison Authority Train 45→53 Closeout

Date: 2026-07-28

Status: the implementation and publication train is merged; the exact 55-URL
public cohort passed two independent read-only production release-gate runs.
The 7/14/28-day Google Search Console observations are scheduled and remain
read-only.

This document is the backend technical record for the complete
MBTI-COMP-PROJECTION-45 through MBTI-GSC-53 task line. It supplements the
[52-URL authority closeout](./mbti-full-personality-authority-closeout-2026-07-15.md)
and records the implementation, production repairs, exact authorization
receipts, public readback, and deferred monitoring discussed during the train.

This is an evidence and operating-boundary document. It is not standing
authorization for deployment, CMS/database writes, publication, indexability,
sitemap or llms mutation, search submission, URL Inspection, or request
indexing. Historical approval hashes must never be replayed against a different
package, state, SHA, environment, or cohort.

## Final released cohort

| Surface | Count | Current authority |
| --- | ---: | --- |
| A/T personality profiles | 32 | backend CMS and public personality read models |
| A/T comparisons | 16 | backend comparison records and comparison projection |
| Cross comparisons | 7 | backend comparison records and comparison projection |
| Total release-gate URLs | 55 | backend public APIs consumed by fap-web |

The 7 cross-comparison records are:

- `enfp-vs-entp`
- `entj-vs-intj`
- `estj-vs-entj`
- `infj-vs-infp`
- `intj-vs-intp`
- `isfp-vs-infp`
- `istj-vs-isfj`

The three records added by this train are `enfp-vs-entp`, `estj-vs-entj`, and
`isfp-vs-infp`. The other four were already in the 52-URL authority baseline.
The train did not authorize arbitrary comparison expansion, MBTI × career,
Big Five, Enneagram, RIASEC, private results, attempts, reports, orders,
payments, or any inferred comparison slug.

## Linear PR train

All nine declared items were merged in dependency order.

| Order | Task | Repository PR | Merge commit | Delivered boundary |
| ---: | --- | --- | --- | --- |
| 45 | MBTI-COMP-PROJECTION-45 | fap-api #3175 | `781d1636b2c74f5852076a5864b681910a1e0e47` | backend-authoritative A/T comparison projection |
| 46 | MBTI-COMP-RUNTIME-46 | fap-api #3206 | `e225b8fd0b70a4295f36d88b77d61d38ee0380bf` | production runtime and single-record revision repair controls |
| 47 | MBTI-COMP-GATE-47 | fap-web #1794 | `3cab146bb6dc03516d4a15b23e8319ba04c9f4b2` | visible-body, nonce, canonical, robots, and schema gate |
| 48 | MBTI-CROSS-APPROVAL-48 | fap-web #1801 | `5ae48bdda2ccd0e63bee9ca30de395ccd8ddd26b` | exact three-record editorial approval package |
| 49 | MBTI-CROSS-PUBLISHER-49 | fap-api #3227 | `f028eb5d86947c8ef2e3b84244fc363cfdeee224` | fail-closed content and discoverability publisher |
| 50 | MBTI-CROSS-AUTHORITY-50 | fap-api #3229 | `fe0b411ac90adf63b56290249138668d6439e3cd` | public authority/readback contract |
| 51 | MBTI-CROSS-PUBLISH-51 | fap-api #3294 | `0c4b7099088c24ae12096dd11f6657f02037c211` | production content then indexability release evidence |
| 52 | MBTI-INDEX-52 | fap-web #1816 | `0239753b3df65f920a1ea0b9fc50401def893b95` | two-run 55-URL release gate and projection repair contract |
| 53 | MBTI-GSC-53 | fap-web #1823 | `4d81f495ea9120d6e9ef32094efb568e925f2e0a` | read-only 55-URL GSC observation queue |

Supporting repair PRs remained scoped to prerequisites or same-train defects:

| Repository PR | Purpose |
| --- | --- |
| fap-api #3205 | canonicalize the Runtime-46 production section hashes |
| fap-web #1795 | restore the CSP nonce/runtime rendering boundary |
| fap-web #1796 | make the personality comparison body visible in SSR HTML |
| fap-web #1808 | keep MBTI base profiles backend-authoritative |
| fap-web #1810 | make canonical sitemap responses no-store/revalidated |
| fap-web #1815 | restore backend-authoritative `noindex,follow` rendering |
| fap-web #1818 | render backend revision markers and structured rows |
| fap-web #1820 | render backend-authoritative `next_reading` visibly |
| fap-web #1822 | hold the English alternate without a real `en` authority record |
| fap-api #3307, #3308, #3309 | close and document the cross-publish cache/readback recovery |
| fap-api #3340, #3342, #3344 | build, isolate, and guard the INDEX-52 comparison projection repair |

Git history proves implementation and review. The database, public API, rendered
HTML, and feed readback prove public state. Neither source alone substitutes
for the other.

## Authority and release pipeline

```text
reviewed A/T and cross-comparison assets
  -> backend comparison projection and runtime read model
  -> exact editorial package and operator approval
  -> content write for the exact approved records
  -> read-only public content verification
  -> separately authorized indexability/sitemap/llms release
  -> backend-authoritative fap-web rendering
  -> two independent 55-URL production read-only gates
  -> read-only GSC 7/14/28-day observation queue
```

The stages are deliberately separate:

1. Code deployment may change executable code only. It does not authorize a
   CMS/database write or public-state mutation.
2. Content promotion may write only the exact approved records. It does not
   imply indexability, sitemap, llms, or search authorization.
3. Indexability promotion may release only the exact content-readback cohort.
   It must not rewrite bodies and never implies search submission.
4. Public verification is read-only. A failed readback may invoke only the
   exact rollback contract that accompanied the write authorization.
5. GSC monitoring is observation only. It cannot submit a sitemap, request
   indexing, mutate URL Inspection state, or rewrite content.

## Runtime-46: A/T comparison repair

### Code deployment

The successful production code-only deployment was:

```text
workflow_run=29674638168
deployed_sha=bc0ed833bc9aae1473ab37f1dead2517e1aff618
previous_active_sha=781d1636b2c74f5852076a5864b681910a1e0e47
release=mbti-comp-runtime-46-20260719-r3-29674638168-1
started_at=2026-07-19T05:18:13Z
completed_at=2026-07-19T05:24:17Z
```

Earlier exact-SHA approvals and staging attempts were superseded rather than
combined:

- `781d1636b2c74f5852076a5864b681910a1e0e47` was the initial code-only
  production target and later the base of the isolated repair.
- `847ce70b2b27983c00691a5e499cdf961302237a` was separately bounded and
  explicitly excluded `21552d19a6fc76053de43ac87bf1ba3e6db5192c` and all
  later commits.
- `bc0ed833bc9aae1473ab37f1dead2517e1aff618` was the final isolated staging
  and production repair target based on the known `781d1636...` production
  state.

These approvals were not cumulative ranges. Commits outside the exact selected
target were not silently included.

### Single-record content revision

The only Runtime-46 production content revision was
`intp-a-vs-intp-t`:

```text
package=mbti-comp-runtime-46-intp-revision-2026-07-19-r1
payload_sha256=10b306f2dbac4f9a801a7718ec5584d84f56f6de601ada0f8f677bcb163f960e
promotion_sha256=5b8afeec191d348dbb888c6cb4a63ea1e167e1a004bf35e41c1e64399f0c8369
authorization_sha256=c9b3c3fa7f68a73e946f6bbc0a3f02ea6a95f3cbf5e9d3141778dd7d6408e03d
result_revision=212
```

The dry-run was workflow `29674866867`. The first write run
`29675163335` failed on a control-hash mismatch; its public readback matched
and the exact rollback contract passed. The hash canonicalization fix was
isolated in fap-api #3205. Retry `29675471856` succeeded.

The acceptance evidence then showed:

- 16/16 A/T comparison APIs passed.
- 16/16 public comparison pages passed.
- each record exposed the exact nine-section visible contract.
- FAQ, canonical, robots, JSON-LD, and `index,follow` parity passed.
- no publication, indexability, sitemap, llms, or search state was changed by
  the single-record revision.

The durable evidence is:

- `backend/docs/seo/mbti-comp-runtime-46-production-acceptance.md`
- `backend/docs/seo/generated/mbti-comp-runtime-46-production-acceptance.v1.json`
- `backend/docs/seo/import-packages/mbti-comp-runtime-46-intp-revision-2026-07-19.json`

### Staging cache prerequisite

The staging path exposed unrelated Career detail cache misses. Repair authority
was explicitly cache-only:

- locales were bounded to `en` and `zh-CN`.
- the dynamic target cap was 250.
- the requested verification denominator was 2092/2092.
- no production CMS/database, publication, indexability, sitemap, llms, or
  search authority was granted.

Candidate release `94b5117e9fcb25f827d5febff90d195f163c6f41` run
`29656602481` did not become the accepted deployment. The existing standard
staging deployment at exact SHA
`bb5e65f1dfe6990cf0aebb6e82c951ee20b5050f`, run `29669116329`,
succeeded. This detour did not create a new content authority or weaken the
linear MBTI train.

## Gate-47: public rendering boundary

The comparison API being correct was insufficient while the public HTML did
not expose the same authority. Gate-47 therefore repaired two independent
boundaries:

1. fap-web #1795, SHA
   `062a7130e5dcff8a08b55489b430c1338431b1b0`, restored the CSP
   nonce/runtime boundary.
2. fap-web #1796, SHA
   `dd25c48a6e756fa7abd71c14f111922049ff7bd7`, made the personality
   comparison body visible to SSR/read-only clients.

The exact `dd25c48a6e756fa7abd71c14f111922049ff7bd7` recovery deployment
was workflow `29939112644`.
GitHub recorded the workflow as failed because a post-deploy analytics
homepage check timed out; deployment, revision, and public target smoke had
already succeeded. Independent target readback proved nonce parity, zero CSP
blocking, and Gate-47 at 16/16. This distinction is recorded so a workflow
conclusion is not misreported as either a total deployment failure or a clean
all-checks success.

No frontend editorial fallback was introduced. Comparison content, FAQ,
canonical, robots, JSON-LD, and revision markers remained backend-authoritative.

## Cross Approval-48 through Publish-51

### Exact editorial package

The final operator-reviewed package covers only the three new records:

```text
package_sha256=604851b56031d22d48036e87a5358bf85c9e13268655dbe36d2ab798b3f58dae
authorization_sha256=be4f17484334074cf2c90d57898ab80b6074093b2510a4b7b4b0432a164b4670
slugs=enfp-vs-entp,estj-vs-entj,isfp-vs-infp
```

Earlier package hashes proposed during editorial iteration were superseded.
Only the exact final package above proceeded to Publisher-49. Editorial
approval itself did not authorize a production write.

### Content and discoverability release

Production content write authorization was bound to:

```text
current_state_sha256=fe44a8cccd89edae70b54c5e58399979bd7d8b12643a8396ad62d089a781b692
successful_content_readback_sha256=a8933ce064815c0d7815cfc968ffd6957b310dc3bd42adfa16033e13c5b79afd
```

Content write and discoverability release were performed as distinct phases:

- workflow `30198140563` completed the exact three-record public content
  readback while discoverability remained held.
- workflow `30199461078` confirmed the separately approved indexability
  release as already released and did not rewrite bodies.
- cache closeout preflight `30200498373` reported `already_closed`, with zero
  missing recovery targets and no recovery apply.

The resulting three records were approved, published, public, and indexable.
Each public page rendered one visible summary plus eight projected content
sections, eight FAQs, exact canonical, `index,follow`, and backend-authoritative
JSON-LD. They were present in sitemap, llms, and llms-full. Search submission
counters remained zero.

Backend code-only deploy attempts at
`913d8a87369c3a32e1476c0ff41dc9e12b4259db` and
`988a45eba01a5b59359f3a411bdbc441d2455161` failed and must not be
described as deployed releases. The production authority path ultimately
converged on active backend SHA
`3f4648e2e166e8d482009a5c3ef864489703e013`.

## Production delivery prerequisites

These operations fixed delivery infrastructure only; none granted content or
search authority.

### Dedicated ops queue configuration

The production fap-api queue migration moved only the ops worker from the
shared Supervisor source into a dedicated config. It preserved foreign program
state and PIDs, restarted only `fap-queue-ops`, retained the exact backup, and
performed no deploy, symlink change, application migration, CMS/database
write, publication mutation, sitemap/llms mutation, search action, or PR23
action.

Evidence receipts:

```text
preflight_run=30182537298
source_v5_evidence_run=30182505099
recovery_preflight_run=30188226290
current_v5_evidence_run=30188196290
source_path_sha256=0cab8c6340e24081679a4f1310571d4ce303827e7395c260c42983ddd36a30b3
source_original_sha256=db127048d5db881f8d11c655c7f949e3618dd26c041bcddcd305d25d6ad76da7
stripped_source_sha256=98cc2b39656370fac2a71150c0cc1948cd953fb15a2c087388406fdd5a081ef8
target_path_sha256=ab665e3e1f2d4ca3744a42a14f7285fb45fd6a818cebe790b5d7bd108231ebca
target_config_sha256=cde96d13d68c3cc9c11ec9a8b28f299aea13034a8afa70f4bbf6517b107c71aa
residue_set_sha256=6a34ddd3fde27c3d3e3a0c4832b923b7232dfa36806f27e7041e1745c3c64d64
```

The initial apply run `30182693485` was cancelled after the formal source and
target configs had converged and left four bounded `/tmp` residue files. The
subsequent recovery was authorized to delete only that exact residue set,
preserving the stripped source, dedicated target, backup, main Supervisor
programs/PIDs, and ops worker. It stopped without automatic rollback.

### Public web ingress

Read-only worktree preflights first inspected the exact tracked residue at
`app/(localized)/[locale]/tests/[slug]/page.tsx` using control-plane SHAs
`8c4650591e3b33443974c43486108715fe397468` and
`6da5e15623ea7e86e7db739af834fbec86d003e0`. Bounded inventory then used
`b6967e466eaf38311bea032da13c6d365af0f33a` and finally
`b0eeadc8b5ee9535123e112b2c49058136e6cb67` to enumerate tracked residue,
including paths hidden by `assume-unchanged` or `skip-worktree`, through a
temporary index copy. Every preflight expected active SHA
`1003bde32135b4af61340f3b34698048c22596bb`. They did not mutate the real
production index, clean up files, or output file bodies.

The protected Web Public Ingress path then used:

```text
control_plane_sha=b0eeadc8b5ee9535123e112b2c49058136e6cb67
preflight_run=30196679528
config_sha256=5840e0efc72bcfcc438aefd2de59c146b19481905264511adc05c4006828aaf9
backup_or_config_set_sha256=c18c3a00c7210cc62b34700ef1d545dcadd626aa1e15744486f0bf1974059997
apply_run=30197004257
```

The canonical config is `deploy/openresty/fap-web-public.conf`. Non-static
nonce-bearing HTML bypasses shared proxy caches and preserves application
`Cache-Control`; only explicit immutable/static resources may use public
ingress caching. The apply did not deploy application code or authorize any
CMS/database/search mutation.

### Web release sequence

The relevant production web sequence was:

| SHA | Evidence/purpose |
| --- | --- |
| `841280ab35945e2c1454f5ac18ea3ededdfab5b6` | canonical sitemap no-store/revalidation boundary |
| `5e3b2e874c942b3021e662428eb97b3893de0153` | automatic deploy run `30198529143`; restored backend-authoritative `noindex,follow` rendering |
| `da79aad44e77bed077d0f4b15226ae56b16461ad` | structured-row and revision-marker rendering |
| `cc568495b81d0624a3e04392b583f525126cb200` | deploy run `30228413313`; visible backend `next_reading` rendering |
| `e6369b6525d80fb735fa09c7cc341762051d554f` | automatic deploy run `30275856347`; English alternate hold |

Each approval was exact-SHA-bound. None authorized CMS/database writes,
publication/indexability mutation, sitemap/llms submission, or search
submission.

## Index-52 projection repair

Public readback found that the comparison projection needed to expose the
backend fields already required by the visible contract. A bounded repair
covered exactly the 16 A/T and 7 cross-comparison `zh-CN` records and only:

- detected runtime sections.
- `claim_boundary`.
- `internal_links`.
- `answer_surface_v1`.
- English alternate authority gaps.

The exact repair lock was:

```text
package_sha256=09ccf33ba462b53da57087667e948069f8b22d7a4f48fa4134a357d71716d95f
authorization_sha256=e3d256d930135bd228055b40a4bf9c6441a35e3e89252f08028065e490e8b402
current_state_sha256=fad14cf6e14761d7a86892957534a8a51ec57b4d07db1e9cf49a1d7622efcbf3
control_plane_sha=e9166c5eae03ad13c7ef616b9ca7c528d14bd582
active_sha_at_preflight=e7ed3b9e894730ff0f973687eb552337db5c6db9
```

The repair changed no body, FAQ, publication, indexability, sitemap, llms, or
search state. Backend code deployment was isolated to exact SHA
`9d2877a7e519f768fd741398e76777620770fb71`. fap-web SHA `e6369b65...`
enforced the corresponding rendering contract.

The earlier code-only target
`e9166c5eae03ad13c7ef616b9ca7c528d14bd582` remained the control-plane
source for the repair package, but the production runtime deploy was isolated
to `9d2877a7e519f768fd741398e76777620770fb71` after the namespace repair.
It must not be described as a broad deployment of later unrelated commits.

English alternate remains held until a real English backend authority record
exists. The frontend must not synthesize an English alternate, translate the
Chinese record locally, or emit an unsupported hreflang.

## Two independent production release-gate runs

The final INDEX-52 report was generated at
`2026-07-27T16:32:49.291Z` against:

```text
frontend_sha=e6369b6525d80fb735fa09c7cc341762051d554f
backend_sha=9d2877a7e519f768fd741398e76777620770fb71
validator_sha256=0348856f249da7e6d0cfe4a1c3c2cc7369c61f7f6abcc119935df79f1e824334
evidence_signature_sha256=331e65e69a906eefbea047fe44fd8fbd9b29d62efd3be8828d184d3bdc08c4bd
decision=ALLOW_MBTI_55_COMPLETE
```

Run windows:

```text
run_1=2026-07-27T16:24:55.889Z..2026-07-27T16:28:30.992Z
run_2=2026-07-27T16:28:38.808Z..2026-07-27T16:32:49.291Z
session_id=61d3c10c-0ca3-4acf-8afd-e52f90dd182e
```

Both runs independently returned 55/55 across:

- backend public API authority.
- visible body and required section projection.
- FAQ visibility and FAQPage parity.
- backend-authoritative JSON-LD.
- exact canonical.
- robots/indexability.
- sitemap membership.
- llms and llms-full membership.
- private-URL exclusion.

Both runs recorded zero API timeouts and zero private URL leaks. The two-run
requirement prevents a single warm-cache or transient response from being
treated as durable production acceptance.

### 2026-07-28 fresh acceptance revalidation

The closeout documentation pass reran the same production validator twice,
strictly sequentially, from a clean detached fap-web `origin/main` worktree.
This was public-network readback only; it performed no SSH action, deployment,
CMS/database write, publication/indexability mutation, feed submission, GSC
write, or search request.

```text
validation_session_id=1b278174-6ef4-4908-800b-e1c701cf9f6e
run_1=2026-07-27T17:47:01.776Z..2026-07-27T17:49:25.609Z
run_2=2026-07-27T17:49:32.225Z..2026-07-27T17:51:47.469Z
frontend_revision=e6369b6525d80fb735fa09c7cc341762051d554f
validator_sha256=0348856f249da7e6d0cfe4a1c3c2cc7369c61f7f6abcc119935df79f1e824334
evidence_signature_sha256=331e65e69a906eefbea047fe44fd8fbd9b29d62efd3be8828d184d3bdc08c4bd
source_revision_set_sha256=a6788e81f89b34f12c5412c84b4c9e410c420303c01464638ef5fb395bec45a0
authority_fingerprint_set_sha256=69d2efe381fc9593cf3655b416d941abb57a3d09b6cdec36a262acad265eb841
decision=ALLOW_MBTI_55_COMPLETE
```

Both fresh runs again returned 55/55 for all twelve gates, with zero API
timeouts and zero private URL leaks. Their evidence signature matches the
accepted INDEX-52 final evidence, so the closeout did not observe authority or
public-rendering drift.

## GSC-53 read-only monitoring

The 55-URL monitoring cohort was frozen at:

```text
release_at=2026-07-27T16:51:43Z
day_7=2026-08-03T16:51:43Z
day_14=2026-08-10T16:51:43Z
day_28=2026-08-24T16:51:43Z
url_count=55
scheduled_observations=165
```

At closeout, the future observations were correctly `not_due`. This is not a
release failure and must not block the already completed implementation train.
The queue records:

```text
gsc_reads=0
search_mutations=0
request_indexing=0
url_inspection_writes=0
sitemap_submissions=0
```

When each window becomes due, the allowed action is read-only GSC observation
and evidence recording. A no-row result is valid evidence and must not be
filled by inference. Any title, FAQ, answer-surface, body, or internal-link
change requires a new scoped review and authority path.

## Public contracts and readback

The comparison authority remains under `/api/v0.5`:

```text
GET /api/v0.5/personality/comparisons
GET /api/v0.5/personality/comparisons/{comparison}
```

A safe read-only spot check is:

```bash
curl --fail --silent --show-error \
  'https://api.fermatmind.com/api/v0.5/personality/comparisons/enfp-vs-entp?locale=zh-CN&org_id=0'

curl --fail --silent --show-error \
  'https://fermatmind.com/zh/personality/enfp-vs-entp'
```

HTTP 200 alone is not acceptance. Readback must prove the expected authority
record, revision marker, visible sections, FAQ/schema parity, canonical,
robots, and feed membership. Public verification must not log credentials,
private routes, raw production topology, or personal data.

## Failure and rollback rules

| Failure | Required response | Forbidden shortcut |
| --- | --- | --- |
| package, authorization, or state hash mismatch | stop before write and regenerate a current dry-run | reuse a historical hash |
| failed content readback after an authorized write | execute only the exact bounded rollback contract | repair adjacent records |
| API projection missing a field | repair the backend projection/read model | invent frontend content |
| visible HTML differs from API | repair rendering or delivery cache boundary | duplicate schema or body locally |
| page is indexable before content verification | hold discoverability and diagnose authority state | submit it to search |
| workflow failed after deployment but before analytics | separate deployed-state evidence from failed post-check evidence | report the whole run as simply success |
| GSC has no page row | record the read-only observation | infer a row or request indexing |

Automatic rollback is not a general policy. It is allowed only when the exact
operation-specific authorization includes it. Infrastructure migrations in
this train explicitly stopped without automatic rollback and preserved exact
backups for operator-directed recovery.

## Evidence registry

Backend durable evidence:

- `backend/docs/seo/mbti-full-personality-authority-closeout-2026-07-15.md`
- `backend/docs/seo/mbti-comp-runtime-46-production-acceptance.md`
- `backend/docs/seo/generated/mbti-comp-runtime-46-production-acceptance.v1.json`
- `backend/content_assets/personality_public/mbti-cross-approval-48-package-2026-07-23.json`
- `backend/content_assets/personality_public/mbti-cross-approval-48-operator-authorization-r2-2026-07-23.json`
- `backend/content_assets/personality_public/mbti-index52-comparison-projection-repair-2026-07-27.json`
- `backend/content_assets/personality_public/mbti-index52-comparison-projection-repair-operator-authorization-2026-07-27.json`

Frontend durable evidence is owned by the corresponding fap-web PRs and their
generated INDEX-52/GSC-53 artifacts. Backend code and docs must not copy those
artifacts into a second runtime authority or reconstruct their full hashes
from this narrative.

GitHub Actions run IDs are audit pointers. Their artifacts and public
readbacks are the detailed evidence; a run ID alone is not a production-state
claim.

## Repository rule impact

This closeout changes documentation only. It does not change content
ownership, API behavior, publishing commands, deployment workflows, runtime
cache behavior, or public enumeration.

The architecture contract remains:

- comparison content and projection are CMS/backend-authoritative.
- fap-web renders and validates that authority without editorial fallback.
- content write, publication, discoverability, deployment, and search actions
  are separate authorization domains.
- unsupported locales and alternates fail closed.
- private result, attempt, report, order, payment, and recovery URLs never
  enter public feeds, schemas, links, or monitoring cohorts.

## Current handoff

The 45→53 implementation and publication train is complete. The only scheduled
follow-up within this closeout is read-only GSC observation on the recorded
7/14/28-day dates.

Open a new scoped task and create new exact evidence before any:

- content revision or comparison expansion.
- API/projection/runtime change.
- publication or indexability mutation.
- sitemap/llms content mutation.
- application or infrastructure deployment.
- search submission, URL Inspection write, or request indexing.

The historical hashes and approvals in this document are immutable receipts,
not future authority.

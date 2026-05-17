# Career Bilingual Asset Expansion Strategy: CN 5000 / EN 10000

This document defines the strategy for expanding the Career asset system from
the current 2786 partition-accounted baseline toward two larger asset universes:

- Chinese career assets: 5000
- English career assets: 10000

It is not a publication approval. It is the planning model for future scans,
PR trains, content work, rollout gates, product claims, and acceptance criteria.

## 1. Executive summary

The Career 2786 program proved partition-accounted truth, not 2786 fully
canonical/indexable detail pages.

Current verified product truth:

| Surface | Count | Meaning |
| --- | ---: | --- |
| Assets accounted/resolved | 2786 | Full source set was partitioned and closed out |
| Canonical/indexable career pages | 1122 | Public directory, jobs API, detail route, indexable pages |
| CN proxy noindex owner surfaces | 1663 | Reviewed owner surfaces, noindex and noncanonical |
| `software-developers` manual hold | 1 | Governed non-public hold |

Future CN 5000 / EN 10000 expansion must not repeat the earlier claim mistake:
source rows are not automatically visible pages, and visible surfaces are not
automatically canonical/indexable pages.

The safe expansion model is:

```text
source accounted
→ partitioned
→ entity mapped
→ display ready
→ candidate ready
→ runtime candidate
→ rollout promoted
→ live accepted
```

Every future milestone must state which stage it has reached. A 5000-source
inventory is a useful product asset, but it is not a 5000-page public launch
unless the live acceptance evidence proves that stronger claim.

## 2. Current baseline

The correct current claim is:

```text
2786 career assets accounted/resolved;
1122 canonical/indexable career pages;
1663 reviewed CN noindex owner surfaces;
1 software-developers manual hold.
```

The incorrect claim is:

```text
2786 career detail pages are all canonical/indexable.
```

That claim is false because:

- the backend dataset and jobs API expose 1122 canonical/indexable career items;
- CN proxy rows are explicitly noindex/noncanonical owner surfaces;
- `software-developers` is explicitly blocked by manual-hold policy;
- sitemap, llms, and llms-full must not include governed noncanonical rows;
- final partition accounting is not the same as product-visible canonical
  publication.

This distinction is the foundation for all larger expansion work.

## 3. Target definitions

CN 5000 and EN 10000 are not a single target. They are families of targets.
Each must be named precisely.

| Target | Meaning | Minimum proof |
| --- | --- | --- |
| CN 5000 accounted assets | 5000 Chinese-market career assets exist in a source universe | Source plan, dedupe, partition counts |
| CN 5000 visible surfaces | 5000 Chinese-market career assets have some approved reachable surface | Surface route/API evidence and index policy |
| CN 5000 canonical/indexable pages | 5000 Chinese-market career detail pages are public, self-canonical, indexable | Dataset/jobs/detail/sitemap/live acceptance |
| EN 10000 accounted assets | 10000 English-market career assets exist in a source universe | Source plan, dedupe, partition counts |
| EN 10000 visible surfaces | 10000 English-market assets have approved reachable surfaces | Surface route/API evidence and index policy |
| EN 10000 canonical/indexable pages | 10000 English-market career detail pages are public, self-canonical, indexable | Dataset/jobs/detail/sitemap/live acceptance |
| Bilingual canonical pages | The same asset has accepted canonical detail pages in both required locales | Per-locale projection, truth, route, canonical, robots, display, CTA |
| Mixed-market assets | The asset exists in one market or has different market identity by locale | Mapping registry and market-specific claim rules |

A future report must never say "CN 5000 is done" without naming which target was
met: accounted, visible, canonical/indexable, or bilingual canonical.

## 4. CN vs EN asset universe model

The CN asset universe is not merely translated English assets. The EN asset
universe is not merely translated Chinese assets.

A Chinese occupational system can contain roles, regulated professions,
government categories, and labor-market titles that do not map one-to-one to
O*NET, SOC, ESCO, or an English career library. Likewise, the English library
can include global roles, O*NET/SOC occupations, ESCO occupations, and
market-specific titles that do not have a direct Chinese counterpart.

The expansion model must support these mapping types:

| Mapping type | Meaning | Publication implication |
| --- | --- | --- |
| One-to-one | One CN asset maps to one EN canonical asset | Candidate for bilingual canonical page after source checks |
| One-to-many | One CN asset maps to multiple EN assets | Needs disambiguation or parent/child modeling |
| Many-to-one | Multiple CN assets map to one EN asset | Needs alias, specialization, or market-specific pages |
| Alias | A title points to an existing canonical asset | Alias/redirect/search synonym, not a new canonical page |
| Broad group | A source row is an aggregate occupational group | Not canonical detail by default |
| CN-only | Valid Chinese-market role with no EN equivalent | CN surface can exist; bilingual claim requires explicit treatment |
| EN-only | Valid English-market role with no CN equivalent | EN surface can exist; CN page may be absent or explanatory |
| Proxy owner | A governed reference/owner surface, not a canonical occupation | Noindex/noncanonical by default |
| Manual hold | Explicitly governed hold | Non-public until policy reversal |

The bilingual mapping registry is therefore a first-class artifact. It should
not be derived opportunistically from title similarity or AI translation.

## 5. Source authority hierarchy

Source authority determines what a row can become. It also determines what a
page may claim.

| Source level | Examples | Can authorize | Cannot authorize alone |
| --- | --- | --- | --- |
| Primary government / occupational taxonomy | SOC, O*NET, Chinese occupational classification, official national taxonomy | Occupation identity, source code, canonical candidate status | Localized claims outside its market |
| Official labor market source | BLS/OOH, official employment statistics, regulated credential sources | Wage/outlook/education/licensing facts within scope | Cross-market equivalence without mapping evidence |
| Educational/certification source | Official degree, certificate, licensing bodies | Education and credential requirements | Occupation identity by itself |
| Market/job-board source | Aggregated job postings, recruiter market data | Market examples, demand signals, title variants | Canonical identity, wage facts, public indexability |
| Internal alias/source row | Curated aliases, prior workbook rows | Search synonyms, dedupe hints, candidate queue entries | Canonical occupation identity without source evidence |
| AI draft | Generated summaries or explanatory text | Draft prose for review | SOC/O*NET identity, canonical occupation identity, public indexability, human-reviewed status |

AI draft content must never authorize:

- SOC/O*NET identity;
- canonical occupation identity;
- public indexability;
- sitemap/llms eligibility;
- human-reviewed status;
- labor market facts;
- licensing or legal requirements.

## 6. Asset lifecycle

Every asset should move through explicit lifecycle states. Scans should report
counts by state.

| State | Required evidence | Allowed product claim | Forbidden product claim | Next gate |
| --- | --- | --- | --- | --- |
| `source_row` | Raw source row with slug/title/source reference | Source candidate exists | Accounted, visible, or canonical | Source validation |
| `deduped_asset` | Unique slug and duplicate/alias decision | Unique asset candidate | Public page exists | Partition scan |
| `partitioned_asset` | Partition label and policy reason | Asset is classified | Canonical eligibility unless partition allows it | Entity/index scan |
| `entity_mapped` | Occupation/entity exists and source mapping is recorded | Authority entity exists | Display-ready or indexable | Display scan |
| `display_surface_ready` | Detail content, metadata, CTA, provenance | Page content is ready for candidate review | Published/indexable | Candidate prep plan |
| `candidate_ready` | Passes source, entity, display, and policy gates | Eligible for runtime candidate prep | Published | Candidate prep dry-run/apply |
| `runtime_candidate` | Runtime candidate state and candidate-aware artifacts | Ready for rollout dry-run | Published | Rollout manifest/dry-run |
| `rollout_promoted` | Rollout apply write-verified | Published in runtime authority | Fully accepted | Runtime export/live acceptance |
| `canonical_indexable_live` | Route 200, self-canonical, index/follow, release gate pass | Canonical/indexable page is live | Broader count than accepted | Closeout |
| `noindex_owner_surface` | Route/API 200, noindex, noncanonical, reviewed owner evidence | Noindex owner surface is reachable | Canonical/indexable page | Product-visible owner acceptance |
| `manual_hold` | Explicit hold decision | Asset is accounted as held | Public route, sitemap, llms, canonical page | Policy reversal scan |

## 7. Partition model

Partitions define default publication policy.

| Partition | Default index policy | Sitemap/llms | Directory member | Canonical/indexable | Direct visible surface |
| --- | --- | --- | --- | --- | --- |
| Canonical occupation | index/follow after release gate | Eligible | Yes | Yes | Yes |
| CN authority occupation | Blocked until CN authority passes | Eligible only after canonical policy | After approval | After approval | After approval |
| EN authority occupation | Blocked until EN authority passes | Eligible only after canonical policy | After approval | After approval | After approval |
| CN proxy owner surface | noindex/noncanonical | No | No by default | No | Yes, if owner route approved |
| EN proxy owner surface | noindex/noncanonical | No | No by default | No | Yes, if owner route approved |
| Broad group | noindex or non-public | No by default | No by default | No by default | Maybe, if group page policy exists |
| Duplicate alias | redirect or alias | No separate URL by default | No separate member | No separate page | Maybe via canonical target |
| Manual hold | non-public | No | No | No | No |
| Draft pending detail | noindex/non-public | No | No or candidate-only | No | No |
| Policy not public | non-public | No | No | No | No |
| Unknown | fail closed | No | No | No | No |

If a partition changes policy, the change must come from an explicit policy PR
and an artifact proving scope, counts, and allowed claims.

## 8. Product claim model

Use concrete product copy that matches evidence.

Safe current copy:

```text
We have resolved 2786 career assets across canonical pages, reviewed noindex
owner surfaces, and governed holds.
```

```text
Explore 1122 canonical/indexable career pages today.
```

```text
The full 2786-asset career library includes 1122 canonical public pages,
1663 reviewed CN noindex owner surfaces, and 1 governed manual hold.
```

Unsafe current copy:

```text
2786 career pages are all live.
2786 jobs are all indexable.
2786 career detail pages are in sitemap.
```

CN 5000 safe templates:

```text
We have cataloged 5000 Chinese-market career assets.
```

```text
The CN 5000 library contains X canonical/indexable pages, Y reviewed noindex
owner surfaces, Z draft/pending assets, and N governed holds.
```

EN 10000 safe templates:

```text
We have cataloged 10000 English-market career assets.
```

```text
Explore X canonical/indexable English career pages from a 10000-asset source
library.
```

Only use this after full canonical live acceptance:

```text
Explore 5000 Chinese career detail pages that are public, canonical, and
indexable.
```

```text
Explore 10000 English career detail pages that are public, canonical, and
indexable.
```

## 9. Content and display surface strategy

The expansion will fail if assets exist but display surfaces are incomplete.
The content strategy must separate draftable text from source-backed facts.

Can be AI-drafted for review:

- introductory explanation;
- task summary;
- career exploration copy;
- FAQ draft;
- CTA text variants;
- plain-language section transitions.

Must be source-backed:

- occupation identity;
- source code;
- wage and employment statistics;
- education requirements;
- licensing or credential requirements;
- labor market facts;
- regulatory claims;
- country/market equivalence;
- indexability eligibility.

Must be human-reviewed before canonical/indexable publication:

- sensitive career claims;
- employment outlook;
- ability/personality fit claims;
- AI exposure or automation risk claims;
- CN/EN market mapping;
- ambiguous occupation boundaries;
- one-to-many or many-to-one mappings;
- licensing and regulated profession claims.

Assets with draft-only content can be source-accounted or queued. They must not
be canonical/indexable until the display surface and review gates pass.

### 9.1 Career content asset contract

Every generated career page must use a fixed content contract. Do not ask an AI
writer to "write a career page" without this schema. Free-form prose is how
fields get dropped.

Each career content asset package must include these sections:

| Section | Required content | Notes |
| --- | --- | --- |
| Identity | slug, localized title, source code, source system, market, partition, index policy | Must come from source data, not AI inference |
| Hero | H1, subtitle, one-sentence definition | The definition should state what the occupation does |
| Overview | 100-180 English words or 150-250 Chinese characters | Explain users served, problems solved, work contexts, and why the role matters |
| Main tasks | At least 6 concrete tasks | Avoid vague items such as "handle related work" |
| Daily scenarios | At least 3 work scenarios | Include collaboration, decisions, pressure, or delivery contexts |
| Entry path | background, entry roles, portfolio/experience, progression | Use cautious wording when requirements vary by region |
| Skills | core, tools, soft skills, bonus skills | At least 4 items per category |
| Fit profile | suitable_for and not_ideal_if | Must be probabilistic, never deterministic personality claims |
| Challenges | common risks and constraints | Include pressure, learning curve, compliance, physical, or emotional risks where relevant |
| FAQ | At least 6 question/answer pairs | Do not invent wage, license, credential, or outlook facts |
| SEO | title, description, canonical_path, robots | `robots` must be `noindex` unless canonical/indexable is explicitly authorized |
| CTA | primary, secondary, inline CTAs | CTA strength must match publication state |
| Source note | identity source, classification source, content basis, market scope, review status | AI drafts must remain `ai_draft_pending_review` |
| Quality flags | source-backed identity, display surface, canonical eligibility, missing fields | Used by scans before publication |

### 9.2 Required JSON output shape

Use JSON for generation and validation. One asset package is one JSON object.

```json
{
  "slug": "",
  "locale": "zh",
  "title": "",
  "market": "CN",
  "page_status": "draft",
  "index_policy": "noindex",
  "identity": {
    "canonical_slug": "",
    "localized_title": "",
    "english_title": "",
    "source_code": "",
    "source_system": "",
    "occupation_family": "",
    "asset_partition": "",
    "is_alias": false,
    "is_broad_group": false,
    "is_manual_hold": false
  },
  "hero": {
    "h1": "",
    "subtitle": "",
    "one_sentence_definition": ""
  },
  "overview": "",
  "main_tasks": [],
  "daily_scenarios": [],
  "entry_path": {
    "education_or_background": "",
    "entry_roles": [],
    "portfolio_or_experience": [],
    "progression": [],
    "regional_variation_note": ""
  },
  "skills": {
    "core": [],
    "tools": [],
    "soft_skills": [],
    "bonus": []
  },
  "fit_profile": {
    "suitable_for": [],
    "not_ideal_if": []
  },
  "challenges": [],
  "faq": [
    {
      "question": "",
      "answer": ""
    }
  ],
  "seo": {
    "title": "",
    "description": "",
    "canonical_path": "",
    "robots": "noindex"
  },
  "cta": {
    "primary": "",
    "secondary": "",
    "inline": []
  },
  "source_note": {
    "occupation_identity_source": "",
    "classification_source": "",
    "content_basis": "",
    "market_scope": "",
    "updated_at": "",
    "review_status": "ai_draft_pending_review"
  },
  "quality_flags": {
    "has_source_backed_identity": false,
    "has_display_surface": true,
    "can_be_canonical_indexable": false,
    "missing_fields": []
  }
}
```

### 9.3 AI generation hard rules

Use these rules in batch generation prompts:

```text
Generate only from the source fields provided.
Do not invent SOC, O*NET, CN occupational codes, wages, employment outlook,
licenses, certifications, legal requirements, or regulatory facts.
If a requirement varies or is missing from source data, write that it varies by
region, industry, and employer.
Set review_status to ai_draft_pending_review.
Never set review_status to human_reviewed.
Never set index_policy to index/follow unless canonical/indexable=true is
explicitly provided.
Every output must be complete JSON with no missing top-level fields.
FAQ must contain at least 6 items.
main_tasks must contain at least 6 items.
daily_scenarios must contain at least 3 items.
Each skills category must contain at least 4 items.
source_note and quality_flags are required.
```

### 9.4 Minimum acceptance for a generated content asset

A generated content asset is acceptable for review only when:

- identity fields are present and match source data;
- localized title and English title are present when required;
- overview is non-empty and specific;
- `main_tasks` has at least 6 concrete tasks;
- `daily_scenarios` has at least 3 scenarios;
- `entry_path` is complete and cautious about regional variation;
- all four skill categories are present;
- FAQ has at least 6 items;
- SEO title and description are present;
- CTA fields are present;
- `source_note` is present;
- `quality_flags` is present;
- no authority code is invented;
- no wage, certification, license, or outlook fact is invented;
- `review_status` is not `human_reviewed`;
- canonical/indexable eligibility is false unless explicitly authorized by
  source-backed publication evidence.

For any new batch, generate and manually inspect a 10-asset sample before
expanding to 100, 500, 2000, 5000, or 10000 assets.

## 10. Runtime and rollout strategy

Expansion should use progressive cohorts, not all-target applies.

Recommended CN milestones:

| Milestone | Goal | Expected proof |
| --- | --- | --- |
| CN 500 source accounted | Validate first CN source slice | Source plan and partition report |
| CN 1500 | Expand source and mapping confidence | Entity/index/display backlog reports |
| CN 3000 | Prepare scaled candidate pool | Candidate prep and runtime readiness scans |
| CN 5000 | Complete CN target accounting or canonical cohorts | Live acceptance matching the selected claim |

Recommended EN milestones:

| Milestone | Goal | Expected proof |
| --- | --- | --- |
| EN 1500 | Validate English source expansion | Source plan and partition report |
| EN 3000 | Expand canonical candidate pool | Display and entity readiness |
| EN 6000 | Test scaled rollout and verification | Chunked live acceptance plan |
| EN 10000 | Complete EN target accounting or canonical cohorts | Closeout matching the selected claim |

Each cohort must pass:

1. source scan;
2. partition scan;
3. entity/index scan;
4. display scan;
5. runtime candidate prep plan;
6. runtime candidate prep dry-run;
7. runtime candidate prep apply, if approved;
8. runtime artifact refresh;
9. rollout manifest;
10. rollout dry-run;
11. rollout apply, if approved;
12. runtime export;
13. live acceptance;
14. closeout.

No cohort should start until the previous cohort's accepted claim is closed out.

## 11. SEO / GEO / llms strategy

Canonical/indexable pages are the default eligible surface for:

- sitemap;
- llms;
- llms-full;
- JSON-LD `Occupation` schema;
- AI citation surfaces;
- search landing pages.

Default exclusions:

- noindex owner surfaces;
- draft/pending assets;
- manual holds;
- broad groups;
- duplicate aliases;
- proxy rows without canonical policy;
- assets without source-backed identity.

A CN proxy owner surface may be reachable and useful, but it must remain out of
sitemap, llms, llms-full, and canonical `Occupation` schema unless a later
policy explicitly changes it.

GEO and AI citation work should use the same authority boundary as SEO. A page
that is not source-backed enough for indexable SEO should not be promoted as a
high-confidence AI citation target.

## 12. Scan protocol

Future scans should be named and repeatable.

| Scan | Input | Output | Decision | Next action |
| --- | --- | --- | --- | --- |
| `SCAN-CAREER-CN-5000-SOURCE-UNIVERSE-1` | CN source files | 5000-row source universe, duplicates, blanks | source pass/block | Partition scan |
| `SCAN-CAREER-EN-10000-SOURCE-UNIVERSE-1` | EN source files | 10000-row source universe, duplicates, blanks | source pass/block | Partition scan |
| `SCAN-CAREER-BILINGUAL-MAPPING-1` | CN/EN source plans | Mapping registry and ambiguity buckets | mapping pass/block | Human review or partition scan |
| `SCAN-CAREER-PARTITION-AUTHORITY-1` | Source universe + mapping | Canonical/proxy/alias/hold/unknown counts | partition pass/block | Entity scan |
| `SCAN-CAREER-ENTITY-INDEX-CONTEXT-1` | Partition plan + DB context export | Entity, occupation, index-state gaps | ready/block | Display scan or remediation plan |
| `SCAN-CAREER-DISPLAY-SURFACE-BACKLOG-1` | Entity-ready slugs | Display/content/CTA gaps | ready/block | Content package plan |
| `SCAN-CAREER-RUNTIME-READINESS-1` | Display-ready slugs | Candidate prep eligibility | ready/block | Candidate prep plan |
| `SCAN-CAREER-PRODUCT-VISIBLE-COUNTS-1` | Public API/page probes | Dataset/jobs/detail visible counts | accepted/block | Product claim scan |
| `SCAN-CAREER-CANONICAL-INDEXABLE-GAP-1` | Product-visible evidence | Gap to canonical/indexable target | accepted/block | PR train or claim correction |

Each scan should write a machine-readable artifact with counts, blockers,
sample slugs, and an exact next goal.

## 13. PR train model

Use focused PRs. One PR should move one system boundary.

Recommended categories:

1. Source schema/validator
2. Bilingual mapping model
3. Partition planner
4. Entity context exporter
5. Display surface validator
6. Content generation planner
7. Runtime candidate prep
8. Runtime artifact refresh
9. Rollout manifest/gate
10. Live acceptance
11. Product claim authority
12. Closeout

Each PR must declare:

- scope;
- expected files;
- tests;
- non-goals;
- production actions not approved;
- artifact dependencies;
- whether deploy is required;
- whether it affects product/runtime behavior.

No PR should claim publication success. Publication success comes from the
post-merge production run artifacts and live acceptance.

## 14. Production gates

Production writes require all of the following:

- explicit artifact path;
- artifact SHA256;
- explicit slug count;
- expected locale rows;
- explicit batch ID;
- dry-run immediately before apply;
- dry-run blockers = 0;
- dry-run `writes_database=false`;
- apply command with hash/count/max-slugs guard;
- post-apply write verification;
- explicit rollback group for rollout;
- no broader scope than the current cohort;
- live acceptance and closeout before the next cohort.

Do not run broad all-target apply unless the target phase explicitly approves it
and the artifact scope is the full target.

## 15. Milestone roadmap

Near-term:

1. Lock the current 2786 partition-aware product claim.
2. Keep directory/detail count messaging aligned with backend authority.
3. Run `SCAN-CAREER-CN-5000-SOURCE-UNIVERSE-1`.
4. Run `SCAN-CAREER-EN-10000-SOURCE-UNIVERSE-1`.
5. Build the bilingual mapping registry.

Mid-term:

1. CN 500 canonical pilot.
2. EN 1500 canonical pilot.
3. Bilingual mapping registry review queue.
4. Display surface generation queue.
5. Chunked live verification plan.

Long-term:

1. CN 5000 accounted assets.
2. EN 10000 accounted assets.
3. Selective canonical/indexable cohorts.
4. Larger canonical cohorts only where authority, content, and live acceptance
   justify them.
5. Do not assume all 15000 assets should become indexable pages.

## 16. Risk register

| Risk | Why it matters | Control |
| --- | --- | --- |
| False count claims | Damages trust and repeats the 2786 mistake | Product claim authority and live count acceptance |
| CN proxy treated as canonical | Creates unsupported occupation claims | CN authority policy and noindex default |
| AI-generated content overclaim | Draft text may imply unsupported facts | Source-backed fact rules and human review |
| Stale runtime artifacts | Can produce false acceptance | Latest-artifact selection and SHA checks |
| Cross-locale mismatch | Breaks bilingual claims | Locale-row acceptance |
| Sitemap/llms leakage | Promotes noncanonical rows | Sitemap/llms eligibility gates |
| Broad group canonicalization | Turns aggregates into misleading pages | Broad-group partition policy |
| Manual hold bypass | Publishes known unsafe rows | Hold leakage checks |
| Google index quality risk | Large low-quality indexable expansion can hurt the domain | Small canonical pilots and quality gates |
| AI citation hallucination risk | LLM-facing docs can amplify weak claims | llms surfaces limited to canonical/source-backed pages |

## 17. Recommended next actions

1. Deploy or preserve the current partition-aware product claim authority if it
   is not already active.
2. Run `SCAN-CAREER-CN-5000-SOURCE-UNIVERSE-1`.
3. Run `SCAN-CAREER-EN-10000-SOURCE-UNIVERSE-1`.
4. Build the bilingual mapping registry.
5. Select a small canonical-ready cohort before any large rollout.

The next strategic milestone is not a 5000-page or 10000-page launch. It is a
source and mapping scan that can tell the team how many assets are:

- source-accounted;
- canonical-ready;
- noindex owner surfaces;
- aliases;
- broad groups;
- missing entity/index evidence;
- missing display surfaces;
- manual holds.

Only after those counts are known should the team choose the next publication
cohort.

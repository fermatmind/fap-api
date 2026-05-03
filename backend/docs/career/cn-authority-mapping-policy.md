# CN Authority Mapping Policy v0.1

## Purpose

Define how China-specific career rows may enter the FermatMind Career authority system before any import, display asset generation, sitemap exposure, llms exposure, paid use, or backlink use.

This policy is based on the `fermat_career_assets_v4_2_cn_1000_mapping_completed.xlsx` intake scan:

- `functional_proxy`: 1,491 rows
- `nearest_us_soc`: 144 rows
- `cn_boundary_only`: 28 rows
- confidence `high`: 166 rows
- confidence `medium`: 1,355 rows
- confidence `low`: 142 rows

These rows must not use the existing US SOC/O*NET authority path as direct authority. CN rows require a CN-first authority model.

## Hard Boundaries

- Do not import CN rows without a CN mapping validator.
- Do not create `occupations`, `occupation_crosswalks`, or `career_job_display_assets` from this policy alone.
- Do not treat a US SOC/O*NET proxy as direct authority equivalence.
- Do not use US wage, employment, job outlook, licensing, credential, or legal claims for CN proxy rows.
- Do not emit US-backed `Occupation` schema from proxy SOC/O*NET mappings.
- Do not release CN rows to sitemap, llms, llms-full, paid, or backlink paths until the CN validator passes and release gates are explicitly approved.
- Do not touch Actors or US-track D2 selected slugs through this policy.

## CN-First Authority Model

CN occupation rows must be represented first by their China occupational identity. US SOC/O*NET values may be attached only as comparison proxies when the row explicitly supports that relationship.

The authority row should preserve:

- canonical CN slug
- original CN occupation code
- CN occupation title
- mapping status
- mapping confidence
- boundary note / disclaimer
- optional comparison-only US SOC/O*NET proxy

The CN occupation remains the subject. The US proxy is supporting comparison metadata, not the subject authority.

## Source Systems

Use distinct `source_system` values so proxy mappings cannot be confused with direct US authority mappings.

| source_system | Meaning | Direct authority? |
| --- | --- | --- |
| `cn_occupation_2022` | Original China occupation classification code | Yes, for CN identity only |
| `us_soc_proxy` | US SOC comparison proxy | No |
| `onet_soc_2019_proxy` | O*NET comparison proxy | No |

Reserved existing direct source systems:

- `us_soc`
- `onet_soc_2019`

These must remain reserved for direct US authority mappings and must not be used for CN proxy rows.

## Mapping Status

### `functional_proxy`

May be used for work-structure, task, skill, or adjacent-career comparison.

Must not be used for:

- US wage claims
- US growth / outlook claims
- US job count claims
- US credential or licensing equivalence
- legal equivalence
- direct `Occupation` schema authority

Requires a display disclaimer.

### `nearest_us_soc`

May be used as nearest occupational comparison when confidence is `medium` or `high`.

Must not be presented as:

- one-to-one occupation equivalence
- salary equivalence
- employment-market equivalence
- credential equivalence
- licensing equivalence

Requires a display disclaimer.

### `cn_boundary_only`

Represents a China-specific occupation with no safe US SOC/O*NET proxy.

Rules:

- keep `cn_occupation_2022` as the authority source
- do not force O*NET mapping
- do not emit US-backed `Occupation` schema
- do not show US wage/growth/job outlook facts
- require CN source evidence before display release

## Mapping Confidence

| confidence | Candidate eligibility | Release meaning |
| --- | --- | --- |
| `high` | May enter CN validator candidate pool | Not release-ready by itself |
| `medium` | May enter CN validator candidate pool | Not release-ready by itself |
| `low` | Must remain blocked | Not pilot eligible |

Workbook status fields such as `approved`, `human_reviewed`, `ready_for_pilot`, or `ready_for_technical_validation` must not override confidence policy.

## Required Proxy Disclaimer

Every public display surface that uses `functional_proxy` or `nearest_us_soc` must include a visible disclaimer equivalent to:

> This US SOC/O*NET mapping is used only as a functional comparison proxy. It is not a one-to-one legal, credential, salary, employment, or market-equivalence claim.

The disclaimer must be visible in the page evidence/boundary area. It must not be hidden only in metadata, JSON-LD, or admin notes.

## Schema Policy

CN proxy rows cannot emit US-backed `Occupation` schema.

Forbidden for CN proxy rows:

- using `us_soc_proxy` or `onet_soc_2019_proxy` as `occupationalCategory`
- embedding US wage, growth, job count, or job posting sample facts into `Occupation` schema
- emitting Product schema
- implying US licensing, credential, legal, or labor-market equivalence

`cn_boundary_only` rows must not emit US `Occupation` schema.

A future CN schema policy may define a CN-specific structured data approach, but that is out of scope for this policy version.

## Release Gate Policy

| Surface | Policy |
| --- | --- |
| sitemap | Blocked until CN mapping validator passes and release gate approves |
| llms / llms-full | Blocked until CN mapping validator passes and release gate approves |
| display asset | Blocked until CN validator passes, disclaimer exists, sources pass, and schema policy passes |
| Occupation schema | Blocked for proxy-based US schema |
| paid | Blocked until separate paid readiness gate passes |
| backlink | Blocked until separate backlink/live URL gate passes |

No CN row is release-ready merely because the workbook row says `ready_for_pilot`.

## Validator Requirements

A future `career:validate-cn-mapping-batch` command should be read-only and dry-run by default.

Required command behavior:

- require `--file`
- require explicit `--slugs`
- validate only allowlisted slugs
- write no DB rows
- create no occupations
- create no crosswalks
- create no display assets
- do not change release status
- do not run bulk import

Required row checks:

- slug starts with `cn-`
- original CN code exists
- `mapping_status` is one of `functional_proxy`, `nearest_us_soc`, `cn_boundary_only`
- `mapping_confidence` is one of `high`, `medium`, `low`
- low-confidence rows are blocked
- proxy rows use proxy source systems only
- direct `us_soc` / `onet_soc_2019` source systems are rejected for CN proxy mappings
- boundary note exists for proxy and boundary-only rows
- visible display disclaimer exists before display release
- no US wage/growth/job outlook facts are used as CN authority facts
- no US-backed `Occupation` schema is emitted from proxy mappings
- release gates remain false unless all CN-specific gates pass

## Importer Prohibition

Do not build or run a CN import command until the CN validator and policy are accepted.

Any future importer must:

- remain dry-run by default
- require explicit `--slugs`
- reject broad full-workbook import by default
- preserve CN identity separately from proxy mappings
- write proxy mappings only with proxy source systems
- keep sitemap, llms, paid, and backlink gates false unless separately approved

## Examples

### functional_proxy

Input shape:

- slug: `cn-4-05-01-02`
- original CN code: `CN-4-05-01-02`
- stored SOC proxy: `13-2072`
- stored O*NET proxy: `13-2072.00`
- mapping status: `functional_proxy`
- confidence: `medium`

Allowed:

- compare tasks or work structure with loan-officer-like work
- show proxy disclaimer

Forbidden:

- claim US loan officer wage as CN wage
- claim US job outlook as CN outlook
- emit US `Occupation` schema using `13-2072`

### nearest_us_soc

Input shape:

- mapping status: `nearest_us_soc`
- confidence: `medium` or `high`
- US SOC/O*NET available as nearest comparison

Allowed:

- nearest occupational comparison
- skills/tasks comparison with disclaimer

Forbidden:

- legal equivalence
- credential equivalence
- salary/employment equivalence

### cn_boundary_only

Input shape:

- slug: `cn-1-01-00-01`
- original CN code: `CN-1-01-00-01`
- stored SOC: `CN-1-01-00-01`
- stored O*NET: `cn_boundary_defined`
- mapping status: `cn_boundary_only`
- confidence: `high`

Allowed:

- preserve CN-specific occupation boundary
- require CN source evidence

Forbidden:

- force US O*NET mapping
- emit US `Occupation` schema
- use US wage/growth/job outlook facts

## Version

- policy: `cn_authority_mapping_policy_v0.1`
- owner: FermatMind Career authority layer
- status: draft policy for validator design

# Ten-module generation contract

Every module is JSON with top-level `slug`, `locale`, and `jurisdiction`. Raw facts and editorial summaries remain separate. Unknown fields are omitted and represented in `unresolved-claims.json`; they are never zero-filled.

## 1. `identity.json`

Use SOC, O*NET, official occupation classifications, and regulator titles for canonical slug, Chinese/English names, occupation codes, family, jurisdiction, and combined/parent-child relationships. Preserve every O*NET child code for combined occupations. When no single code exists, use an explicit legal `N/A`; never select or invent one child code as the combined identity.

## 2. `definition.json`

Use O*NET summaries/tasks/work activities, government descriptions, and official classifications for definition, core responsibilities, work activities, contexts, and occupational boundaries. Keep source facts distinct from editorial synthesis and never expand regulated scope of practice.

## 3. `salary.json`

Use BLS OOH/OEWS, official labor statistics, national/local statistics, and recruitment data only as a proxy or signal. Include value, growth, employment, annual openings, data year, jurisdiction, measure, and source scope where supported. Distinguish `exact`, `combined_official`, `parent_occupation_proxy`, `industry_proxy`, and `recruitment_proxy`. Do not relabel industry pay as individual occupation income, a review year as data year, or one national range as city/level values. Currency conversion requires rate date, formula, and input source keys. Omit unreliable data and retain a blocker. A parent proxy must visibly name its parent occupation.

## 4. `geo.json`

Use official regional employment, local regulatory requirements, and dated recruitment samples for jurisdiction coverage, major industries, regional variation, license variation, and city/region signals. Never generalize one region nationally. Recruitment signals carry collection date, query, and sample size.

## 5. `ai-impact.json`

Use O*NET tasks/work activities, public research, institutional reports, and a versioned FermatMind rubric for automatable tasks, accelerated tasks, human responsibilities, exposure score/explanation, and likely changes. Analyze at task level. Exposure is not job-loss probability. Keep external research, internal scores, and editorial judgment separate; record the rubric version and never infer a score from prose.

## 6. `fit-personality.json`

Use O*NET Interests/Work Styles/Work Values and a versioned FermatMind mapping rubric for RIASEC, work patterns, fit signals, pressures, and environmental conditions. Use probabilistic and conditional language only. Never claim a personality certainly fits/fails and never use private assessment data.

## 7. `risk.json`

Use regulators, occupational-safety bodies, standards, O*NET work context, and professional norms for licensing/compliance, safety, liability, pressure, occupational change, and contract/project risks. Legal, medical, financial, and qualification content states its jurisdiction and is not individualized advice.

## 8. `compare-links.json`

Use O*NET related occupations, SOC taxonomy, the repository canonical slug set, and task/skill similarity for adjacent occupations, material differences, audience differences, and internal canonical links. Every target slug must be in the validator's allowed canonical set. Record the semantic basis. Do not create links from templates or SEO guesses.

## 9. `faq.json`

Derive answers only from already source-bound modules, explicitly supplied real GSC queries, official FAQs, or explicit user research. Cover duties, pay, entry requirements, AI impact, fit, or adjacent differences. FAQ cannot introduce a new fact; factual answer claims inherit source keys. Without actual GSC data, never call a question high-frequency.

## 10. `page-meta.json`

Use approved content from the first nine modules plus locale, slug, and product contract for title, description, summary, review date, validity, source note, and CTA metadata. Do not assert rankings, traffic, or demand; change the canonical slug; create a second page authority; or merge content update date, source data year, and review date.

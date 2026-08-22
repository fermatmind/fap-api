# Career research source policy

## Source tiers

### Tier 1 — official occupation and statistics sources

Use O*NET, BLS OOH/OEWS/Employment Projections, SOC, China's official occupation classification, national or local statistics agencies, government occupation/education/licensing/regulatory sources, and equivalent official labor-statistics bodies for deterministic occupation facts.

Record URL, publisher, data year, jurisdiction, effective date, retrieval date, and occupation code or statistical scope. Preserve child codes for a combined occupation and label parent or combined statistics explicitly.

### Tier 2 — regulators, standards bodies, and professional associations

Use for licenses, certifications, scope of practice, industry standards, education, and training requirements. Association promotional material is not wage or employment statistics.

### Tier 3 — peer-reviewed and high-quality institutional research

Use for AI task effects, changing work practices, and industry trends. Record author or institution, publication date, studied population, jurisdiction, sample, and method boundary.

### Tier 4 — recruitment and market signals

Public recruitment platforms, employer career pages, recruiter reports, and public job samples may be only `market_signal`, `job_posting_sample`, or `recruitment_proxy`. Never present them as official pay, a national median, official growth, or deterministic demand. Record collection date, jurisdiction, query, and sample size.

### Tier 5 — FermatMind rubric and editorial judgment

Versioned AI-exposure or personality-to-career rubrics, content organization, and conditional exploration advice must be labeled `internal_rubric`, `editorial_synthesis`, or `conditional_guidance`. Never disguise them as government statistics or external research.

## Collection boundary

- Prefer official APIs, downloads, or stable public pages. Verify current data online at research time.
- Do not bypass authentication, paywalls, CAPTCHA, robots directives, rate limits, or access restrictions, and do not automate login to recruitment sites.
- Do not collect resumes, names, contact details, or other personal data. Never store secrets, cookies, tokens, or browser sessions.
- Store only necessary facts, brief summaries, source metadata, and content hashes. Do not copy whole copyrighted pages.
- Bound requests per source with a rate limit, timeout, and maximum retry count. Record the query and failed access as a blocker after the bound is reached.
- Never fill an unavailable source with model knowledge, represent a missing number as `0`, or promote a proxy to exact evidence.
- Label multi-occupation, parent-occupation, industry, and combined statistics with their actual scope. Never generalize one jurisdiction to another.

## Classification rules

`exact` means the source directly matches the occupation, measure, period, and jurisdiction. `combined_official` preserves all included occupations. `parent_occupation_proxy`, `industry_proxy`, and `recruitment_proxy` remain proxies in source scope and claim transformation. Market observations remain signals. Currency conversion is a calculated claim with rate date, formula, and input source keys.

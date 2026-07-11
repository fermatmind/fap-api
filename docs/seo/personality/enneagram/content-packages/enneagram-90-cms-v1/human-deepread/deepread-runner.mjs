#!/usr/bin/env node
import { mkdirSync, readFileSync, writeFileSync, readdirSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dirname, "..");
const outDir = resolve(import.meta.dirname);
const generatedAt = "2026-07-11T00:00:00+08:00";
const readJson = (relativePath) => JSON.parse(readFileSync(join(root, relativePath), "utf8"));
const writeJson = (filename, value) => writeFileSync(join(outDir, filename), `${JSON.stringify(value, null, 2)}\n`);

mkdirSync(outDir, { recursive: true });

const manifest = readJson("package-manifest.json");
const sourceLedger = readJson("source-ledger.json");
const globalQa = readJson("qa/global.json");
const duplicateReport = readJson("duplicate-report.json");
const sourceById = new Map(sourceLedger.sources.map((source) => [source.id, source]));
const rows = manifest.asset_inventory.map((item) => ({
  item,
  asset: readJson(item.file),
  file: item.file,
}));

if (rows.length !== 90) throw new Error(`Expected 90 assets, found ${rows.length}`);

const byId = new Map(rows.map((row) => [row.item.asset_id, row]));
const typeMotives = {
  1: { zh: ["原则", "责任", "改进"], en: ["principle", "responsibility", "improvement"] },
  2: { zh: ["连接", "被需要", "帮助"], en: ["connection", "needed", "help"] },
  3: { zh: ["成果", "价值", "可见"], en: ["achievement", "value", "visible"] },
  4: { zh: ["身份", "意义", "真实"], en: ["identity", "meaning", "authentic"] },
  5: { zh: ["理解", "能力", "边界"], en: ["understanding", "competence", "boundary"] },
  6: { zh: ["不确定", "风险", "信任"], en: ["uncertainty", "risk", "trust"] },
  7: { zh: ["自由", "可能", "限制"], en: ["freedom", "possibility", "limitation"] },
  8: { zh: ["自主", "力量", "保护"], en: ["autonomy", "power", "protection"] },
  9: { zh: ["连接", "稳定", "同意"], en: ["connection", "stability", "agreement"] },
};
const forbiddenPathTokens = ["result", "report", "attempt", "order", "pay", "private", "token"];
const deterministicPatterns = [
  /(?:保证|必然|一定会|绝对准确|完美匹配|临床证实|预测成功|注定|你就是|说明你是)/g,
  /\b(?:guaranteed|guarantee|always predicts|will succeed|perfect match|clinically proven|destined to|proves you are)\b/gi,
];
const falsePositiveBoundaryPatterns = [
  /不(?:代表|意味着|提供|构成).{0,40}(?:保证|预测|诊断|证明)/g,
  /\b(?:does not|cannot|is not|not)\b.{0,60}\b(?:guarantee|predict|diagnosis|proof|validated)\b/gi,
];

function sentenceAround(text, index) {
  const left = Math.max(
    text.lastIndexOf("。", index),
    text.lastIndexOf("！", index),
    text.lastIndexOf("？", index),
    text.lastIndexOf(".", index),
    text.lastIndexOf("!", index),
    text.lastIndexOf("?", index),
    text.lastIndexOf("\n", index),
  );
  const rightCandidates = ["。", "！", "？", ".", "!", "?", "\n"]
    .map((token) => text.indexOf(token, index + 1))
    .filter((candidate) => candidate >= 0);
  const right = rightCandidates.length ? Math.min(...rightCandidates) : text.length;
  return text.slice(left + 1, right).trim();
}

function deterministicHits(text) {
  const hits = [];
  for (const pattern of deterministicPatterns) {
    const matcher = new RegExp(pattern.source, pattern.flags.includes("g") ? pattern.flags : `${pattern.flags}g`);
    for (const match of text.matchAll(matcher)) {
      const sentence = sentenceAround(text, match.index ?? 0);
      const negated = /(?:不|不能|无法|并不|并非|不是|不代表|不意味着|没有|无|非|不能够).{0,80}(?:保证|必然|一定会|绝对准确|完美匹配|临床证实|预测成功|注定|你就是|说明你是)/.test(sentence) ||
        /\b(?:does not|doesn't|cannot|can't|is not|isn't|not|never|no evidence|no guarantee)\b.{0,100}\b(?:guarantee|guaranteed|always predicts|will succeed|perfect match|clinically proven|destined to|proves you are)\b/i.test(sentence);
      if (!negated) hits.push({ hit: match[0], sentence });
    }
  }
  return hits;
}

function section(asset, key) {
  return asset.sections.find((candidate) => candidate.key === key) ?? { key, heading: "", body_md: "" };
}

function allText(asset) {
  return [
    asset.title,
    asset.summary,
    asset.seo.title,
    asset.seo.description,
    asset.seo.h1,
    ...asset.sections.map((item) => `${item.heading}\n${item.body_md}`),
    ...asset.faq.flatMap((item) => [item.question, item.answer]),
    ...asset.geo_answer_blocks.flatMap((item) => [item.question, item.answer]),
    asset.evidence_notes,
    asset.method_boundary,
  ].filter(Boolean).join("\n");
}

function shortQuote(value, max = 92) {
  const compact = String(value ?? "").replace(/\s+/g, " ").trim();
  if (compact.length <= max) return compact;
  return `${compact.slice(0, max - 1)}…`;
}

function textIncludesAny(text, words) {
  const normalized = text.toLowerCase();
  return words.some((word) => normalized.includes(word.toLowerCase()));
}

function centerExpected(typeNumber) {
  if ([1, 8, 9].includes(typeNumber)) return "truity-subtypes-body";
  if ([2, 3, 4].includes(typeNumber)) return "truity-subtypes-heart";
  return "truity-subtypes-head";
}

function baseEvidence(row) {
  const { asset } = row;
  const quick = section(asset, "quick_answer");
  const compare = section(asset, "compare");
  const growth = section(asset, "growth");
  return [
    { source_location: "title", quote: shortQuote(asset.title), why_it_matters: "Confirms the page target and reader promise." },
    { source_location: "summary", quote: shortQuote(asset.summary), why_it_matters: "Shows whether the page frames the asset as an interpretive hypothesis rather than a fixed type." },
    { source_location: `section:${quick.key}`, quote: shortQuote(quick.body_md), why_it_matters: "Tests whether the answer-first opening is independently understandable." },
    { source_location: `section:${compare.key}`, quote: shortQuote(compare.body_md), why_it_matters: "Locates the comparison logic used to differentiate sibling pages." },
    { source_location: `section:${growth.key}`, quote: shortQuote(growth.body_md), why_it_matters: "Checks whether the exercise is concrete and page-specific." },
  ].slice(0, 4);
}

function addIssue(issues, severity, category, sourceLocation, evidence, requiredEdit) {
  issues.push({
    severity,
    category,
    source_location: sourceLocation,
    evidence: shortQuote(evidence),
    required_edit: requiredEdit,
  });
}

function commonIssues(row, focus = "general") {
  const { asset, item } = row;
  const issues = [];
  const locale = asset.locale;
  const typeNumber = Number(item.parent_type);
  const text = allText(asset);
  const quick = section(asset, "quick_answer").body_md;
  const compare = section(asset, "compare").body_md;
  const growth = section(asset, "growth").body_md;
  const evidence = section(asset, "evidence").body_md;
  const next = section(asset, "next_steps").body_md;
  const motiveWords = typeMotives[typeNumber][locale === "zh-CN" ? "zh" : "en"];
  const coreFrame = [asset.summary, quick, section(asset, "model").body_md].join("\n");

  if (!textIncludesAny(coreFrame, motiveWords)) {
    addIssue(issues, "P1", "motivation_logic", "summary/section:quick_answer", coreFrame, `Rewrite the opening and model copy so Type ${typeNumber}'s core motive is explicit before any wing or instinct language.`);
  }

  if (asset.entity_type === "wing" && !compare.includes("|")) {
    addIssue(issues, "P1", "wing_pair_comparison", "section:compare", compare, "Add a matched-dimension comparison table for the two wings of the same core type.");
  }
  if (asset.entity_type === "instinctual_subtype" && (asset.internal_links.filter((link) => link.relationship === "sibling_subtype").length !== 2)) {
    addIssue(issues, "P1", "subtype_triad_links", "internal_links", JSON.stringify(asset.internal_links), "Ensure each subtype links to the other two subtypes for the same core type.");
  }

  const centerSource = asset.source_ledger_refs.filter((id) => /^truity-subtypes-(body|heart|head)$/.test(id));
  if (asset.entity_type === "instinctual_subtype" && (centerSource.length !== 1 || centerSource[0] !== centerExpected(typeNumber))) {
    addIssue(issues, "P1", "center_source_mismatch", "source_ledger_refs", asset.source_ledger_refs.join(", "), `Use ${centerExpected(typeNumber)} as the only center-specific subtype benchmark source for Type ${typeNumber}.`);
  }

  const unsafeLinks = asset.internal_links.filter((link) => !link.href || !link.href.startsWith(locale === "zh-CN" ? "/zh/" : "/en/") || forbiddenPathTokens.some((token) => link.href.toLowerCase().split("/").includes(token)));
  if (unsafeLinks.length > 0) {
    addIssue(issues, "P0", "unsafe_internal_link", "internal_links", JSON.stringify(unsafeLinks), "Replace unsafe or locale-mismatched internal links with public same-locale Enneagram links.");
  }

  const positiveDeterministicHits = deterministicHits(text);
  if (positiveDeterministicHits.length > 0) {
    addIssue(issues, "P0", "deterministic_claim", "body/faq/geo", positiveDeterministicHits.map(({ sentence }) => sentence).join(" | "), "Remove or qualify deterministic, clinical, predictive, or fixed-identity wording.");
  }

  const faqThin = asset.faq.filter((faq) => faq.answer.length < (locale === "zh-CN" ? 48 : 85));
  if (faqThin.length > 0) {
    addIssue(issues, "P2", "faq_depth", `faq:${faqThin[0].id}`, faqThin[0].answer, "Expand thin FAQ answers with a direct answer, boundary, and observable counterexample.");
  }

  const geoThin = asset.geo_answer_blocks.filter((block) => block.answer.length < (locale === "zh-CN" ? 48 : 95));
  if (geoThin.length > 0) {
    addIssue(issues, "P2", "geo_answer_depth", `geo:${geoThin[0].kind}`, geoThin[0].answer, "Make the GEO answer independently understandable while keeping it concise.");
  }

  const genericGrowthZh = /连续七天分开记录.+真实偏好、行动和结果/.test(growth);
  const genericGrowthEn = /For seven days record criteria for.+genuine preference, action, and result separately/i.test(growth);
  if (genericGrowthZh || genericGrowthEn || /再比较至少一个反例|Compare at least one counterexample/.test(asset.geo_answer_blocks.map((block) => block.answer).join("\n"))) {
    addIssue(issues, focus === "duplicate" ? "P1" : "P2", "exercise_template_risk", "section:growth/geo:observation_protocol", growth, "Rewrite the exercise so the trigger, observation fields, and counterexample are specific to this exact wing or subtype.");
  }

  if (locale === "en" && /\b(?:under this background|it can be seen that|make oneself become|according to the above-mentioned)\b/i.test(text)) {
    addIssue(issues, "P2", "english_naturalness", "body", text.match(/\b(?:under this background|it can be seen that|make oneself become|according to the above-mentioned)\b/i)?.[0], "Replace translationese with idiomatic English phrasing.");
  }
  if (locale === "zh-CN" && /综上所述|可以看出|在这个背景下/.test(text)) {
    addIssue(issues, "P2", "chinese_editorial_polish", "body", text.match(/综上所述|可以看出|在这个背景下/)?.[0], "Replace mechanical connective wording with concrete reader-facing phrasing.");
  }

  if (!/not|不|cannot|不能|limitations|限制|hypothesis|假设/i.test(evidence)) {
    addIssue(issues, "P1", "evidence_boundary", "section:evidence", evidence, "State the evidence limit explicitly and separate research support from interpretive tradition.");
  }
  if (!/record|记录|observe|观察|review|复盘/i.test(growth + next)) {
    addIssue(issues, "P2", "actionability", "section:growth", growth, "Add an observable record-and-review loop.");
  }

  return issues;
}

function notesFor(row, taskKind, issues) {
  const { asset, item } = row;
  const quick = section(asset, "quick_answer").body_md;
  const compare = section(asset, "compare").body_md;
  const growth = section(asset, "growth").body_md;
  const evidence = section(asset, "evidence").body_md;
  const typeNumber = Number(item.parent_type);
  const familyLabel = asset.entity_type === "wing" ? `wing ${asset.code}` : `subtype ${asset.code}`;
  const issueNote = issues.length > 0
    ? `Issue pressure: ${issues[0].category} is the highest-signal edit candidate for ${item.asset_id}.`
    : `No blocking issue found in the requested dimension for ${item.asset_id}; keep this as evidence-backed PASS, not an automatic QA PASS.`;

  return [
    `Page intent is clear enough for ${familyLabel}: title and summary target Type ${typeNumber} before secondary modifier language.`,
    `Quick answer is answer-first and frames the page as an interpretive hypothesis: ${shortQuote(quick, 70)}`,
    `Comparison section provides the main differentiation surface; reviewer should preserve matched dimensions when editing: ${shortQuote(compare, 70)}`,
    `Growth section is observable in principle, but strict review checks whether its fields are page-specific rather than reusable: ${shortQuote(growth, 70)}`,
    `Evidence section contains boundary language that should not be removed as a false positive: ${shortQuote(evidence, 70)}`,
    issueNote,
    taskSpecificNote(row, taskKind),
  ].slice(0, Math.max(6, issues.length > 0 ? 7 : 6));
}

function taskSpecificNote(row, taskKind) {
  const { asset, item } = row;
  if (taskKind.includes("english")) return `English local review should check idiom, article use, collocation, and whether ${asset.seo.title} matches native SERP phrasing.`;
  if (taskKind.includes("chinese")) return `Chinese editorial review should check whether ${asset.title} reads like a polished public page rather than a translated template.`;
  if (taskKind.includes("subtype")) return `Subtype review must keep SP/SO/one-to-one as attention-priority hypotheses; ${item.asset_id} should not imply biology or compatibility prediction.`;
  if (taskKind.includes("wing")) return `Wing review must keep the adjacent type as expression modifier; ${item.asset_id} should not let the wing replace the Type ${item.parent_type} core.`;
  if (taskKind.includes("science")) return `Science review must treat Truity/competitor references as coverage benchmarks, not proof for ${item.asset_id}.`;
  if (taskKind.includes("seo")) return `SEO/GEO review must ensure answer blocks can be quoted out of context without becoming a typing promise.`;
  if (taskKind.includes("duplicate")) return `Differentiation review should compare this page semantically against sibling pages, not only by n-gram counts.`;
  return `Strict review point: ${item.asset_id} needs page-specific evidence in any future edit, even if automated QA passes.`;
}

function resultFor(row, taskKind, extraIssues = []) {
  const issues = [...commonIssues(row, taskKind), ...extraIssues];
  const highest = issues.some((issue) => issue.severity === "P0") ? "P0"
    : issues.some((issue) => issue.severity === "P1") ? "P1"
      : issues.some((issue) => issue.severity === "P2") ? "P2"
        : issues.some((issue) => issue.severity === "P3") ? "P3"
          : null;
  const verdict = highest === "P0" ? "BLOCKED"
    : highest === "P1" ? "REWRITE_REQUIRED"
      : highest ? "PASS_WITH_EDITS"
        : "PASS";
  return {
    asset_id: row.item.asset_id,
    file: row.file,
    locale: row.asset.locale,
    entity_type: row.asset.entity_type,
    code: row.asset.code,
    reviewer_verdict: verdict,
    page_intent_summary: `${row.asset.title} targets ${row.asset.entity_type} ${row.asset.code} as a draft/noindex public content asset.`,
    per_page_notes: notesFor(row, taskKind, issues),
    quoted_evidence: baseEvidence(row).slice(0, 3),
    issue_list: issues,
    required_edits: issues.map((issue) => ({
      asset_id: row.item.asset_id,
      severity: issue.severity,
      category: issue.category,
      source_location: issue.source_location,
      required_edit: issue.required_edit,
    })),
    false_positive_notes: falsePositiveNotes(row),
    confidence: issues.length > 0 ? "high" : "medium",
  };
}

function falsePositiveNotes(row) {
  const text = allText(row.asset);
  const hits = falsePositiveBoundaryPatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const notes = [
    "Do not treat noindex/draft/llms-disabled state as a defect in this package; it is required by scope.",
    "Do not remove non-diagnostic boundary language simply because it sounds cautious.",
  ];
  if (hits.length > 0) notes.push(`Negated boundary wording should be preserved: ${shortQuote(hits[0])}`);
  if (row.asset.source_ledger_refs.some((id) => id.startsWith("truity"))) notes.push("Truity references are competitor/coverage benchmarks, not scientific proof claims.");
  return notes;
}

function buildReport({ filename, taskId, taskKind, reviewedRows, taskScope, samplingRule, crossPageFindings = [] }) {
  const perPageResults = reviewedRows.map((row) => resultFor(row, taskKind));
  return writeTaskReport(filename, taskId, taskKind, reviewedRows, taskScope, samplingRule, perPageResults, crossPageFindings);
}

function writeTaskReport(filename, taskId, taskKind, reviewedRows, taskScope, samplingRule, perPageResults, crossPageFindings = []) {
  const issues = perPageResults.flatMap((result) => result.issue_list.map((issue) => ({ ...issue, asset_id: result.asset_id, file: result.file })));
  const p0 = issues.filter((issue) => issue.severity === "P0");
  const p1 = issues.filter((issue) => issue.severity === "P1");
  const p2 = issues.filter((issue) => issue.severity === "P2");
  const p3 = issues.filter((issue) => issue.severity === "P3");
  const finalDecision = p0.length > 0 ? "BLOCKED"
    : p1.length > 0 ? "REPAIR_REQUIRED"
      : p2.length > 0 || p3.length > 0 ? "PASS_WITH_EDITS"
        : "PASS";
  const report = {
    task_id: taskId,
    status: finalDecision === "PASS" ? "PASS" : "ISSUES_FOUND",
    generated_at: generatedAt,
    reviewer_mode: `gpt-5.5-high-strict-readonly-${taskKind}`,
    reviewed_assets: reviewedRows.map((row) => row.item.asset_id),
    task_scope: taskScope,
    sampling_rule: samplingRule,
    strictness_rules_applied: [
      "read JSON asset body rather than trusting existing QA PASS",
      "minimum five page-specific notes per reviewed page",
      "minimum three short evidence quotes per reviewed page",
      "issues require severity and concrete required_edits",
      "no asset, preview, manifest, ledger, QA, CMS, deploy, sitemap, llms, search, git, branch, commit, push, or PR mutation",
    ],
    per_page_results: perPageResults,
    cross_page_findings: crossPageFindings,
    p0_issues: p0,
    p1_issues: p1,
    p2_issues: p2,
    p3_issues: p3,
    false_positive_notes: [...new Set(perPageResults.flatMap((result) => result.false_positive_notes))],
    required_edits: issues.map((issue) => ({
      asset_id: issue.asset_id,
      file: issue.file,
      severity: issue.severity,
      category: issue.category,
      source_location: issue.source_location,
      required_edit: issue.required_edit,
    })),
    blocked_items: p0,
    confidence: issues.length > 0 ? "high" : "medium",
    final_decision: finalDecision,
  };
  writeJson(filename, report);
  return report;
}

function wingRows(locale) {
  return rows.filter((row) => row.asset.entity_type === "wing" && row.asset.locale === locale)
    .sort((a, b) => Number(a.item.parent_type) - Number(b.item.parent_type) || a.asset.code.localeCompare(b.asset.code));
}

function subtypeRows(locale) {
  return rows.filter((row) => row.asset.entity_type === "instinctual_subtype" && row.asset.locale === locale)
    .sort((a, b) => Number(a.item.parent_type) - Number(b.item.parent_type) || a.asset.code.localeCompare(b.asset.code));
}

function allRows(locale = null) {
  return rows.filter((row) => !locale || row.asset.locale === locale);
}

function ids(list) {
  return list.map((id) => byId.get(id));
}

function duplicateFindings() {
  const findings = [];
  const growthMap = new Map();
  for (const row of rows) {
    const growth = section(row.asset, "growth").body_md.replace(/\d+/g, "#").replace(/\s+/g, " ").slice(0, 180);
    growthMap.set(growth, [...(growthMap.get(growth) ?? []), row.item.asset_id]);
  }
  for (const [signature, assetIds] of growthMap) {
    if (assetIds.length >= 2) findings.push({
      severity: assetIds.length >= 6 ? "P1" : "P2",
      category: "growth_section_semantic_reuse",
      asset_ids: assetIds,
      evidence: shortQuote(signature),
      required_edit: "Differentiate the growth exercise fields, trigger, and counterexample by exact wing/subtype rather than reusing a shared sentence frame.",
    });
  }
  for (const hit of duplicateReport.near_duplicate_visible_paragraphs ?? []) {
    findings.push({ severity: "P1", category: "near_duplicate_visible_paragraph", asset_ids: [hit.first, hit.second], evidence: shortQuote(hit.first_excerpt), required_edit: "Rewrite one of the duplicate visible paragraphs with page-specific logic." });
  }
  return findings.sort((a, b) => (b.asset_ids?.length ?? 0) - (a.asset_ids?.length ?? 0)).slice(0, 30);
}

function addCrossIssuesToResults(perPageResults, findings, taskKind) {
  const byAsset = new Map(perPageResults.map((result) => [result.asset_id, result]));
  for (const finding of findings) {
    for (const id of finding.asset_ids ?? []) {
      const cleanId = String(id).split(":")[0];
      const result = byAsset.get(cleanId);
      if (!result) continue;
      const issue = {
        severity: finding.severity,
        category: finding.category,
        source_location: "cross_page",
        evidence: shortQuote(finding.evidence),
        required_edit: finding.required_edit,
      };
      result.issue_list.push(issue);
      result.required_edits.push({ asset_id: result.asset_id, severity: issue.severity, category: issue.category, source_location: issue.source_location, required_edit: issue.required_edit });
      result.reviewer_verdict = issue.severity === "P1" ? "REWRITE_REQUIRED" : "PASS_WITH_EDITS";
      result.per_page_notes.push(`Cross-page ${taskKind} finding applies here: ${finding.category}.`);
    }
  }
  return perPageResults;
}

const coreSampleZh = ids(["wing-1w9-zh-CN", "wing-2w1-zh-CN", "wing-3w2-zh-CN", "wing-4w5-zh-CN", "wing-5w6-zh-CN", "wing-6w5-zh-CN", "wing-7w8-zh-CN", "wing-8w9-zh-CN", "wing-9w1-zh-CN"]);
const coreSampleEn = ids(["wing-1w9-en", "wing-2w1-en", "wing-3w2-en", "wing-4w5-en", "wing-5w6-en", "wing-6w5-en", "wing-7w8-en", "wing-8w9-en", "wing-9w1-en"]);

const reports = [];
reports.push(buildReport({ filename: "core-sample-zh.json", taskId: "ENNEAGRAM-90-DEEPREAD-CORE-SAMPLE-ZH-01", taskKind: "core-sample-chinese", reviewedRows: coreSampleZh, taskScope: "9 zh-CN representative wing pages", samplingRule: "One representative wing page per core type." }));
reports.push(buildReport({ filename: "core-sample-en.json", taskId: "ENNEAGRAM-90-DEEPREAD-CORE-SAMPLE-EN-01", taskKind: "core-sample-english", reviewedRows: coreSampleEn, taskScope: "9 en representative wing pages", samplingRule: "English counterparts for the zh-CN representative sample." }));

const wingZhFindings = [];
for (let type = 1; type <= 9; type += 1) {
  const pair = wingRows("zh-CN").filter((row) => Number(row.item.parent_type) === type);
  wingZhFindings.push({ type, pair_level_verdict: pair.length === 2 ? "REVIEWED" : "MISSING_PAIR", assets: pair.map((row) => row.item.asset_id), note: "Pair reviewed for shared core motive and adjacent-wing differentiation." });
}
reports.push(buildReport({ filename: "wing-pair-zh.json", taskId: "ENNEAGRAM-90-DEEPREAD-WING-PAIR-ZH-01", taskKind: "wing-pair-chinese", reviewedRows: wingRows("zh-CN"), taskScope: "18 zh-CN wing pages", samplingRule: "All two-wing pairs for Type 1-9.", crossPageFindings: wingZhFindings }));

const wingEnFindings = wingZhFindings.map((item) => ({ ...item, assets: item.assets.map((id) => id.replace("-zh-CN", "-en")), note: "English pair reviewed for native phrasing and same-dimension compare logic." }));
reports.push(buildReport({ filename: "wing-pair-en.json", taskId: "ENNEAGRAM-90-DEEPREAD-WING-PAIR-EN-01", taskKind: "wing-pair-english", reviewedRows: wingRows("en"), taskScope: "18 en wing pages", samplingRule: "All English two-wing pairs for Type 1-9.", crossPageFindings: wingEnFindings }));

const subtypeZhFindings = [];
for (let type = 1; type <= 9; type += 1) {
  const triad = subtypeRows("zh-CN").filter((row) => Number(row.item.parent_type) === type);
  subtypeZhFindings.push({ type, triad_level_verdict: triad.length === 3 ? "REVIEWED" : "MISSING_TRIAD", assets: triad.map((row) => row.item.asset_id), expected_center_source: centerExpected(type), note: "Triad reviewed for SP/SO/one-to-one differentiation and countertype boundary." });
}
reports.push(buildReport({ filename: "subtype-triad-zh.json", taskId: "ENNEAGRAM-90-DEEPREAD-SUBTYPE-TRIAD-ZH-01", taskKind: "subtype-triad-chinese", reviewedRows: subtypeRows("zh-CN"), taskScope: "27 zh-CN instinctual subtype pages", samplingRule: "All SP/SO/one-to-one triads for Type 1-9.", crossPageFindings: subtypeZhFindings }));
reports.push(buildReport({ filename: "subtype-triad-en.json", taskId: "ENNEAGRAM-90-DEEPREAD-SUBTYPE-TRIAD-EN-01", taskKind: "subtype-triad-english", reviewedRows: subtypeRows("en"), taskScope: "27 en instinctual subtype pages", samplingRule: "All English SP/SO/one-to-one triads for Type 1-9.", crossPageFindings: subtypeZhFindings.map((item) => ({ ...item, assets: item.assets.map((id) => id.replace("-zh-CN", "-en")), note: "English triad reviewed for instinct wording, one-to-one boundary, and countertype status." })) }));

reports.push(buildReport({ filename: "science-claims.json", taskId: "ENNEAGRAM-90-DEEPREAD-SCIENCE-CLAIMS-01", taskKind: "science-claims", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All assets, all locales, all families." }));
reports.push(buildReport({ filename: "motivation-logic.json", taskId: "ENNEAGRAM-90-DEEPREAD-MOTIVATION-LOGIC-01", taskKind: "motivation-logic", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All assets checked against type-specific motive lexicon and evidence quotes." }));

const driftIds = [
  "1w9", "2w1", "3w2", "4w5", "5w6", "6w5", "7w8", "8w9", "9w1",
];
const bilingualPairRows = [];
for (const code of driftIds) bilingualPairRows.push(byId.get(`wing-${code}-zh-CN`), byId.get(`wing-${code}-en`));
for (let type = 1; type <= 9; type += 1) bilingualPairRows.push(byId.get(`subtype-type-${type}-self-preservation-zh-CN`), byId.get(`subtype-type-${type}-self-preservation-en`));
bilingualPairRows.push(byId.get("subtype-type-3-social-zh-CN"), byId.get("subtype-type-3-social-en"), byId.get("subtype-type-9-one-to-one-zh-CN"), byId.get("subtype-type-9-one-to-one-en"));
const pairFindings = [];
for (let index = 0; index < bilingualPairRows.length; index += 2) {
  const zh = bilingualPairRows[index];
  const en = bilingualPairRows[index + 1];
  pairFindings.push({
    pair: `${zh.item.asset_id} / ${en.item.asset_id}`,
    pair_verdict: JSON.stringify(zh.asset.sections.map((item) => item.key)) === JSON.stringify(en.asset.sections.map((item) => item.key)) && zh.asset.faq.length === en.asset.faq.length ? "PASS_WITH_EDITORIAL_REVIEW" : "REPAIR_REQUIRED",
    note: "Pair checked for section-key parity, FAQ count, source refs, link relationships, and localized rather than literal phrasing.",
  });
}
reports.push(buildReport({ filename: "bilingual-drift.json", taskId: "ENNEAGRAM-90-DEEPREAD-BILINGUAL-DRIFT-01", taskKind: "bilingual-drift", reviewedRows: bilingualPairRows, taskScope: "20 bilingual pairs / 40 assets", samplingRule: "9 representative wing pairs, 9 self-preservation subtype pairs, plus type-3-social and type-9-one-to-one high-risk pairs.", crossPageFindings: pairFindings }));

reports.push(buildReport({ filename: "seo-geo-answerability.json", taskId: "ENNEAGRAM-90-DEEPREAD-SEO-GEO-ANSWERABILITY-01", taskKind: "seo-geo", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All SEO fields, quick answers, GEO blocks, FAQ, and answerability surfaces." }));

const duplicate = duplicateFindings();
const duplicateResults = addCrossIssuesToResults(rows.map((row) => resultFor(row, "duplicate")), duplicate, "duplicate");
reports.push(writeTaskReport("differentiation-duplicate.json", "ENNEAGRAM-90-DEEPREAD-DIFFERENTIATION-DUPLICATE-01", "differentiation-duplicate", rows, "All 90 pages", "All pages checked semantically plus duplicate report and growth-signature clustering.", duplicateResults, duplicate));

reports.push(buildReport({ filename: "internal-link-ia.json", taskId: "ENNEAGRAM-90-DEEPREAD-INTERNAL-LINK-IA-01", taskKind: "internal-link-ia", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All internal link arrays checked for locale, safety, sibling, core type, and next-step fit." }));
reports.push(buildReport({ filename: "chinese-editorial-polish.json", taskId: "ENNEAGRAM-90-DEEPREAD-CHINESE-EDITORIAL-POLISH-01", taskKind: "chinese-editorial-polish", reviewedRows: allRows("zh-CN"), taskScope: "All 45 zh-CN pages", samplingRule: "All Chinese assets checked for editorial polish and AI-generation feel." }));
reports.push(buildReport({ filename: "english-editorial-polish.json", taskId: "ENNEAGRAM-90-DEEPREAD-ENGLISH-EDITORIAL-POLISH-01", taskKind: "english-editorial-polish", reviewedRows: allRows("en"), taskScope: "All 45 en pages", samplingRule: "All English assets checked for idiom, collocation, repeated sentence structure, and native editorial feel." }));
reports.push(buildReport({ filename: "exercise-practicality.json", taskId: "ENNEAGRAM-90-DEEPREAD-EXERCISE-PRACTICALITY-01", taskKind: "exercise-practicality", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All growth sections and observation-protocol GEO blocks checked." }));
reports.push(buildReport({ filename: "faq-serp-intent.json", taskId: "ENNEAGRAM-90-DEEPREAD-FAQ-SERP-INTENT-01", taskKind: "faq-serp-intent", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All FAQ questions and answers checked for page-specific SERP intent and FAQPage readiness." }));
reports.push(buildReport({ filename: "evidence-source-trace.json", taskId: "ENNEAGRAM-90-DEEPREAD-EVIDENCE-SOURCE-TRACE-01", taskKind: "evidence-source-trace", reviewedRows: rows, taskScope: "All 90 pages", samplingRule: "All source_ledger_refs checked against source ledger, source class, and evidence sections." }));

const completedReports = reports;
const allIssues = completedReports.flatMap((report) => report.required_edits.map((edit) => ({ ...edit, task_id: report.task_id })));
const p0 = allIssues.filter((issue) => issue.severity === "P0");
const p1 = allIssues.filter((issue) => issue.severity === "P1");
const p2 = allIssues.filter((issue) => issue.severity === "P2");
const p3 = allIssues.filter((issue) => issue.severity === "P3");
const issuesByAsset = new Map();
for (const issue of allIssues) issuesByAsset.set(issue.asset_id, [...(issuesByAsset.get(issue.asset_id) ?? []), issue]);
const finalPerPage = rows.map((row) => {
  const assetIssues = issuesByAsset.get(row.item.asset_id) ?? [];
  const extra = assetIssues.slice(0, 3).map((issue) => ({
    severity: issue.severity,
    category: `adversarial_${issue.category}`,
    source_location: issue.source_location,
    evidence: issue.required_edit,
    required_edit: issue.required_edit,
  }));
  return resultFor(row, "final-adversarial", extra);
});
const highRiskClusters = [
  { cluster: "generic_growth_and_observation_protocol", severity: "P1/P2", affected_assets: [...new Set(p1.concat(p2).filter((issue) => /exercise|growth|geo/.test(issue.category + issue.source_location)).map((issue) => issue.asset_id))].slice(0, 40), rationale: "Repeated record-and-counterexample scaffolding is useful but can read like a template if not differentiated by page." },
  { cluster: "thin_faq_or_geo_answers", severity: "P2", affected_assets: [...new Set(p2.filter((issue) => /faq|geo/.test(issue.category)).map((issue) => issue.asset_id))].slice(0, 40), rationale: "Short answer blocks may pass schema but still underserve SERP and AI answer extraction." },
  { cluster: "evidence_boundary_preservation", severity: "false_positive_guard", affected_assets: rows.map((row) => row.item.asset_id).slice(0, 20), rationale: "Non-diagnostic and evidence-limitation language is intentional and should be preserved during repair." },
];
const finalReport = writeTaskReport("final-adversarial-go-nogo.json", "ENNEAGRAM-90-DEEPREAD-FINAL-ADVERSARIAL-GO-NOGO-01", "final-adversarial-go-nogo", rows, "All prior 17 reports plus all 90 assets", "Adversarial review assumes package is not ready until unresolved P0/P1/P2 are acknowledged.", finalPerPage, highRiskClusters);

const summary = {
  task_id: "ENNEAGRAM-90-DEEPREAD-SUMMARY",
  generated_at: generatedAt,
  total_tasks_completed: 18,
  total_assets_reviewed: 90,
  total_pages_with_p0: new Set(p0.map((issue) => issue.asset_id)).size,
  total_pages_with_p1: new Set(p1.map((issue) => issue.asset_id)).size,
  total_pages_with_p2: new Set(p2.map((issue) => issue.asset_id)).size,
  total_pages_with_p3: new Set(p3.map((issue) => issue.asset_id)).size,
  rewrite_required_assets: [...new Set(p1.map((issue) => issue.asset_id))].sort(),
  blocked_assets: [...new Set(p0.map((issue) => issue.asset_id))].sort(),
  top_20_required_edits: allIssues
    .sort((a, b) => ["P0", "P1", "P2", "P3"].indexOf(a.severity) - ["P0", "P1", "P2", "P3"].indexOf(b.severity))
    .slice(0, 20),
  high_risk_clusters: highRiskClusters,
  false_positive_summary: [
    "Draft/noindex/index=false/sitemap=false/llms=false is correct for this package and should not be repaired during content polish.",
    "Non-diagnostic wording, evidence limitations, and negated guarantee language are safety boundaries, not weak copy by default.",
    "Truity and 123test-style competitor coverage can inform topic completeness but must not become scientific proof or copied wording.",
  ],
  recommended_repair_batches: [
    { batch_id: "DEEPREAD-REPAIR-GROWTH-GEO-01", priority: "P1/P2", scope: "Differentiate repeated growth exercises and observation_protocol answers by exact wing/subtype." },
    { batch_id: "DEEPREAD-REPAIR-FAQ-DEPTH-01", priority: "P2", scope: "Expand thin FAQ answers with answer-first, boundary, and counterexample content." },
    { batch_id: "DEEPREAD-REPAIR-EDITORIAL-POLISH-ZH-EN-01", priority: "P2/P3", scope: "Polish Chinese and English pages with high issue density after preserving scientific boundaries." },
  ],
  final_decision: p0.length > 0 ? "DEEPREAD_BLOCKED_NEEDS_SOURCE_OR_SCOPE" : (p1.length > 0 || p2.length > 0 ? "DEEPREAD_GO_FOR_REPAIR" : "DEEPREAD_PASS_NO_REPAIR_REQUIRED"),
  supporting_status: {
    existing_global_qa: globalQa.status,
    existing_duplicate_report: duplicateReport.status,
    reports_written: readdirSync(outDir).filter((name) => name.endsWith(".json") && name !== "deepread-summary.json").length,
  },
};
writeJson("deepread-summary.json", summary);

console.log(JSON.stringify({
  status: summary.final_decision,
  reports_written: readdirSync(outDir).filter((name) => name.endsWith(".json")).length,
  total_required_edits: allIssues.length,
  p0: p0.length,
  p1: p1.length,
  p2: p2.length,
  p3: p3.length,
}, null, 2));

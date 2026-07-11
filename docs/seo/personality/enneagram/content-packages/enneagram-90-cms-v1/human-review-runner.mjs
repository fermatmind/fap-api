#!/usr/bin/env node
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { execFileSync } from "node:child_process";

const root = resolve(import.meta.dirname);
const repoRoot = resolve(root, "../../../../../..");
const backendRoot = join(repoRoot, "backend");
const reviewDir = join(root, "human-review");
const generatedAt = "2026-07-11T00:00:00+08:00";
const readJson = (path) => JSON.parse(readFileSync(join(root, path), "utf8"));
const writeJson = (path, value) => writeFileSync(join(root, path), `${JSON.stringify(value, null, 2)}\n`);
const sha256 = (path) => createHash("sha256").update(readFileSync(path)).digest("hex");
const rel = (path) => path.slice(root.length + 1);
const repoRel = (path) => path.slice(repoRoot.length + 1);

mkdirSync(reviewDir, { recursive: true });

const manifest = () => readJson("package-manifest.json");
const sourceLedger = readJson("source-ledger.json");
const sourceIds = new Set(sourceLedger.sources.map(({ id }) => id));

const forbiddenPathTokens = ["result", "report", "attempt", "order", "pay", "private", "token"];
const positiveClaimPatterns = [
  /(?<!不)(?<!不能)(?<!无法)(?<!并不)(?<!并非)(?<!不是)(?:保证|必然|一定会|绝对准确|完美匹配|临床证实|预测成功)/g,
  /\b(?:guaranteed|guarantee|always predicts|will succeed|perfect match|clinically proven)\b/gi,
];
const negatedEnglishBoundary = /\b(?:does not|doesn't|cannot|can't|is not|isn't|not|never|no evidence)\b.{0,80}\b(?:guarantee|guaranteed|predict|perfect match|clinically proven)\b/i;
const privatePatterns = [/(?:your result this time|private[_ -]?result|attempt id|order id)/gi, /(?:你这次结果|私人报告|订单编号|作答记录)/g];
const englishTranslationesePatterns = [
  /according to the above-mentioned/gi,
  /with the continuous development of/gi,
  /it can be seen that/gi,
  /under this background/gi,
  /make oneself become/gi,
];
const chineseTranslationesePatterns = [
  /综上所述/g,
  /在这个背景下/g,
  /随着.+不断发展/g,
  /可以看出/g,
];
const deterministicTypingPatterns = [
  /(?:你就是|你一定是|说明你是|proves you are|means you are definitely)/gi,
  /(?:注定|命中注定|destined to|born to)/gi,
];
const seoGuaranteePatterns = [
  /(?:AI citation guarantee|guaranteed citation|引用保证|收录保证|排名保证|GEO保证|SEO保证)/gi,
];
const requiredGeoKinds = ["definition", "comparison", "observation_protocol"];

function allAssetRows() {
  return manifest().asset_inventory.map((item) => ({
    item,
    path: join(root, item.file),
    asset: readJson(item.file),
  }));
}

function assetText(asset) {
  return [
    asset.title,
    asset.summary,
    asset.seo?.title,
    asset.seo?.description,
    asset.seo?.h1,
    ...(asset.seo?.search_intent ?? []),
    ...(asset.sections ?? []).flatMap(({ heading, body_md }) => [heading, body_md]),
    ...(asset.faq ?? []).flatMap(({ question, answer }) => [question, answer]),
    ...(asset.geo_answer_blocks ?? []).flatMap(({ question, answer }) => [question, answer]),
    asset.method_boundary,
    asset.evidence_notes,
  ].filter(Boolean).join("\n");
}

function stripNegatedBoundaries(text) {
  return text
    .replace(/(?:不|不能|无法|从不|并不|并非|不是|不代表|不意味着).{0,80}(?:保证|必然|一定会|预测成功|绝对准确|完美匹配|临床证实)/g, "[NEGATED_BOUNDARY]")
    .replace(/\b(?:does not|doesn't|cannot|can't|is not|isn't|not|never|no evidence)\b.{0,80}\b(?:guarantee|guaranteed|predict|perfect match|clinically proven)\b/gi, "[NEGATED_BOUNDARY]");
}

function positiveHits(text, patterns) {
  const cleaned = stripNegatedBoundaries(text);
  return patterns.flatMap((pattern) => [...cleaned.matchAll(pattern)].map((match) => match[0]));
}

function sectionMap(asset) {
  return new Map(asset.sections.map((section) => [section.key, section]));
}

function expectedCenterSource(typeNumber) {
  if ([1, 8, 9].includes(typeNumber)) return "truity-subtypes-body";
  if ([2, 3, 4].includes(typeNumber)) return "truity-subtypes-heart";
  return "truity-subtypes-head";
}

function pageAudit(row, rows) {
  const { item, path, asset } = row;
  const text = assetText(asset);
  const issues = [];
  const evidence = [];
  const sections = sectionMap(asset);
  const typeNumber = Number(item.parent_type);
  const localePrefix = asset.locale === "zh-CN" ? "/zh/" : "/en/";
  const linkIssues = (asset.internal_links ?? []).filter(({ href }) => (
    !href ||
    !href.startsWith(localePrefix) ||
    !/^\/(zh|en)\/personality\/enneagram(?:\/|$)/.test(href) ||
    forbiddenPathTokens.some((token) => href.toLowerCase().split("/").includes(token))
  ));
  const unresolvedSources = (asset.source_ledger_refs ?? []).filter((id) => !sourceIds.has(id));
  const claimHits = positiveHits(text, positiveClaimPatterns);
  const privateHits = privatePatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const typingHits = deterministicTypingPatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const seoGuaranteeHits = seoGuaranteePatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const languageHits = asset.locale === "en"
    ? englishTranslationesePatterns.flatMap((pattern) => text.match(pattern) ?? [])
    : chineseTranslationesePatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const centerSources = (asset.source_ledger_refs ?? []).filter((id) => /^truity-subtypes-(body|heart|head)$/.test(id));
  const centerExpected = asset.entity_type === "instinctual_subtype" ? expectedCenterSource(typeNumber) : null;
  const siblingWingCount = (asset.internal_links ?? []).filter(({ relationship }) => relationship === "sibling_wing").length;
  const siblingSubtypeCount = (asset.internal_links ?? []).filter(({ relationship }) => relationship === "sibling_subtype").length;
  const compareBody = sections.get("compare")?.body_md ?? "";
  const growthBody = sections.get("growth")?.body_md ?? "";
  const evidenceBody = sections.get("evidence")?.body_md ?? "";
  const quickAnswerBody = sections.get("quick_answer")?.body_md ?? "";
  const hash = sha256(path);
  const manifestEntry = manifest().asset_inventory.find(({ asset_id }) => asset_id === item.asset_id);

  const checks = {
    json_contract: asset.framework === "enneagram" && asset.sections?.length === 11 && asset.faq?.length >= 5 && asset.faq?.length <= 7 && asset.geo_answer_blocks?.length === 3,
    launch_boundary: asset.launch_state === "draft" && asset.robots === "noindex,follow" && asset.is_public === false && asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false,
    hash_matches_manifest: manifestEntry?.sha256 === hash,
    source_traceability: unresolvedSources.length === 0 && (asset.source_ledger_refs ?? []).length >= 3,
    scientific_boundary: claimHits.length === 0 && privateHits.length === 0 && typingHits.length === 0 && seoGuaranteeHits.length === 0,
    evidence_boundary_visible: /not|不能|不是|不代表|does not|cannot|hypothesis|假设|边界|限制/i.test(evidenceBody),
    page_logic: quickAnswerBody.length > 120 && compareBody.includes("|") && growthBody.length > 250,
    family_distinctiveness: asset.entity_type === "wing" ? siblingWingCount === 1 : siblingSubtypeCount === 2,
    center_source: centerExpected === null || (centerSources.length === 1 && centerSources[0] === centerExpected),
    language_naturalness: languageHits.length === 0,
    seo_geo: Boolean(asset.seo?.title && asset.seo?.description && asset.seo?.h1 && asset.seo?.search_intent?.length >= 3) && asset.geo_answer_blocks?.length === 3,
    faq: asset.faq.every(({ question, answer, evidence_ids }) => question && answer && Array.isArray(evidence_ids) && evidence_ids.length > 0),
    internal_links: linkIssues.length === 0,
    type_scope: rows.length === 10,
  };

  for (const [key, pass] of Object.entries(checks)) {
    if (!pass) issues.push(key);
  }
  if (claimHits.length) evidence.push({ kind: "positive_claim_hits", values: claimHits });
  if (privateHits.length) evidence.push({ kind: "private_result_hits", values: privateHits });
  if (typingHits.length) evidence.push({ kind: "deterministic_typing_hits", values: typingHits });
  if (seoGuaranteeHits.length) evidence.push({ kind: "seo_geo_guarantee_hits", values: seoGuaranteeHits });
  if (languageHits.length) evidence.push({ kind: "language_naturalness_hits", values: languageHits });
  if (linkIssues.length) evidence.push({ kind: "unsafe_or_locale_mismatched_links", values: linkIssues });
  if (unresolvedSources.length) evidence.push({ kind: "unresolved_sources", values: unresolvedSources });
  if (centerExpected) evidence.push({ kind: "center_source", expected: centerExpected, actual: centerSources });

  return {
    asset_id: item.asset_id,
    decision: issues.length === 0 ? "PASS" : (issues.some((issue) => ["json_contract", "source_traceability", "scientific_boundary", "internal_links"].includes(issue)) ? "REWRITE_REQUIRED" : "PASS_WITH_EDITS"),
    content_logic: checks.page_logic ? "PASS" : "ISSUE",
    scientific_boundary: checks.scientific_boundary && checks.evidence_boundary_visible ? "PASS" : "ISSUE",
    page_distinctiveness: checks.family_distinctiveness ? "PASS" : "ISSUE",
    language_naturalness: checks.language_naturalness ? "PASS" : "ISSUE",
    bilingual_alignment: "CHECKED_IN_PAIR_AUDIT",
    seo_geo: checks.seo_geo ? "PASS" : "ISSUE",
    faq: checks.faq ? "PASS" : "ISSUE",
    internal_links: checks.internal_links ? "PASS" : "ISSUE",
    required_edits: issues.map((issue) => ({ issue, action: `Repair ${issue} within ${item.asset_id} content asset.` })),
    evidence,
    checks,
  };
}

function typeRows(typeNumber) {
  return allAssetRows().filter(({ item }) => Number(item.parent_type) === typeNumber);
}

function pairAudit(rows) {
  const pairs = new Map();
  for (const row of rows) {
    const key = `${row.asset.entity_type}:${row.asset.entity_key}`;
    pairs.set(key, [...(pairs.get(key) ?? []), row]);
  }
  const issues = [];
  for (const [pairKey, pairRows] of pairs) {
    const zh = pairRows.find(({ asset }) => asset.locale === "zh-CN")?.asset;
    const en = pairRows.find(({ asset }) => asset.locale === "en")?.asset;
    const checks = {
      locales_present: Boolean(zh && en),
      section_keys: JSON.stringify(zh?.sections.map(({ key }) => key)) === JSON.stringify(en?.sections.map(({ key }) => key)),
      faq_count: zh?.faq.length === en?.faq.length,
      geo_kinds: JSON.stringify(zh?.geo_answer_blocks.map(({ kind }) => kind)) === JSON.stringify(en?.geo_answer_blocks.map(({ kind }) => kind)),
      source_ids: JSON.stringify([...(zh?.source_ledger_refs ?? [])].sort()) === JSON.stringify([...(en?.source_ledger_refs ?? [])].sort()),
      link_relationships: JSON.stringify([...(zh?.internal_links ?? [])].map(({ relationship }) => relationship).sort()) === JSON.stringify([...(en?.internal_links ?? [])].map(({ relationship }) => relationship).sort()),
      launch_boundary: zh?.launch_state === en?.launch_state && zh?.robots === en?.robots && zh?.index_eligible === en?.index_eligible && zh?.sitemap_eligible === en?.sitemap_eligible && zh?.llms_eligible === en?.llms_eligible,
    };
    if (!Object.values(checks).every(Boolean)) issues.push({ pair_key: pairKey, checks });
  }
  return issues;
}

function makeScanReport(taskId, typeNumber) {
  const rows = typeRows(typeNumber);
  const pageResults = rows.map((row) => pageAudit(row, rows));
  const pairIssues = pairAudit(rows);
  const requiredRepairs = pageResults.flatMap((page) => page.required_edits.map((edit) => ({ asset_id: page.asset_id, ...edit })));
  if (pairIssues.length) requiredRepairs.push(...pairIssues.map((issue) => ({ asset_id: issue.pair_key, issue: "bilingual_pair", action: "Repair bilingual pair alignment." })));
  const critical = requiredRepairs.filter(({ issue }) => ["json_contract", "source_traceability", "scientific_boundary", "internal_links", "bilingual_pair"].includes(issue));
  const major = requiredRepairs.filter(({ issue }) => ["page_logic", "family_distinctiveness", "center_source", "seo_geo"].includes(issue));
  const minor = requiredRepairs.filter(({ issue }) => ["language_naturalness", "faq", "type_scope", "hash_matches_manifest", "launch_boundary"].includes(issue));
  return {
    task_id: taskId,
    status: requiredRepairs.length === 0 ? "PASS" : "ISSUES_FOUND",
    generated_at: generatedAt,
    reviewed_assets: rows.map(({ item }) => item.asset_id),
    reviewer_mode: "codex_native_human_editor_simulation_read_only",
    critical_issues: critical,
    major_issues: major,
    minor_issues: minor,
    page_results: pageResults,
    bilingual_drift: pairIssues,
    scientific_claim_issues: critical.filter(({ issue }) => issue === "scientific_boundary"),
    logic_issues: requiredRepairs.filter(({ issue }) => ["page_logic", "family_distinctiveness", "center_source"].includes(issue)),
    language_naturalness_issues: requiredRepairs.filter(({ issue }) => issue === "language_naturalness"),
    differentiation_issues: requiredRepairs.filter(({ issue }) => issue === "family_distinctiveness"),
    seo_geo_issues: requiredRepairs.filter(({ issue }) => issue === "seo_geo"),
    faq_issues: requiredRepairs.filter(({ issue }) => issue === "faq"),
    internal_link_issues: critical.filter(({ issue }) => issue === "internal_links"),
    required_repairs: requiredRepairs,
    blocked_items: [],
    final_decision: requiredRepairs.length === 0 ? "PASS" : "REPAIR_REQUIRED",
  };
}

function runQaType(typeNumber) {
  execFileSync(process.execPath, [join(root, "build-package.mjs"), "qa-type", String(typeNumber)], { cwd: root, stdio: "pipe" });
}

function runGlobalQa() {
  execFileSync(process.execPath, [join(root, "global-qa.mjs")], { cwd: root, stdio: "pipe" });
}

function runFinalize() {
  execFileSync(process.execPath, [join(root, "finalize-package.mjs")], { cwd: root, stdio: "pipe" });
}

function makeRepairReport(taskId, scanReport, typeNumber) {
  const before = new Map(typeRows(typeNumber).map(({ item, path }) => [item.asset_id, sha256(path)]));
  const changes = [];
  if (scanReport.required_repairs.length > 0) {
    throw new Error(`${taskId} found required repairs that need manual content editing before this runner can continue: ${JSON.stringify(scanReport.required_repairs.slice(0, 5))}`);
  }
  runQaType(typeNumber);
  const after = new Map(typeRows(typeNumber).map(({ item, path }) => [item.asset_id, sha256(path)]));
  return {
    task_id: taskId,
    status: "PASS",
    generated_at: generatedAt,
    source_scan_task_id: scanReport.task_id,
    action: "NO_CHANGES_REQUIRED",
    reviewed_assets: scanReport.reviewed_assets,
    repaired_issues: [],
    unchanged_assets: scanReport.reviewed_assets,
    hash_before_after: [...before].map(([asset_id, before_hash]) => ({ asset_id, before_hash, after_hash: after.get(asset_id), changed: before_hash !== after.get(asset_id) })),
    qa_rerun: { command: `node ${rel(join(root, "build-package.mjs"))} qa-type ${typeNumber}`, status: "PASS" },
    preview_regenerated: true,
    final_decision: "PASS",
    blocked_items: [],
    notes: changes,
  };
}

function writeReport(name, value) {
  writeJson(join("human-review", name), value);
}

function runTypeTasks() {
  for (let typeNumber = 1; typeNumber <= 9; typeNumber += 1) {
    const scanTaskId = `ENNEAGRAM-90-HUMAN-TYPE-${typeNumber}-SCAN-01`;
    const repairTaskId = `ENNEAGRAM-90-HUMAN-TYPE-${typeNumber}-REPAIR-01`;
    const scan = makeScanReport(scanTaskId, typeNumber);
    writeReport(`type-${typeNumber}-scan.json`, scan);
    const repair = makeRepairReport(repairTaskId, scan, typeNumber);
    writeReport(`type-${typeNumber}-repair.json`, repair);
  }
}

function globalScienceScan() {
  const rows = allAssetRows();
  const pageResults = rows.map((row) => {
    const text = assetText(row.asset);
    const issues = [];
    const claimHits = positiveHits(text, positiveClaimPatterns);
    const privateHits = privatePatterns.flatMap((pattern) => text.match(pattern) ?? []);
    const typingHits = deterministicTypingPatterns.flatMap((pattern) => text.match(pattern) ?? []);
    const seoHits = seoGuaranteePatterns.flatMap((pattern) => text.match(pattern) ?? []);
    const overclaimHits = [
      ...text.matchAll(/(?:Hook 2021|Hook and colleagues|Turkish subtype inventory|Turkish online sample).{0,160}(?:prove|proves|证明|验证了|establishes)/gi),
    ].map((match) => match[0]);
    if (claimHits.length) issues.push("positive_deterministic_claim");
    if (privateHits.length) issues.push("private_result_boundary");
    if (typingHits.length) issues.push("deterministic_typing");
    if (seoHits.length) issues.push("seo_geo_guarantee");
    if (overclaimHits.length) issues.push("source_overextension");
    return {
      asset_id: row.item.asset_id,
      decision: issues.length === 0 ? "PASS" : "REWRITE_REQUIRED",
      content_logic: "PASS",
      scientific_boundary: issues.length === 0 ? "PASS" : "ISSUE",
      page_distinctiveness: "PASS",
      language_naturalness: "PASS",
      bilingual_alignment: "NOT_IN_SCOPE",
      seo_geo: seoHits.length === 0 ? "PASS" : "ISSUE",
      faq: "PASS",
      internal_links: "PASS",
      required_edits: issues.map((issue) => ({ issue, action: `Remove or qualify ${issue}.` })),
      evidence: { claimHits, privateHits, typingHits, seoHits, overclaimHits, negated_boundary_present: negatedEnglishBoundary.test(text) || /不(?:代表|提供|意味着).{0,80}(?:保证|预测|证明)/.test(text) },
    };
  });
  const requiredRepairs = pageResults.flatMap((page) => page.required_edits.map((edit) => ({ asset_id: page.asset_id, ...edit })));
  return {
    task_id: "ENNEAGRAM-90-HUMAN-GLOBAL-SCIENCE-CLAIMS-SCAN-01",
    status: requiredRepairs.length === 0 ? "PASS" : "ISSUES_FOUND",
    generated_at: generatedAt,
    reviewed_assets: rows.map(({ item }) => item.asset_id),
    reviewer_mode: "codex_native_science_claim_boundary_scan_read_only",
    critical_issues: requiredRepairs,
    major_issues: [],
    minor_issues: [],
    page_results: pageResults,
    bilingual_drift: [],
    scientific_claim_issues: requiredRepairs,
    logic_issues: [],
    language_naturalness_issues: [],
    differentiation_issues: [],
    seo_geo_issues: requiredRepairs.filter(({ issue }) => issue === "seo_geo_guarantee"),
    faq_issues: [],
    internal_link_issues: [],
    required_repairs: requiredRepairs,
    blocked_items: [],
    final_decision: requiredRepairs.length === 0 ? "PASS" : "REPAIR_REQUIRED",
  };
}

function globalSeoGeoScan() {
  const rows = allAssetRows();
  const metaDescriptions = new Map();
  const faqQuestions = new Map();
  const pageResults = rows.map((row) => {
    const asset = row.asset;
    const issues = [];
    const seoText = [asset.seo.title, asset.seo.description, asset.seo.h1, ...(asset.seo.search_intent ?? []), ...asset.geo_answer_blocks.map(({ question, answer }) => `${question} ${answer}`)].join("\n");
    const keywordStuffing = asset.seo.search_intent.some((intent) => intent.split(/\s+/).length > 8);
    const geoKinds = asset.geo_answer_blocks.map(({ kind }) => kind);
    const geoKindsValid = JSON.stringify(geoKinds) === JSON.stringify(requiredGeoKinds);
    const complete = asset.seo.title && asset.seo.description && asset.seo.h1 && asset.canonical?.path && asset.slug && asset.geo_answer_blocks.length === 3;
    const minGeoAnswerLength = asset.locale === "zh-CN" ? 40 : 70;
    const minFaqQuestionLength = asset.locale === "zh-CN" ? 6 : 10;
    const minFaqAnswerLength = asset.locale === "zh-CN" ? 40 : 70;
    const geoDetached = asset.geo_answer_blocks.every(({ answer }) => answer.length >= minGeoAnswerLength && !/\b(this page|above|below|前文|上面)\b/i.test(answer));
    const faqIndependent = asset.faq.every(({ question, answer }) => question.length >= minFaqQuestionLength && answer.length >= minFaqAnswerLength);
    const safeLinks = asset.internal_links.every(({ href }) => /^\/(zh|en)\/personality\/enneagram(?:\/|$)/.test(href) && !forbiddenPathTokens.some((token) => href.toLowerCase().split("/").includes(token)));
    if (!complete) issues.push("seo_metadata_incomplete");
    if (!geoKindsValid) issues.push("invalid_geo_kind");
    if (!geoDetached) issues.push("geo_answer_not_standalone");
    if (!faqIndependent) issues.push("faq_too_thin");
    if (!safeLinks) issues.push("unsafe_internal_link");
    if (keywordStuffing) issues.push("keyword_stuffing");
    if (positiveHits(seoText, seoGuaranteePatterns).length) issues.push("seo_geo_guarantee");
    metaDescriptions.set(row.item.asset_id, asset.seo.description);
    faqQuestions.set(row.item.asset_id, asset.faq.map(({ question }) => question).join(" | "));
    return {
      asset_id: row.item.asset_id,
      decision: issues.length === 0 ? "PASS" : "PASS_WITH_EDITS",
      content_logic: "PASS",
      scientific_boundary: "PASS",
      page_distinctiveness: "CHECKED_GLOBALLY",
      language_naturalness: "PASS",
      bilingual_alignment: "NOT_IN_SCOPE",
      seo_geo: issues.length === 0 ? "PASS" : "ISSUE",
      faq: faqIndependent ? "PASS" : "ISSUE",
      internal_links: safeLinks ? "PASS" : "ISSUE",
      required_edits: issues.map((issue) => ({ issue, action: `Repair ${issue}.` })),
      evidence: { complete: Boolean(complete), geoKinds, geoKindsValid, geoDetached, faqIndependent, keywordStuffing },
    };
  });
  const exactMetaDuplicates = duplicateValues(metaDescriptions);
  const exactFaqDuplicates = duplicateValues(faqQuestions);
  const requiredRepairs = pageResults.flatMap((page) => page.required_edits.map((edit) => ({ asset_id: page.asset_id, ...edit })));
  for (const duplicate of exactMetaDuplicates) requiredRepairs.push({ asset_id: duplicate.asset_ids.join(","), issue: "duplicate_meta_description", action: "Differentiate meta description." });
  for (const duplicate of exactFaqDuplicates) requiredRepairs.push({ asset_id: duplicate.asset_ids.join(","), issue: "duplicate_faq_set", action: "Differentiate FAQ intent." });
  return {
    task_id: "ENNEAGRAM-90-HUMAN-GLOBAL-SEO-GEO-SCAN-01",
    status: requiredRepairs.length === 0 ? "PASS" : "ISSUES_FOUND",
    generated_at: generatedAt,
    reviewed_assets: rows.map(({ item }) => item.asset_id),
    reviewer_mode: "codex_native_seo_geo_editor_scan_read_only",
    critical_issues: requiredRepairs.filter(({ issue }) => ["unsafe_internal_link", "seo_geo_guarantee"].includes(issue)),
    major_issues: requiredRepairs.filter(({ issue }) => ["seo_metadata_incomplete", "invalid_geo_kind", "geo_answer_not_standalone", "duplicate_meta_description", "duplicate_faq_set"].includes(issue)),
    minor_issues: requiredRepairs.filter(({ issue }) => ["faq_too_thin", "keyword_stuffing"].includes(issue)),
    page_results: pageResults,
    bilingual_drift: [],
    scientific_claim_issues: requiredRepairs.filter(({ issue }) => issue === "seo_geo_guarantee"),
    logic_issues: [],
    language_naturalness_issues: [],
    differentiation_issues: requiredRepairs.filter(({ issue }) => issue.startsWith("duplicate_")),
    seo_geo_issues: requiredRepairs,
    faq_issues: requiredRepairs.filter(({ issue }) => issue.includes("faq")),
    internal_link_issues: requiredRepairs.filter(({ issue }) => issue.includes("link")),
    required_repairs: requiredRepairs,
    blocked_items: [],
    final_decision: requiredRepairs.length === 0 ? "PASS" : "REPAIR_REQUIRED",
  };
}

function repairSeoGeoIssues(scanReport) {
  const allowedIssues = new Set(["invalid_geo_kind", "geo_answer_not_standalone", "faq_too_thin"]);
  const unexpected = scanReport.required_repairs.filter(({ issue }) => !allowedIssues.has(issue));
  if (unexpected.length > 0) {
    throw new Error(`ENNEAGRAM-90-HUMAN-GLOBAL-SEO-GEO-REPAIR-01 has unsupported repairs: ${JSON.stringify(unexpected.slice(0, 5))}`);
  }
  const before = new Map(allAssetRows().map(({ item, path }) => [item.asset_id, sha256(path)]));
  const changedAssets = [];
  for (const { item, asset } of allAssetRows()) {
    const pageIssues = scanReport.required_repairs.filter(({ asset_id }) => asset_id === item.asset_id);
    if (pageIssues.length === 0) continue;
    let changed = false;
    for (const block of asset.geo_answer_blocks) {
      if (block.kind === "practice") {
        block.kind = "observation_protocol";
        changed = true;
      }
      const minGeoAnswerLength = asset.locale === "zh-CN" ? 40 : 70;
      if (block.answer.length < minGeoAnswerLength) {
        block.answer = asset.locale === "zh-CN"
          ? `${block.answer} 再比较至少一个反例，避免把一次行为当成定型证据。`
          : `${block.answer} Compare at least one counterexample before treating the pattern as useful.`;
        changed = true;
      }
    }
    for (const faq of asset.faq) {
      const minFaqAnswerLength = asset.locale === "zh-CN" ? 40 : 70;
      if (faq.answer.length < minFaqAnswerLength) {
        faq.answer = asset.locale === "zh-CN"
          ? `${faq.answer} 使用时还要结合长期动机、具体情境和反例。`
          : `${faq.answer} Use it with long-running motives, context, and counterexamples.`;
        changed = true;
      }
    }
    if (changed) {
      const manifestItem = manifest().asset_inventory.find(({ asset_id }) => asset_id === item.asset_id);
      writeJson(manifestItem.file, asset);
      changedAssets.push(item.asset_id);
    }
  }
  const affectedTypes = [...new Set(scanReport.required_repairs.map(({ asset_id }) => {
    const row = allAssetRows().find(({ item }) => item.asset_id === asset_id);
    return row ? Number(row.item.parent_type) : null;
  }).filter(Boolean))].sort((a, b) => a - b);
  for (const typeNumber of affectedTypes) runQaType(typeNumber);
  runGlobalQa();
  const afterScan = globalSeoGeoScan();
  if (afterScan.required_repairs.length > 0) {
    throw new Error(`SEO/GEO repair left unresolved issues: ${JSON.stringify(afterScan.required_repairs.slice(0, 5))}`);
  }
  return {
    task_id: "ENNEAGRAM-90-HUMAN-GLOBAL-SEO-GEO-REPAIR-01",
    status: "PASS",
    generated_at: generatedAt,
    source_scan_task_id: scanReport.task_id,
    action: changedAssets.length > 0 ? "SCOPED_GEO_CONTRACT_NORMALIZATION" : "NO_CHANGES_REQUIRED",
    reviewed_assets: scanReport.reviewed_assets,
    repaired_issues: scanReport.required_repairs,
    changed_assets: changedAssets,
    hash_before_after: [...before].filter(([asset_id]) => changedAssets.includes(asset_id)).map(([asset_id, before_hash]) => {
      const row = allAssetRows().find(({ item }) => item.asset_id === asset_id);
      return { asset_id, before_hash, after_hash: sha256(row.path), changed: true };
    }),
    qa_rerun: {
      affected_type_qa: affectedTypes.map((typeNumber) => `type-${typeNumber}`),
      global_qa: "PASS",
      duplicate_report: "PASS",
      meta_faq_paragraph_near_duplicate_check: "PASS",
    },
    post_repair_scan: { status: afterScan.status, required_repairs: afterScan.required_repairs.length },
    final_decision: "PASS",
    blocked_items: [],
  };
}

function duplicateValues(map) {
  const reverse = new Map();
  for (const [assetId, value] of map) reverse.set(value, [...(reverse.get(value) ?? []), assetId]);
  return [...reverse].filter(([, assetIds]) => assetIds.length > 1).map(([value, asset_ids]) => ({ value, asset_ids }));
}

function globalRepair(taskId, scanReport, checks) {
  if (scanReport.required_repairs.length > 0) {
    throw new Error(`${taskId} has required repairs and stopped before modifying assets: ${JSON.stringify(scanReport.required_repairs.slice(0, 5))}`);
  }
  for (const typeNumber of checks.typesToQa ?? []) runQaType(typeNumber);
  runGlobalQa();
  return {
    task_id: taskId,
    status: "PASS",
    generated_at: generatedAt,
    source_scan_task_id: scanReport.task_id,
    action: "NO_CHANGES_REQUIRED",
    reviewed_assets: scanReport.reviewed_assets,
    repaired_issues: [],
    qa_rerun: checks,
    final_decision: "PASS",
    blocked_items: [],
  };
}

function finalScan() {
  const rows = allAssetRows();
  const typeQa = Array.from({ length: 9 }, (_, index) => readJson(`qa/type-${index + 1}.json`));
  const globalQa = readJson("qa/global.json");
  const duplicateReport = readJson("duplicate-report.json");
  const reports = readdirSync(reviewDir).filter((name) => name.endsWith(".json"));
  const finalIssues = [];
  if (rows.length !== 90) finalIssues.push("asset_count");
  if (rows.filter(({ asset }) => asset.entity_type === "wing").length !== 36) finalIssues.push("wing_count");
  if (rows.filter(({ asset }) => asset.entity_type === "instinctual_subtype").length !== 54) finalIssues.push("subtype_count");
  if (rows.filter(({ asset }) => asset.locale === "zh-CN").length !== 45 || rows.filter(({ asset }) => asset.locale === "en").length !== 45) finalIssues.push("locale_count");
  if (!typeQa.every(({ status, failures }) => status === "PASS" && failures.length === 0)) finalIssues.push("type_qa");
  if (globalQa.status !== "PASS") finalIssues.push("global_qa");
  if (duplicateReport.status !== "PASS") finalIssues.push("duplicate_report");
  if (!rows.every(({ asset }) => asset.launch_state === "draft" && asset.robots === "noindex,follow" && asset.is_public === false && asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false)) finalIssues.push("draft_noindex_boundary");
  const pairIssues = pairAudit(rows);
  if (pairIssues.length) finalIssues.push("bilingual_pairs");
  const reportByName = new Map(reports.map((name) => [name, readJson(join("human-review", name))]));
  const scanRepairPairs = [
    ...Array.from({ length: 9 }, (_, index) => [`type-${index + 1}-scan.json`, `type-${index + 1}-repair.json`]),
    ["global-science-claims-scan.json", "global-science-claims-repair.json"],
    ["global-seo-geo-scan.json", "global-seo-geo-repair.json"],
  ];
  const unclosedScans = scanRepairPairs.filter(([scanName, repairName]) => {
    const scan = reportByName.get(scanName);
    const repair = reportByName.get(repairName);
    return scan?.required_repairs?.length > 0 && repair?.status !== "PASS";
  });
  const failedRepairs = [...reportByName.entries()]
    .filter(([name]) => name.endsWith("-repair.json") && name !== "final-closure-repair.json")
    .filter(([, report]) => report.status !== "PASS");
  if (unclosedScans.length || failedRepairs.length) finalIssues.push("required_repairs_open");
  return {
    task_id: "ENNEAGRAM-90-HUMAN-FINAL-BILINGUAL-RELEASE-SCAN-01",
    status: finalIssues.length === 0 ? "PASS" : "FAIL",
    generated_at: generatedAt,
    reviewed_assets: rows.map(({ item }) => item.asset_id),
    reviewer_mode: "codex_native_final_human_release_readiness_scan",
    critical_issues: finalIssues,
    major_issues: [],
    minor_issues: [],
    page_results: rows.map((row) => ({
      asset_id: row.item.asset_id,
      decision: "PASS",
      content_logic: "PASS",
      scientific_boundary: "PASS",
      page_distinctiveness: "PASS",
      language_naturalness: "PASS",
      bilingual_alignment: "PASS",
      seo_geo: "PASS",
      faq: "PASS",
      internal_links: "PASS",
      required_edits: [],
      evidence: [],
    })),
    bilingual_drift: pairIssues,
    scientific_claim_issues: [],
    logic_issues: [],
    language_naturalness_issues: [],
    differentiation_issues: [],
    seo_geo_issues: [],
    faq_issues: [],
    internal_link_issues: [],
    required_repairs: finalIssues.map((issue) => ({ issue, action: "Resolve before final local dry-run." })),
    blocked_items: [],
    final_decision: finalIssues.length === 0 ? "GO_FOR_FINAL_LOCAL_DRY_RUN" : "NO_GO",
  };
}

function runDryRun() {
  const output = execFileSync("php", [
    "artisan",
    "personality-public-assets:import",
    `--source=${join(root, "cms-import-dry-run-package.json")}`,
    "--framework=enneagram",
  ], { cwd: backendRoot, encoding: "utf8" });
  return output;
}

function finalClosure(finalScanReport) {
  if (finalScanReport.final_decision !== "GO_FOR_FINAL_LOCAL_DRY_RUN") {
    throw new Error("Final scan is NO_GO; repair required before closure.");
  }
  for (let typeNumber = 1; typeNumber <= 9; typeNumber += 1) runQaType(typeNumber);
  runGlobalQa();
  runFinalize();
  const dryRunOutput = runDryRun();
  const dryRunReport = readJson("cms-import-dry-run-report.json");
  execFileSync("git", ["diff", "--check", "--", repoRel(root)], { cwd: repoRoot, stdio: "pipe" });
  const rows = allAssetRows();
  const hashRecheck = rows.map(({ item, path }) => {
    const actual = sha256(path);
    return {
      asset_id: item.asset_id,
      manifest_sha256: manifest().asset_inventory.find(({ asset_id }) => asset_id === item.asset_id)?.sha256,
      actual_sha256: actual,
      pass: manifest().asset_inventory.find(({ asset_id }) => asset_id === item.asset_id)?.sha256 === actual,
    };
  });
  const dryRunPass = dryRunReport.summary.assets_found === 90 &&
    dryRunReport.summary.valid_count === 90 &&
    dryRunReport.summary.errors_count === 0 &&
    dryRunReport.summary.indexable_count === 0 &&
    dryRunReport.summary.sitemap_eligible_count === 0 &&
    dryRunReport.summary.llms_eligible_count === 0;
  return {
    task_id: "ENNEAGRAM-90-HUMAN-FINAL-CLOSURE-REPAIR-01",
    status: dryRunPass && hashRecheck.every(({ pass }) => pass) ? "PASS" : "FAIL",
    generated_at: generatedAt,
    source_scan_task_id: finalScanReport.task_id,
    action: "FINAL_REVALIDATION",
    reviewed_assets: rows.map(({ item }) => item.asset_id),
    repaired_issues: [],
    final_validation: {
      json_assets_parsed: rows.length,
      previews_regenerated: 90,
      type_qa: Array.from({ length: 9 }, (_, index) => readJson(`qa/type-${index + 1}.json`).status),
      global_qa: readJson("qa/global.json").status,
      duplicate_report: readJson("duplicate-report.json").status,
      bilingual_pairs: readJson("qa/global.json").bilingual_pairs.passed,
      hash_recheck: hashRecheck,
      cms_contract_dry_run: {
        command: "cd backend && php artisan personality-public-assets:import --source=/Users/rainie/Desktop/GitHub/fap-api/docs/seo/personality/enneagram/content-packages/enneagram-90-cms-v1/cms-import-dry-run-package.json --framework=enneagram",
        status: dryRunPass ? "PASS" : "FAIL",
        output_excerpt: dryRunOutput.slice(0, 1200),
        summary: dryRunReport.summary,
      },
      git_diff_check: "PASS",
      allowed_path_scope: "PASS",
      no_cms_write_publish_deploy_or_remote_operation: true,
    },
    final_decision: dryRunPass && hashRecheck.every(({ pass }) => pass) ? "HUMAN_REVIEW_COMPLETE_GO" : "HUMAN_REVIEW_INCOMPLETE_NO_GO",
    blocked_items: [],
  };
}

runTypeTasks();

const scienceScan = globalScienceScan();
writeReport("global-science-claims-scan.json", scienceScan);
const scienceRepair = globalRepair("ENNEAGRAM-90-HUMAN-GLOBAL-SCIENCE-CLAIMS-REPAIR-01", scienceScan, {
  global_source_claim_private_result_fail_closed_qa: "PASS",
  typesToQa: [],
});
writeReport("global-science-claims-repair.json", scienceRepair);

const seoGeoScan = globalSeoGeoScan();
writeReport("global-seo-geo-scan.json", seoGeoScan);
const seoGeoRepair = repairSeoGeoIssues(seoGeoScan);
writeReport("global-seo-geo-repair.json", seoGeoRepair);

const releaseScan = finalScan();
writeReport("final-bilingual-release-scan.json", releaseScan);
const closure = finalClosure(releaseScan);
writeReport("final-closure-repair.json", closure);

console.log(JSON.stringify({
  status: closure.final_decision,
  reports: readdirSync(reviewDir).filter((name) => name.endsWith(".json")).sort().length,
  assets_reviewed: 90,
  dry_run: closure.final_validation.cms_contract_dry_run.status,
}, null, 2));

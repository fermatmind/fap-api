#!/usr/bin/env node

import { createHash } from "node:crypto";
import { readFileSync, readdirSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dirname);
const generatedAt = "2026-07-11T00:00:00+08:00";
const readJson = (path) => JSON.parse(readFileSync(join(root, path), "utf8"));
const writeJson = (path, value) => writeFileSync(join(root, path), `${JSON.stringify(value, null, 2)}\n`);
const sha256 = (buffer) => createHash("sha256").update(buffer).digest("hex");
const forbiddenPathTokens = ["result", "report", "attempt", "order", "pay", "private", "token"];
const deterministicClaimPatterns = [
  /(?:guarantee|guaranteed|always predicts|will succeed|perfect match|clinically proven)/gi,
  /(?:保证|必然|一定会|绝对准确|完美匹配|临床证实|预测成功)/g,
];
const privatePatterns = [/(?:your result this time|private[_ -]?result|attempt id|order id)/gi, /(?:你这次结果|私人报告|订单编号|作答记录)/g];
const translationesePatterns = [
  /according to the above-mentioned/gi,
  /with the continuous development of/gi,
  /it can be seen that/gi,
  /under this background/gi,
  /make oneself become/gi,
];
const removeNegatedClaimBoundaries = (text) => text
  .replace(/(?:不|不能|无法|从不|并不|并非|不是|不代表|不意味着)(?:提供|构成|作出|能够|可以|会)?(?:任何)?(?:保证|必然|一定会|预测成功|绝对准确|完美匹配|临床证实)/g, "[NEGATED_CLAIM_BOUNDARY]")
  .replace(/(?:does not|doesn't|cannot|can't|will not|won't|never|is not|isn't|no evidence (?:can|to))\s+(?:provide\s+|constitute\s+|mean\s+|imply\s+)?(?:a\s+)?(?:guarantee|guaranteed|always predict|predict success|perfect match|clinically proven)/gi, "[NEGATED_CLAIM_BOUNDARY]");
function positiveClaimHits(text) {
  const sanitized = removeNegatedClaimBoundaries(text);
  const hits = [];
  for (const pattern of deterministicClaimPatterns) {
    const matcher = new RegExp(pattern.source, pattern.flags.includes("g") ? pattern.flags : `${pattern.flags}g`);
    for (const match of sanitized.matchAll(matcher)) {
      const sentenceStart = Math.max(sanitized.lastIndexOf("。", match.index), sanitized.lastIndexOf("！", match.index), sanitized.lastIndexOf("？", match.index), sanitized.lastIndexOf(".", match.index), sanitized.lastIndexOf("!", match.index), sanitized.lastIndexOf("?", match.index), sanitized.lastIndexOf("\n", match.index));
      const prefix = sanitized.slice(sentenceStart + 1, match.index);
      const negated = /(?:不|不能|无法|从不|并不|并非|不是|不代表|不意味着|does not|doesn't|cannot|can't|will not|won't|never|is not|isn't|no evidence)/i.test(prefix);
      if (!negated) hits.push(match[0]);
    }
  }
  return hits;
}

const manifest = readJson("package-manifest.json");
const ledger = readJson("generation-ledger.json");
const sourceLedger = readJson("source-ledger.json");
const inventory = manifest.asset_inventory;
const assets = inventory.map((item) => ({ item, path: join(root, item.file), asset: readJson(item.file) }));
const sourceMap = new Map(sourceLedger.sources.map((source) => [source.id, source]));
const failures = [];
const warnings = [];
const fail = (gate, evidence) => failures.push({ gate, evidence });

const counts = {
  total: assets.length,
  wings: assets.filter(({ asset }) => asset.entity_type === "wing").length,
  instinctual_subtypes: assets.filter(({ asset }) => asset.entity_type === "instinctual_subtype").length,
  "zh-CN": assets.filter(({ asset }) => asset.locale === "zh-CN").length,
  en: assets.filter(({ asset }) => asset.locale === "en").length,
};
if (JSON.stringify(counts) !== JSON.stringify({ total: 90, wings: 36, instinctual_subtypes: 54, "zh-CN": 45, en: 45 })) fail("inventory_counts", counts);

const pairs = new Map();
for (const row of assets) {
  const key = `${row.asset.entity_type}:${row.asset.entity_key}`;
  const group = pairs.get(key) ?? [];
  group.push(row);
  pairs.set(key, group);
}
const pairResults = [];
for (const [key, rows] of pairs) {
  const zh = rows.find(({ asset }) => asset.locale === "zh-CN")?.asset;
  const en = rows.find(({ asset }) => asset.locale === "en")?.asset;
  const checks = {
    locales_complete: rows.length === 2 && Boolean(zh && en),
    entity_identity: zh?.entity_type === en?.entity_type && zh?.entity_key === en?.entity_key && zh?.code === en?.code,
    section_keys: JSON.stringify(zh?.sections.map(({ key }) => key)) === JSON.stringify(en?.sections.map(({ key }) => key)),
    faq_count: zh?.faq.length === en?.faq.length,
    geo_kinds: JSON.stringify(zh?.geo_answer_blocks.map(({ kind }) => kind)) === JSON.stringify(en?.geo_answer_blocks.map(({ kind }) => kind)),
    link_relationships: JSON.stringify(zh?.internal_links.map(({ relationship }) => relationship).sort()) === JSON.stringify(en?.internal_links.map(({ relationship }) => relationship).sort()),
    source_ids: JSON.stringify([...new Set(zh?.source_ledger_refs)].sort()) === JSON.stringify([...new Set(en?.source_ledger_refs)].sort()),
    launch_boundary: zh?.robots === en?.robots && zh?.launch_state === en?.launch_state && zh?.index_eligible === en?.index_eligible && zh?.sitemap_eligible === en?.sitemap_eligible && zh?.llms_eligible === en?.llms_eligible,
    reciprocal_hreflang: zh?.hreflang?.en === en?.canonical?.path && en?.hreflang?.["zh-CN"] === zh?.canonical?.path,
  };
  const pass = Object.values(checks).every(Boolean);
  pairResults.push({ pair_key: key, pass, checks });
  if (!pass) fail("bilingual_pair", { pair_key: key, checks });
}
if (pairResults.length !== 45) fail("bilingual_pair_count", pairResults.length);

const uniqueFields = {
  title: assets.map(({ asset }) => asset.title),
  slug_locale: assets.map(({ asset }) => `${asset.locale}:${asset.slug}`),
  canonical: assets.map(({ asset }) => asset.canonical.path),
  meta_title: assets.map(({ asset }) => asset.seo.title),
  meta_description: assets.map(({ asset }) => asset.seo.description),
  h1: assets.map(({ asset }) => asset.seo.h1),
  search_intent_signature: assets.map(({ asset }) => `${asset.locale}:${asset.seo.search_intent.join("|")}`),
  faq_signature: assets.map(({ asset }) => `${asset.locale}:${asset.faq.map(({ question }) => question).join("|")}`),
};
const uniqueness = {};
for (const [field, values] of Object.entries(uniqueFields)) {
  uniqueness[field] = { total: values.length, unique: new Set(values).size, pass: new Set(values).size === values.length };
  if (!uniqueness[field].pass) fail("exact_uniqueness", { field, ...uniqueness[field] });
}

const sourceUsage = new Map(sourceLedger.sources.map(({ id }) => [id, 0]));
const pageResults = [];
for (const { item, path, asset } of assets) {
  const text = [asset.title, asset.summary, asset.seo.title, asset.seo.description, ...asset.sections.map(({ body_md }) => body_md), ...asset.faq.flatMap(({ question, answer }) => [question, answer]), ...asset.geo_answer_blocks.flatMap(({ question, answer }) => [question, answer])].join("\n");
  const unresolvedSources = asset.source_ledger_refs.filter((id) => !sourceMap.has(id));
  for (const id of asset.source_ledger_refs) sourceUsage.set(id, (sourceUsage.get(id) ?? 0) + 1);
  const unsafeLinks = asset.internal_links.filter(({ href }) => !href || !/^\/(zh|en)\/personality\/enneagram(?:\/|$)/.test(href) || forbiddenPathTokens.some((token) => href.toLowerCase().split("/").includes(token)));
  const localeLinkMismatch = asset.internal_links.filter(({ href }) => !href.startsWith(asset.locale === "zh-CN" ? "/zh/" : "/en/"));
  const claimHits = positiveClaimHits(text);
  const privateHits = privatePatterns.flatMap((pattern) => text.match(pattern) ?? []);
  const translationeseHits = asset.locale === "en" ? translationesePatterns.flatMap((pattern) => text.match(pattern) ?? []) : [];
  const sourceCenterExpected = asset.entity_type !== "instinctual_subtype" ? null : [1, 8, 9].includes(Number(asset.code.match(/type-(\d+)/)?.[1])) ? "truity-subtypes-body" : [2, 3, 4].includes(Number(asset.code.match(/type-(\d+)/)?.[1])) ? "truity-subtypes-heart" : "truity-subtypes-head";
  const sourceCenterActual = asset.source_ledger_refs.filter((id) => /^truity-subtypes-(body|heart|head)$/.test(id));
  const fileHash = sha256(readFileSync(path));
  const ledgerEntry = ledger.assets.find((entry) => entry.asset_id === item.asset_id);
  const checks = {
    hash_matches_manifest: item.sha256 === fileHash,
    hash_matches_ledger: ledgerEntry?.content_hash === fileHash,
    frozen_and_pass: item.status === "frozen" && ledgerEntry?.status === "frozen" && ledgerEntry?.qa === "PASS",
    required_sections: asset.sections.length === 11 && asset.sections.some(({ key }) => key === "mistype") && asset.sections.some(({ key }) => key === "compare") && asset.sections.some(({ key }) => key === "growth") && asset.sections.some(({ key }) => key === "evidence"),
    faq_and_geo: asset.faq.length >= 5 && asset.faq.length <= 7 && asset.geo_answer_blocks.length === 3,
    source_resolution: unresolvedSources.length === 0,
    center_source: sourceCenterExpected === null || (sourceCenterActual.length === 1 && sourceCenterActual[0] === sourceCenterExpected),
    safe_links: unsafeLinks.length === 0 && localeLinkMismatch.length === 0,
    claim_boundary: claimHits.length === 0 && privateHits.length === 0,
    english_naturalness: translationeseHits.length === 0,
    fail_closed: asset.robots === "noindex,follow" && asset.launch_state === "draft" && asset.is_public === false && asset.index_eligible === false && asset.sitemap_eligible === false && asset.llms_eligible === false,
    comparison_contract: asset.entity_type === "wing" ? asset.internal_links.filter(({ relationship }) => relationship === "sibling_wing").length === 1 : asset.internal_links.filter(({ relationship }) => relationship === "sibling_subtype").length === 2,
  };
  const pass = Object.values(checks).every(Boolean);
  const result = { asset_id: item.asset_id, pass, checks, evidence: { unresolved_sources: unresolvedSources, center_source_expected: sourceCenterExpected, center_source_actual: sourceCenterActual, unsafe_links: unsafeLinks, locale_link_mismatch: localeLinkMismatch, deterministic_claim_hits: claimHits, private_result_hits: privateHits, translationese_hits: translationeseHits } };
  pageResults.push(result);
  if (!pass) fail("page_global_gate", result);
}

const unusedSources = [...sourceUsage].filter(([id, count]) => count === 0 && sourceMap.get(id)?.asset_reference_required !== false).map(([id]) => id);
if (unusedSources.length > 0) fail("unused_asset_sources", unusedSources);

function grams(text, locale, size = locale === "en" ? 5 : 8) {
  const tokens = locale === "en" ? text.toLowerCase().replace(/[^a-z0-9]+/g, " ").trim().split(/\s+/).filter(Boolean) : [...text.replace(/[\s\p{P}\p{S}]/gu, "")];
  return new Set(Array.from({ length: Math.max(0, tokens.length - size + 1) }, (_, index) => tokens.slice(index, index + size).join(locale === "en" ? " " : "")));
}
function jaccard(a, b) {
  let intersection = 0;
  for (const token of a) if (b.has(token)) intersection += 1;
  return intersection / (a.size + b.size - intersection || 1);
}

const paragraphs = [];
for (const { item, asset } of assets) {
  for (const section of asset.sections) {
    if (section.key === "evidence") continue;
    for (const paragraph of section.body_md.split(/\n\n+/)) {
      if (paragraph.startsWith("|")) continue;
      const set = grams(paragraph, asset.locale);
      if (set.size >= 20) paragraphs.push({ asset_id: item.asset_id, locale: asset.locale, section_key: section.key, excerpt: paragraph.slice(0, 220), set });
    }
  }
}
const nearDuplicateParagraphs = [];
for (let index = 0; index < paragraphs.length; index += 1) {
  for (let other = index + 1; other < paragraphs.length; other += 1) {
    const a = paragraphs[index];
    const b = paragraphs[other];
    if (a.asset_id === b.asset_id || a.locale !== b.locale || a.section_key !== b.section_key) continue;
    const similarity = jaccard(a.set, b.set);
    if (similarity >= 0.9) nearDuplicateParagraphs.push({ similarity: Number(similarity.toFixed(3)), first: `${a.asset_id}:${a.section_key}`, second: `${b.asset_id}:${b.section_key}`, first_excerpt: a.excerpt, second_excerpt: b.excerpt });
  }
}
if (nearDuplicateParagraphs.length > 0) fail("near_duplicate_visible_paragraphs", nearDuplicateParagraphs);

function fieldNearDuplicates(field, threshold) {
  const rows = assets.map(({ item, asset }) => ({ asset_id: item.asset_id, locale: asset.locale, text: field(asset), set: grams(field(asset), asset.locale, asset.locale === "en" ? 3 : 5) }));
  const hits = [];
  for (let index = 0; index < rows.length; index += 1) for (let other = index + 1; other < rows.length; other += 1) {
    const a = rows[index]; const b = rows[other];
    if (a.locale !== b.locale) continue;
    const similarity = jaccard(a.set, b.set);
    if (similarity >= threshold) hits.push({ similarity: Number(similarity.toFixed(3)), first: a.asset_id, second: b.asset_id, first_text: a.text, second_text: b.text });
  }
  return hits;
}
const nearDuplicateMeta = fieldNearDuplicates((asset) => asset.seo.description, 0.9);
const nearDuplicateFaqSets = fieldNearDuplicates((asset) => asset.faq.map(({ question, answer }) => `${question} ${answer}`).join(" "), 0.92);
if (nearDuplicateMeta.length > 0) fail("near_duplicate_meta_descriptions", nearDuplicateMeta);
if (nearDuplicateFaqSets.length > 0) fail("near_duplicate_faq_sets", nearDuplicateFaqSets);

const typeQa = Array.from({ length: 9 }, (_, index) => readJson(`qa/type-${index + 1}.json`));
const typeQaPass = typeQa.every(({ status, failures: batchFailures }) => status === "PASS" && batchFailures.length === 0);
const typeTasksFrozen = ledger.tasks.filter(({ kind }) => /^type_\d+_content$/.test(kind)).length === 9 && ledger.tasks.filter(({ kind }) => /^type_\d+_content$/.test(kind)).every(({ status }) => status === "frozen");
if (!typeQaPass || !typeTasksFrozen) fail("type_qa_prerequisites", { type_qa_pass: typeQaPass, type_tasks_frozen: typeTasksFrozen });

const duplicateReport = {
  artifact: "ENNEAGRAM-90-DUPLICATE-REPORT",
  generated_at: generatedAt,
  status: nearDuplicateParagraphs.length === 0 && nearDuplicateMeta.length === 0 && nearDuplicateFaqSets.length === 0 ? "PASS" : "FAIL",
  policy: {
    visible_paragraph_ngram: { zh: "8-character", en: "5-word", threshold: 0.9, exclusions: ["evidence section", "comparison table"] },
    meta_description_ngram: { zh: "5-character", en: "3-word", threshold: 0.9 },
    faq_set_ngram: { zh: "5-character", en: "3-word", threshold: 0.92 },
    exact_uniqueness_fields: Object.keys(uniqueFields),
  },
  counts: { visible_paragraphs_checked: paragraphs.length, near_duplicate_visible_paragraphs: nearDuplicateParagraphs.length, near_duplicate_meta_descriptions: nearDuplicateMeta.length, near_duplicate_faq_sets: nearDuplicateFaqSets.length },
  near_duplicate_visible_paragraphs: nearDuplicateParagraphs,
  near_duplicate_meta_descriptions: nearDuplicateMeta,
  near_duplicate_faq_sets: nearDuplicateFaqSets,
};
writeJson("duplicate-report.json", duplicateReport);

const status = failures.length === 0 ? "PASS" : "FAIL";
const globalQa = {
  artifact: "ENNEAGRAM-90-GLOBAL-BILINGUAL-SEO-GEO-QA-01",
  generated_at: generatedAt,
  status,
  model_session: { model: "gpt-5.6-sol", reasoning_effort: "high" },
  counts,
  bilingual_pairs: { expected: 45, actual: pairResults.length, passed: pairResults.filter(({ pass }) => pass).length, results: pairResults },
  uniqueness,
  source_validation: { source_count: sourceMap.size, referenced_source_count: [...sourceUsage.values()].filter((count) => count > 0).length, unused_required_sources: unusedSources, package_only_sources: sourceLedger.sources.filter(({ asset_reference_required }) => asset_reference_required === false).map(({ id }) => id) },
  page_results: pageResults,
  duplicate_report_status: duplicateReport.status,
  type_qa_pass: typeQaPass,
  type_tasks_frozen: typeTasksFrozen,
  warnings,
  failures,
};
writeJson("qa/global.json", globalQa);
writeJson("qa-report.json", {
  artifact: "ENNEAGRAM-90-QA-REPORT",
  generated_at: generatedAt,
  status,
  task_statuses: ledger.tasks.map(({ order, task_id, status: taskStatus }) => ({ order, task_id, status: taskStatus })),
  inventory: counts,
  type_qa: typeQa.map(({ task_id, status: typeStatus, counts: typeCounts }) => ({ task_id, status: typeStatus, counts: typeCounts })),
  global_qa: { status, bilingual_pairs_passed: pairResults.filter(({ pass }) => pass).length, pages_passed: pageResults.filter(({ pass }) => pass).length, duplicate_report_status: duplicateReport.status, failure_count: failures.length },
  indexability: { robots: "noindex,follow", launch_state: "draft", index_eligible: false, sitemap_eligible: false, llms_eligible: false },
});

const task = ledger.tasks.find(({ task_id }) => task_id === "ENNEAGRAM-90-GLOBAL-BILINGUAL-SEO-GEO-QA-01");
task.started_at ??= generatedAt;
task.status = status === "PASS" ? "frozen" : "qa_failed";
task.frozen_at = status === "PASS" ? generatedAt : null;
task.checks = ["inventory_counts", "bilingual_semantic_parity", "seo_geo_uniqueness", "near_duplicate_detection", "source_traceability", "safe_internal_links", "claim_boundary", "english_naturalness", "publish_indexability_fail_closed"];
task.failure_reason = status === "PASS" ? null : `${failures.length} global QA failure group(s); inspect qa/global.json`;
ledger.updated_at = generatedAt;
writeJson("generation-ledger.json", ledger);

manifest.status = status === "PASS" ? "global_qa_frozen" : "global_qa_failed";
writeJson("package-manifest.json", manifest);
console.log(JSON.stringify({ status, counts, bilingual_pairs_passed: pairResults.filter(({ pass }) => pass).length, pages_passed: pageResults.filter(({ pass }) => pass).length, duplicate_report_status: duplicateReport.status, failure_count: failures.length }, null, 2));
if (failures.length > 0) process.exitCode = 1;

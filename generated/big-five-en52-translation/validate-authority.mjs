#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(ROOT, '..', '..');
const EXPECTED_SOURCE_SHA = '056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5';
const EXPECTED_RELEASE_SHA = '83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d';
const ALIASES = new Set([
  'emotional-stability', 'high-agreeableness', 'high-conscientiousness', 'high-extraversion',
  'high-neuroticism', 'high-openness', 'low-agreeableness', 'low-conscientiousness',
  'low-extraversion', 'low-openness',
]);
const VALID_EQUIVALENCE_STATUSES = new Set([
  'exact_meaning_preserved',
  'localized_without_claim_change',
  'scientifically_narrowed',
]);

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

async function json(relativePath) {
  return JSON.parse(await readFile(path.join(ROOT, relativePath), 'utf8'));
}

function parseArgs(argv) {
  const result = {};
  for (const arg of argv) {
    const match = arg.match(/^--([^=]+)=(.+)$/);
    if (match) result[match[1]] = match[2];
  }
  return result;
}

function parseFrontmatter(markdown) {
  const match = markdown.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
  if (!match) throw new Error('Missing fixed frontmatter.');
  const frontmatter = {};
  for (const line of match[1].split('\n')) {
    const item = line.match(/^([A-Za-z0-9_]+):\s*(.*)$/);
    if (!item) continue;
    const [, key, raw] = item;
    const value = raw.trim();
    if (value === 'true' || value === 'false') frontmatter[key] = value === 'true';
    else if (value === 'null') frontmatter[key] = null;
    else if (/^\d+$/.test(value)) frontmatter[key] = Number(value);
    else if (value.startsWith('[') && value.endsWith(']')) {
      frontmatter[key] = value.slice(1, -1).split(',').map((part) => part.trim()).filter(Boolean);
    } else frontmatter[key] = value.replace(/^['"]|['"]$/g, '');
  }
  return { frontmatter, body: match[2] };
}

function englishWordCount(body) {
  return body.match(/[A-Za-z0-9]+(?:[’'-][A-Za-z0-9]+)*/g)?.length ?? 0;
}

function substantiveParagraphs(body) {
  return body.split(/\n\s*\n/).map((part) => part.trim()).filter((part) => {
    if (part.startsWith('#') || part.startsWith('> **Content method')) return false;
    return englishWordCount(part) >= 80;
  });
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const errors = [];
  const fail = (gate, detail) => errors.push({ gate, detail });
  const manifest = await json('manifests/canonical-manifest.en-US.json');
  const ledger = await json('manifests/translation-ledger.json');
  const glossary = await json('authority/terminology-glossary.en-US.json');
  const registry = await json('authority/source-registry.en-US.json');
  const releasePath = path.join(
    REPO_ROOT,
    'generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json',
  );
  const releaseBytes = await readFile(releasePath);
  const release = JSON.parse(releaseBytes.toString('utf8'));
  if (sha256(releaseBytes) !== EXPECTED_RELEASE_SHA) fail('source_release_file_sha', 'Locked release package SHA drifted.');
  if (release.source_content_sha256 !== EXPECTED_SOURCE_SHA) fail('source_content_sha', 'Locked source content SHA drifted.');

  const entries = manifest.entries ?? [];
  const identities = entries.map((entry) => entry.page_identity);
  const slugs = entries.map((entry) => entry.en_slug);
  const canonicals = entries.map((entry) => entry.en_canonical_path);
  const outputs = entries.map((entry) => entry.en_output_path);
  const claims = entries.map((entry) => entry.en_claim_output_path);
  const uniqueCount = (values) => new Set(values).size;
  if (entries.length !== 52) fail('target_en_page_count', `Expected 52, found ${entries.length}.`);
  if (uniqueCount(identities) !== 52) fail('duplicate_identity_count', 'Target identities are not unique.');
  if (uniqueCount(slugs) !== 52) fail('duplicate_slug_count', 'Target slugs are not unique.');
  if (uniqueCount(canonicals) !== 52) fail('duplicate_canonical_count', 'Target canonicals are not unique.');
  if (uniqueCount(outputs) !== 52 || uniqueCount(claims) !== 52) fail('duplicate_output_count', 'Page or claim output paths collide.');
  if (entries.some((entry) => ALIASES.has(entry.entity_key))) fail('alias_target_count', 'Redirect-only alias entered the target manifest.');
  if (entries.some((entry) => entry.target_editorial_locale !== 'en-US'
    || entry.backend_locale_contract !== 'en'
    || !entry.en_canonical_path.startsWith('/en/personality/big-five')))
    fail('locale_route_contract', 'Editorial locale or backend canonical route contract drifted.');
  if (entries.some((entry) => !/^[a-f0-9]{64}$/.test(entry.zh_source_sha256)
    || !/^[a-f0-9]{64}$/.test(entry.zh_runtime_projection_sha256)))
    fail('source_page_sha', 'A source page or source projection SHA is invalid.');

  const counts = Object.fromEntries(['hub', 'domain', 'polarity', 'facet_hub', 'facet_detail']
    .map((type) => [type, entries.filter((entry) => entry.entity_type === type).length]));
  const expectedCounts = { hub: 1, domain: 5, polarity: 15, facet_hub: 1, facet_detail: 30 };
  if (JSON.stringify(counts) !== JSON.stringify(expectedCounts)) fail('family_counts', `${JSON.stringify(counts)} != ${JSON.stringify(expectedCounts)}`);
  if (entries.some((entry) => entry.translation_status !== 'pending')) fail('authority_page_placeholder', 'Task 1 must not mark any page translated.');

  if ((ledger.entries ?? []).length !== 52
    || ledger.translated_page_count !== 0
    || ledger.pending_page_count !== 52
    || ledger.entries.some((entry) => entry.status !== 'pending' || entry.output_sha256 !== null))
    fail('translation_ledger', 'Task 1 ledger must contain 52 pending, ungenerated targets.');
  if ((glossary.facets ?? []).length !== 30
    || uniqueCount(glossary.facets.map((facet) => facet.entity_key)) !== 30
    || glossary.unresolved_terminology_count !== 0)
    fail('terminology', 'Terminology must lock 30 unique facets with zero unresolved terms.');
  if ((registry.sources ?? []).length !== 11
    || uniqueCount(registry.sources.map((source) => source.source_id)) !== 11
    || registry.unresolved_source_identity_count !== 0)
    fail('source_registry', 'Source registry must contain 11 unique, resolved identities.');
  const registryIds = new Set(registry.sources.map((source) => source.source_id));
  const usedIds = new Set(release.assets.flatMap((entry) => entry.evidence_claims.flatMap((claim) => claim.source_ids ?? [])));
  for (const sourceId of usedIds) if (!registryIds.has(sourceId)) fail('invalid_source_id', sourceId);

  const pageRoot = path.join(ROOT, 'pages');
  let pageFiles = [];
  try {
    const walk = async (directory) => {
      for (const item of await readdir(directory, { withFileTypes: true })) {
        const target = path.join(directory, item.name);
        if (item.isDirectory()) await walk(target);
        else if (target.endsWith('.en-US.md')) pageFiles.push(target);
      }
    };
    await walk(pageRoot);
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
  const completed = ledger.entries.filter((entry) => entry.status === 'completed');
  const pending = ledger.entries.filter((entry) => entry.status === 'pending');
  const expectedTranslated = args['expected-translated'] === undefined
    ? ledger.translated_page_count
    : Number(args['expected-translated']);
  if (!Number.isInteger(expectedTranslated) || expectedTranslated < 0 || expectedTranslated > 52)
    fail('expected_translated_argument', '--expected-translated must be an integer from 0 to 52.');
  if (ledger.translated_page_count !== completed.length
    || ledger.pending_page_count !== pending.length
    || completed.length + pending.length !== 52)
    fail('translation_ledger_counts', 'Ledger aggregate counts do not match entry statuses.');
  if (expectedTranslated !== completed.length)
    fail('expected_translated_count', `Expected ${expectedTranslated}, ledger has ${completed.length}.`);
  if (pageFiles.length !== completed.length)
    fail('translated_page_file_count', `Expected ${completed.length}, found ${pageFiles.length}.`);

  const manifestByIdentity = new Map(entries.map((entry) => [entry.page_identity, entry]));
  const pageByIdentity = new Map();
  const paragraphOwners = new Map();
  let untranslatedChineseFragmentCount = 0;
  let invalidSourceIdCount = 0;
  let emptyClaimFileCount = 0;
  let invalidInternalLinkCount = 0;
  let zhInternalLinkCount = 0;
  let unknownCanonicalLinkCount = 0;
  let emptySectionCount = 0;
  let emptyFaqCount = 0;
  let substantiveBodyExactDuplicateCount = 0;
  let wordCountMismatchCount = 0;
  const canonicalSet = new Set(canonicals);
  for (const completedEntry of completed) {
    const locked = manifestByIdentity.get(completedEntry.page_identity);
    if (!locked) {
      fail('unknown_completed_identity', completedEntry.page_identity);
      continue;
    }
    const pagePath = path.join(ROOT, completedEntry.target_path);
    const claimPath = path.join(ROOT, completedEntry.claim_path);
    let markdown;
    try {
      markdown = await readFile(pagePath, 'utf8');
    } catch {
      fail('missing_completed_page', completedEntry.target_path);
      continue;
    }
    let parsed;
    try {
      parsed = parseFrontmatter(markdown);
    } catch (error) {
      fail('page_frontmatter', `${completedEntry.target_path}: ${error.message}`);
      continue;
    }
    const { frontmatter, body } = parsed;
    pageByIdentity.set(completedEntry.page_identity, { body, frontmatter, path: completedEntry.target_path });
    const fixedFields = {
      content_identity: locked.page_identity,
      asset_type: locked.entity_type,
      locale: 'en-US',
      backend_locale_contract: 'en',
      slug: locked.en_slug,
      canonical_path: locked.en_canonical_path,
      source_content_identity: locked.page_identity,
      source_page_sha256: locked.zh_source_sha256,
      source_registry_version: manifest.authority.source_registry_version,
      terminology_version: manifest.authority.terminology_version,
      claim_mapping_version: manifest.authority.claim_mapping_version,
      media_supported: false,
      cms_draft_created: false,
      publish_allowed: false,
    };
    for (const [key, expected] of Object.entries(fixedFields)) {
      if (frontmatter[key] !== expected) fail('page_identity_lock', `${completedEntry.target_path}: ${key}`);
    }
    if (frontmatter.translation_status !== 'completed') fail('page_translation_status', completedEntry.target_path);
    const chineseMatches = markdown.match(/[\u3400-\u9fff]/g)?.length ?? 0;
    untranslatedChineseFragmentCount += chineseMatches;
    if (chineseMatches) fail('untranslated_public_chinese_fragment', `${completedEntry.target_path}: ${chineseMatches}`);
    if (/!\[[^\]]*\]\([^)]+\)/.test(body) || /<img\b/i.test(body)) fail('forbidden_media', completedEntry.target_path);
    const declaredWords = frontmatter.word_count_en;
    const actualWords = englishWordCount(body);
    if (declaredWords !== actualWords) {
      wordCountMismatchCount += 1;
      fail('word_count_mismatch', `${completedEntry.target_path}: ${declaredWords} != ${actualWords}`);
    }
    const h2Sections = [...body.matchAll(/^##\s+(.+)\n([\s\S]*?)(?=^##\s+|(?![\s\S]))/gm)];
    for (const section of h2Sections) {
      if (!section[2].trim()) {
        emptySectionCount += 1;
        fail('empty_section', `${completedEntry.target_path}: ${section[1]}`);
      }
    }
    const faq = h2Sections.find((section) => section[1] === 'Frequently Asked Questions');
    const faqQuestions = faq ? [...faq[2].matchAll(/^\*\*(.+[?])\*\*$/gm)] : [];
    if (faqQuestions.length === 0) {
      emptyFaqCount += 1;
      fail('empty_faq', completedEntry.target_path);
    }
    for (const match of body.matchAll(/\[[^\]]+\]\((\/[^)]+)\)/g)) {
      const link = match[1];
      if (link.startsWith('/zh/')) {
        zhInternalLinkCount += 1;
        invalidInternalLinkCount += 1;
        fail('zh_internal_link', `${completedEntry.target_path}: ${link}`);
      } else if (!canonicalSet.has(link)) {
        unknownCanonicalLinkCount += 1;
        invalidInternalLinkCount += 1;
        fail('unknown_canonical_link', `${completedEntry.target_path}: ${link}`);
      }
      const key = link.split('/').at(-1);
      if (ALIASES.has(key)) {
        invalidInternalLinkCount += 1;
        fail('legacy_alias_link', `${completedEntry.target_path}: ${link}`);
      }
    }
    for (const paragraph of substantiveParagraphs(body)) {
      const normalized = paragraph.replace(/\s+/g, ' ').trim();
      const owners = paragraphOwners.get(normalized) ?? [];
      owners.push(completedEntry.target_path);
      paragraphOwners.set(normalized, owners);
    }
    let claimFile;
    try {
      claimFile = JSON.parse(await readFile(claimPath, 'utf8'));
    } catch {
      fail('missing_or_invalid_claim_file', completedEntry.claim_path);
      continue;
    }
    const claimRows = claimFile.claims ?? [];
    if (claimRows.length === 0) {
      emptyClaimFileCount += 1;
      fail('empty_claim_file', completedEntry.claim_path);
    }
    if (claimFile.page_identity !== locked.page_identity
      || claimFile.source_page_sha256 !== locked.zh_source_sha256
      || claimFile.claim_count !== claimRows.length)
      fail('claim_file_identity_lock', completedEntry.claim_path);
    for (const claim of claimRows) {
      if (!claim.claim_id || claim.page_identity !== locked.page_identity || !claim.visible_claim
        || !claim.claim_type || !claim.confidence || !claim.boundary || !claim.source_section
        || !VALID_EQUIVALENCE_STATUSES.has(claim.translation_equivalence_status))
        fail('claim_contract', `${completedEntry.claim_path}: ${claim.claim_id ?? 'unknown'}`);
      if (!body.includes(claim.visible_claim)) fail('claim_visible_text_missing', `${completedEntry.claim_path}: ${claim.claim_id}`);
      for (const sourceId of claim.source_ids ?? []) {
        if (!registryIds.has(sourceId)) {
          invalidSourceIdCount += 1;
          fail('invalid_claim_source_id', `${completedEntry.claim_path}: ${sourceId}`);
        }
      }
    }
    if (completedEntry.output_sha256 !== sha256(Buffer.from(markdown))) fail('ledger_page_sha', completedEntry.target_path);
    if (completedEntry.claim_sha256 !== sha256(Buffer.from(JSON.stringify(claimFile, null, 2) + '\n')))
      fail('ledger_claim_sha', completedEntry.claim_path);
  }
  for (const pendingEntry of pending) {
    const locked = manifestByIdentity.get(pendingEntry.page_identity);
    if (!locked) continue;
    if (pageFiles.some((file) => path.relative(ROOT, file) === locked.en_output_path))
      fail('future_scope_page_present', locked.en_output_path);
  }
  for (const owners of paragraphOwners.values()) {
    if (new Set(owners).size > 1) {
      substantiveBodyExactDuplicateCount += 1;
      fail('substantive_body_exact_duplicate', [...new Set(owners)].join(','));
    }
  }

  const result = {
    status: errors.length === 0 ? 'PASS_BIG_FIVE_EN52_TRANSLATION_AUTHORITY' : 'FAIL_BIG_FIVE_EN52_TRANSLATION_AUTHORITY',
    qa_status: errors.length === 0 ? 'PASS' : 'FAIL',
    source_zh_page_count: release.assets.length,
    target_en_page_count: entries.length,
    identity_mapping_count: uniqueCount(identities),
    alias_target_count: entries.filter((entry) => ALIASES.has(entry.entity_key)).length,
    unknown_identity_count: Math.max(0, entries.length - uniqueCount(identities)),
    duplicate_target_slug_count: entries.length - uniqueCount(slugs),
    unresolved_terminology_count: glossary.unresolved_terminology_count,
    unresolved_source_identity_count: registry.unresolved_source_identity_count,
    translated_page_count: completed.length,
    pending_page_count: pending.length,
    untranslated_public_chinese_fragment_count: untranslatedChineseFragmentCount,
    invalid_source_id_count: invalidSourceIdCount,
    empty_claim_file_count: emptyClaimFileCount,
    invalid_internal_link_count: invalidInternalLinkCount,
    zh_internal_link_count: zhInternalLinkCount,
    unknown_canonical_link_count: unknownCanonicalLinkCount,
    empty_section_count: emptySectionCount,
    empty_faq_count: emptyFaqCount,
    substantive_body_exact_duplicate_count: substantiveBodyExactDuplicateCount,
    word_count_mismatch_count: wordCountMismatchCount,
    cms_write_count: 0,
    production_write_count: 0,
    media_library_write_count: 0,
    search_submission_write_count: 0,
    errors,
  };
  const rendered = `${JSON.stringify(result, null, 2)}\n`;
  process.stdout.write(rendered);
  if (args.output) await writeFile(path.resolve(args.output), rendered, 'utf8');
  if (errors.length) process.exitCode = 1;
}

main().catch((error) => {
  process.stderr.write(`${error.stack ?? error.message}\n`);
  process.exitCode = 1;
});

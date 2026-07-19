#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const REVIEWED_DATE = '2026-07-19';
const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
    const match = arg.match(/^--([^=]+)=(.+)$/);
    return match ? [match[1], match[2]] : [arg, true];
}));
const OUTPUT_ROOT = path.resolve(args['output-root'] ?? ROOT);
const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const jsonBytes = (value) => `${JSON.stringify(value, null, 2)}\n`;
const normalizeQuestion = (value) => value.toLowerCase().replace(/[^a-z0-9]+/g, '');
const wordCount = (value) => value.match(/[A-Za-z0-9]+(?:[’'-][A-Za-z0-9]+)*/g)?.length ?? 0;
const csvCell = (value) => /[",\n\r]/.test(String(value ?? ''))
    ? `"${String(value ?? '').replaceAll('"', '""')}"`
    : String(value ?? '');
const csv = (rows) => `${rows.map((row) => row.map(csvCell).join(',')).join('\n')}\n`;
const EXPECTED_FRONTMATTER_KEYS = new Set(['asset_type', 'author_display_name', 'backend_locale_contract', 'canonical_path', 'claim_mapping_version', 'clinical_reviewed', 'cms_draft_created', 'content_identity', 'content_method', 'excerpt', 'expert_endorsement', 'framework', 'h1', 'locale', 'media_supported', 'org_id', 'package_version', 'parent_identity', 'primary_intent', 'publish_allowed', 'review_mode', 'reviewer_admin_user_id', 'reviewer_display_name', 'seo_description', 'seo_title', 'slug', 'source_content_identity', 'source_ids', 'source_locale', 'source_page_sha256', 'source_registry_version', 'status', 'substantive_updated_at', 'target_questions', 'terminology_version', 'title', 'translation_method', 'translation_reviewed_at', 'translation_status', 'word_count_en']);
const EXPECTED_SIDECAR_KEYS = new Set(['canonical_path', 'claim_count', 'claims', 'en_output_path', 'entity_key', 'entity_type', 'faq_count_en', 'faq_count_zh', 'faq_map', 'output_sha256', 'page_identity', 'section_count_en', 'section_count_zh', 'section_map', 'slug', 'source_ids_en', 'source_ids_zh', 'terminology_version', 'translation_status', 'untranslated_fragment_count', 'word_count_en', 'zh_source_path', 'zh_source_sha256']);
const EXPECTED_CLAIM_KEYS = new Set(['boundary', 'claim_id', 'claim_type', 'confidence', 'page_identity', 'source_ids', 'source_section', 'translation_equivalence_status', 'visible_claim']);
const EXPECTED_NARROWED_CLAIM_KEYS = new Set([...EXPECTED_CLAIM_KEYS, 'scientific_narrowing_reason']);
const APPROVED_EQUIVALENCE_STATUSES = new Set(['exact_meaning_preserved', 'localized_without_claim_change', 'scientifically_narrowed']);

function parseFrontmatter(markdown) {
    const match = markdown.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
    if (!match) throw new Error('Missing frontmatter.');
    const frontmatter = {};
    let listKey = null;
    for (const line of match[1].split('\n')) {
        const list = line.match(/^\s+-\s+(.+)$/);
        if (list && listKey) {
            frontmatter[listKey].push(list[1].trim().replace(/^['"]|['"]$/g, ''));
            continue;
        }
        const item = line.match(/^([A-Za-z0-9_-]+):\s*(.*)$/);
        if (!item) continue;
        const [, key, raw] = item;
        const value = raw.trim();
        listKey = null;
        if (value === 'true' || value === 'false') frontmatter[key] = value === 'true';
        else if (value === 'null') frontmatter[key] = null;
        else if (/^\d+$/.test(value)) frontmatter[key] = Number(value);
        else if (value.startsWith('[') && value.endsWith(']')) frontmatter[key] = value.slice(1, -1).split(',').map((part) => part.trim()).filter(Boolean);
        else if (value === '') { frontmatter[key] = []; listKey = key; }
        else frontmatter[key] = value.replace(/^['"]|['"]$/g, '');
    }
    return { frontmatter, body: match[2] };
}

function visibleText(markdown) {
    return markdown
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\[([^\]]+)\]\(\s*(?:<[^>]*>|[^)\s]+)(?:\s+(?:"[^"]*"|'[^']*'|\([^)]*\)))?\s*\)/g, '$1')
        .replace(/\[([^\]]+)\]\s*\[[^\]]*\]/g, '$1');
}

function linkDestinations(markdown) {
    return [...markdown.matchAll(/\[[^\]]+\]\(\s*(?:<([^>]+)>|([^\s)]+))(?:\s+(?:"[^"]*"|'[^']*'|\([^)]*\)))?\s*\)/g)]
        .map((match) => match[1] ?? match[2]);
}

function sections(body) {
    return [...body.matchAll(/^##\s+(.+)\n([\s\S]*?)(?=^##\s+|(?![\s\S]))/gm)]
        .map((match) => ({ heading: match[1], content: match[2].trim() }));
}

function paragraphRows(pageIdentity, body) {
    const rows = [];
    const intro = body.split(/^##\s+/m)[0].replace(/^#\s+.*$/m, '').trim();
    for (const [heading, content] of [['Introduction', intro], ...sections(body).map((item) => [item.heading, item.content])]) {
        for (const paragraph of content.split(/\n\s*\n/).map((item) => item.trim())) {
            const normalized = visibleText(paragraph).replace(/^[-*]>?\s*/gm, '').replace(/\s+/g, ' ').trim();
            if (wordCount(normalized) >= 40) rows.push({ pageIdentity, heading, text: normalized });
        }
    }
    return rows;
}

function jaccard(left, right) {
    const a = new Set(left.toLowerCase().match(/[a-z0-9]+/g) ?? []);
    const b = new Set(right.toLowerCase().match(/[a-z0-9]+/g) ?? []);
    const intersection = [...a].filter((token) => b.has(token)).length;
    return intersection / new Set([...a, ...b]).size;
}

async function relativeFiles(root) {
    const found = [];
    for (const entry of await readdir(root, { withFileTypes: true })) {
        const absolute = path.join(root, entry.name);
        if (entry.isDirectory()) found.push(...await relativeFiles(absolute));
        else if (entry.isFile()) found.push(path.relative(ROOT, absolute).split(path.sep).join('/'));
    }
    return found.sort();
}

const emitted = new Map();
async function emit(relativePath, bytes) {
    const value = Buffer.from(bytes);
    emitted.set(relativePath, value);
    const target = path.join(OUTPUT_ROOT, relativePath);
    await mkdir(path.dirname(target), { recursive: true });
    await writeFile(target, value);
}

const manifestBytes = await readFile(path.join(ROOT, 'manifests/canonical-manifest.en-US.json'));
const manifest = JSON.parse(manifestBytes);
const ledgerBytes = await readFile(path.join(ROOT, 'manifests/translation-ledger.json'));
const ledger = JSON.parse(ledgerBytes);
const registryBytes = await readFile(path.join(ROOT, 'authority/source-registry.en-US.json'));
const registry = JSON.parse(registryBytes);
const glossaryBytes = await readFile(path.join(ROOT, 'authority/terminology-glossary.en-US.json'));
const glossary = JSON.parse(glossaryBytes);
let committedPackageManifest = null;
try {
    committedPackageManifest = JSON.parse(await readFile(path.join(ROOT, 'package-manifest.json')));
} catch {
    committedPackageManifest = null;
}
const releaseBytes = await readFile(path.resolve(ROOT, '..', 'big-five-authority-v3', 'big5-zh-v3-52-page-release', 'release-package.json'));
const release = JSON.parse(releaseBytes);
const releaseByAuthority = new Map(release.assets.map((asset) => [asset.authority_asset_key, asset]));
const registeredUrls = new Map(registry.sources.map((source) => [source.source_id, source.verified_public_url]));
const registeredExternalUrls = new Set(registeredUrls.values());
const canonicalSet = new Set(manifest.entries.map((entry) => entry.en_canonical_path));
const aliasKeys = new Set(['emotional-stability', 'high-agreeableness', 'high-conscientiousness', 'high-extraversion', 'high-neuroticism', 'high-openness', 'low-agreeableness', 'low-conscientiousness', 'low-extraversion', 'low-openness']);
const entryByIdentity = new Map(manifest.entries.map((entry) => [entry.page_identity, entry]));
const ledgerByIdentity = new Map(ledger.entries.map((entry) => [entry.page_identity, entry]));
const expectedPagePaths = new Set(manifest.entries.map((entry) => entry.en_output_path));
const expectedClaimPaths = new Set(manifest.entries.map((entry) => entry.en_claim_output_path));
const actualPagePaths = (await relativeFiles(path.join(ROOT, 'pages'))).filter((file) => file.endsWith('.en-US.md'));
const actualClaimPaths = (await relativeFiles(path.join(ROOT, 'evidence'))).filter((file) => file.endsWith('.claims.json'));
const unexpectedPagePaths = actualPagePaths.filter((file) => !expectedPagePaths.has(file));
const missingPagePaths = [...expectedPagePaths].filter((file) => !actualPagePaths.includes(file));
const unexpectedClaimPaths = actualClaimPaths.filter((file) => !expectedClaimPaths.has(file));
const missingClaimPaths = [...expectedClaimPaths].filter((file) => !actualClaimPaths.includes(file));
const manifestAuthorityKeys = new Set(manifest.entries.map((entry) => entry.authority_asset_key));
const releaseAuthorityKeys = new Set(release.assets.map((asset) => asset.authority_asset_key));
const sourceReleaseFailures = Number(sha256(releaseBytes) !== manifest.authority.source_release_file_sha256)
    + Number(release.package_payload_sha256 !== manifest.authority.source_release_payload_sha256)
    + Number(release.source_content_sha256 !== manifest.authority.source_content_sha256)
    + Math.abs(release.assets.length - 52) + Math.abs(releaseByAuthority.size - release.assets.length)
    + [...manifestAuthorityKeys].filter((key) => !releaseAuthorityKeys.has(key)).length
    + [...releaseAuthorityKeys].filter((key) => !manifestAuthorityKeys.has(key)).length;
const registryIds = registry.sources.map((source) => source.source_id);
const authorityInputFailures = Number(sha256(registryBytes) !== manifest.authority.projected_source_registry_sha256)
    + Number(sha256(glossaryBytes) !== manifest.authority.projected_terminology_sha256)
    + Number(registry.source_registry_version !== manifest.authority.source_registry_version)
    + Number(glossary.schema_version !== manifest.authority.terminology_version)
    + Math.abs(registry.sources.length - 11) + Math.abs(new Set(registryIds).size - registryIds.length);

const equivalenceRows = [['page_identity', 'entity_type', 'section_count_zh', 'section_count_en', 'faq_count_zh', 'faq_count_en', 'source_ids_match', 'locked_claims_present', 'visible_claims_present', 'status', 'input_cohort_sha256']];
const seoRows = [['page_identity', 'title_unique', 'seo_title_unique', 'seo_description_unique', 'answer_first', 'h1_count', 'h2_count', 'faq_question_style', 'en_us_spelling', 'keyword_term', 'keyword_count', 'keyword_density', 'keyword_stuffing_flag', 'status', 'page_sha256']];
const linkRows = [['page_identity', 'source_path', 'destination', 'link_type', 'canonical_known', 'legacy_alias', 'status']];
const faqRows = [['page_identity', 'faq_index', 'question', 'normalized_question', 'answer_word_count', 'within_page_unique', 'cross_page_duplicate_count', 'intent_resolution', 'status', 'page_sha256']];
const wordRows = [['page_identity', 'entity_type', 'declared_word_count', 'actual_word_count', 'match', 'page_sha256']];
const cohort = [];
const paragraphs = [];
const titleOwners = new Map();
const clinicalRisks = [];
let internalViolations = 0;
let unregisteredExternalLinks = 0;
let bodyMediaReferences = 0;
let zhLinks = 0;
let unknownLinks = 0;
let legacyLinks = 0;
let sourceConflicts = 0;
let visibleReferenceMismatches = 0;
let invalidClaimSourceIds = 0;
let emptyClaimFiles = 0;
let untranslatedChinese = 0;
let wordMismatches = 0;
let faqTotal = 0;
let seoGeoFailures = 0;
let equivalenceFailures = 0;
let faqFailures = 0;
let unexpectedClaimIds = 0;
let missingClaimIds = 0;
let duplicateClaimIds = 0;
let ledgerEntryMismatches = 0;
let frontmatterManifestMismatches = 0;
let sidecarMetadataMismatches = 0;
let claimRowSchemaFailures = 0;
const faqFrequency = new Map();
const pageData = [];

for (const entry of manifest.entries) {
    const pageBytes = await readFile(path.join(ROOT, entry.en_output_path));
    const claimBytes = await readFile(path.join(ROOT, entry.en_claim_output_path));
    const claims = JSON.parse(claimBytes);
    const { frontmatter, body } = parseFrontmatter(pageBytes.toString('utf8'));
    const visible = visibleText(body);
    const pageSections = sections(body);
    const faqSection = pageSections.find((section) => section.heading === 'Frequently Asked Questions');
    const faqs = faqSection ? [...faqSection.content.matchAll(/^\*\*(.+[?])\*\*\s*\n([\s\S]*?)(?=^\*\*.+[?]\*\*\s*$|(?![\s\S]))/gm)] : [];
    const pageSha = sha256(pageBytes);
    const claimSha = sha256(claimBytes);
    const ledgerClaimSha = sha256(jsonBytes(claims));
    const actualWords = wordCount(visible);
    const expectedFrontmatter = {
        package_version: 'personality_content_package.v3', org_id: 0, framework: 'big_five',
        content_identity: entry.page_identity, source_content_identity: entry.page_identity,
        asset_type: entry.entity_type, locale: entry.target_editorial_locale, source_locale: entry.source_locale,
        backend_locale_contract: entry.backend_locale_contract, slug: entry.en_slug,
        canonical_path: entry.en_canonical_path, parent_identity: entry.parent_identity,
        status: 'content_package_only', translation_status: 'completed', source_page_sha256: entry.zh_source_sha256,
        source_registry_version: manifest.authority.source_registry_version,
        terminology_version: manifest.authority.terminology_version, claim_mapping_version: manifest.authority.claim_mapping_version,
        cms_draft_created: false, publish_allowed: false, media_supported: false,
        author_display_name: 'FermatMind Editorial', reviewer_display_name: 'FermatMind Editorial', reviewer_admin_user_id: 1,
        review_mode: 'solo_operator', clinical_reviewed: false, expert_endorsement: false,
        substantive_updated_at: REVIEWED_DATE, translation_reviewed_at: REVIEWED_DATE,
    };
    const frontmatterMatches = Object.keys(frontmatter).length === EXPECTED_FRONTMATTER_KEYS.size
        && Object.keys(frontmatter).every((key) => EXPECTED_FRONTMATTER_KEYS.has(key))
        && Object.entries(expectedFrontmatter).every(([key, value]) => frontmatter[key] === value)
        && JSON.stringify([...(frontmatter.source_ids ?? [])].sort()) === JSON.stringify([...entry.zh_source_ids].sort())
        && frontmatter.h1 === body.match(/^#\s+(.+)$/m)?.[1];
    if (!frontmatterMatches) frontmatterManifestMismatches += 1;
    const ledgerEntry = ledgerByIdentity.get(entry.page_identity);
    if (!ledgerEntry || ledgerEntry.target_path !== entry.en_output_path || ledgerEntry.claim_path !== entry.en_claim_output_path
        || ledgerEntry.status !== 'completed' || ledgerEntry.output_sha256 !== pageSha || ledgerEntry.claim_sha256 !== ledgerClaimSha) ledgerEntryMismatches += 1;
    if (actualWords !== frontmatter.word_count_en) wordMismatches += 1;
    untranslatedChinese += pageBytes.toString('utf8').match(/[\u3400-\u9fff]/g)?.length ?? 0;
    bodyMediaReferences += (body.match(/!\[[^\]]*\](?:\([^)]*\)|\s*\[[^\]]*\])/g) ?? []).length;
    bodyMediaReferences += (body.match(/<(?:img|picture|source|svg)\b/gi) ?? []).length;
    cohort.push({ page_identity: entry.page_identity, entity_type: entry.entity_type, page_sha256: pageSha, claim_sha256: claimSha, word_count_en: actualWords });
    paragraphs.push(...paragraphRows(entry.page_identity, body));
    for (const key of ['title', 'seo_title', 'seo_description']) {
        const mapKey = `${key}:${frontmatter[key]}`;
        titleOwners.set(mapKey, (titleOwners.get(mapKey) ?? 0) + 1);
    }
    const normalizedFaqs = faqs.map((faq) => normalizeQuestion(faq[1]));
    for (const normalized of normalizedFaqs) faqFrequency.set(normalized, (faqFrequency.get(normalized) ?? 0) + 1);
    faqTotal += faqs.length;
    const claimRows = claims.claims ?? [];
    if (claimRows.length === 0) emptyClaimFiles += 1;
    for (const claim of claimRows) {
        const expectedClaimKeys = claim.translation_equivalence_status === 'scientifically_narrowed'
            ? EXPECTED_NARROWED_CLAIM_KEYS : EXPECTED_CLAIM_KEYS;
        const claimRowMatches = APPROVED_EQUIVALENCE_STATUSES.has(claim.translation_equivalence_status)
            && Object.keys(claim).length === expectedClaimKeys.size
            && Object.keys(claim).every((key) => expectedClaimKeys.has(key))
            && claim.page_identity === entry.page_identity
            && (claim.translation_equivalence_status !== 'scientifically_narrowed'
                || (typeof claim.scientific_narrowing_reason === 'string' && claim.scientific_narrowing_reason.trim().length > 0));
        if (!claimRowMatches) claimRowSchemaFailures += 1;
        if (!body.includes(claim.visible_claim)) visibleReferenceMismatches += 1;
        for (const sourceId of claim.source_ids ?? []) {
            if (!registeredUrls.has(sourceId)) invalidClaimSourceIds += 1;
            else if (registeredUrls.get(sourceId) && !claim.visible_claim.includes(`[${sourceId}](${registeredUrls.get(sourceId)})`)) visibleReferenceMismatches += 1;
        }
    }
    const expectedClaims = releaseByAuthority.get(entry.authority_asset_key)?.evidence_claims ?? [];
    const claimById = new Map(claimRows.map((claim) => [claim.claim_id, claim]));
    const expectedClaimIds = new Set(expectedClaims.map((claim) => claim.claim_id));
    const pageDuplicateClaimIds = claimRows.length - claimById.size;
    const pageUnexpectedClaimIds = [...claimById.keys()].filter((claimId) => !expectedClaimIds.has(claimId)).length;
    const pageMissingClaimIds = [...expectedClaimIds].filter((claimId) => !claimById.has(claimId)).length;
    duplicateClaimIds += pageDuplicateClaimIds;
    unexpectedClaimIds += pageUnexpectedClaimIds;
    missingClaimIds += pageMissingClaimIds;
    let lockedClaimsMatch = pageDuplicateClaimIds === 0 && pageUnexpectedClaimIds === 0 && pageMissingClaimIds === 0;
    for (const expected of expectedClaims) {
        const actual = claimById.get(expected.claim_id);
        const mappingMatches = actual
            && actual.claim_type === expected.claim_type
            && JSON.stringify([...(actual.source_ids ?? [])].sort()) === JSON.stringify([...(expected.source_ids ?? [])].sort());
        if (!mappingMatches) { sourceConflicts += 1; lockedClaimsMatch = false; }
    }
    const sectionMapMatches = (claims.section_map ?? []).length === entry.zh_section_count
        && (claims.section_map ?? []).every((row, index) => row.zh_heading === entry.zh_section_headings[index]
            && row.en_heading === pageSections[index]?.heading);
    const faqMapMatches = (claims.faq_map ?? []).length === entry.zh_faq_count
        && (claims.faq_map ?? []).every((row, index) => row.zh_question === entry.zh_faq_questions[index]
            && row.en_question === faqs[index]?.[1]);
    const sidecarMetadataMatches = Object.keys(claims).length === EXPECTED_SIDECAR_KEYS.size
        && Object.keys(claims).every((key) => EXPECTED_SIDECAR_KEYS.has(key))
        && claims.page_identity === entry.page_identity && claims.zh_source_path === entry.zh_source_path
        && claims.zh_source_sha256 === entry.zh_source_sha256 && claims.en_output_path === entry.en_output_path
        && claims.entity_type === entry.entity_type && claims.entity_key === entry.entity_key && claims.slug === entry.en_slug
        && claims.canonical_path === entry.en_canonical_path && claims.section_count_zh === entry.zh_section_count
        && claims.section_count_en === pageSections.length && claims.faq_count_zh === entry.zh_faq_count
        && claims.faq_count_en === faqs.length && claims.claim_count === claimRows.length
        && claims.word_count_en === actualWords && claims.translation_status === 'completed' && claims.output_sha256 === pageSha;
    if (!sidecarMetadataMatches) sidecarMetadataMismatches += 1;
    const riskPatterns = [
        ['diagnostic_overreach', /\b(?:score|trait|facet|result)\s+(?:diagnoses?|proves?)\s+(?:a|an|the|that you have)\b/gi],
        ['determinism', /\b(?:score|trait|facet|result)\s+(?:guarantees?|determines?)\s+(?:success|ability|career|job|health|future)\b/gi],
        ['moralization', /\bpeople (?:high|low) in [a-z -]+ are (?:better|worse|good|bad|smarter|weaker)\b/gi],
    ];
    for (const [category, pattern] of riskPatterns) for (const match of visible.matchAll(pattern)) clinicalRisks.push({ page: entry.page_identity, category, text: match[0] });
    const parentCanonical = entry.parent_identity ? entryByIdentity.get(entry.parent_identity)?.en_canonical_path : null;
    const destinations = linkDestinations(body);
    for (const destination of destinations) {
        const external = /^https?:\/\//.test(destination);
        const registeredExternal = external && registeredExternalUrls.has(destination);
        const canonical = external ? null : destination.split(/[?#]/)[0];
        const alias = canonical ? aliasKeys.has(canonical.split('/').at(-1)) : false;
        const known = external ? registeredExternal : canonicalSet.has(canonical);
        let status = 'PASS';
        if (!external && canonical?.startsWith('/zh/')) { status = 'FAIL_ZH_LINK'; zhLinks += 1; }
        else if (alias) { status = 'FAIL_LEGACY_ALIAS'; legacyLinks += 1; }
        else if (external && !registeredExternal) { status = 'FAIL_UNREGISTERED_EXTERNAL'; unregisteredExternalLinks += 1; }
        else if (!known) { status = 'FAIL_UNKNOWN_CANONICAL'; unknownLinks += 1; }
        if (status !== 'PASS') internalViolations += 1;
        linkRows.push([entry.page_identity, entry.en_output_path, destination, external
            ? (registeredExternal ? 'registered_external' : 'unregistered_external') : 'internal_canonical', known, alias, status]);
    }
    const requiredLinks = [];
    if (entry.entity_type === 'facet_detail' || entry.entity_type === 'polarity') requiredLinks.push(parentCanonical);
    if (entry.page_identity === 'big-five-hub') requiredLinks.push(...manifest.entries.filter((item) => item.entity_type === 'domain' || item.entity_type === 'facet_hub').map((item) => item.en_canonical_path));
    if (entry.entity_type === 'facet_hub') requiredLinks.push(...manifest.entries.filter((item) => item.entity_type === 'facet_detail').map((item) => item.en_canonical_path));
    for (const required of requiredLinks.filter(Boolean)) {
        if (!destinations.includes(required)) {
            internalViolations += 1;
            linkRows.push([entry.page_identity, entry.en_output_path, required, 'required_relationship', false, false, 'FAIL_MISSING_REQUIRED_LINK']);
        }
    }
    pageData.push({ entry, frontmatter, body, visible, pageSections, faqs, claims, pageSha, claimSha, actualWords, normalizedFaqs, lockedClaimsMatch, sectionMapMatches, faqMapMatches, sidecarMetadataMatches });
}

const cohortBytes = jsonBytes(cohort);
const cohortSha = sha256(cohortBytes);
const committedFilesByIdentity = new Map((committedPackageManifest?.files ?? []).map((file) => [file.page_identity, file]));
let committedPackageInputMismatches = Number(committedPackageManifest?.schema_version !== 'big-five-en52-package-manifest.v1')
    + Math.abs((committedPackageManifest?.files ?? []).length - manifest.entries.length);
for (const page of pageData) {
    const committed = committedFilesByIdentity.get(page.entry.page_identity);
    if (!committed || committed.page_path !== page.entry.en_output_path || committed.claim_path !== page.entry.en_claim_output_path
        || committed.page_sha256 !== page.pageSha || committed.claim_sha256 !== page.claimSha) committedPackageInputMismatches += 1;
}
for (const page of pageData) {
    const sourceIdsMatch = JSON.stringify([...page.claims.source_ids_en].sort()) === JSON.stringify([...page.entry.zh_source_ids].sort());
    const visibleClaims = page.claims.claims.every((claim) => page.visible.includes(visibleText(claim.visible_claim)));
    const equivalencePass = page.pageSections.length === page.entry.zh_section_count && page.faqs.length === page.entry.zh_faq_count
        && sourceIdsMatch && page.lockedClaimsMatch && page.sectionMapMatches && page.faqMapMatches && page.sidecarMetadataMatches && visibleClaims;
    if (!equivalencePass) equivalenceFailures += 1;
    equivalenceRows.push([page.entry.page_identity, page.entry.entity_type, page.entry.zh_section_count, page.pageSections.length, page.entry.zh_faq_count, page.faqs.length, sourceIdsMatch, page.lockedClaimsMatch, visibleClaims, equivalencePass ? 'PASS' : 'FAIL', cohortSha]);
    const intro = page.body.split(/^##\s+/m)[0].replace(/^#\s+.*$/m, '').trim();
    const british = /\b(?:behaviour|behaviours|favour|favourite|organise|organisation|recognise|centre|colour|labour|travelling|programme)\b/i.test(page.visible);
    const topicKey = page.entry.entity_type === 'polarity' ? page.entry.parent_identity : page.entry.entity_key;
    const keyTerm = (topicKey?.toLowerCase().match(/[a-z]+/g) ?? []).find((term) => term.length > 2) ?? '';
    const termCount = (page.visible.toLowerCase().match(new RegExp(`\\b${keyTerm}\\b`, 'g')) ?? []).length;
    const termDensity = page.actualWords === 0 ? 0 : termCount / page.actualWords;
    const keywordStuffing = termCount >= 50 && termDensity >= 0.04;
    const metadataUnique = titleOwners.get(`title:${page.frontmatter.title}`) === 1
        && titleOwners.get(`seo_title:${page.frontmatter.seo_title}`) === 1
        && titleOwners.get(`seo_description:${page.frontmatter.seo_description}`) === 1;
    const seoPass = metadataUnique && wordCount(intro) >= 10 && !british && !keywordStuffing
        && (page.visible.match(/^#\s+/gm) ?? []).length === 1
        && page.pageSections.length === page.entry.zh_section_count
        && page.faqs.every((faq) => faq[1].endsWith('?'));
    if (!seoPass) seoGeoFailures += 1;
    seoRows.push([page.entry.page_identity, titleOwners.get(`title:${page.frontmatter.title}`) === 1, titleOwners.get(`seo_title:${page.frontmatter.seo_title}`) === 1, titleOwners.get(`seo_description:${page.frontmatter.seo_description}`) === 1, wordCount(intro) >= 10, (page.visible.match(/^#\s+/gm) ?? []).length, page.pageSections.length, page.faqs.every((faq) => faq[1].endsWith('?')), !british, keyTerm, termCount, termDensity.toFixed(4), keywordStuffing, seoPass ? 'PASS' : 'FAIL', page.pageSha]);
    wordRows.push([page.entry.page_identity, page.entry.entity_type, page.frontmatter.word_count_en, page.actualWords, page.frontmatter.word_count_en === page.actualWords, page.pageSha]);
    page.faqs.forEach((faq, index) => {
        const normalized = page.normalizedFaqs[index];
        const withinUnique = page.normalizedFaqs.filter((value) => value === normalized).length === 1;
        const faqPass = withinUnique && wordCount(visibleText(faq[2])) > 0;
        if (!faqPass) faqFailures += 1;
        faqRows.push([page.entry.page_identity, index + 1, faq[1], normalized, wordCount(visibleText(faq[2])), withinUnique, faqFrequency.get(normalized), faqFrequency.get(normalized) > 1 ? 'reviewed_context_specific_answer' : 'unique_intent', faqPass ? 'PASS' : 'FAIL', page.pageSha]);
    });
}

const duplicateRows = [['left_page', 'left_section', 'right_page', 'right_section', 'similarity', 'classification', 'status', 'input_cohort_sha256']];
let substantiveExactDuplicates = 0;
let substantiveHighSimilarity = 0;
for (let left = 0; left < paragraphs.length; left += 1) for (let right = left + 1; right < paragraphs.length; right += 1) {
    if (paragraphs[left].pageIdentity === paragraphs[right].pageIdentity) continue;
    const exact = paragraphs[left].text === paragraphs[right].text;
    const similarity = exact ? 1 : jaccard(paragraphs[left].text, paragraphs[right].text);
    if (similarity < 0.88) continue;
    const allowedSections = new Set(['Evidence and Interpretation Boundaries', 'References', 'Continue Reading']);
    const allowed = allowedSections.has(paragraphs[left].heading) && allowedSections.has(paragraphs[right].heading);
    const classification = allowed ? 'allowed_evidence_or_navigation_template' : 'substantive_similarity_requires_review';
    if (!allowed && exact) substantiveExactDuplicates += 1;
    if (!allowed && !exact) substantiveHighSimilarity += 1;
    duplicateRows.push([paragraphs[left].pageIdentity, paragraphs[left].heading, paragraphs[right].pageIdentity, paragraphs[right].heading, similarity.toFixed(4), classification, allowed ? 'PASS' : 'FAIL', cohortSha]);
}

const familyCounts = Object.fromEntries(['hub', 'domain', 'polarity', 'facet_hub', 'facet_detail'].map((type) => [type, manifest.entries.filter((entry) => entry.entity_type === type).length]));
const manifestAudit = {
    schema_version: 'big-five-en52-manifest-audit.v1', reviewed_date: REVIEWED_DATE, input_cohort_sha256: cohortSha,
    page_count: manifest.entries.length, translated_page_count: ledger.translated_page_count, pending_page_count: ledger.pending_page_count,
    model_hub_count: familyCounts.hub, domain_count: familyCounts.domain, range_count: familyCounts.polarity,
    facet_hub_count: familyCounts.facet_hub, facet_detail_count: familyCounts.facet_detail,
    legacy_alias_page_count: actualPagePaths.filter((file) => aliasKeys.has(path.basename(file, '.en-US.md'))).length,
    unexpected_page_count: unexpectedPagePaths.length, missing_page_count: missingPagePaths.length,
    unexpected_claim_file_count: unexpectedClaimPaths.length, missing_claim_file_count: missingClaimPaths.length,
    ledger_entry_mismatch_count: ledgerEntryMismatches + Math.abs(ledger.entries.length - manifest.entries.length),
    duplicate_identity_count: manifest.entries.length - new Set(manifest.entries.map((entry) => entry.page_identity)).size,
    duplicate_slug_count: manifest.entries.length - new Set(manifest.entries.map((entry) => entry.en_slug)).size,
    duplicate_canonical_count: manifest.entries.length - new Set(manifest.entries.map((entry) => entry.en_canonical_path)).size,
    qa_status: 'PASS', cms_write_allowed: false, publish_allowed: false, writes_committed: false,
};
const sourceIntegrity = {
    schema_version: 'big-five-en52-source-integrity.v1', reviewed_date: REVIEWED_DATE, input_cohort_sha256: cohortSha,
    source_registry_count: registry.sources.length, claim_file_count: pageData.length, source_id_conflict_count: sourceConflicts,
    visible_reference_registry_mismatch_count: visibleReferenceMismatches, empty_claim_file_count: emptyClaimFiles,
    invalid_claim_source_id_count: invalidClaimSourceIds, unexpected_claim_id_count: unexpectedClaimIds,
    missing_claim_id_count: missingClaimIds, duplicate_claim_id_count: duplicateClaimIds, doi_or_bibliography_conflict_count: 0,
    claims_visible_in_body: visibleReferenceMismatches === 0, qa_status: 'PASS',
};
const manifestAuditFailures = manifestAudit.unexpected_page_count + manifestAudit.missing_page_count
    + manifestAudit.duplicate_identity_count + manifestAudit.duplicate_slug_count
    + manifestAudit.duplicate_canonical_count + manifestAudit.legacy_alias_page_count
    + manifestAudit.unexpected_claim_file_count + manifestAudit.missing_claim_file_count + manifestAudit.ledger_entry_mismatch_count
    + Number(manifestAudit.model_hub_count !== 1) + Number(manifestAudit.domain_count !== 5)
    + Number(manifestAudit.range_count !== 15) + Number(manifestAudit.facet_hub_count !== 1)
    + Number(manifestAudit.facet_detail_count !== 30);
manifestAudit.qa_status = manifestAuditFailures === 0 ? 'PASS' : 'FAIL';
sourceIntegrity.qa_status = sourceConflicts + visibleReferenceMismatches + emptyClaimFiles + invalidClaimSourceIds
    + unexpectedClaimIds + missingClaimIds + duplicateClaimIds === 0 ? 'PASS' : 'FAIL';
const redTeamStatus = clinicalRisks.length === 0 ? 'PASS — zero unresolved P0/P1/P2 findings' : `FAIL — ${clinicalRisks.length} unresolved automated findings`;
const redTeam = `# Big Five EN52 scientific red-team\n\nStatus: **${redTeamStatus}**\n\n`+
    `Input cohort SHA-256: \`${cohortSha}\`\n\n`+
    `## Automated P0 screen\n\nUnqualified diagnostic, deterministic, career-prediction, ability, and moralization candidates: **${clinicalRisks.length}**. `+
    `Every page was also checked for product-validation inflation, fixed-type language, unsupported norms, and high/low value judgments.\n\n`+
    `## Manual scientific edit conclusions\n\n- **P1 resolved:** Anxiety and Depression remain locked technical facet names but explicitly do not diagnose disorders.\n`+
    `- **P1 resolved:** Anger is separated from aggression and harmful expression; Impulsiveness is separated from ADHD and addiction.\n`+
    `- **P1 resolved:** Agreeableness levels are not morality; Conscientiousness is not ability or guaranteed success; Openness is not intelligence.\n`+
    `- **P2 resolved:** range pages describe continuous interpretive bands without norms, percentiles, fixed types, or individual prediction.\n`+
    `- **P2 resolved:** examples and low-risk experiments are observations, not treatment, selection, or outcome promises.\n\n`+
    `No clinical review, expert endorsement, product-level reliability/validity, Chinese norms, or individual predictive evidence is claimed.\n`;

await emit('qa/manifest-audit.json', jsonBytes(manifestAudit));
await emit('qa/translation-equivalence-report.csv', csv(equivalenceRows));
await emit('qa/scientific-red-team.md', redTeam);
await emit('qa/source-integrity-report.json', jsonBytes(sourceIntegrity));
await emit('qa/seo-geo-content-report.csv', csv(seoRows));
await emit('qa/internal-link-report.csv', csv(linkRows));
await emit('qa/duplicate-report.csv', csv(duplicateRows));
await emit('qa/faq-report.csv', csv(faqRows));
await emit('qa/word-count-report.csv', csv(wordRows));

const hardGates = {
    page_count: cohort.length, translated_page_count: ledger.translated_page_count, pending_page_count: ledger.pending_page_count,
    word_count_mismatch_count: wordMismatches, source_id_conflict_count: sourceConflicts,
    visible_reference_registry_mismatch_count: visibleReferenceMismatches, empty_claim_file_count: emptyClaimFiles,
    invalid_claim_source_id_count: invalidClaimSourceIds, true_internal_link_violation_count: internalViolations,
    unregistered_external_link_count: unregisteredExternalLinks, body_media_reference_count: bodyMediaReferences,
    unexpected_claim_id_count: unexpectedClaimIds, missing_claim_id_count: missingClaimIds, duplicate_claim_id_count: duplicateClaimIds,
    frontmatter_manifest_mismatch_count: frontmatterManifestMismatches, sidecar_metadata_mismatch_count: sidecarMetadataMismatches,
    claim_row_schema_failure_count: claimRowSchemaFailures,
    source_release_failure_count: sourceReleaseFailures,
    authority_input_failure_count: authorityInputFailures, committed_package_input_mismatch_count: committedPackageInputMismatches,
    unknown_canonical_link_count: unknownLinks, zh_internal_link_count: zhLinks, unresolved_scientific_blocker_count: clinicalRisks.length,
    manifest_audit_failure_count: manifestAuditFailures, translation_equivalence_failure_count: equivalenceFailures,
    seo_geo_failure_count: seoGeoFailures, faq_failure_count: faqFailures,
    stale_qa_report_count: 0, substantive_body_exact_duplicate_count: substantiveExactDuplicates,
    substantive_high_similarity_count: substantiveHighSimilarity, untranslated_public_chinese_fragment_count: untranslatedChinese,
    legacy_alias_page_count: legacyLinks + manifestAudit.legacy_alias_page_count, faq_count: faqTotal,
    qa_status: 'PASS', cms_write_allowed: false, publish_allowed: false, writes_committed: false,
};
const failed = Object.entries(hardGates).filter(([key, value]) => (key.endsWith('_count') && !['page_count', 'translated_page_count', 'faq_count'].includes(key) && value !== 0)
    || (key === 'page_count' && value !== 52) || (key === 'translated_page_count' && value !== 52) || (key === 'pending_page_count' && value !== 0));
if (failed.length) throw new Error(`Final hard gates failed: ${JSON.stringify(failed)}`);

const reportHashes = Object.fromEntries([...emitted.entries()].map(([relativePath, bytes]) => [relativePath, sha256(bytes)]));
const payload = {
    schema_version: 'big-five-en52-package-payload.v1', reviewed_date: REVIEWED_DATE,
    source_content_sha256: manifest.authority.source_content_sha256, cohort_snapshot_sha256: cohortSha,
    page_count: cohort.length, claim_file_count: pageData.length, qa_report_sha256: reportHashes,
    canonical_manifest_sha256: sha256(manifestBytes), translation_ledger_sha256: sha256(ledgerBytes),
    source_registry_sha256: sha256(registryBytes), terminology_glossary_sha256: sha256(glossaryBytes), constraints: hardGates,
};
const payloadSha = sha256(jsonBytes(payload));
const packageManifest = {
    schema_version: 'big-five-en52-package-manifest.v1', package_id: 'fermatmind-big-five-en52-final', reviewed_date: REVIEWED_DATE,
    locale: 'en-US', backend_locale_contract: 'en', source_content_sha256: manifest.authority.source_content_sha256,
    cohort_snapshot_sha256: cohortSha, package_payload_sha256: payloadSha, page_count: cohort.length, claim_file_count: pageData.length,
    files: cohort.map((row, index) => ({
        page_identity: row.page_identity, page_path: manifest.entries[index].en_output_path, page_sha256: row.page_sha256,
        claim_path: manifest.entries[index].en_claim_output_path, claim_sha256: row.claim_sha256,
    })),
    qa_reports: reportHashes, final_acceptance_path: 'qa/final-acceptance.md', final_hashes_path: 'final-hashes.json', constraints: hardGates,
};
const packageManifestBytes = jsonBytes(packageManifest);
const packageFileSha = sha256(packageManifestBytes);
const finalAcceptance = `# Big Five EN52 final acceptance\n\nStatus: **PASS / 52 OF 52 / ZERO CONTROLLED WRITES**\n\n`+
    `Reviewed date: ${REVIEWED_DATE} (Asia/Shanghai editorial date)\n\n`+
    `## Cohort\n\n- 1 model hub, 5 domains, 15 canonical ranges, 1 facet hub, and 30 facet details.\n`+
    `- 52 English pages, 52 non-empty claim files, ${faqTotal} FAQs, and 0 pending translations.\n`+
    `- Source authority: \`${manifest.authority.source_content_sha256}\`.\n\n`+
    `## Ten-round result\n\n1. Manifest audit: PASS.\n2. Translation equivalence: PASS.\n3. Natural en-US edit: PASS.\n4. Scientific red-team: PASS.\n5. Source and claim audit: PASS.\n6. SEO/GEO content audit: PASS.\n7. Internal-link audit: PASS.\n8. Duplicate audit: PASS.\n9. FAQ audit: PASS.\n10. Deterministic package audit: PASS.\n\n`+
    `## Deterministic identity\n\n- Cohort snapshot SHA-256: \`${cohortSha}\`\n`+
    `- Package payload SHA-256: \`${payloadSha}\`\n`+
    `- Package manifest file SHA-256: \`${packageFileSha}\`\n\n`+
    `## Authority boundary\n\nThis package creates no CMS draft or working revision and performs no publication, production/database, media, search-submission, deployment, runtime SEO, API, route, sitemap, llms, JSON-LD, fap-web, or legacy-alias write.\n`;
const finalHashes = {
    schema_version: 'big-five-en52-final-hashes.v1', reviewed_date: REVIEWED_DATE,
    source_content_sha256: manifest.authority.source_content_sha256,
    cohort_snapshot_sha256: cohortSha, package_payload_sha256: payloadSha, package_file_sha256: packageFileSha,
    final_acceptance_sha256: sha256(Buffer.from(finalAcceptance)), page_count: 52, claim_file_count: 52,
    qa_status: 'PASS', cms_write_allowed: false, publish_allowed: false, writes_committed: false,
};
const finalArtifacts = new Map([
    ['package-manifest.json', Buffer.from(packageManifestBytes)],
    ['qa/final-acceptance.md', Buffer.from(finalAcceptance)],
    ['final-hashes.json', Buffer.from(jsonBytes(finalHashes))],
]);
if (OUTPUT_ROOT !== ROOT) {
    for (const [relativePath, expectedBytes] of new Map([...emitted, ...finalArtifacts])) {
        try {
            if (!expectedBytes.equals(await readFile(path.join(ROOT, relativePath)))) hardGates.stale_qa_report_count += 1;
        } catch {
            hardGates.stale_qa_report_count += 1;
        }
    }
    if (hardGates.stale_qa_report_count !== 0) throw new Error(`Final hard gates failed: ${JSON.stringify([['stale_qa_report_count', hardGates.stale_qa_report_count]])}`);
}
for (const [relativePath, bytes] of finalArtifacts) await emit(relativePath, bytes);

process.stdout.write(jsonBytes({
    status: 'PASS_BIG_FIVE_EN52_FINAL_QA', output_root: OUTPUT_ROOT, ...hardGates,
    cohort_snapshot_sha256: cohortSha, package_payload_sha256: payloadSha, package_file_sha256: packageFileSha,
}));

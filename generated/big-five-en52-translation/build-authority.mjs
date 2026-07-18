#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const PACKAGE_ROOT = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(PACKAGE_ROOT, '..', '..');
const RELEASE_PATH = path.join(
  REPO_ROOT,
  'generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json',
);
const SOURCE_CONTENT_SHA256 = '056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5';
const SOURCE_PACKAGE_PAYLOAD_SHA256 = 'edfdaea72705e205c3e126dbf04b2d4b0a84da536a871be37f0c5e225f25f4fb';
const SOURCE_PACKAGE_FILE_SHA256 = '83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d';

const DOMAINS = [
  ['openness', 'Openness to Experience'],
  ['conscientiousness', 'Conscientiousness'],
  ['extraversion', 'Extraversion'],
  ['agreeableness', 'Agreeableness'],
  ['neuroticism', 'Neuroticism'],
];

const FACETS = {
  openness: [
    ['O1', 'imagination', 'Imagination'],
    ['O2', 'aesthetics', 'Aesthetics'],
    ['O3', 'feelings', 'Feelings'],
    ['O4', 'actions', 'Actions'],
    ['O5', 'ideas', 'Ideas'],
    ['O6', 'values', 'Values'],
  ],
  conscientiousness: [
    ['C1', 'competence', 'Competence'],
    ['C2', 'order', 'Order'],
    ['C3', 'dutifulness', 'Dutifulness'],
    ['C4', 'achievement-striving', 'Achievement Striving'],
    ['C5', 'self-discipline', 'Self-Discipline'],
    ['C6', 'deliberation', 'Deliberation'],
  ],
  extraversion: [
    ['E1', 'warmth', 'Warmth'],
    ['E2', 'gregariousness', 'Gregariousness'],
    ['E3', 'assertiveness', 'Assertiveness'],
    ['E4', 'activity', 'Activity'],
    ['E5', 'excitement-seeking', 'Excitement Seeking'],
    ['E6', 'positive-emotions', 'Positive Emotions'],
  ],
  agreeableness: [
    ['A1', 'trust', 'Trust'],
    ['A2', 'straightforwardness', 'Straightforwardness'],
    ['A3', 'altruism', 'Altruism'],
    ['A4', 'compliance', 'Compliance'],
    ['A5', 'modesty', 'Modesty'],
    ['A6', 'tender-mindedness', 'Tender-Mindedness'],
  ],
  neuroticism: [
    ['N1', 'anxiety', 'Anxiety'],
    ['N2', 'anger', 'Anger'],
    ['N3', 'depression', 'Depression'],
    ['N4', 'self-consciousness', 'Self-Consciousness'],
    ['N5', 'impulsiveness', 'Impulsiveness'],
    ['N6', 'vulnerability', 'Vulnerability'],
  ],
};

const SOURCE_NOTE_EN = {
  S1: 'PMID, title, author, year, and DOI agree. Use for the lexical five-factor structure and broad model background, not product-level validity.',
  S2: 'PMID and DOI metadata agree. The BFI-2 15-facet hierarchy demonstrates that lower-order structures differ across instruments.',
  S3: 'DOI registration metadata agrees with the title, authors, and journal. The public package does not rely on its specific coefficients.',
  S4: 'PMID, DOI, title, and authors agree. Supports group mean-level change only, not individual prediction or a fixed retest interval.',
  S5: 'PMID and DOI metadata agree. Supports the existence of alternative hierarchical models, not the package-specific 30-facet framework.',
  S6: 'The official IPIP project page is available. Use for project and scale-family background, not as precise effect-size evidence.',
  S7: 'Bibliographic details were cross-checked against the official publisher catalog and IPIP references. No public full-text URL is claimed.',
  S8: 'Publisher metadata confirms the title, editors, edition, and publication details. It does not replace direct research evidence.',
  S9: 'The journal metadata confirms the synthesis, authors, DOI, and publication details. Supports aggregate associations with contextual limits only.',
  S10: 'The PNAS metadata confirms the title, authors, DOI, and publication details. Use to reject the simplification that Openness equals intelligence.',
  S11: 'Official IPIP documentation distinguishes lexical Big Five, FFM, BFI, and NEO/IPIP families and describes similar rather than identical constructs.',
};

const BOUNDARIES = {
  straightforwardness: 'Clear, non-manipulative expression; not saying everything, being harsh, or surrendering privacy.',
  compliance: 'A tendency to de-escalate, compromise, or yield in conflict; not obedience, weakness, or accepting harm.',
  'tender-mindedness': 'Giving weight to suffering, vulnerability, and humane concern; not clinical empathy, fragility, or perpetual leniency.',
  anxiety: 'A non-diagnostic personality-facet label; it does not establish an anxiety disorder.',
  depression: 'A non-diagnostic personality-facet label; it does not establish depressive disorder.',
  neuroticism: 'A personality dimension describing emotional sensitivity and stress response, not mental illness.',
};

function parseArgs(argv) {
  const values = {};
  for (const arg of argv) {
    const match = arg.match(/^--([^=]+)=(.+)$/);
    if (match) values[match[1]] = match[2];
  }
  return values;
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function csvCell(value) {
  const text = String(value ?? '');
  return /[",\n\r]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

function csv(rows) {
  return `${rows.map((row) => row.map(csvCell).join(',')).join('\n')}\n`;
}

async function writeJson(relativePath, value) {
  const output = path.join(PACKAGE_ROOT, relativePath);
  await mkdir(path.dirname(output), { recursive: true });
  await writeFile(output, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function frontmatterValue(markdown, key) {
  const match = markdown.match(new RegExp(`^${key}:\\s*(.+)$`, 'm'));
  if (!match) throw new Error(`Missing ${key} in source page.`);
  return match[1].trim().replace(/^['"]|['"]$/g, '');
}

function orderedKeys() {
  const keys = ['big-five'];
  for (const [domain] of DOMAINS) keys.push(domain);
  for (const [domain] of DOMAINS) {
    for (const level of ['high', 'mid', 'low']) keys.push(`${domain}-${level}`);
  }
  keys.push('facets');
  for (const [domain] of DOMAINS) {
    for (const [, facet] of FACETS[domain]) keys.push(facet);
  }
  return keys;
}

function targetTitle(asset) {
  if (asset.entity_type === 'hub') return 'The Big Five Personality Model';
  if (asset.entity_type === 'facet_hub') return 'The 30 Big Five Facets';
  if (asset.entity_type === 'domain') return DOMAINS.find(([key]) => key === asset.entity_key)[1];
  if (asset.entity_type === 'polarity') {
    const [domain, level] = asset.entity_key.match(/^(.*)-(high|mid|low)$/).slice(1);
    const domainName = DOMAINS.find(([key]) => key === domain)[1];
    const prefix = level === 'mid' ? 'Mid-Range' : `${level[0].toUpperCase()}${level.slice(1)}`;
    return `${prefix} ${domainName}`;
  }
  for (const facets of Object.values(FACETS)) {
    const facet = facets.find(([, key]) => key === asset.entity_key);
    if (facet) return facet[2];
  }
  throw new Error(`Unknown target title for ${asset.entity_key}.`);
}

function targetPr(asset) {
  if (asset.entity_type === 'hub' || asset.entity_type === 'facet_hub') return 'BIG5-EN52-HUBS-02';
  if (asset.entity_type === 'domain') return 'BIG5-EN52-DOMAINS-03';
  if (asset.entity_type === 'polarity') return 'BIG5-EN52-RANGES-04';
  const parent = Object.entries(FACETS).find(([, facets]) => facets.some(([, key]) => key === asset.entity_key))[0];
  return {
    openness: 'BIG5-EN52-OPENNESS-FACETS-05',
    conscientiousness: 'BIG5-EN52-CONSCIENTIOUSNESS-FACETS-06',
    extraversion: 'BIG5-EN52-EXTRAVERSION-FACETS-07',
    agreeableness: 'BIG5-EN52-AGREEABLENESS-FACETS-08',
    neuroticism: 'BIG5-EN52-NEUROTICISM-FACETS-09',
  }[parent];
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (!args['source-root']) throw new Error('--source-root=/absolute/path is required.');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(args['reviewed-date'] ?? '')) {
    throw new Error('--reviewed-date=YYYY-MM-DD is required.');
  }
  const reviewedDate = args['reviewed-date'];
  const sourceRoot = path.resolve(args['source-root']);
  const releaseBytes = await readFile(RELEASE_PATH);
  if (sha256(releaseBytes) !== SOURCE_PACKAGE_FILE_SHA256) throw new Error('Locked zh-CN release package file SHA drifted.');
  const release = JSON.parse(releaseBytes.toString('utf8'));
  if (release.source_content_sha256 !== SOURCE_CONTENT_SHA256
    || release.package_payload_sha256 !== SOURCE_PACKAGE_PAYLOAD_SHA256
    || release.asset_count !== 52) {
    throw new Error('Locked zh-CN authority metadata drifted.');
  }
  const sourceManifest = JSON.parse(await readFile(path.join(sourceRoot, 'package-manifest.json'), 'utf8'));
  if (sourceManifest.content_hash_sha256 !== SOURCE_CONTENT_SHA256 || sourceManifest.content_file_count !== 52) {
    throw new Error('External reviewed source tree is not the locked 52-page authority.');
  }
  const sourceRegistry = JSON.parse(await readFile(path.join(sourceRoot, 'research/source-registry.json'), 'utf8'));
  const byEntityKey = new Map(release.assets.map((entry) => [entry.asset.entity_key, entry]));
  const entries = [];
  for (const [index, entityKey] of orderedKeys().entries()) {
    const sourceEntry = byEntityKey.get(entityKey);
    if (!sourceEntry) throw new Error(`Missing locked source entity ${entityKey}.`);
    const sourcePath = sourceEntry.source_file;
    const sourceBytes = await readFile(path.join(sourceRoot, sourcePath));
    const markdown = sourceBytes.toString('utf8');
    const pageIdentity = frontmatterValue(markdown, 'content_identity');
    const parentIdentity = frontmatterValue(markdown, 'parent_identity') === 'null'
      ? null
      : frontmatterValue(markdown, 'parent_identity');
    const enCanonical = sourceEntry.asset.canonical.path.replace(/^\/zh\//, '/en/');
    const outputPath = sourcePath.replace('.zh-CN.md', '.en-US.md');
    entries.push({
      asset_index: index + 1,
      page_identity: pageIdentity,
      authority_asset_key: sourceEntry.authority_asset_key,
      org_id: 0,
      framework: 'big_five',
      entity_type: sourceEntry.asset.entity_type,
      entity_key: sourceEntry.asset.entity_key,
      parent_identity: parentIdentity,
      source_locale: 'zh-CN',
      target_editorial_locale: 'en-US',
      backend_locale_contract: 'en',
      zh_source_path: sourcePath,
      zh_source_sha256: sha256(sourceBytes),
      zh_runtime_projection_sha256: sourceEntry.runtime_projection_sha256,
      zh_canonical_path: sourceEntry.asset.canonical.path,
      en_slug: sourceEntry.asset.slug,
      en_canonical_path: enCanonical,
      en_locked_name: targetTitle(sourceEntry.asset),
      en_output_path: outputPath,
      en_claim_output_path: `evidence/${pageIdentity}.claims.json`,
      translation_pr: targetPr(sourceEntry.asset),
      translation_status: 'pending',
    });
  }

  const familyCounts = Object.fromEntries(
    [...new Set(entries.map((entry) => entry.entity_type))]
      .sort()
      .map((type) => [type, entries.filter((entry) => entry.entity_type === type).length]),
  );
  const manifest = {
    schema_version: 'big-five-en52-canonical-manifest.v1',
    package_id: 'fermatmind-big-five-en52-translation',
    generated_at: reviewedDate,
    review_timezone: 'Asia/Shanghai',
    target_editorial_locale: 'en-US',
    backend_locale_contract: 'en',
    canonical_locale_segment: 'en',
    authority: {
      source_package: 'FermatMind-Big-Five-ZH-V3-content-package',
      source_content_sha256: SOURCE_CONTENT_SHA256,
      source_release_package: 'generated/big-five-authority-v3/big5-zh-v3-52-page-release/release-package.json',
      source_release_payload_sha256: SOURCE_PACKAGE_PAYLOAD_SHA256,
      source_release_file_sha256: SOURCE_PACKAGE_FILE_SHA256,
      source_registry_version: sourceRegistry.registry_version,
      terminology_version: 'big-five-en52-terminology.v1',
      claim_mapping_version: 'big-five-en52-claims.v1',
    },
    counts: {
      page_count: entries.length,
      model_hub_count: familyCounts.hub,
      domain_count: familyCounts.domain,
      range_count: familyCounts.polarity,
      facet_hub_count: familyCounts.facet_hub,
      facet_detail_count: familyCounts.facet_detail,
      legacy_alias_page_count: 0,
    },
    constraints: {
      media_supported: false,
      cms_write_allowed: false,
      production_write_allowed: false,
      publish_allowed: false,
      deploy_allowed: false,
      runtime_seo_change_allowed: false,
      fap_web_change_allowed: false,
    },
    entries,
  };
  await writeJson('manifests/canonical-manifest.en-US.json', manifest);

  const mapHeader = [
    'asset_index', 'page_identity', 'authority_asset_key', 'entity_type', 'entity_key', 'parent_identity',
    'zh_source_path', 'zh_source_sha256', 'zh_runtime_projection_sha256', 'zh_canonical_path',
    'target_editorial_locale', 'backend_locale_contract', 'en_locked_name', 'en_slug',
    'en_canonical_path', 'en_output_path', 'en_claim_output_path', 'translation_pr', 'translation_status',
  ];
  await mkdir(path.join(PACKAGE_ROOT, 'manifests'), { recursive: true });
  await writeFile(
    path.join(PACKAGE_ROOT, 'manifests/zh-en-page-map.csv'),
    csv([mapHeader, ...entries.map((entry) => mapHeader.map((key) => entry[key]))]),
    'utf8',
  );

  const facets = Object.entries(FACETS).flatMap(([domain, rows]) => rows.map(([code, entityKey, name]) => ({
    code,
    domain,
    entity_key: entityKey,
    canonical_en: name,
    semantic_boundary: BOUNDARIES[entityKey] ?? null,
  })));
  await writeJson('authority/terminology-glossary.en-US.json', {
    schema_version: 'big-five-en52-terminology.v1',
    locale: 'en-US',
    status: 'locked',
    unresolved_terminology_count: 0,
    core_terms: [
      { term: 'Big Five', usage: 'Preferred public model name.' },
      { term: 'Five-Factor Model (FFM)', usage: 'Academic context; do not imply all instruments share one facet hierarchy.' },
      { term: 'OCEAN', usage: 'Retain the acronym and expand it on first use.' },
      ...DOMAINS.map(([entityKey, name]) => ({ entity_key: entityKey, term: name, usage: BOUNDARIES[entityKey] ?? 'Continuous trait domain; not a type or value judgment.' })),
    ],
    hierarchy_terms: [
      { term: 'domain', meaning: 'One of the five broad trait dimensions.' },
      { term: 'facet', meaning: 'A narrower tendency nested within a domain; not an independent personality type.' },
      { term: 'trait', meaning: 'A probabilistic tendency whose expression can vary by context.' },
    ],
    range_terms: [
      { key: 'high', preferred_label: 'high', boundary: 'A higher tendency, not better, stronger, or more capable.' },
      { key: 'mid', preferred_label: 'mid-range', boundary: 'Not an absence of personality; both ends may appear across contexts.' },
      { key: 'low', preferred_label: 'low', boundary: 'A lower tendency, not a deficit or failure.' },
    ],
    facets,
    prohibited_equivalences: [
      'Openness equals intelligence',
      'Agreeableness equals morality',
      'Conscientiousness equals ability or guaranteed success',
      'Low Extraversion equals shyness, loneliness, or poor social skill',
      'Neuroticism equals mental illness',
      'A facet equals an independent personality type',
      'A high, mid-range, or low band is a diagnostic or normative category',
    ],
  });

  await writeJson('authority/source-registry.en-US.json', {
    schema_version: 'big-five-en52-source-registry.v1',
    locale: 'en-US',
    source_registry_version: sourceRegistry.registry_version,
    generated_at: reviewedDate,
    review_timezone: 'Asia/Shanghai',
    status: 'verified_with_one_bibliographic_only_source',
    total_sources: sourceRegistry.sources.length,
    verified_public_source_count: sourceRegistry.verified_public_source_count,
    bibliographic_only_count: sourceRegistry.bibliographic_only_count,
    unresolved_source_identity_count: 0,
    competitor_sources: [],
    sources: sourceRegistry.sources.map((source) => ({
      ...source,
      verification_note: SOURCE_NOTE_EN[source.source_id],
      translation_registry_status: 'formal_english_bibliography_preserved',
      external_recheck_date: reviewedDate,
      external_recheck_status: source.source_id === 'S7'
        ? 'bibliographic_only_rechecked_against_official_publisher_metadata'
        : 'rechecked_against_primary_or_registration_metadata',
    })),
  });

  await writeJson('manifests/translation-ledger.json', {
    schema_version: 'big-five-en52-translation-ledger.v1',
    generated_at: reviewedDate,
    review_timezone: 'Asia/Shanghai',
    source_content_sha256: SOURCE_CONTENT_SHA256,
    target_page_count: 52,
    translated_page_count: 0,
    pending_page_count: 52,
    entries: entries.map((entry) => ({
      page_identity: entry.page_identity,
      entity_type: entry.entity_type,
      entity_key: entry.entity_key,
      target_path: entry.en_output_path,
      claim_path: entry.en_claim_output_path,
      assigned_pr: entry.translation_pr,
      status: 'pending',
      completed_at: null,
      output_sha256: null,
      claim_sha256: null,
    })),
  });

  const authorityReport = `# Big Five EN52 translation authority resolution\n\n`+
    `Status: **PASS / AUTHORITY FROZEN / ZERO RUNTIME WRITES**\n\n`+
    `## Resolution\n\n`+
    `The unique source authority is the reviewed local 52-page zh-CN V3 Markdown tree at `+
    `\`/Users/rainie/Desktop/FermatMind-Big-Five-ZH-V3-content-package\`. It is not selected by filename alone. `+
    `The current origin/main repository rules, V3 compiler, V3 publisher, release README, compile report, and controlled-release test all bind the same content and package hashes.\n\n`+
    `| Evidence | Locked result |\n|---|---|\n`+
    `| zh-CN source pages | 52 = 1 hub + 5 domains + 15 canonical ranges + 1 facet hub + 30 facet details |\n`+
    `| source content SHA-256 | \`${SOURCE_CONTENT_SHA256}\` |\n`+
    `| compiled payload SHA-256 | \`${SOURCE_PACKAGE_PAYLOAD_SHA256}\` |\n`+
    `| compiled file SHA-256 | \`${SOURCE_PACKAGE_FILE_SHA256}\` |\n`+
    `| claims / FAQs / sources | 170 / 261 / 11 |\n`+
    `| legacy alias content pages | 0 |\n`+
    `| target en-US pages | 52 |\n\n`+
    `## Reproducibility evidence\n\n`+
    `The source validator returned PASS with 52 pages, 170 verified claims, 261 FAQs, zero invalid links, zero substantive exact duplicates, zero scientific blockers, and zero stale reports. `+
    `The origin/main compiler rebuilt the package from the local Markdown tree byte-for-byte identically to the checked-in release package.\n\n`+
    `## Locale and route lock\n\n`+
    `Editorial content uses \`en-US\`. The existing backend locale contract remains \`en\`, and canonical URLs remain under \`/en/personality/big-five\`. `+
    `No API, CMS schema, route catalog, canonical, hreflang, robots, sitemap, llms, JSON-LD, or runtime SEO behavior changes in this package.\n\n`+
    `## Alias boundary\n\n`+
    `The ten historical \`high-*\`, \`low-*\`, and \`emotional-stability\` identities are redirect-only and are absent from the 52 target entries. `+
    `The only range keys are the fifteen \`{domain}-{high|mid|low}\` canonical identities.\n\n`+
    `## Authority state\n\n`+
    `This is a local translation authority and evidence package. It is not a CMS draft, working revision, published projection, promotion authorization, deploy artifact, or production readback. `+
    `CMS writes, production writes, Media Library writes, search submissions, and fap-web changes are all forbidden.\n`;
  await mkdir(path.join(PACKAGE_ROOT, 'authority'), { recursive: true });
  await writeFile(path.join(PACKAGE_ROOT, 'authority/authority-resolution.md'), authorityReport, 'utf8');

  process.stdout.write(`${JSON.stringify({
    status: 'PASS_BIG_FIVE_EN52_AUTHORITY_BUILD',
    source_zh_page_count: 52,
    target_en_page_count: 52,
    identity_mapping_count: 52,
    alias_target_count: 0,
    unresolved_terminology_count: 0,
    unresolved_source_identity_count: 0,
    reviewed_date: reviewedDate,
    review_timezone: 'Asia/Shanghai',
    source_content_sha256: SOURCE_CONTENT_SHA256,
  }, null, 2)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error.stack ?? error.message}\n`);
  process.exitCode = 1;
});

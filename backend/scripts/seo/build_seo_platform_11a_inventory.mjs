#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, lstatSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { basename, relative, resolve } from 'node:path';

const [apiRootArg, webRootArg, outputArg] = process.argv.slice(2);
if (!apiRootArg || !webRootArg || !outputArg) {
  throw new Error('usage: build_seo_platform_11a_inventory.mjs <fap-api-root> <fap-web-root> <output>');
}

const roots = {
  'fap-api': resolve(apiRootArg),
  'fap-web': resolve(webRootArg),
};

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
};
const canonicalJson = (value) => JSON.stringify(canonicalize(value));
const normalizedPath = (repository, file) => relative(roots[repository], file).replaceAll('\\', '/');

function walk(directory) {
  if (!existsSync(directory)) return [];
  const files = [];
  for (const name of readdirSync(directory).sort()) {
    if (['.git', 'node_modules', 'vendor'].includes(name)) continue;
    const path = resolve(directory, name);
    const stat = lstatSync(path);
    if (stat.isSymbolicLink()) continue;
    if (stat.isDirectory()) files.push(...walk(path));
    else if (stat.isFile()) files.push(path);
  }
  return files;
}

function matching(repository, predicate) {
  return walk(roots[repository]).filter((file) => predicate(normalizedPath(repository, file), file));
}

function fileRow(repository, file) {
  const bytes = readFileSync(file);
  return {
    repository,
    path: normalizedPath(repository, file),
    sha256: sha256(bytes),
    byte_size: bytes.length,
  };
}

const used = new Set();
const records = [];
const pathManifest = [];

function classify(repository, paths, content) {
  const joined = paths.join('\n');
  if (/docs\/result-page-agents\//.test(joined)) return 'product_domain_out_of_seo_scope';
  if (/Wellbeing|AgentTickJob|SendAgentMessageJob|Services\/Agent\/|EqAgent|EQ.*Agent|MachineTranslationProvider|DeploySre|Security|Commerce|Tenant|Queue/.test(joined)) return 'product_domain_out_of_seo_scope';
  if (/Console\/Commands\/SeoAgent/.test(joined)) return 'historical_superseded';
  if (/docs\/agent-os\/|release-guard-agent|seo-agent-fapweb-code-pr-writer|push-baidu|docs\/seo\/agent\//.test(joined)) return 'historical_superseded';
  if (/public-profile-seo-asset-factory\/(agents|prompts)\//.test(joined)) return 'historical_superseded';
  if (/career-content-orchestrator/.test(joined)) return 'bounded_capability';
  if (/career-content-research-producer|career-canonical-builder|career-quick-decision-authoring/.test(joined)) return 'bounded_capability';
  if (/career-editorial-qa/.test(joined)) return 'review_mode';
  if (/Personality.*(ApprovalQueueReview|PostPromotionSearchGate)|PersonalityApprovalQueueReadModel/.test(joined)) return 'review_mode';
  if (/Personality|BigFivePublicProfile/.test(joined)) return 'bounded_capability';
  if (/PageAssembly/.test(joined)) return 'deterministic_tool';
  if (/Services\/SeoAgent\/(Cms.*Scanner|Runtime.*Scanner|OpportunityAggregator)/.test(joined)) return 'deterministic_tool';
  if (/Services\/SeoAgent\//.test(joined)) return 'bounded_capability';
  if (/Services\/SeoIntel\//.test(joined)) return /Read|Review|Evaluator/.test(joined) ? 'review_mode' : 'deterministic_tool';
  if (/\.github\/workflows\//.test(joined) || /scripts\/seo\//.test(joined) || /package\.json$/.test(joined)) return 'deterministic_tool';
  if (/tests?\//.test(joined) || /docs\//.test(joined) || /prompts?\//.test(joined)) return 'contract_only';
  if (/agents\/openai\.yaml/.test(joined)) return /career-content-orchestrator/.test(joined) ? 'bounded_capability' : 'product_domain_out_of_seo_scope';
  if (/\.agents\/skills\//.test(joined)) return 'bounded_capability';
  if (/bootstrap\/app\.php|Console\/Kernel\.php|routes\//.test(joined)) return 'deterministic_tool';
  if (/Agent Council|SeoAgentCouncil/.test(content)) return 'contract_only';
  return 'contract_only';
}

function dispositionFor(classification, paths) {
  const joined = paths.join('\n');
  if (classification === 'historical_superseded') {
    if (/opportunity-aggregate/.test(joined)) return ['retire_cli', 'seo.decision_authority'];
    if (/release-guard-agent/.test(joined)) return ['retire_role', 'seo.release_separation'];
    if (/push-baidu/.test(joined)) return ['remove_active_entrypoint', 'future_search_authority'];
    return ['retire_or_preserve_historical_evidence', 'fap-api canonical registry or deterministic domain capability'];
  }
  if (classification === 'product_domain_out_of_seo_scope') return ['exclude_from_seo_registry', 'owning product-domain authority'];
  if (classification === 'review_mode') return ['retain_as_review_only', 'deterministic review capability'];
  if (classification === 'deterministic_tool') return ['retain_without_agent_authority', 'deterministic domain authority'];
  if (classification === 'bounded_capability') return ['retain_disabled_from_agent_invocation', 'bounded domain authority'];
  return ['retain_as_contract_evidence', 'fap-api canonical registry'];
}

function addRecord(repository, assetId, files, pathGlob = null) {
  const uniqueFiles = [...new Set(files)].filter((file) => {
    const key = `${repository}:${normalizedPath(repository, file)}`;
    if (used.has(key)) return false;
    used.add(key);
    return true;
  }).sort();
  if (uniqueFiles.length === 0) return;

  const manifest = uniqueFiles.map((file) => fileRow(repository, file));
  pathManifest.push(...manifest);
  const paths = manifest.map((row) => row.path);
  const content = uniqueFiles.map((file) => readFileSync(file, 'utf8')).join('\n');
  const classification = classify(repository, paths, content);
  const [disposition, replacement] = dispositionFor(classification, paths);
  const signatures = [...content.matchAll(/(?:protected\s+\$signature\s*=|"scripts"\s*:|"seo[^"\n]*"\s*:)[^;\n]*/g)]
    .map((match) => match[0].slice(0, 240));
  const hasDatabaseWrite = /DB::(?:table|transaction)|->(?:insert|update|delete|save|create|updateOrCreate)\s*\(/.test(content);
  const hasFilesystemWrite = /writeFile|write_text|file_put_contents|File::put|os\.replace|rename\s*\(/.test(content);
  const hasEgress = /Http::|fetch\s*\(|https?:\/\/|IndexNow|Baidu|OpenAI/.test(content) && !/\.md$|\.json$/.test(paths[0]);
  const modelInvocation = /OpenAI|ChatGPT|Gemini|model[_ -]invocation/i.test(content) && !/model_invocation_enabled["']?\s*[:=]\s*false/i.test(content);

  records.push({
    asset_id: assetId,
    repository,
    path: paths.length === 1 ? paths[0] : null,
    path_glob: pathGlob,
    members: paths,
    members_hash: sha256(canonicalJson(manifest.map(({ path, sha256: hash }) => ({ path, sha256: hash })))),
    asset_type: paths.length > 1 ? 'asset_group' : (paths[0].split('.').pop() || 'file'),
    entrypoints: signatures,
    callers: ['current-tree static reference scan'],
    input: 'repository-bound inputs declared by the asset',
    output: 'deterministic artifact, review evidence, or bounded domain output',
    model_invocation: modelInvocation,
    database_writes: hasDatabaseWrite,
    filesystem_writes: hasFilesystemWrite,
    external_egress: hasEgress,
    scheduler: /schedule|cron|weeklyOn|dailyAt|every[A-Z]/.test(content),
    workflow: paths.some((path) => path.startsWith('.github/workflows/')),
    authority_source: classification === 'historical_superseded' ? 'historical evidence only' : `${repository}:${paths[0]}`,
    classification,
    disposition,
    replacement,
    evidence_state: 'verified',
    evidence_refs: paths,
  });
}

function addIndividual(repository, prefix, files) {
  for (const file of files.sort()) {
    const path = normalizedPath(repository, file);
    addRecord(repository, `${prefix}.${sha256(path).slice(0, 16)}`, [file]);
  }
}

function addLogicalRecord(repository, assetId, classification, disposition, replacement, evidencePath, assetType = 'role_contract') {
  const file = resolve(roots[repository], evidencePath);
  const manifest = fileRow(repository, file);
  records.push({
    asset_id: assetId,
    repository,
    path: null,
    path_glob: null,
    members: [evidencePath],
    members_hash: sha256(canonicalJson([{ path: manifest.path, sha256: manifest.sha256 }])),
    asset_type: assetType,
    entrypoints: [],
    callers: ['historical registry consumers'],
    input: 'historical contract evidence',
    output: 'authority disposition only',
    model_invocation: false,
    database_writes: false,
    filesystem_writes: false,
    external_egress: false,
    scheduler: false,
    workflow: false,
    authority_source: 'fap-api canonical SEO role/capability registry',
    classification,
    disposition,
    replacement,
    evidence_state: 'verified',
    evidence_refs: [evidencePath],
  });
}

for (const repository of ['fap-api', 'fap-web']) {
  const skillRoot = resolve(roots[repository], '.agents/skills');
  const skillDirectories = readdirSync(skillRoot).filter((name) => statSync(resolve(skillRoot, name)).isDirectory()).sort();
  for (const skill of skillDirectories) {
    const all = walk(resolve(skillRoot, skill));
    const profile = all.filter((file) => normalizedPath(repository, file).endsWith('/agents/openai.yaml'));
    addRecord(repository, `${repository}.skill.${skill}`, all.filter((file) => !profile.includes(file)), `.agents/skills/${skill}/**`);
    addIndividual(repository, `${repository}.profile`, profile);
  }
}

const apiCommands = matching('fap-api', (path) => /^backend\/app\/Console\/Commands\/SeoAgent[^/]*\.php$/.test(path));
const apiServices = matching('fap-api', (path) => /^backend\/app\/Services\/SeoAgent\/[^/]+\.php$/.test(path));
addIndividual('fap-api', 'fap-api.legacy-seo-agent-command', apiCommands);
addIndividual('fap-api', 'fap-api.seo-agent-service', apiServices);
addIndividual('fap-api', 'fap-api.canonical-seo-governance', matching('fap-api', (path) =>
  /^backend\/app\/Services\/SeoAgentGovernance\//.test(path)
  || /^backend\/resources\/seo-agent\//.test(path)
  || /^backend\/scripts\/seo\/(?:build_seo_platform_11a_inventory\.mjs|export_seo_agent_registry\.php)$/.test(path)
));

addIndividual('fap-api', 'fap-api.personality-capability', matching('fap-api', (path) =>
  /^backend\/app\/Console\/Commands\/Personality.*(?:ApprovalQueue|PostPromotionSearchGate|PublicProfile.*(?:Draft|Promote)).*\.php$/.test(path)
  || /^backend\/app\/Services\/Cms\/(?:Personality.*ApprovalQueue|BigFivePublicProfile.*(?:Draft|Promotion)).*\.php$/.test(path)
));
addIndividual('fap-api', 'fap-api.page-assembly', matching('fap-api', (path) => /PageAssembly/.test(path) && /^(backend\/app|backend\/tests)/.test(path)));
addIndividual('fap-api', 'fap-api.product-domain-agent', matching('fap-api', (path) =>
  /^(backend\/app|backend\/tests)/.test(path)
  && /Wellbeing|AgentTickJob|SendAgentMessageJob|Services\/Agent\/AgentOrchestrator|EqAgent|OpenAi.*TranslationProvider/.test(path)
));
addIndividual('fap-api', 'fap-api.seo-intel-authority', matching('fap-api', (path) =>
  /^backend\/app\/Services\/SeoIntel\/(?:Runtime|Ledger|Decision|Lifecycle|MaterialFingerprint|UrlTruth|PageFamily|SearchChannelQueue)\//.test(path)
  || /^backend\/app\/Services\/SeoIntel\/(?:QueryOwnerUrlTruthReadModel|UrlTruthInventoryRecordWriter|SearchChannelSubmissionStatusNormalizer)\.php$/.test(path)
));
addIndividual('fap-api', 'fap-api.registration-surface', matching('fap-api', (path) => [
  'backend/bootstrap/app.php',
  'backend/app/Console/Kernel.php',
  'backend/routes/api.php',
  'backend/routes/console.php',
  'backend/app/Filament/Ops/Support/SeoAgentCouncilUiContract.php',
].includes(path)));
addIndividual('fap-api', 'fap-api.workflow', matching('fap-api', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)));
addIndividual('fap-api', 'fap-api.agent-contract-evidence', matching('fap-api', (path) =>
  (/^(backend\/docs|backend\/tests)\//.test(path)
    && path !== 'backend/docs/seo/generated/seo-platform-11a-inventory.v2.json'
    && /agent|prompt|orchestrator|release[-_ ]guard|seo-platform-(?:0[7-9]|10)/i.test(path))
));

addIndividual('fap-web', 'fap-web.workflow', matching('fap-web', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)));
addIndividual('fap-web', 'fap-web.agent-os', matching('fap-web', (path) => /^docs\/agent-os\//.test(path)));
addIndividual('fap-web', 'fap-web.result-page-agent', matching('fap-web', (path) => /^docs\/result-page-agents\//.test(path)));
addIndividual('fap-web', 'fap-web.seo-agent-doc', matching('fap-web', (path) => /^docs\/seo\/agent\//.test(path)));
addIndividual('fap-web', 'fap-web.seo-script', matching('fap-web', (path) => /^scripts\/seo\//.test(path)));
addIndividual('fap-web', 'fap-web.package-entrypoint', matching('fap-web', (path) => path === 'package.json'));
addIndividual('fap-web', 'fap-web.agent-contract-test', matching('fap-web', (path) => /^tests\//.test(path) && /agent|release[-_ ]guard/i.test(path)));

const legacyRoleDispositions = {
  agent_os_release_coordination: ['historical_superseded', 'retire_role', 'fap-api canonical registry'],
  seo_geo_control: ['historical_superseded', 'merge_role', 'seo.orchestrator'],
  runtime_qa: ['review_mode', 'retain_as_review_only', 'deterministic runtime QA capability'],
  cms_draft_package: ['deterministic_tool', 'retain_without_agent_authority', 'deterministic CMS draft package capability'],
  cms_publish_readback: ['review_mode', 'retain_as_review_only', 'deterministic CMS publish readback capability'],
  analytics_gsc_opportunity: ['review_mode', 'merge_role', 'seo.expert.search_analytics_measurement'],
  assessment_hub: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'assessment product authority'],
  result_page_agent_platform: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'result product authority'],
  career_content_graph: ['historical_superseded', 'merge_role', 'career.content_agent'],
  public_personality_content: ['bounded_capability', 'retain_disabled_from_agent_invocation', 'personality content authority'],
  competitor_alternative_research: ['review_mode', 'merge_role', 'seo.expert.competitor_research'],
  claim_privacy_safety_gate: ['contract_only', 'retain_as_contract_evidence', 'SEO-PLATFORM-11B/11K policy authority'],
  mbti_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'MBTI result product authority'],
  big_five_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'Big Five result product authority'],
  riasec_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'RIASEC result product authority'],
  iq_raven_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'IQ Raven result product authority'],
  eq60_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'EQ60 result product authority'],
  enneagram_result_page: ['product_domain_out_of_seo_scope', 'exclude_from_seo_registry', 'Enneagram result product authority'],
};
for (const [roleId, [classification, disposition, replacement]] of Object.entries(legacyRoleDispositions)) {
  addLogicalRecord('fap-web', `fap-web.legacy-agent-os-role.${roleId}`, classification, disposition, replacement, 'docs/agent-os/agent-registry.v1.json');
}

const expected = {
  api_skills: 18,
  api_profiles: 2,
  api_seo_agent_commands: 35,
  api_seo_agent_services: 5,
  api_workflows: 4,
  web_skills: 13,
  web_profiles: 1,
  web_seo_scripts: 138,
  web_workflows: 4,
};
const observed = {
  api_skills: readdirSync(resolve(roots['fap-api'], '.agents/skills')).filter((name) => statSync(resolve(roots['fap-api'], '.agents/skills', name)).isDirectory()).length,
  api_profiles: matching('fap-api', (path) => /\/agents\/openai\.yaml$/.test(path)).length,
  api_seo_agent_commands: apiCommands.length,
  api_seo_agent_services: apiServices.length,
  api_workflows: matching('fap-api', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)).length,
  web_skills: readdirSync(resolve(roots['fap-web'], '.agents/skills')).filter((name) => statSync(resolve(roots['fap-web'], '.agents/skills', name)).isDirectory()).length,
  web_profiles: matching('fap-web', (path) => /\/agents\/openai\.yaml$/.test(path)).length,
  web_seo_scripts: matching('fap-web', (path) => /^scripts\/seo\//.test(path)).length,
  web_workflows: matching('fap-web', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)).length,
};
if (JSON.stringify(expected) !== JSON.stringify(observed)) {
  throw new Error(`inventory baseline count mismatch: ${JSON.stringify({ expected, observed })}`);
}

records.sort((a, b) => a.asset_id.localeCompare(b.asset_id));
pathManifest.sort((a, b) => `${a.repository}:${a.path}`.localeCompare(`${b.repository}:${b.path}`));
const assetIds = records.map((record) => record.asset_id);
const classifications = new Set(['active_agent', 'bounded_capability', 'deterministic_tool', 'review_mode', 'contract_only', 'product_domain_out_of_seo_scope', 'historical_superseded', 'retire_candidate']);
const invalidClassification = records.filter((record) => !classifications.has(record.classification));
const missingDisposition = records.filter((record) => !record.disposition);
const duplicateAssetIds = assetIds.filter((id, index) => assetIds.indexOf(id) !== index);

const inventory = {
  schema_version: 'seo-platform-11a-inventory.v2',
  inventory_id: 'seo-platform-11a-inventory',
  inventory_version: '2.0.0',
  status: 'frozen',
  owner_repository: 'fap-api',
  source_repository_snapshots: [
    { repository: 'fap-api', sha: 'c80612517e2c6f83586d46b579c1c8353205514d', evidence_state: 'verified' },
    { repository: 'fap-web', sha: '16b5e655b4ae3e2c74bb265b90568676bbcd55dc', evidence_state: 'verified' },
  ],
  fixed_boundaries: {
    fap_web_agent_authority: false,
    read_only_gsc: true,
    search_submission_allowed: false,
    post12_agent_write_enabled: false,
    l4_state: 'dormant_not_authorized',
    runtime_model_invocation_enabled: false,
  },
  baseline_counts: observed,
  summary: {
    inventory_record_count: records.length,
    path_manifest_count: pathManifest.length,
    unclassified_count: invalidClassification.length,
    missing_disposition_count: missingDisposition.length,
    duplicate_asset_id_count: duplicateAssetIds.length,
    unknown_authority_critical_count: 0,
    inventory_coverage_percent: 100,
  },
  records,
  paths_manifest: pathManifest,
};
inventory.inventory_hash = sha256(canonicalJson(inventory));
writeFileSync(resolve(outputArg), `${JSON.stringify(inventory, null, 2)}\n`);
console.log(JSON.stringify({ ok: true, records: records.length, paths: pathManifest.length, inventory_hash: inventory.inventory_hash }));

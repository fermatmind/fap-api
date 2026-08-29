#!/usr/bin/env node

import { createHash } from 'node:crypto';
import {
  existsSync,
  lstatSync,
  readFileSync,
  readdirSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { relative, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const V2_PATH = 'backend/docs/seo/generated/seo-platform-11a-inventory.v2.json';
const V3_PATH = 'backend/docs/seo/generated/seo-platform-11a-inventory.v3.json';
const V2_BUILDER_PATH = 'backend/scripts/seo/build_seo_platform_11a_inventory.mjs';
const WEB_PROJECTION_PATH = 'docs/seo/generated/seo-platform-11a-final-tree-projection.v1.json';
const V2_FILE_SHA256 = '34c0f0df4e541a23c6de0a1758b190af53cd0ab0e0e8e5396b1a1678c39cf3d3';
const V2_INVENTORY_HASH = '925bb5dcd128f00bba6a251b55e87d2e37bb75de1fb14cc8ce3a107f7177da01';
const REGISTRY_HASH = 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791';
const CLASSIFICATIONS = new Set([
  'active_agent',
  'bounded_capability',
  'deterministic_tool',
  'review_mode',
  'contract_only',
  'product_domain_out_of_seo_scope',
  'historical_superseded',
  'retire_candidate',
]);
const REQUIRED_SIX_WEB_PATHS = [
  '.agents/skills/public-profile-seo-asset-factory/authority-supersession.v1.json',
  'docs/result-page-agents/seo-authority-supersession.v1.json',
  'docs/seo/SEO_CODE_CHANGE_ARTIFACT.md',
  'docs/seo/seo-platform-11a-authority-supersession.v1.json',
  'scripts/seo/generate-seo-code-change-artifact.mjs',
  'tests/contracts/seo-platform-11a-authority-convergence.contract.test.ts',
];
const REQUIRED_NINE_REFRESHED_PATHS = [
  ['fap-web', '.agents/skills/fap-web-seo-geo-authority/SKILL.md'],
  ['fap-web', '.agents/skills/fermatmind-seo-ops/SKILL.md'],
  ['fap-web', '.agents/skills/public-profile-seo-asset-factory/agents/release-guard-agent.md'],
  ['fap-web', '.agents/skills/public-profile-seo-asset-factory/SKILL.md'],
  ['fap-web', 'docs/agent-os/agent-registry.v1.json'],
  ['fap-web', 'docs/seo/agent/FAPWEB_CODE_PR_WRITER.md'],
  ['fap-web', 'package.json'],
  ['fap-web', 'scripts/seo/push-baidu.mjs'],
  ['fap-web', 'tests/contracts/seo-agent-fapweb-code-pr-writer.contract.test.ts'],
];
const LEGACY_ROLE_DISPOSITIONS = {
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

const args = new Map(process.argv.slice(2).map((arg) => {
  const separator = arg.indexOf('=');
  return separator === -1 ? [arg, true] : [arg.slice(0, separator), arg.slice(separator + 1)];
}));
const apiRoot = resolve(String(args.get('--api-root') || process.cwd()));
const webRoot = args.get('--web-root') ? resolve(String(args.get('--web-root'))) : null;
const inventoryPath = resolve(apiRoot, String(args.get('--inventory') || V3_PATH));
const roots = { 'fap-api': apiRoot, 'fap-web': webRoot };
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
  if (!directory || !existsSync(directory)) return [];
  const files = [];
  for (const name of readdirSync(directory).sort()) {
    if (['.git', '.next', 'node_modules', 'vendor', '_fap-web-exact'].includes(name)) continue;
    const file = resolve(directory, name);
    const stat = lstatSync(file);
    if (stat.isSymbolicLink()) continue;
    if (stat.isDirectory()) files.push(...walk(file));
    else if (stat.isFile()) files.push(file);
  }
  return files;
}

function matching(repository, predicate) {
  return walk(roots[repository]).filter((file) => predicate(normalizedPath(repository, file), file));
}

function addFiles(target, files) {
  for (const file of files) target.add(file);
}

function enumerateCandidates(repository, v2, requireHistoricalPaths = false) {
  const root = roots[repository];
  if (!root) throw new Error(`${repository} root is required`);
  const candidates = new Set();
  const addMatching = (predicate) => addFiles(candidates, matching(repository, predicate));

  addFiles(candidates, walk(resolve(root, '.agents/skills')));
  if (repository === 'fap-api') {
    addMatching((path) => /^backend\/app\/Console\/Commands\/SeoAgent[^/]*\.php$/.test(path));
    addMatching((path) => /^backend\/app\/Services\/SeoAgent\/[^/]+\.php$/.test(path));
    addMatching((path) => /^backend\/app\/Services\/SeoAgentGovernance\//.test(path)
      || /^backend\/resources\/seo-agent\//.test(path)
      || /^backend\/scripts\/seo\/(?:build_seo_platform_11a_inventory|reconcile_seo_platform_11a_inventory)\.mjs$/.test(path)
      || path === 'backend/scripts/seo/export_seo_agent_registry.php');
    addMatching((path) => /^backend\/app\/Console\/Commands\/Personality.*(?:ApprovalQueue|PostPromotionSearchGate|PublicProfile.*(?:Draft|Promote)).*\.php$/.test(path)
      || /^backend\/app\/Services\/Cms\/(?:Personality.*ApprovalQueue|BigFivePublicProfile.*(?:Draft|Promotion)).*\.php$/.test(path));
    addMatching((path) => /PageAssembly/.test(path) && /^(backend\/app|backend\/tests)/.test(path));
    addMatching((path) => /^(backend\/app|backend\/tests)/.test(path)
      && /Wellbeing|AgentTickJob|SendAgentMessageJob|Services\/Agent\/AgentOrchestrator|EqAgent|OpenAi.*TranslationProvider/.test(path));
    addMatching((path) => /^backend\/app\/Services\/SeoIntel\/(?:Runtime|Ledger|Decision|Lifecycle|MaterialFingerprint|UrlTruth|PageFamily|SearchChannelQueue)\//.test(path)
      || /^backend\/app\/Services\/SeoIntel\/(?:QueryOwnerUrlTruthReadModel|UrlTruthInventoryRecordWriter|SearchChannelSubmissionStatusNormalizer)\.php$/.test(path));
    addMatching((path) => [
      'backend/bootstrap/app.php',
      'backend/app/Console/Kernel.php',
      'backend/routes/api.php',
      'backend/routes/console.php',
      'backend/app/Filament/Ops/Support/SeoAgentCouncilUiContract.php',
    ].includes(path));
    addMatching((path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path));
    addMatching((path) => /^(backend\/docs|backend\/tests)\//.test(path)
      && path !== V3_PATH
      && /agent|prompt|orchestrator|release[-_ ]guard|seo-platform-(?:0[7-9]|10|11a)/i.test(path));
    for (const path of [
      '.github/trunk/classify-paths.mjs',
      '.github/trunk/classify-paths.test.mjs',
      V2_PATH,
      V2_BUILDER_PATH,
      'backend/scripts/seo/reconcile_seo_platform_11a_inventory.mjs',
      'backend/tests/Feature/SeoIntel/SeoPlatform11ALegacyConvergenceTest.php',
    ]) {
      if (existsSync(resolve(root, path))) candidates.add(resolve(root, path));
    }
  } else {
    addMatching((path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path));
    addMatching((path) => /^docs\/agent-os\//.test(path));
    addMatching((path) => /^docs\/result-page-agents\//.test(path));
    addMatching((path) => /^docs\/seo\/agent\//.test(path));
    addMatching((path) => /^scripts\/seo\//.test(path));
    addMatching((path) => path === 'package.json');
    addMatching((path) => /^tests\//.test(path) && /agent|release[-_ ]guard|seo-platform-11a/i.test(path));
    addMatching((path) => path.toLowerCase().includes('seo-platform-11a') && path !== WEB_PROJECTION_PATH);
    for (const path of REQUIRED_SIX_WEB_PATHS) {
      if (existsSync(resolve(root, path))) candidates.add(resolve(root, path));
    }
    if (existsSync(resolve(root, '.github/trunk/verify-seo-platform-11a-final-tree-projection.mjs'))) {
      candidates.add(resolve(root, '.github/trunk/verify-seo-platform-11a-final-tree-projection.mjs'));
    }
  }

  for (const row of v2.paths_manifest.filter((row) => row.repository === repository)) {
    const file = resolve(root, row.path);
    if (!existsSync(file)) {
      if (requireHistoricalPaths) throw new Error(`historical frozen path missing: ${repository}:${row.path}`);
      continue;
    }
    candidates.add(file);
  }
  candidates.delete(resolve(root, repository === 'fap-api' ? V3_PATH : WEB_PROJECTION_PATH));
  return [...candidates].sort((a, b) => normalizedPath(repository, a).localeCompare(normalizedPath(repository, b)));
}

function classify(repository, path, content) {
  if (path === V2_BUILDER_PATH) return 'historical_superseded';
  if (path === '.agents/skills/public-profile-seo-asset-factory/authority-supersession.v1.json') return 'contract_only';
  if (/docs\/result-page-agents\//.test(path)) return 'product_domain_out_of_seo_scope';
  if (/Wellbeing|AgentTickJob|SendAgentMessageJob|Services\/Agent\/|EqAgent|EQ.*Agent|MachineTranslationProvider|DeploySre|Security|Commerce|Tenant|Queue/.test(path)) return 'product_domain_out_of_seo_scope';
  if (/Console\/Commands\/SeoAgent/.test(path)) return 'historical_superseded';
  if (/docs\/agent-os\/|release-guard-agent|seo-agent-fapweb-code-pr-writer|push-baidu|docs\/seo\/agent\//.test(path)) return 'historical_superseded';
  if (/public-profile-seo-asset-factory\/(agents|prompts)\//.test(path)) return 'historical_superseded';
  if (/career-content-orchestrator|career-content-research-producer|career-canonical-builder|career-quick-decision-authoring/.test(path)) return 'bounded_capability';
  if (/career-editorial-qa/.test(path)) return 'review_mode';
  if (/Personality.*(ApprovalQueueReview|PostPromotionSearchGate)|PersonalityApprovalQueueReadModel/.test(path)) return 'review_mode';
  if (/Personality|BigFivePublicProfile/.test(path)) return 'bounded_capability';
  if (/PageAssembly/.test(path)) return 'deterministic_tool';
  if (/Services\/SeoAgent\/(Cms.*Scanner|Runtime.*Scanner|OpportunityAggregator)/.test(path)) return 'deterministic_tool';
  if (/Services\/SeoAgent\//.test(path)) return 'bounded_capability';
  if (/Services\/SeoIntel\//.test(path)) return /Read|Review|Evaluator/.test(path) ? 'review_mode' : 'deterministic_tool';
  if (/\.github\/|scripts\/seo\/|package\.json$/.test(path)) return 'deterministic_tool';
  if (/tests?\/|docs\/|prompts?\//.test(path)) return 'contract_only';
  if (/agents\/openai\.yaml/.test(path)) return /career-content-orchestrator/.test(path) ? 'bounded_capability' : 'product_domain_out_of_seo_scope';
  if (/\.agents\/skills\//.test(path)) return 'bounded_capability';
  if (/bootstrap\/app\.php|Console\/Kernel\.php|routes\//.test(path)) return 'deterministic_tool';
  if (/Agent Council|SeoAgentCouncil/.test(content)) return 'contract_only';
  return repository === 'fap-web' ? 'contract_only' : 'deterministic_tool';
}

function dispositionFor(classification, path) {
  if (path === V2_BUILDER_PATH) return ['historical_snapshot_generator', 'seo-platform-11a-inventory.v2 historical baseline only'];
  if (classification === 'historical_superseded') return ['retire_or_preserve_historical_evidence', 'fap-api canonical registry or deterministic domain capability'];
  if (classification === 'product_domain_out_of_seo_scope') return ['exclude_from_seo_registry', 'owning product-domain authority'];
  if (classification === 'review_mode') return ['retain_as_review_only', 'deterministic review capability'];
  if (classification === 'deterministic_tool') return ['retain_without_agent_authority', 'deterministic domain authority'];
  if (classification === 'bounded_capability') return ['retain_disabled_from_agent_invocation', 'bounded domain authority'];
  return ['retain_as_contract_evidence', 'fap-api canonical registry'];
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

function recordFor(row) {
  const content = readFileSync(resolve(roots[row.repository], row.path), 'utf8');
  const classification = classify(row.repository, row.path, content);
  const [disposition, replacement] = dispositionFor(classification, row.path);
  const hasDatabaseWrite = /DB::(?:table|transaction)|->(?:insert|update|delete|save|create|updateOrCreate)\s*\(/.test(content);
  const hasFilesystemWrite = /writeFile|write_text|file_put_contents|File::put|os\.replace|rename\s*\(/.test(content);
  const hasEgress = /Http::|fetch\s*\(|https?:\/\/|IndexNow|Baidu|OpenAI/.test(content) && !/\.md$|\.json$/.test(row.path);
  const modelInvocation = /OpenAI|ChatGPT|Gemini|model[_ -]invocation/i.test(content)
    && !/model_invocation_enabled["']?\s*[:=]\s*false/i.test(content);
  return {
    asset_id: `${row.repository}.inventory-file.${sha256(row.path).slice(0, 16)}`,
    repository: row.repository,
    path: row.path,
    path_glob: null,
    members: [row.path],
    members_hash: sha256(canonicalJson([{ path: row.path, sha256: row.sha256 }])),
    asset_type: row.path.split('.').pop() || 'file',
    entrypoints: [],
    callers: ['current-tree static reference scan'],
    input: 'repository-bound inputs declared by the asset',
    output: 'deterministic artifact, review evidence, or bounded domain output',
    model_invocation: modelInvocation,
    database_writes: hasDatabaseWrite,
    filesystem_writes: hasFilesystemWrite,
    external_egress: hasEgress,
    scheduler: /schedule|cron|weeklyOn|dailyAt|every[A-Z]/.test(content),
    workflow: row.path.startsWith('.github/workflows/'),
    authority_source: classification === 'historical_superseded' ? 'historical evidence only' : `${row.repository}:${row.path}`,
    classification,
    disposition,
    replacement,
    evidence_state: 'verified',
    evidence_refs: [row.path],
  };
}

function logicalLegacyRecords(webRegistryRow) {
  return Object.entries(LEGACY_ROLE_DISPOSITIONS).map(([roleId, [classification, disposition, replacement]]) => ({
    asset_id: `fap-web.legacy-agent-os-role.${roleId}`,
    repository: 'fap-web',
    path: null,
    path_glob: null,
    members: [webRegistryRow.path],
    members_hash: sha256(canonicalJson([{ path: webRegistryRow.path, sha256: webRegistryRow.sha256 }])),
    asset_type: 'role_contract',
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
    evidence_refs: [webRegistryRow.path],
  }));
}

function hashWithout(value, field) {
  const payload = { ...value };
  delete payload[field];
  return sha256(canonicalJson(payload));
}

function pathSetHash(rows, repository) {
  return sha256(canonicalJson(rows.filter((row) => row.repository === repository).map((row) => row.path).sort()));
}

function buildInventory() {
  if (!webRoot) throw new Error('--web-root is required with --build');
  const v2Bytes = readFileSync(resolve(apiRoot, V2_PATH));
  const v2 = JSON.parse(v2Bytes);
  if (sha256(v2Bytes) !== V2_FILE_SHA256 || v2.inventory_hash !== V2_INVENTORY_HASH) {
    throw new Error('historical v2 baseline drift');
  }
  const projectionBytes = readFileSync(resolve(webRoot, WEB_PROJECTION_PATH));
  const projection = JSON.parse(projectionBytes);
  if (projection.projection_self_hash !== hashWithout(projection, 'projection_self_hash')) {
    throw new Error('web projection self hash drift');
  }
  const webSha = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: webRoot, encoding: 'utf8' }).trim();
  const expectedWebSha = String(args.get('--web-sha') || webSha);
  if (webSha !== expectedWebSha) throw new Error(`fap-web SHA mismatch: expected ${expectedWebSha}, got ${webSha}`);

  const apiFiles = enumerateCandidates('fap-api', v2, true);
  const webFiles = enumerateCandidates('fap-web', v2, true);
  const dynamicWebPaths = webFiles.map((file) => normalizedPath('fap-web', file));
  const projectionPaths = projection.paths_manifest.map((row) => row.path);
  if (canonicalJson([...dynamicWebPaths].sort()) !== canonicalJson([...projectionPaths].sort())) {
    const dynamicSet = new Set(dynamicWebPaths);
    const projectionSet = new Set(projectionPaths);
    throw new Error(`web projection path set does not match current-tree enumeration: ${JSON.stringify({
      absent_from_projection: dynamicWebPaths.filter((path) => !projectionSet.has(path)),
      absent_from_enumeration: projectionPaths.filter((path) => !dynamicSet.has(path)),
    })}`);
  }
  const pathManifest = [
    ...apiFiles.map((file) => fileRow('fap-api', file)),
    ...webFiles.map((file) => fileRow('fap-web', file)),
  ].sort((a, b) => `${a.repository}:${a.path}`.localeCompare(`${b.repository}:${b.path}`));
  const projectionByPath = new Map(projection.paths_manifest.map((row) => [row.path, row]));
  for (const row of pathManifest.filter((row) => row.repository === 'fap-web')) {
    const projected = projectionByPath.get(row.path);
    if (!projected || projected.sha256 !== row.sha256) throw new Error(`web projection hash drift: ${row.path}`);
  }

  const records = pathManifest.map(recordFor);
  const webRegistryRow = pathManifest.find((row) => row.repository === 'fap-web' && row.path === 'docs/agent-os/agent-registry.v1.json');
  if (!webRegistryRow) throw new Error('historical web agent registry is missing');
  records.push(...logicalLegacyRecords(webRegistryRow));
  records.sort((a, b) => a.asset_id.localeCompare(b.asset_id));

  const v2ByPath = new Map(v2.paths_manifest.map((row) => [`${row.repository}:${row.path}`, row]));
  const refreshed = pathManifest.filter((row) => {
    const baseline = v2ByPath.get(`${row.repository}:${row.path}`);
    return baseline && baseline.sha256 !== row.sha256;
  }).map((row) => ({
    repository: row.repository,
    path: row.path,
    historical_sha256: v2ByPath.get(`${row.repository}:${row.path}`).sha256,
    reconciled_sha256: row.sha256,
  }));
  const refreshedKeys = new Set(refreshed.map((row) => `${row.repository}:${row.path}`));
  for (const [repository, path] of REQUIRED_NINE_REFRESHED_PATHS) {
    if (!refreshedKeys.has(`${repository}:${path}`)) throw new Error(`required historical hash refresh missing: ${repository}:${path}`);
  }
  const requiredNineKeys = new Set(REQUIRED_NINE_REFRESHED_PATHS.map(([repository, path]) => `${repository}:${path}`));
  const newlyClassified = pathManifest.filter((row) => !v2ByPath.has(`${row.repository}:${row.path}`));
  const pathKeys = new Set(pathManifest.map((row) => `${row.repository}:${row.path}`));
  for (const path of REQUIRED_SIX_WEB_PATHS) {
    if (!pathKeys.has(`fap-web:${path}`)) throw new Error(`required omitted web path is not classified: ${path}`);
  }

  const registry = JSON.parse(readFileSync(resolve(apiRoot, 'backend/docs/seo/generated/seo-agent-role-capability-registry.v1.json')));
  const promptManifest = JSON.parse(readFileSync(resolve(apiRoot, 'backend/docs/seo/generated/seo-agent-prompt-manifest.v1.json')));
  const policyManifest = JSON.parse(readFileSync(resolve(apiRoot, 'backend/docs/seo/generated/seo-agent-policy-manifest.v1.json')));
  const schemaHashes = ['seo-role-input.v1.schema.json', 'seo-role-output.v1.schema.json'].map((file) => {
    const path = `backend/resources/seo-agent/schemas/${file}`;
    const schema = JSON.parse(readFileSync(resolve(apiRoot, path)));
    return { schema_id: schema.schema_id, schema_version: schema.schema_version, hash: sha256(canonicalJson(schema)), path };
  });
  if (registry.registry_hash !== REGISTRY_HASH || registry.roles.length !== 9 || registry.capabilities.length !== 20) {
    throw new Error('frozen role/capability registry drift');
  }
  const observed = {
    api_skills: readdirSync(resolve(apiRoot, '.agents/skills')).filter((name) => statSync(resolve(apiRoot, '.agents/skills', name)).isDirectory()).length,
    api_profiles: matching('fap-api', (path) => /\/agents\/openai\.yaml$/.test(path)).length,
    api_seo_agent_commands: matching('fap-api', (path) => /^backend\/app\/Console\/Commands\/SeoAgent[^/]*\.php$/.test(path)).length,
    api_seo_agent_services: matching('fap-api', (path) => /^backend\/app\/Services\/SeoAgent\/[^/]+\.php$/.test(path)).length,
    api_workflows: matching('fap-api', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)).length,
    web_skills: readdirSync(resolve(webRoot, '.agents/skills')).filter((name) => statSync(resolve(webRoot, '.agents/skills', name)).isDirectory()).length,
    web_profiles: matching('fap-web', (path) => /\/agents\/openai\.yaml$/.test(path)).length,
    web_seo_scripts: matching('fap-web', (path) => /^scripts\/seo\//.test(path)).length,
    web_workflows: matching('fap-web', (path) => /^\.github\/workflows\/(?:ci|deploy|nightly|recovery)\.yml$/.test(path)).length,
    registry_roles: registry.roles.length,
    registry_capabilities: registry.capabilities.length,
  };
  if (observed.web_seo_scripts !== 139) throw new Error(`web SEO script count drift: ${observed.web_seo_scripts}`);

  const assetIds = records.map((record) => record.asset_id);
  const inventory = {
    schema_version: 'seo-platform-11a-inventory.v3',
    inventory_id: 'seo-platform-11a-inventory',
    inventory_version: '3.0.0',
    status: 'frozen',
    owner_repository: 'fap-api',
    historical_baseline: {
      path: V2_PATH,
      schema_version: v2.schema_version,
      inventory_version: v2.inventory_version,
      file_sha256: sha256(v2Bytes),
      inventory_hash: v2.inventory_hash,
      record_count: v2.records.length,
      path_manifest_count: v2.paths_manifest.length,
      generator: {
        path: V2_BUILDER_PATH,
        classification: 'historical_snapshot_generator',
        sha256: sha256(readFileSync(resolve(apiRoot, V2_BUILDER_PATH))),
      },
    },
    source_repository_snapshots: [
      {
        repository: 'fap-api',
        enumerated_parent_sha: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: apiRoot, encoding: 'utf8' }).trim(),
        delivery_sha_binding: 'exact_sha_ci_receipt',
        path_set_hash: pathSetHash(pathManifest, 'fap-api'),
      },
      {
        repository: 'fap-web',
        sha: webSha,
        projection_path: WEB_PROJECTION_PATH,
        projection_file_sha256: sha256(projectionBytes),
        projection_self_hash: projection.projection_self_hash,
        projection_path_set_hash: projection.path_set_hash,
        path_set_hash: pathSetHash(pathManifest, 'fap-web'),
      },
    ],
    delivery_binding: {
      repository: 'fap-api',
      path_set_hash: pathSetHash(pathManifest, 'fap-api'),
      commit_sha_source: 'github.sha',
      receipt: 'fermatmind.trunk-validation.v1.seo_platform_11a_closeout',
    },
    fixed_boundaries: {
      fap_web_agent_authority: false,
      execution_authorized: false,
      read_only_gsc: true,
      search_submission_allowed: false,
      post12_agent_write_enabled: false,
      l4_state: 'dormant_not_authorized',
      runtime_created: false,
      runtime_model_invocation_enabled: false,
      model_calls_performed: 0,
      cms_writes: 0,
      seo_data_writes: 0,
      search_submissions: 0,
      production_data_writes: 0,
      delegated_executions: 0,
    },
    registry_freeze: {
      registry_hash: registry.registry_hash,
      registry_status: registry.registry_status,
      role_count: registry.roles.length,
      capability_count: registry.capabilities.length,
      unique_orchestrator_count: registry.roles.filter((role) => role.role_id === 'seo.orchestrator').length,
      unique_career_agent_count: registry.roles.filter((role) => role.role_id === 'career.content_agent').length,
      prompt_manifest_hash: promptManifest.manifest_hash,
      policy_manifest_hash: policyManifest.manifest_hash,
      schema_hashes: schemaHashes,
    },
    observed_counts: observed,
    reconciliation: {
      required_six_omitted_paths: REQUIRED_SIX_WEB_PATHS.map((path) => ({ repository: 'fap-web', path, classification: recordFor(pathManifest.find((row) => row.repository === 'fap-web' && row.path === path)).classification })),
      required_nine_refreshed_paths: refreshed.filter((row) => requiredNineKeys.has(`${row.repository}:${row.path}`)),
      additional_refreshed_paths: refreshed.filter((row) => !requiredNineKeys.has(`${row.repository}:${row.path}`)),
      refreshed_v2_path_count: refreshed.length,
      newly_classified_paths: newlyClassified.map(({ repository, path, sha256: hash }) => ({ repository, path, sha256: hash })),
    },
    summary: {
      inventory_record_count: records.length,
      path_manifest_count: pathManifest.length,
      unclassified_count: records.filter((record) => !CLASSIFICATIONS.has(record.classification)).length,
      missing_disposition_count: records.filter((record) => !record.disposition).length,
      duplicate_asset_id_count: assetIds.filter((id, index) => assetIds.indexOf(id) !== index).length,
      missing_paths: 0,
      unexpected_paths: 0,
      hash_drift: 0,
      inventory_coverage_percent: 100,
    },
    path_set_hashes: {
      'fap-api': pathSetHash(pathManifest, 'fap-api'),
      'fap-web': pathSetHash(pathManifest, 'fap-web'),
      combined_manifest: sha256(canonicalJson(pathManifest.map(({ repository, path, sha256: hash }) => ({ repository, path, sha256: hash })))),
    },
    self_excluded_path: V3_PATH,
    records,
    paths_manifest: pathManifest,
  };
  inventory.inventory_self_hash = hashWithout(inventory, 'inventory_self_hash');
  writeFileSync(inventoryPath, `${JSON.stringify(inventory, null, 2)}\n`);
  return inventory;
}

function verifyInventory(reconciliationMode) {
  const inventoryBytes = readFileSync(inventoryPath);
  const inventory = JSON.parse(inventoryBytes);
  const v2Bytes = readFileSync(resolve(apiRoot, V2_PATH));
  const v2 = JSON.parse(v2Bytes);
  const metadataFailures = [];
  if (inventory.schema_version !== 'seo-platform-11a-inventory.v3') metadataFailures.push('schema_version');
  if (inventory.inventory_version !== '3.0.0') metadataFailures.push('inventory_version');
  if (inventory.status !== 'frozen') metadataFailures.push('status');
  if (sha256(v2Bytes) !== V2_FILE_SHA256 || v2.inventory_hash !== V2_INVENTORY_HASH) metadataFailures.push('historical_v2');
  if (inventory.historical_baseline?.file_sha256 !== V2_FILE_SHA256) metadataFailures.push('v2_file_reference');
  if (inventory.inventory_self_hash !== hashWithout(inventory, 'inventory_self_hash')) metadataFailures.push('inventory_self_hash');
  if (inventory.registry_freeze?.registry_hash !== REGISTRY_HASH
    || inventory.registry_freeze?.role_count !== 9
    || inventory.registry_freeze?.capability_count !== 20
    || inventory.registry_freeze?.unique_orchestrator_count !== 1
    || inventory.registry_freeze?.unique_career_agent_count !== 1) metadataFailures.push('registry_freeze');
  if (inventory.fixed_boundaries?.runtime_model_invocation_enabled !== false
    || inventory.fixed_boundaries?.model_calls_performed !== 0
    || inventory.fixed_boundaries?.cms_writes !== 0
    || inventory.fixed_boundaries?.seo_data_writes !== 0
    || inventory.fixed_boundaries?.search_submissions !== 0
    || inventory.fixed_boundaries?.production_data_writes !== 0
    || inventory.fixed_boundaries?.delegated_executions !== 0
    || inventory.fixed_boundaries?.l4_state !== 'dormant_not_authorized') metadataFailures.push('fixed_boundaries');
  if (inventory.observed_counts?.web_seo_scripts !== 139) metadataFailures.push('web_seo_scripts_recorded');

  const paths = inventory.paths_manifest.map((row) => `${row.repository}:${row.path}`);
  const duplicatePaths = paths.filter((path, index) => paths.indexOf(path) !== index);
  const assetIds = inventory.records.map((record) => record.asset_id);
  const duplicateAssetIds = assetIds.filter((id, index) => assetIds.indexOf(id) !== index);
  const invalidClassifications = inventory.records.filter((record) => !CLASSIFICATIONS.has(record.classification));
  const missingDispositions = inventory.records.filter((record) => !record.disposition);
  for (const repository of ['fap-api', 'fap-web']) {
    if (inventory.path_set_hashes?.[repository] !== pathSetHash(inventory.paths_manifest, repository)) metadataFailures.push(`${repository}_path_set_hash`);
  }
  const combinedManifestHash = sha256(canonicalJson(inventory.paths_manifest.map(({ repository, path, sha256: hash }) => ({ repository, path, sha256: hash }))));
  if (inventory.path_set_hashes?.combined_manifest !== combinedManifestHash) metadataFailures.push('combined_manifest_hash');

  const checkedRepositories = reconciliationMode ? ['fap-api', 'fap-web'] : ['fap-api'];
  if (reconciliationMode && !webRoot) throw new Error('--web-root is required with --verify-reconciliation');
  const missing = [];
  const drift = [];
  for (const row of inventory.paths_manifest.filter((row) => checkedRepositories.includes(row.repository))) {
    const file = resolve(roots[row.repository], row.path);
    if (!existsSync(file)) missing.push(`${row.repository}:${row.path}`);
    else if (sha256(readFileSync(file)) !== row.sha256) drift.push(`${row.repository}:${row.path}`);
  }

  let unexpected = [];
  if (reconciliationMode) {
    const expected = new Set(paths);
    const candidates = [
      ...enumerateCandidates('fap-api', v2).map((file) => `fap-api:${normalizedPath('fap-api', file)}`),
      ...enumerateCandidates('fap-web', v2).map((file) => `fap-web:${normalizedPath('fap-web', file)}`),
    ];
    unexpected = candidates.filter((path) => !expected.has(path));
    const expectedWeb = inventory.source_repository_snapshots.find((row) => row.repository === 'fap-web');
    const actualWebSha = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: webRoot, encoding: 'utf8' }).trim();
    if (expectedWeb?.sha !== actualWebSha) metadataFailures.push('fap_web_sha');
    const projectionBytes = readFileSync(resolve(webRoot, WEB_PROJECTION_PATH));
    const projection = JSON.parse(projectionBytes);
    if (expectedWeb?.projection_file_sha256 !== sha256(projectionBytes)) metadataFailures.push('web_projection_file_sha256');
    if (expectedWeb?.projection_self_hash !== projection.projection_self_hash
      || projection.projection_self_hash !== hashWithout(projection, 'projection_self_hash')) metadataFailures.push('web_projection_self_hash');
    const webScripts = matching('fap-web', (path) => /^scripts\/seo\//.test(path)).length;
    if (webScripts !== 139) metadataFailures.push('web_seo_scripts');
  }

  const result = {
    ok: missing.length === 0
      && drift.length === 0
      && unexpected.length === 0
      && invalidClassifications.length === 0
      && missingDispositions.length === 0
      && duplicatePaths.length === 0
      && duplicateAssetIds.length === 0
      && metadataFailures.length === 0,
    mode: reconciliationMode ? 'verify_reconciliation' : 'verify_frozen',
    missing_paths: missing.length,
    unexpected_paths: unexpected.length,
    hash_drift: drift.length,
    unclassified: invalidClassifications.length + unexpected.length,
    missing_disposition: missingDispositions.length,
    duplicate_paths: duplicatePaths.length,
    duplicate_asset_id: duplicateAssetIds.length,
    web_seo_scripts: reconciliationMode ? matching('fap-web', (path) => /^scripts\/seo\//.test(path)).length : inventory.observed_counts?.web_seo_scripts,
    api_path_set_hash: pathSetHash(inventory.paths_manifest, 'fap-api'),
    inventory_self_hash: hashWithout(inventory, 'inventory_self_hash'),
    details: {
      missing_paths: missing,
      unexpected_paths: unexpected,
      hash_drift: drift,
      unclassified: invalidClassifications.map((record) => record.asset_id),
      missing_disposition: missingDispositions.map((record) => record.asset_id),
      duplicate_paths: [...new Set(duplicatePaths)],
      duplicate_asset_id: [...new Set(duplicateAssetIds)],
      metadata_failures: metadataFailures,
    },
  };
  process.stdout.write(`${JSON.stringify(result)}\n`);
  if (!result.ok) process.exitCode = 1;
}

if (args.has('--build')) buildInventory();
if (args.has('--verify-reconciliation')) verifyInventory(true);
else if (args.has('--verify-frozen') || args.has('--build')) verifyInventory(false);
else throw new Error('choose --build, --verify-frozen, or --verify-reconciliation');

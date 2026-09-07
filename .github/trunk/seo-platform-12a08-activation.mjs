#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
export const SCOPE_VERSION = 'seo-council-a08-dependencies.v2';
export const MISSIONS = ['seo.platform12.daily_gsc_core_runtime', 'seo.platform12.daily_url_truth_reconciliation', 'seo.platform12.daily_private_policy_evidence_drift'];
const base = 'backend/app/Services/SeoCouncil/Platform12/';
export const MISSION_DEPENDENCIES = {
  [MISSIONS[0]]: [`${base}Evaluation/Platform12DailyGscCoreRuntimeEvaluator.php`, 'backend/app/Services/SeoIntel/Gsc', 'backend/app/Services/SeoIntel/Runtime', 'backend/app/Services/Ops/PublicContentDeliveryProbeService.php', 'backend/resources/seo-agent/council/platform12/daily/gsc'],
  [MISSIONS[1]]: [`${base}Evaluation/Platform12DailyUrlTruthEvaluator.php`, 'backend/app/Services/SeoIntel/UrlTruth', 'backend/app/Services/SeoIntel/Sitemap', 'backend/app/Services/SEO/Sitemap', 'backend/resources/seo-agent/council/platform12/daily/url'],
  [MISSIONS[2]]: [`${base}Evaluation/Platform12DailySecurityDriftEvaluator.php`, 'backend/resources/seo-agent/council/platform12/daily/security'],
};
export const COMMON_DEPENDENCIES = [
  'backend/composer.json', 'backend/composer.lock', 'backend/bootstrap/', 'backend/config/', 'backend/routes/',
  'backend/database/migrations/', 'backend/app/Http/Middleware/', 'backend/app/Policies/', 'backend/app/Models/',
  'backend/app/Support/', 'backend/app/Services/Auth/', 'backend/app/Providers/', 'backend/app/Services/SeoAgentPolicyGateway/', 'backend/app/Services/SeoAgentGovernance/',
  'backend/app/Services/SeoAgentEvidence/', 'backend/app/Services/SeoCouncil/', 'backend/resources/seo-agent/',
  'backend/app/Filament/Ops/', 'backend/resources/views/filament/ops/', 'backend/scripts/deploy/',
  '.github/trunk/', '.github/workflows/ci.yml', '.github/workflows/deploy.yml', '.github/workflows/nightly.yml', 'deploy.php',
];
export function scopeFor(path) {
  const mission = MISSIONS.filter(id => MISSION_DEPENDENCIES[id].some(p => path.startsWith(p)));
  if (mission.length) return mission;
  if (/^backend\/content_assets\/(?:personality_public|career)\/current\/.*\.json$/.test(path)) return ['public'];
  if (COMMON_DEPENDENCIES.some(p => path.startsWith(p))
    || /(?:^|\/)(?:manifest|[^/]*(?:authority|schema|contract|identity))[^/]*\.json$/.test(path)) return ['public'];
  return [];
}
export const inRuntimeScope = path => scopeFor(path).length > 0;
export const digest = value => createHash('sha256').update(value).digest('hex');
export function fingerprint(root = process.cwd(), ref = 'HEAD') {
  const rows = execFileSync('git', ['ls-tree', '-r', '-z', ref], { cwd: root, maxBuffer: 32 * 1024 * 1024 }).toString().split('\0').filter(Boolean);
  const result = Object.fromEntries(['public', ...MISSIONS].map(id => [id, []]));
  const semanticRows = rows.filter(row=>/^backend\/content_assets\/(?:personality_public|career)\/current\/.*\.json$/.test(row.slice(row.indexOf('\t')+1)));
  const semantic = new Map();
  if (semanticRows.length) {
    const objects = semanticRows.map(row=>row.split(' ')[2].split('\t')[0]);
    const bytes = execFileSync('git',['cat-file','--batch'],{cwd:root,input:objects.join('\n')+'\n',maxBuffer:128*1024*1024});
    let offset=0;
    for (const row of semanticRows) {
      const end=bytes.indexOf(10,offset), size=Number(bytes.subarray(offset,end).toString().split(' ')[2]);
      if (!Number.isSafeInteger(size)) throw new Error('CONTENT_IDENTITY_FINGERPRINT_HOLD');
      const value=JSON.parse(bytes.subarray(end+1,end+1+size).toString()); offset=end+size+2;
      semantic.set(row,JSON.stringify(contentIdentity(value,row.endsWith('/manifest.json'))));
    }
  }
  for (const row of rows) {
    const path = row.slice(row.indexOf('\t') + 1);
    for (const id of scopeFor(path)) result[id].push(semantic.has(row) ? `${path}\0${semantic.get(row)}` : row);
  }
  return Object.fromEntries(Object.entries(result).map(([id, rows]) => [id, digest(rows.sort().join('\0'))]));
}
// Only compiler-owned Current packages have a reviewed copy/identity split.
// Unknown authority contracts remain byte-bound rather than guessed to be prose.
export function contentIdentity(value, manifest = false) {
  if (manifest) {
    const copy = structuredClone(value);
    delete copy.aggregate_sha256;
    for (const key of ['compatibility_projection_aggregate_sha256','legacy_projection_aggregate_sha256','legacy_versionless_projection_sha256','source_semantic_aggregate_sha256']) delete copy.set_hashes?.[key];
    for (const file of copy.files ?? []) for (const key of ['bytes','sha256','source_content_sha256','compatibility_projection_sha256','legacy_projection_sha256','legacy_row_sha256']) delete file[key];
    return copy;
  }
  const identity=structuredClone(value);
  for (const key of ['blocks','payload','source_content_sha256']) delete identity[key];
  return identity;
}
export function mayCarry(manifest, candidate, mission, root = process.cwd()) {
  if (manifest?.schema_version !== 'seo.platform12_a08_activation.v2'
    || manifest?.repository !== 'fermatmind/fap-api' || !MISSIONS.includes(mission)
    || !/^[a-f0-9]{40}$/.test(manifest.bound_production_sha) || !/^[a-f0-9]{40}$/.test(candidate.production_sha)) return false;
  const ancestor = spawnSync('git', ['merge-base', '--is-ancestor', manifest.bound_production_sha, candidate.production_sha], { cwd: root });
  const fingerprints = fingerprint(root, candidate.production_sha);
  const old = fingerprint(root, manifest.bound_production_sha);
  return ancestor.status === 0 && old.public === fingerprints.public && old[mission] === fingerprints[mission]
    && manifest.validation?.public_checks?.fingerprint === old.public
    && manifest.missions?.[mission]?.checks?.fingerprint === old[mission]
    && JSON.stringify(manifest.runtime?.version_vector) === JSON.stringify(candidate.version_vector);
}
export function validateNightly(receipt, metadata) {
  // Historical reader only; never used to confer scoped authorization.
  if (receipt?.check_scope !== 'weekly_full_checks' || receipt?.status !== 'pass'
    || receipt?.workflow_sha !== metadata?.sha) throw new Error('NIGHTLY_SCOPE_HOLD');
  return true;
}
export const CHECKS = {
  public: ['SeoPlatform12A01MissionCatalogTest','SeoPlatform12A02SchedulerStorageTest','SeoPlatform12A03SchedulerFencingTest','SeoPlatform12A04ProductionPersistenceTest','SeoPlatform12A05ReadOnlyRuntimeGateTest','SeoPlatform12A08ActivationEvidenceTest','SeoPlatform12A08DailyWiringTest','SeoPlatform12A08LegacyScheduleContractTest','MigrationPurityGateTest','SeoPlatform12F01NotificationPolicyContractTest','SeoPlatform12F02NotificationOutboxTest','SeoPlatform11C','SeoOperationsPageTest','SeoUxImpl06AgentCouncilTest','SeoPlatform12E02SystemHealthUiTest','SeoPlatform12E04TraceDrilldownUiSafetyTest'],
  [MISSIONS[0]]: ['SeoPlatform12B01DailyGscCoreRuntimeTest','SeoPlatform12A08ProductionEvidenceTest'],
  [MISSIONS[1]]: ['SeoPlatform12B02DailyUrlTruthTest','SeoPlatform12A08ProductionEvidenceTest'],
  [MISSIONS[2]]: ['SeoPlatform12B03DailySecurityDriftTest','SeoPlatform12A08ProductionEvidenceTest'],
};
export function scopedReceipt(junit, sha, root = process.cwd()) {
  if (!/^[a-f0-9]{40}$/.test(sha) || !junit.includes('<testcase') || /<(?:failure|error)\b/.test(junit)) throw new Error('SCOPED_TEST_RESULTS_HOLD');
  const cases = [...junit.matchAll(/<testcase\b[^>]*(?:\/>|>[\s\S]*?<\/testcase>)/g)].map(match=>match[0]);
  const prints = fingerprint(root, sha);
  const checks = {};
  for (const [id, tests] of Object.entries(CHECKS)) {
    if (!tests.every(test => cases.some(item=>item.includes(test)) && cases.filter(item=>item.includes(test)).every(item=>!/<skipped\b/.test(item)))) throw new Error(`SCOPED_TEST_COVERAGE_HOLD:${id}`);
    checks[id] = {scope_id:id,check_scope: 'a08_scoped_checks', sha, scope_version: SCOPE_VERSION, status: 'pass', fingerprint: prints[id], result_digest: digest(junit), tests};
  }
  return {schema_version: 'seo.a08_scoped_checks.v2', sha, check_scope: 'a08_scoped_checks', checks,
    covered_classes: [...new Set(cases.flatMap(item=>[...item.matchAll(/class(?:name)?="([^"]+)"/g)].map(match=>match[1])))].filter(name=>cases.filter(item=>item.includes(name)).every(item=>!/<skipped\b/.test(item))),
    source_acceptance: 'NOT_PROVEN_BY_OFFLINE_FIXTURES', nightly: 'INDEPENDENT_HEALTH_CHECK'};
}
if (import.meta.url === `file://${process.argv[1]}`) {
  const [cmd, input, sha, output] = process.argv.slice(2);
  if (cmd === 'fingerprint') process.stdout.write(JSON.stringify(fingerprint(input))+'\n');
  else if (cmd === 'scoped-receipt') writeFileSync(output, JSON.stringify(scopedReceipt(readFileSync(input, 'utf8'), sha))+'\n');
  else throw new Error('SEO_COUNCIL_A08_COMMAND_DENIED');
}

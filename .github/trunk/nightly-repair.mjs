import {execFileSync,spawnSync} from 'node:child_process';
import {appendFileSync,writeFileSync} from 'node:fs';
import {phpConsumers,nightlyExecutionInputs,executionConsumes} from './impact-consumers.mjs';
// CodeQL JS/TS source and embedded-script templates, plus extraction/query
// configuration. Generic content JSON/YAML is not executable JS input here.
const codeqlInput = path => /\.(?:[jt]sx?|[cm][jt]s|es6?|html?|xhtml?|vue|hbs|ejs|njk)$/.test(path)
 || /^\.github\/(?:workflows\/|codeql\/)/.test(path)
 || /(?:^|\/)(?:package(?:-lock)?\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml|tsconfig[^/]*\.json|qlpack\.yml|codeql-pack\.lock\.yml)$/.test(path)
 || /\.(?:ql|qll|qls)$/.test(path) || /(?:^|\/)\.gitignore$/.test(path);
const semgrepConfig = path => path === '.github/workflows/nightly.yml'
 || /(?:^|\/)\.semgrep(?:ignore|\.(?:yml|yaml))$/.test(path);
export function repairDomains(paths,root=process.cwd(),executionInputs=[],scanPaths=paths) {
 const graph=phpConsumers(root),required=new Set(paths.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p)));
 for(const file of graph.files.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p))) if(paths.some(p=>graph.closure([file]).has(p))||executionConsumes(graph.sources.get(file),executionInputs)) required.add(file);
 // These runner guards can recover a publication and switch the release to LKG.
 // Revalidate the existing publication transactions and their derived-cache recovery,
 // including dynamic artisan callers that the PHP symbol graph cannot resolve.
 if(paths.some(p=>/^\.github\/trunk\/(?:iq-public-(?:article|scale)|iq-eq-topic|eq-(?:existing-public|new-source|new-english))-(?:publish|online-qa)\.mjs$/.test(p))) {
  for(const file of graph.files) if(/^backend\/tests\/Unit\/ContentPromotion\/(?:ArticleCms|Eq(?:English|ExistingPublic|NewSource|PublicArticle|SourceExecution)|Iq|Promotion)[^/]*Test\.php$/.test(file)
   || /^backend\/tests\/Feature\/(?:SEO\/SitemapSourceCache|Career\/PublicProjectionMigration)Test\.php$/.test(file)) required.add(file);
 }
 const control=paths.some(p=>/^\.github\/(?:workflows\/|trunk\/)/.test(p));
 const highRisk=paths.some(p=>/^backend\/(?:composer\.|database\/|bootstrap\/|app\/(?:Http\/Middleware|Policies|Providers)\/)/.test(p));
 return {php_required:required.size>0,php_files:[...required].sort().map(p=>p.slice(8)),workflow:control,authority:highRisk||paths.some(p=>/^backend\/content_/.test(p)),dependency:paths.some(p=>/^backend\/composer\./.test(p)),security:highRisk||scanPaths.some(path=>codeqlInput(path)||semgrepConfig(path)),semgrep:highRisk||scanPaths.some(semgrepConfig)};
}
// A run count is not a validation boundary: daily/skipped/failed runs can
// outnumber any fixed window while the last successful PHP proof stays valid.
export async function* nightlyRunHistory(listRuns,currentRunId) {
 for(let page=1;;page++) {
  const runs=await listRuns(page);
  if(!Array.isArray(runs))throw new Error('NIGHTLY_RUN_INVENTORY_HOLD');
  for(const run of runs)if(String(run.id)!==String(currentRunId))yield run;
  if(runs.length<100)return;
 }
}
export async function phpRepairBaseline(runs,listJobs,head,isAncestor) {
 for await(const run of runs) {
  if(run.status!=='completed'||!['success','failure'].includes(run.conclusion)||run.head_branch!=='main'||run.run_attempt!==1||!isAncestor(run.head_sha,head))continue;
  const jobs=await listJobs(run.id);
  if(jobs.some(job=>['Full PHPUnit regression and performance contracts','Focused PHPUnit regression and performance contracts'].includes(job.name)&&job.conclusion==='success'))return run.head_sha;
 }
 throw new Error('NIGHTLY_PHP_BASE_HOLD');
}
if(import.meta.url===`file://${process.argv[1]}`){
 const pushBase=process.env.PUSH_BEFORE,head=process.env.GITHUB_SHA;
 const api=path=>JSON.parse(execFileSync('gh',['api',`repos/${process.env.GITHUB_REPOSITORY}/${path}`],{encoding:'utf8',timeout:30000,maxBuffer:8*1024*1024}));
 if(![pushBase,head].every(s=>/^[a-f0-9]{40}$/.test(s??'')&&s!=='0'.repeat(40)))throw new Error('NIGHTLY_REPAIR_BASE_HOLD');
 if(spawnSync('git',['merge-base','--is-ancestor',pushBase,head],{stdio:'ignore'}).status!==0)throw new Error('NIGHTLY_PUSH_BASE_HOLD');
 const changed=(a,b)=>execFileSync('git',['diff','--name-only','-z',a,b]).toString().split('\0').filter(Boolean);
 const scanPaths=changed(pushBase,head);
 // Only a push with actual PHP/authority/dependency consumers repairs the
 // unresolved PHP union. JS-only scans must not repeatedly inherit that union.
 let base=pushBase,paths=scanPaths,plan=repairDomains(paths,process.cwd(),nightlyExecutionInputs(base,head));
 if(plan.php_required||plan.authority||plan.dependency) {
  const runs=nightlyRunHistory(page=>api(`actions/workflows/nightly.yml/runs?status=completed&per_page=100&page=${page}`).workflow_runs,process.env.GITHUB_RUN_ID);
  base=await phpRepairBaseline(runs,id=>{const result=api(`actions/runs/${id}/attempts/1/jobs?per_page=100`);if(result.total_count!==result.jobs?.length)throw new Error('NIGHTLY_JOB_INVENTORY_HOLD');return result.jobs;},head,(a,b)=>/^[a-f0-9]{40}$/.test(a??'')&&spawnSync('git',['merge-base','--is-ancestor',a,b],{stdio:'ignore'}).status===0);
  if(!/^[a-f0-9]{40}$/.test(base??''))throw new Error('NIGHTLY_REPAIR_BASE_HOLD');
  paths=changed(base,head);
  plan=repairDomains(paths,process.cwd(),nightlyExecutionInputs(base,head),scanPaths);
 }
 writeFileSync('nightly-repair.json',JSON.stringify({sha:head,base,push_base:pushBase,paths,scan_paths:scanPaths,...plan})+'\n');
 for(const key of ['php_required','workflow','authority','dependency','security','semgrep'])appendFileSync(process.env.GITHUB_OUTPUT,`${key}=${plan[key]}\n`);
 appendFileSync(process.env.GITHUB_OUTPUT,`php_files=${JSON.stringify(plan.php_files)}\n`);
}

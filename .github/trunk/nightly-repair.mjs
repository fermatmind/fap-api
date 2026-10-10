import {execFileSync,spawnSync} from 'node:child_process';
import {appendFileSync,writeFileSync} from 'node:fs';
import {phpConsumers,nightlyExecutionInputs,executionConsumes} from './impact-consumers.mjs';
export function repairDomains(paths,root=process.cwd(),executionInputs=[]) {
 const graph=phpConsumers(root),required=new Set(paths.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p)));
 for(const file of graph.files.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p))) if(paths.some(p=>graph.closure([file]).has(p))||executionConsumes(graph.sources.get(file),executionInputs)) required.add(file);
 // These runner guards can recover a publication and switch the release to LKG.
 // Revalidate the existing publication transactions and their derived-cache recovery,
 // including dynamic artisan callers that the PHP symbol graph cannot resolve.
 if(paths.some(p=>/^\.github\/trunk\/(?:iq-public-(?:article|scale)|iq-eq-topic|eq-(?:existing-public|new-source|new-english))-(?:publish|online-qa)\.mjs$/.test(p))) {
  for(const file of graph.files) if(/^backend\/tests\/Unit\/ContentPromotion\/(?:ArticleCms|Eq(?:English|ExistingPublic|NewSource|PublicArticle|SourceExecution)|Iq|Promotion)[^/]*Test\.php$/.test(file)
   || /^backend\/tests\/Feature\/(?:SEO\/SitemapSourceCache|Career\/PublicProjectionMigration)Test\.php$/.test(file)) required.add(file);
 }
 const control=paths.some(p=>/^\.github\/(?:workflows\/nightly\.yml|trunk\/)/.test(p));
 const highRisk=paths.some(p=>/^backend\/(?:composer\.|database\/|bootstrap\/|app\/(?:Http\/Middleware|Policies|Providers)\/)/.test(p));
 return {php_required:required.size>0,php_files:[...required].sort().map(p=>p.slice(8)),workflow:control,authority:highRisk||paths.some(p=>/^backend\/content_/.test(p)),dependency:paths.some(p=>/^backend\/composer\./.test(p)),security:highRisk};
}
export async function phpRepairBaseline(runs,listJobs,head,isAncestor) {
 for(const run of runs.slice(0,20)) {
  if(run.status!=='completed'||!['success','failure'].includes(run.conclusion)||run.head_branch!=='main'||run.run_attempt!==1||!isAncestor(run.head_sha,head))continue;
  const jobs=await listJobs(run.id);
  if(jobs.some(job=>['Full PHPUnit regression and performance contracts','Focused PHPUnit regression and performance contracts'].includes(job.name)&&job.conclusion==='success'))return run.head_sha;
 }
 throw new Error('NIGHTLY_PHP_BASE_HOLD');
}
if(import.meta.url===`file://${process.argv[1]}`){
 const pushBase=process.env.PUSH_BEFORE,head=process.env.GITHUB_SHA;
 const api=path=>JSON.parse(execFileSync('gh',['api',`repos/${process.env.GITHUB_REPOSITORY}/${path}`],{encoding:'utf8',timeout:30000,maxBuffer:8*1024*1024}));
 const runs=api('actions/workflows/nightly.yml/runs?status=completed&per_page=100').workflow_runs.filter(run=>String(run.id)!==process.env.GITHUB_RUN_ID);
 const base=await phpRepairBaseline(runs,id=>{const result=api(`actions/runs/${id}/attempts/1/jobs?per_page=100`);if(result.total_count!==result.jobs?.length)throw new Error('NIGHTLY_JOB_INVENTORY_HOLD');return result.jobs;},head,(a,b)=>/^[a-f0-9]{40}$/.test(a??'')&&spawnSync('git',['merge-base','--is-ancestor',a,b],{stdio:'ignore'}).status===0);

 if(![base,head].every(s=>/^[a-f0-9]{40}$/.test(s??'')&&s!=='0'.repeat(40)))throw new Error('NIGHTLY_REPAIR_BASE_HOLD');
 const paths=execFileSync('git',['diff','--name-only','-z',base,head]).toString().split('\0').filter(Boolean),plan=repairDomains(paths,process.cwd(),nightlyExecutionInputs(base,head));
 writeFileSync('nightly-repair.json',JSON.stringify({sha:head,base,push_base:pushBase,paths,...plan})+'\n');
 for(const key of ['php_required','workflow','authority','dependency','security'])appendFileSync(process.env.GITHUB_OUTPUT,`${key}=${plan[key]}\n`);
 appendFileSync(process.env.GITHUB_OUTPUT,`php_files=${JSON.stringify(plan.php_files)}\n`);
}

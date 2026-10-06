import {execFileSync} from 'node:child_process';
import {appendFileSync,writeFileSync} from 'node:fs';
import {phpConsumers} from './impact-consumers.mjs';
export function repairDomains(paths,root=process.cwd()) {
 const graph=phpConsumers(root),required=new Set(paths.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p)));
 for(const file of graph.files.filter(p=>/^backend\/tests\/.*Test\.php$/.test(p))) if(paths.some(p=>graph.closure([file]).has(p))) required.add(file);
 const control=paths.some(p=>/^\.github\/(?:workflows\/nightly\.yml|trunk\/)/.test(p));
 const highRisk=paths.some(p=>/^backend\/(?:composer\.|database\/|bootstrap\/|app\/(?:Http\/Middleware|Policies|Providers)\/)/.test(p));
 return {php_required:required.size>0,php_files:[...required].sort().map(p=>p.slice(8)),workflow:control,authority:highRisk||paths.some(p=>/^backend\/content_/.test(p)),dependency:paths.some(p=>/^backend\/composer\./.test(p)),security:highRisk};
}
if(import.meta.url===`file://${process.argv[1]}`){
 const base=process.env.PUSH_BEFORE,head=process.env.GITHUB_SHA;
 if(![base,head].every(s=>/^[a-f0-9]{40}$/.test(s??'')&&s!=='0'.repeat(40)))throw new Error('NIGHTLY_REPAIR_BASE_HOLD');
 const paths=execFileSync('git',['diff','--name-only','-z',base,head]).toString().split('\0').filter(Boolean),plan=repairDomains(paths);
 writeFileSync('nightly-repair.json',JSON.stringify({sha:head,base,paths,...plan})+'\n');
 for(const key of ['php_required','workflow','authority','dependency','security'])appendFileSync(process.env.GITHUB_OUTPUT,`${key}=${plan[key]}\n`);
 appendFileSync(process.env.GITHUB_OUTPUT,`php_files=${JSON.stringify(plan.php_files)}\n`);
}

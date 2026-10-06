import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
// Resolve actual PHP consumers through App symbols and literal configuration reads.
// Dynamic container bindings, routes, schema, bootstrap and dependency inputs are shared.
export function phpConsumers(root = process.cwd()) {
  root=execFileSync('git',['rev-parse','--show-toplevel'],{cwd:root}).toString().trim();
  const files = execFileSync('git', ['ls-files', '-z', 'backend/app', 'backend/config', 'backend/tests'], {cwd:root}).toString().split('\0').filter(p=>p.endsWith('.php'));
  const symbols = new Map(), sources = new Map();
  for (const path of files) {
    if (!existsSync(`${root}/${path}`)) continue;
    const body = readFileSync(`${root}/${path}`, 'utf8'); sources.set(path,body);
    const ns = /namespace\s+((?:App|Tests)\\[\w\\]+)\s*;/.exec(body)?.[1];
    const cls = /\b(?:class|interface|trait|enum)\s+(\w+)/.exec(body)?.[1];
    if (ns && cls) symbols.set(`${ns}\\${cls}`,path);
  }
  const byNamespace=new Map();
  for(const [symbol,file] of symbols){const ns=symbol.slice(0,symbol.lastIndexOf('\\')); const bucket=byNamespace.get(ns)??[];bucket.push([symbol,file]);byNamespace.set(ns,bucket);}
  const edges = new Map();
  for (const [path,body] of sources) {
    const imports = [...body.matchAll(/\b(?:App|Tests)\\[A-Za-z_][\w\\]+/g)].map(m=>symbols.get(m[0])).filter(Boolean);
    for (const group of body.matchAll(/use\s+((?:App|Tests)\\[\w\\]+)\{([^}]+)\}/g)) for(const member of group[2].split(',')) {
      const file=symbols.get(group[1]+member.trim().split(/\s+as\s+/)[0]);if(file)imports.push(file);
    }
    for(const match of body.matchAll(/(['"])((?:backend\/)?(?:app|config|resources|content_assets|content_packs|scripts|tests|\.github)\/[^'"\n]+)\1/g)) {
      const file=sources.has(match[2])?match[2]:'backend/'+match[2]; if(existsSync(`${root}/${file}`)) imports.push(file);
    }
    if (/\$this->(?:get|post|put|delete|patch)(?:Json)?\(/.test(body)) imports.push('backend/routes/api.php');
    const ns = /namespace\s+((?:App|Tests)\\[\w\\]+)\s*;/.exec(body)?.[1];
    for (const [symbol,file] of byNamespace.get(ns) ?? []) if (new RegExp(`\\b${symbol.split('\\').at(-1)}\\b`).test(body)) imports.push(file);
    for (const match of body.matchAll(/\bconfig\(\s*['"]([\w-]+)(?:\.[^'"]*)?['"]/g)) {
      const config=`backend/config/${match[1]}.php`; if(sources.has(config)) imports.push(config);
    }
    edges.set(path,[...new Set(imports)]);
  }
  const closure = roots => {
    const found = new Set(roots), pending=[...found];
    while(pending.length) for(const file of edges.get(pending.pop()) ?? []) if(!found.has(file)){found.add(file);pending.push(file);}
    return found;
  };
  const consumer = expressions => closure(files.filter(path=>expressions.some(re=>re.test(path))));
  return {files, sources, edges, closure, consumer};
}
export function selectOperations(paths, root=process.cwd()) {
  const graph=phpConsumers(root);
  const unknown=paths.some(p=>/^backend\/app\/.*\.php$/.test(p)&&!graph.sources.has(p));
  const shared=unknown||paths.some(p=>p==='deploy.php'||/^\.github\/(?:workflows\/|trunk\/(?:impact-consumers|classify-release|production-evidence|admit-release)\.mjs$)/.test(p))||paths.some(p=>/^backend\/(?:routes\/|bootstrap\/|database\/migrations\/|app\/(?:Http\/Middleware\/|Providers\/)|composer\.(?:json|lock)$|config\/(?:app|database|auth|cache|fap|content_packs)\.php$)/.test(p));
  const affected=(roots,inputs=[])=>shared || paths.some(p=>roots.has(p)||inputs.some(prefix=>p.startsWith(prefix)));
  const family={
    big5:graph.consumer([/\/(?:BigFivePrivateResult(?:CompileService|PackLoader)|BigFiveContentCompileService)\.php$/, /\/Services\/(?:Report|Content)\/[^/]*(?:BigFive|Big5)[^/]*\.php$/]),
    riasec:graph.consumer([/\/(?:RiasecPrivateResultCompileService|RiasecPackLoader)\.php$/, /\/Services\/(?:Report|Content)\/[^/]*Riasec[^/]*\.php$/]),
    enneagram:graph.consumer([/\/Services\/(?:Report|Content)\/[^/]*Enneagram[^/]*\.php$/]),
    eq60:graph.consumer([/\/Services\/(?:Report|Content)\/[^/]*Eq60[^/]*\.php$/]),
  };
  const publisher=graph.consumer([/\/Console\/Commands\/Packs2?Publish\.php$/, /\/Services\/Content\/ContentPackV2(?:Resolver|Materializer|RuntimeTruthService)\.php$/]);
  const familyInputs=['backend/content_packs/BIG5_OCEAN/','backend/content_packs/RIASEC/','backend/content_assets/riasec/','backend/content_packs/ENNEAGRAM/','backend/content_packs/EQ_60/'];
  const commonPublisher=shared||paths.some(p=>!familyInputs.some(prefix=>p.startsWith(prefix))&&(publisher.has(p)||p.startsWith('backend/scripts/content_packs/')||p.startsWith('backend/app/Services/Content/ContentPack')))
  const input={big5:['backend/content_packs/BIG5_OCEAN/'],riasec:['backend/content_assets/riasec/','backend/content_packs/RIASEC/'],enneagram:['backend/content_packs/ENNEAGRAM/'],eq60:['backend/content_packs/EQ_60/']};
  const operations={};
  for(const [name,roots] of Object.entries(family)) operations[`${name}_private_publish`]=commonPublisher||affected(roots,input[name]);
  const career=graph.consumer([/\/Domain\/Career\/(?:Display|Compilation)\//,/\/Services\/Career\//]);
  const url=graph.consumer([/\/Services\/(?:SeoIntel\/UrlTruth|SeoIntel\/Sitemap|SEO\/Sitemap)/,/\/Http\/Controllers\/API\/V0_5\/SEO\/SitemapSourceController\.php$/,/\/Listeners\/QueueUrlTruthIncrementalSync\.php$/]);
  operations.career_cache=affected(career,['backend/content_assets/career/current/']);
  operations.url_truth=affected(url,['backend/content_assets/personality_public/current/','backend/content_assets/career/current/','backend/routes/','backend/app/Policies/','backend/app/Http/Controllers/API/V0_5/Cms/']);
  operations.scales_seed=shared||paths.some(p=>/^backend\/(?:database\/(?:seeders|seed_data)|app\/Console\/Commands\/.*ScalesSeed)/.test(p));
  operations.big5_tests=affected(graph.consumer([/\/(?:Big5|BigFive|NonMbtiReport)[^/]*\.php$/]),input.big5);
  operations.mbti_modes=affected(graph.consumer([/\/(?:Mbti|MBTI)[^/]*\.php$/]),['content_packages/default/','backend/content_packs/MBTI/'])||paths.some(p=>/Mbti|MBTI/.test(p)&&/tests\//.test(p));
  operations.personality_manifest=affected(graph.consumer([/\/Domain\/Personality\/Current\//]),['backend/content_assets/personality_public/current/']);
  const featureInputs=graph.closure([...graph.sources].filter(([,body])=>/FEATURE_(?:SELFCHECK_V2|LEGACY_MBTI_REPORT_PAYLOAD_V2|PAYMENT_WEBHOOK_V2|CONTENT_STORE_V2)|feature_(?:selfcheck_v2|legacy_mbti_report_payload_v2|payment_webhook_v2|content_store_v2)/i.test(body)).map(([path])=>path));
  operations.test_modes=operations.mbti_modes||affected(featureInputs,['backend/app/Services/Commerce/'])?['legacy','v2']:['legacy'];
  const packInputs=['content_packages/','backend/content_packs/','backend/content_assets/riasec/'];
  const packConsumers=graph.consumer([/\/Services\/Content\/(?:Content(?:Compile|Lint|PacksIndex|PackV2)[^/]*|(?:BigFive|Riasec|Enneagram|Eq60)[^/]*)\.php$/]);
  operations.content_pack_checks=unknown||paths.some(p=>packInputs.some(prefix=>p.startsWith(prefix))||packConsumers.has(p));
  const intersects=(deps,p)=>deps.has(p)||[...deps].some(input=>!input.endsWith('.php')&&p.startsWith(input.replace(/\/$/,'')+'/'));
  const selected=graph.files.filter(p=>/Test\.php$/.test(p)&&paths.some(input=>intersects(graph.closure([p]),input)));
  if(operations.content_pack_checks && !selected.length) {
    // Unknown/dynamic pack readers retain the existing shared compiler/index contracts.
    selected.push(...graph.files.filter(p=>/\/(?:ContentPacksIndexArtifact|ContentPackLint)Test\.php$/.test(p)));
  }
  operations.content_test_files=selected.filter(p=>/\/(?:Content|ContentPacks|ClinicalCombo68|Riasec|Enneagram|BigFive|Report)\//.test(p)||/Content(?:Compile|Lint|Pack)/.test(graph.sources.get(p)??'')).map(p=>p.slice(8)).sort();
  if(operations.content_pack_checks && !operations.content_test_files.length) throw new Error('CONTENT_CONSUMER_SELECTION_EMPTY');
  return operations;
}

// Workflow environment and checkout inputs are consumed by PHP topology/history tests.
export function nightlyExecutionInputs(base,head,root=process.cwd()) {
 const patch=execFileSync('git',['diff','--unified=0',base,head,'--','.github/workflows/nightly.yml'],{cwd:root}).toString();
 const inputs=new Set();
 for(const line of patch.split('\n')) {
  if(!/^[+-](?![+-])/.test(line))continue;
  const key=/^[+-]\s+((?:SEO_TEST_MYSQL|RUN_DELIVERY_REDIS|REDIS)_[A-Z0-9_]+):/.exec(line)?.[1];
  if(key)inputs.add(key);
  if(/^[+-]\s+fetch-depth:/.test(line))inputs.add('git_history');
  if(/^[+-]\s+(?:DB_[A-Z_]+|MYSQL_DATABASE|php-version|extensions):/.test(line))inputs.add('*');
 }
 return [...inputs];
}

export function executionConsumes(body,inputs) {
 return inputs.some(key=>key==='*'||(key==='git_history'?/['"]git['"]\s*,\s*['"](?:show|log|diff|rev-list|merge-base|cat-file)['"]|\bgit\s+(?:show|log|diff|rev-list|merge-base|cat-file)\b|\bgit\s*\(\s*['"](?:show|log|diff|rev-list|merge-base|cat-file)['"]/.test(body??''):body?.includes(key)));
}

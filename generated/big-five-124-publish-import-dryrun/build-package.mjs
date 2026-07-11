import fs from 'node:fs'; import path from 'node:path';
const here=path.dirname(new URL(import.meta.url).pathname); const root=path.resolve(here,'../..');
const targetPath=path.join(here,'big_five_124_merged_v1_seed.json'); const target=JSON.parse(fs.readFileSync(targetPath,'utf8'));
const packageFiles=['openness','conscientiousness','extraversion','agreeableness','neuroticism'].map(domain=>path.join(root,`generated/big-five-en-facet-${domain}-content-package/big_five_en_facet_${domain}_seed.json`));
packageFiles.push(path.join(root,'generated/big-five-en-facet-hub-content-package/big_five_en_facet_hub_seed.json'));
packageFiles.push(path.join(root,'generated/big-five-en-legacy-polarity-content-package/big_five_en_legacy_polarity_10_seed.json'));
const replacements=new Map();
for(const file of packageFiles){const pkg=JSON.parse(fs.readFileSync(file,'utf8')); for(const asset of pkg.assets) replacements.set(`${asset.locale}:${asset.entity_type}:${asset.entity_key}`,asset);}
const polished=JSON.parse(fs.readFileSync(path.join(root,'generated/big-five-content-polish/cms-import-draft.polished.json'),'utf8'));
for(const locale of ['zh-CN','en-US']){const row=polished.find(x=>x.slug==='big-five'&&x.locale===locale); if(!row) throw new Error(`missing ${locale} hub source`); const key=`${locale==='en-US'?'en':locale}:hub:big-five`; const current=target.assets.find(a=>`${a.locale}:${a.entity_type}:${a.entity_key}`===key); replacements.set(key,{...current,sections:row.body_sections.map((s,i)=>({key:`section_${i+1}`,title:s.heading,body_md:s.body})),faq:row.faq,seo:row.seo,internal_links:row.internal_links.map((href,i)=>({label:`Related Big Five resource ${i+1}`,href,relationship:'related'}))});}
target.assets=target.assets.map(asset=>{const replacement=replacements.get(`${asset.locale}:${asset.entity_type}:${asset.entity_key}`)??asset; const {content_sections,...clean}=replacement; return clean;});
target.package='big-five-124-publish-import-dryrun-2026-07-11'; target.contract_version='personality_public_asset.v1';
fs.writeFileSync(targetPath,`${JSON.stringify(target,null,2)}\n`);
const aliases=new Set(['emotional-stability','high-agreeableness','high-conscientiousness','high-extraversion','high-neuroticism','high-openness','low-agreeableness','low-conscientiousness','low-extraversion','low-openness']);
const canonical=target.assets.filter(a=>!(a.locale==='zh-CN'&&aliases.has(a.entity_key))); const checks={exact_124:target.assets.length===124,locale_62_each:['zh-CN','en'].every(l=>target.assets.filter(a=>a.locale===l).length===62),identity_unique:new Set(target.assets.map(a=>`${a.locale}:${a.entity_type}:${a.entity_key}`)).size===124,canonical_114:canonical.length===114,redirect_alias_10:target.assets.length-canonical.length===10,canonical_body_md:canonical.every(a=>Array.isArray(a.sections)&&a.sections.length>0&&a.sections.every(s=>typeof s.body_md==='string'&&s.body_md.trim()!=='')),no_bodyMd:!JSON.stringify(target).includes('bodyMd')};
fs.writeFileSync(path.join(here,'qa_report.json'),`${JSON.stringify({status:Object.values(checks).every(Boolean)?'pass':'fail',checks},null,2)}\n`);
if(Object.values(checks).some(v=>!v)) throw new Error(JSON.stringify(checks));

import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { readCandidateRows } from './iq-eq-topic-package.mjs';
import { readOnlinePrestate, verifyOnlineCandidates } from './iq-eq-topic-online-qa.mjs';
const root=fileURLToPath(new URL('../../backend/', import.meta.url));
const rows=readCandidateRows(root);
const esc=value=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
function dom(row){
 const text=row.snapshot.section_candidates[0].body_md.replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/[*`_]/g,'').replace(/^\s*(?:>\s*)+/gm,'').replace(/^\s*(?:#{1,6}\s+|[-+]\s+|[0-9]+[.)]\s+)/gm,'');
 const links=[...row.snapshot.section_candidates[0].body_md.matchAll(/\[[^\]]+\]\(([^)]+)\)/g)].map(m=>m[1]);
 links.push(...[...row.snapshot.section_candidates[0].body_md.matchAll(/\/(?:zh|en)\/[a-z0-9][a-z0-9/-]*/g)].map(m=>m[0]));
 const faqs=row.snapshot.section_candidates[1].payload_json.items;const path=`/${row.identity.locale==='en'?'en':'zh'}/topics/iq-eq`;
 return `<html><head><meta charset="utf-8"><title>${esc(row.snapshot.seo_text_candidate.title)}</title><meta name="description" content="${esc(row.snapshot.seo_text_candidate.description)}"><meta name="robots" content="noindex,nofollow,noarchive,nocache"><link rel="canonical" href="https://fermatmind.com${path}"></head><body><h1>${esc(row.snapshot.profile_patch.title)}</h1><div id="overview">${esc(text)}${links.map(href=>`<a href="${esc(href)}">${esc(href)}</a>`).join('')}</div><div id="faq"><dl>${faqs.map(faq=>`<div><dt>${esc(faq.question)}</dt><dd>${esc(faq.answer)}</dd></div>`).join('')}</dl></div></body></html>`;
}
function fetcher(mutate=()=>{}, robots=null){return async(value,init)=>{
 assert.equal(init.redirect,'error');const u=new URL(value);
 if(u.hostname==='fermatmind.com')return new Response('<html/>',{headers:{'content-type':'text/html',...(robots?{'x-robots-tag':robots}:{})}});
 const row=rows.find(row=>row.identity.locale===u.searchParams.get('locale'));assert.ok(row);
 const seo={...row.snapshot.seo_text_candidate,canonical:`https://fermatmind.com/${row.identity.locale==='en'?'en':'zh'}/topics/iq-eq`,robots:'noindex,follow',og:{...row.snapshot.seo_text_candidate},twitter:{...row.snapshot.seo_text_candidate}};
 const payload=u.pathname.endsWith('/seo')?{meta:seo}:{ok:true,profile:{id:row.identity.locale==='en'?2:5,...row.identity,...row.snapshot.profile_patch,status:'published',is_public:true,is_indexable:false},sections:structuredClone(row.snapshot.section_candidates).map(section=>({...section,body_html:null})),entry_groups:Object.fromEntries(row.snapshot.entry_excerpt_overrides.map(entry=>[entry.group_key,[{entry_type:entry.entry_type,target_key:entry.target_key,url:entry.expected_url,excerpt:entry.excerpt_override}]])),answer_surface_v1:{faq_blocks:row.snapshot.section_candidates[1].payload_json.items}};
 mutate(payload,row,u);return new Response(JSON.stringify(payload),{headers:{'content-type':'application/json'}});
};}
const renderer=value=>dom(rows.find(row=>new URL(value).pathname.startsWith(row.identity.locale==='en'?'/en/':'/zh/')));
test('both Topic API, SEO, overview and seven visible FAQ definitions are read',async()=>{
 const pre=await readOnlinePrestate('production',root,fetcher());const result=await verifyOnlineCandidates('production',root,fetcher(),renderer,pre);
 assert.deepEqual(result,{environment:'production',api_readback_count:2,seo_readback_count:2,ssr_readback_count:2});
});
test('JSON object key order preserves FAQ semantics while list order remains authority',async()=>{
 const pre=await readOnlinePrestate('production',root,fetcher());
 await verifyOnlineCandidates('production',root,fetcher(payload=>{if(payload.sections)payload.sections[1].payload_json.items=payload.sections[1].payload_json.items.map(faq=>({answer:faq.answer,question:faq.question}));}),renderer,pre);
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(payload=>{if(payload.sections)payload.sections[1].payload_json.items=[...payload.sections[1].payload_json.items].reverse();}),renderer,pre),/FAQ_DRIFT/);
});
test('last locale authority, retained qualification, excerpt and FAQ drift fail',async()=>{
 const pre=await readOnlinePrestate('production',root,fetcher());
 for(const field of ['qualification','faq','entry','copy'])await assert.rejects(verifyOnlineCandidates('production',root,fetcher((payload,row)=>{
 if(row.identity.locale!=='en'||!payload.profile)return;
 if(field==='qualification')payload.profile.is_indexable=true;
 if(field==='faq')payload.answer_surface_v1.faq_blocks=payload.answer_surface_v1.faq_blocks.slice(0,6);
 if(field==='entry')payload.entry_groups.tests[0].excerpt='Old';
 if(field==='copy')payload.profile.title='Old';
 }),renderer,pre),/IQ_EQ_TOPIC_ONLINE_/);
});
test('hidden or missing last FAQ and crawler-specific extra restrictions cannot pass',async()=>{
 const pre=await readOnlinePrestate('production',root,fetcher());
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(),value=>renderer(value).replace('id="faq"','id="faq" hidden'),pre),/VISIBLE_FAQ_DRIFT/);
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(),value=>renderer(value).replace(/<div><dt>[^<]*<\/dt><dd>[^<]*<\/dd><\/div><\/dl>/,'</dl>'),pre),/VISIBLE_FAQ_DRIFT/);
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(undefined,'googlebot: noimageindex'),renderer,pre),/ROBOTS_MISMATCH/);
});

test('Topic API and SSR canonical reject another HTTPS port',async()=>{
 const pre=await readOnlinePrestate('production',root,fetcher());
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(payload=>{if(payload.meta)payload.meta.canonical=payload.meta.canonical.replace('fermatmind.com/','fermatmind.com:8443/');}),renderer,pre),/CANONICAL_DRIFT/);
 await assert.rejects(verifyOnlineCandidates('production',root,fetcher(),value=>renderer(value).replace('href="https://fermatmind.com/','href="https://fermatmind.com:8443/'),pre),/SSR_CANONICAL_MISMATCH/);
});

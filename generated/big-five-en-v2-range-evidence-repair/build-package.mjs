import fs from 'node:fs';
import path from 'node:path';
const here=path.dirname(new URL(import.meta.url).pathname);
const ledger=JSON.parse(fs.readFileSync(path.join(here,'source_ledger.json'),'utf8'));
if(ledger.items.length!==15) throw new Error('expected 15 source rows');
const assets=ledger.items.map(({id,org_id,published_at,updated_at,source_hash,...asset})=>({
  ...asset,
  source_package:'big-five-en-v2-range-evidence-repair.v1',
  evidence_notes:[
    {source:'Soto & John (2017), BFI-2',source_type:'peer_reviewed_measure_development',claim:'Five-factor trait-range language is descriptive and non-diagnostic.',limitations:'Does not establish individual clinical, hiring, or predictive conclusions.'},
    {source:'John, Naumann & Soto (2008), Big Five trait taxonomy chapter',source_type:'scholarly_review',claim:'Big Five domains summarize broad patterns rather than fixed identities or value judgments.',limitations:'Public explanatory copy is not a personalized assessment interpretation.'},
    {source:'BIG5-124-PUBLISH-READINESS-AUDIT-01',source_type:'internal_production_baseline',claim:'This repair adds provenance notes only; the existing content, links, canonical, and noindex gates are retained.',limitations:'No CMS write or runtime release is performed by this package.'}
  ]
}));
fs.writeFileSync(path.join(here,'big_five_en_v2_range_evidence_repair_15_seed.json'),`${JSON.stringify({package:'big-five-en-v2-range-evidence-repair-2026-07-11',contract_version:'personality_public_asset.v1',assets},null,2)}\n`);
const checks={exact_15:assets.length===15,all_have_three_evidence_notes:assets.every(a=>a.evidence_notes.length===3),content_ready_noindex_preserved:assets.every(a=>a.launch_state==='content_ready'&&a.robots==='noindex,follow'&&!a.index_eligible&&!a.sitemap_eligible&&!a.llms_eligible),identity_unique:new Set(assets.map(a=>`${a.locale}:${a.entity_type}:${a.entity_key}`)).size===15,no_bodyMd:!JSON.stringify(assets).includes('bodyMd')};
fs.writeFileSync(path.join(here,'qa_report.json'),`${JSON.stringify({status:Object.values(checks).every(Boolean)?'pass':'fail',row_count:15,checks},null,2)}\n`);

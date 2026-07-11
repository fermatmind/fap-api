import fs from 'node:fs';
import path from 'node:path';
const here = path.dirname(new URL(import.meta.url).pathname);
const rows = [
  ['high-agreeableness','agreeableness','high'], ['low-agreeableness','agreeableness','low'],
  ['high-conscientiousness','conscientiousness','high'], ['low-conscientiousness','conscientiousness','low'],
  ['high-extraversion','extraversion','high'], ['low-extraversion','extraversion','low'],
  ['high-neuroticism','neuroticism','high'], ['emotional-stability','neuroticism','low'],
  ['high-openness','openness','high'], ['low-openness','openness','low'],
];
const label = { agreeableness:'Agreeableness', conscientiousness:'Conscientiousness', extraversion:'Extraversion', neuroticism:'Neuroticism', openness:'Openness' };
const link = (label, href, relationship) => ({ label, href, relationship });
const assets = rows.map(([entity_key, domain, level]) => {
  const canonical = `/en/personality/big-five/${entity_key}`;
  const v2 = `/en/personality/big-five/${domain}-${level}`;
  const opposite = `/en/personality/big-five/${domain}-${level === 'high' ? 'low' : 'high'}`;
  return {
    contract_version:'personality_public_asset.v1', framework:'big_five', entity_type:'polarity', entity_key, code:entity_key,
    slug:`big-five/${entity_key}`, locale:'en', canonical:{path:canonical}, canonical_path:canonical,
    hreflang:{ en:canonical, 'zh-CN':canonical.replace('/en/','/zh/') },
    internal_links:[
      link('Big Five personality','/en/personality/big-five','framework_hub'),
      link(label[domain],`/en/personality/big-five/${domain}`,'parent_domain'),
      link(`${label[domain]} ${level} range`,v2,'v2_range'),
      link(`Opposite ${label[domain]} range`,opposite,'paired_range'),
      link('How to read Big Five results','/en/personality/big-five/how-to-read-big-five-results','reading_guide'),
      link('Big Five score ranges','/en/personality/big-five/big-five-score-ranges','score_guide'),
      link('Big Five 30-day review','/en/personality/big-five/big-five-30-day-review','action_guide'),
    ],
    launch_state:'content_ready', review_state:'codex_repaired_ready', robots:'noindex,follow', is_public:true,
    index_eligible:false, sitemap_eligible:false, llms_eligible:false,
    source_package:'big-five-en-legacy-polarity-link-repair.v1',
    evidence_notes:[{source:'BIG5-124-PUBLISH-READINESS-AUDIT-01',source_type:'production_gap_baseline',repair_scope:'internal_links_only'}],
  };
});
const output = { package:'big-five-en-legacy-polarity-link-repair-2026-07-11', contract_version:'personality_public_asset.v1', assets };
fs.writeFileSync(path.join(here,'big_five_en_legacy_polarity_link_repair_10_seed.json'),`${JSON.stringify(output,null,2)}\n`);
const checks = {
  exact_10: assets.length===10,
  exact_legacy_identity_set: new Set(assets.map(a=>a.entity_key)).size===10 && assets.some(a=>a.entity_key==='emotional-stability'),
  seven_unique_english_links: assets.every(a=>a.internal_links.length===7 && new Set(a.internal_links.map(l=>l.href)).size===7 && a.internal_links.every(l=>l.href.startsWith('/en/'))),
  v2_range_target_present: assets.every(a=>a.internal_links.some(l=>l.relationship==='v2_range')),
  content_ready_noindex_preserved: assets.every(a=>a.launch_state==='content_ready' && a.robots==='noindex,follow' && !a.index_eligible && !a.sitemap_eligible && !a.llms_eligible),
  no_bodyMd: !JSON.stringify(assets).includes('bodyMd'),
};
fs.writeFileSync(path.join(here,'qa_report.json'),`${JSON.stringify({status:Object.values(checks).every(Boolean)?'pass':'fail',row_count:10,checks},null,2)}\n`);

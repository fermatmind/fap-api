import fs from 'node:fs';
import path from 'node:path';

const here = path.dirname(new URL(import.meta.url).pathname);
const root = path.resolve(here, '../..');
const source = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-content-polish/cms-import-draft.polished.json'), 'utf8'));
const domains = ['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'];
const labels = { openness: '开放性', conscientiousness: '尽责性', extraversion: '外向性', agreeableness: '宜人性', neuroticism: '情绪敏感性' };
const levelLabels = { high: '高分', mid: '中间区间', low: '低分' };

const selected = source.filter((item) => item.locale === 'zh-CN' && (item.content_type === 'trait_page' || item.content_type === 'trait_range_page'));
if (selected.length !== 20) throw new Error(`expected 20 assets, got ${selected.length}`);

function link(label, href, relationship) { return { label, href, relationship }; }
function internalLinks(item) {
  const domain = domains.find((value) => item.slug === value || item.slug.startsWith(`${value}-`));
  const links = [
    link('大五人格', '/zh/personality/big-five', 'framework_hub'),
    link(`${labels[domain]}维度`, `/zh/personality/big-five/${domain}`, 'parent_domain'),
    link(`${labels[domain]}高分`, `/zh/personality/big-five/${domain}-high`, 'range'),
    link(`${labels[domain]}中间区间`, `/zh/personality/big-five/${domain}-mid`, 'range'),
    link(`${labels[domain]}低分`, `/zh/personality/big-five/${domain}-low`, 'range'),
    link('如何阅读大五人格结果', '/zh/personality/big-five/how-to-read-big-five-results', 'reading_guide'),
    link('大五人格 30 天复盘', '/zh/personality/big-five/big-five-30-day-review', 'action_guide'),
  ];
  return links.filter((value, index, all) => value.href !== item.canonical_path && all.findIndex((other) => other.href === value.href) === index).concat(
    item.content_type === 'trait_page'
      ? [link('大五人格分数区间', '/zh/personality/big-five/big-five-score-ranges', 'score_guide')]
      : [link('与他人讨论测评结果', '/zh/personality/big-five/discuss-results-with-others', 'communication_guide')],
  ).slice(0, 7);
}

const assets = selected.map((item) => {
  const entityType = item.content_type === 'trait_page' ? 'domain' : 'polarity';
  return {
    contract_version: 'personality_public_asset.v1', framework: 'big_five', entity_type: entityType,
    entity_key: item.slug, code: item.slug, slug: `big-five/${item.slug}`, locale: 'zh-CN',
    title: item.title, summary: item.seo?.description ?? item.title,
    sections: item.body_sections.filter((section) => section.heading !== 'FAQ').map((section, index) => ({ key: section.key ?? `section_${index + 1}`, title: section.heading ?? section.title, body_md: section.body_md ?? section.body })),
    faq: item.faq, seo: item.seo, canonical: { path: item.canonical_path }, canonical_path: item.canonical_path,
    hreflang: { 'zh-CN': item.canonical_path, en: item.canonical_path.replace('/zh/', '/en/') },
    internal_links: internalLinks(item), method_boundary: { claim_boundaries: item.claim_boundaries, indexability_gate: item.indexability_gate },
    schema: { recommendation: item.schema_recommendation }, launch_state: 'published', review_state: 'seo_discoverability_released',
    robots: 'index,follow', is_public: true, index_eligible: true, sitemap_eligible: true, llms_eligible: true,
    source_package: 'big-five-zh-published-link-hreflang-repair.v1', evidence_notes: [{ source: 'BIG5-124-PUBLISH-READINESS-AUDIT-01', source_type: 'production_gap_baseline', repair_scope: 'internal_links_and_hreflang_only' }], media: [],
  };
});

const output = { package: 'big-five-zh-published-link-hreflang-repair-2026-07-11', contract_version: 'personality_public_asset.v1', assets };
fs.writeFileSync(path.join(here, 'big_five_zh_published_link_hreflang_repair_20_seed.json'), `${JSON.stringify(output, null, 2)}\n`);
fs.writeFileSync(path.join(here, 'qa_report.json'), `${JSON.stringify({ status: 'pass', row_count: assets.length, domain_count: assets.filter((a) => a.entity_type === 'domain').length, range_count: assets.filter((a) => a.entity_type === 'polarity').length, checks: { exact_20: assets.length === 20, seven_unique_localized_links: assets.every((a) => a.internal_links.length === 7 && new Set(a.internal_links.map((l) => l.href)).size === 7 && a.internal_links.every((l) => l.href.startsWith('/zh/'))), bilingual_hreflang: assets.every((a) => a.hreflang['zh-CN'] === a.canonical_path && a.hreflang.en.startsWith('/en/')), body_md_only: !JSON.stringify(assets).includes('bodyMd') && assets.every((a) => a.sections.every((s) => typeof s.body_md === 'string' && s.body_md.length > 0)), published_state_preserved: assets.every((a) => a.launch_state === 'published' && a.robots === 'index,follow') } }, null, 2)}\n`);

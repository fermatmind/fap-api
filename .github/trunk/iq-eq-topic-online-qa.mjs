import { canonical } from './iq-public-scale-package.mjs';
import { readCandidateRows } from './iq-eq-topic-package.mjs';
import { articleDom, assertSsrCandidate, renderPage } from './iq-public-article-online-qa.mjs';

const hosts = environment => {
  if (!['staging', 'production'].includes(environment)) throw new Error('IQ_EQ_TOPIC_ONLINE_ENV_INVALID');
  return environment === 'staging' ? ['https://staging-api.fermatmind.com', 'https://staging.fermatmind.com'] : ['https://api.fermatmind.com', 'https://fermatmind.com'];
};
const equal = (a, b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const normalized = value => String(value).replace(/\s+/gu, ' ').trim();
async function read(url, type, fetcher) {
  const response = await fetcher(url, { redirect: 'error', signal: AbortSignal.timeout(15000), headers: { 'Cache-Control': 'no-cache' } });
  if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('IQ_EQ_TOPIC_ONLINE_RESPONSE_INVALID');
  const bytes = await response.text();
  if (bytes.length > 2000000) throw new Error('IQ_EQ_TOPIC_ONLINE_PAYLOAD_LIMIT');
  return { bytes, robots: response.headers.get('x-robots-tag') };
}
export async function readOnlinePrestate(environment, backendRoot, fetcher = fetch) {
  const [api] = hosts(environment); const state = {};
  for (const row of readCandidateRows(backendRoot)) {
    const { bytes } = await read(`${api}/api/v0.5/topics/iq-eq?locale=${row.identity.locale}&org_id=0`, 'application/json', fetcher);
    const payload = JSON.parse(bytes); const profile = payload.profile;
    if (payload.ok !== true || profile?.org_id !== 0 || profile?.slug !== 'iq-eq' || profile?.locale !== row.identity.locale
      || profile?.status !== 'published' || profile?.is_public !== true || typeof profile?.is_indexable !== 'boolean') throw new Error('IQ_EQ_TOPIC_ONLINE_PRESTATE_INVALID');
    state[row.identity.locale] = { id: profile.id, is_indexable: profile.is_indexable };
  }
  return state;
}
export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch, renderer = renderPage, prestate) {
  const [api, web] = hosts(environment);
  if (!prestate) throw new Error('IQ_EQ_TOPIC_ONLINE_PRESTATE_REQUIRED');
  for (const row of readCandidateRows(backendRoot)) {
    const query = `?locale=${row.identity.locale}&org_id=0`;
    const payload = JSON.parse((await read(`${api}/api/v0.5/topics/iq-eq${query}`, 'application/json', fetcher)).bytes);
    const profile = payload.profile; const before = prestate[row.identity.locale];
    if (payload.ok !== true || profile?.org_id !== 0 || profile?.locale !== row.identity.locale || profile?.slug !== 'iq-eq'
      || profile?.status !== 'published' || profile?.is_public !== true || !before || profile?.id !== before.id || profile?.is_indexable !== before.is_indexable) throw new Error('IQ_EQ_TOPIC_ONLINE_AUTHORITY_DRIFT');
    for (const [field, value] of Object.entries(row.snapshot.profile_patch)) if (profile[field] !== value) throw new Error('IQ_EQ_TOPIC_ONLINE_PROFILE_DRIFT');
    for (const candidate of row.snapshot.section_candidates) {
      const sections = payload.sections?.filter(section => section.section_key === candidate.section_key);
      if (sections?.length !== 1 || sections[0].render_variant !== candidate.render_variant || (sections[0].body_html ?? null) !== null) throw new Error('IQ_EQ_TOPIC_ONLINE_SECTION_DRIFT');
      if (candidate.section_key === 'overview' && sections[0].body_md !== candidate.body_md) throw new Error('IQ_EQ_TOPIC_ONLINE_BODY_DRIFT');
      if (candidate.section_key === 'faq' && !equal(sections[0].payload_json?.items, candidate.payload_json.items)) throw new Error('IQ_EQ_TOPIC_ONLINE_FAQ_DRIFT');
    }
    const faqs = row.snapshot.section_candidates[1].payload_json.items;
    if (!equal(payload.answer_surface_v1?.faq_blocks?.map(item => ({question:item.question, answer:item.answer})), faqs)) throw new Error('IQ_EQ_TOPIC_ONLINE_FAQ_DRIFT');
    for (const candidate of row.snapshot.entry_excerpt_overrides) {
      const matches = payload.entry_groups?.[candidate.group_key]?.filter(entry => entry.entry_type === candidate.entry_type && entry.target_key === candidate.target_key);
      if (matches?.length !== 1 || matches[0].excerpt !== candidate.excerpt_override || matches[0].url !== candidate.expected_url) throw new Error('IQ_EQ_TOPIC_ONLINE_ENTRY_DRIFT');
    }
    const seo = JSON.parse((await read(`${api}/api/v0.5/topics/iq-eq/seo${query}`, 'application/json', fetcher)).bytes).meta;
    for (const key of ['title','description']) if (seo?.[key] !== row.snapshot.seo_text_candidate[key]
      || seo?.og?.[key] !== row.snapshot.seo_text_candidate[key] || seo?.twitter?.[key] !== row.snapshot.seo_text_candidate[key]) throw new Error('IQ_EQ_TOPIC_ONLINE_METADATA_DRIFT');
    const path = `/${row.identity.locale === 'en' ? 'en' : 'zh'}/topics/iq-eq`;
    let canonicalUrl;
    try { canonicalUrl = new URL(seo.canonical); } catch { throw new Error('IQ_EQ_TOPIC_ONLINE_CANONICAL_DRIFT'); }
    const allowed = environment === 'staging' ? ['fermatmind.com', 'www.fermatmind.com', 'staging.fermatmind.com'] : ['fermatmind.com', 'www.fermatmind.com'];
    if (canonicalUrl.protocol !== 'https:' || !allowed.includes(canonicalUrl.hostname) || canonicalUrl.pathname !== path
      || canonicalUrl.port || canonicalUrl.search || canonicalUrl.hash || canonicalUrl.username || canonicalUrl.password) throw new Error('IQ_EQ_TOPIC_ONLINE_CANONICAL_DRIFT');
    const response = await read(web+path, 'text/html', fetcher);
    const verifyDOM = html => {
      assertSsrCandidate(html, { identity:row.identity, snapshot:{ title:row.snapshot.profile_patch.title, content_md:row.snapshot.section_candidates[0].body_md,
        seo_title:row.snapshot.seo_text_candidate.title, seo_description:row.snapshot.seo_text_candidate.description } }, seo, environment, response.robots, 'topic');
      const dom = articleDom(html, 'topic_faq');
      if (dom.faq_questions.length !== 7 || dom.faq_answers.length !== 7 || faqs.some((faq,index) => normalized(dom.faq_questions[index]) !== normalized(faq.question)
        || normalized(dom.faq_answers[index]) !== normalized(faq.answer))) throw new Error('IQ_EQ_TOPIC_ONLINE_VISIBLE_FAQ_DRIFT');
    };
    verifyDOM(await renderer(web+path, { surface: 'topic', verifyDOM }));
  }
  return { environment, api_readback_count:2, seo_readback_count:2, ssr_readback_count:2 };
}

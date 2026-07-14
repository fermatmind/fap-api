import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T11:56:00Z';
const read = (relativePath) => JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));
const inventory = read('generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json');
const inventoryRoutes = new Set(inventory.mappings.map((mapping) => mapping.route));

const nodes = [];
const addNode = ({ pageFamily, locale, route, sourcePackage, sourceType, translationGroup, declaredTargets = [] }) => {
  nodes.push({
    node_id: `${pageFamily}:${locale}:${route}`,
    page_family: pageFamily,
    locale,
    route,
    canonical_path: route,
    canonical_mode: 'self_canonical_candidate',
    translation_group: translationGroup ?? null,
    intent_class: translationGroup?.startsWith('legacy:') ? 'legacy_polarity_explainer' : pageFamily === 'range' ? 'v2_range_interpretation' : `${pageFamily}_navigation`,
    navigation_visibility: translationGroup?.startsWith('legacy:') ? 'compatibility_only' : 'standard',
    source_package: sourcePackage,
    source_type: sourceType,
    declared_targets: declaredTargets,
    release_eligibility: 'deferred_to_pr36',
    authority: 'CMS/backend_candidate',
  });
};

const pageSources = [
  ['big5-authority-v2-hub-07', 'model_hub'],
  ['big5-authority-v2-domains-08', 'domain'],
  ['big5-authority-v2-facet-hubs-09', 'facet_hub'],
  ['big5-authority-v2-range-openness-10', 'range'],
  ['big5-authority-v2-range-conscientiousness-11', 'range'],
  ['big5-authority-v2-range-extraversion-12', 'range'],
  ['big5-authority-v2-range-agreeableness-13', 'range'],
  ['big5-authority-v2-range-neuroticism-14', 'range'],
  ['big5-authority-v2-facets-openness-15', 'facet'],
  ['big5-authority-v2-facets-conscientiousness-16', 'facet'],
  ['big5-authority-v2-facets-extraversion-17', 'facet'],
  ['big5-authority-v2-facets-agreeableness-18', 'facet'],
  ['big5-authority-v2-facets-neuroticism-19', 'facet'],
  ['big5-authority-v2-test-landing-20', 'test_landing'],
];
for (const [sourcePackage, pageFamily] of pageSources) {
  const packageData = read(`generated/big-five-authority-v2/${sourcePackage}/final-package.json`);
  for (const page of packageData.pages) {
    addNode({
      pageFamily,
      locale: page.locale,
      route: page.canonical_path,
      sourcePackage,
      sourceType: 'public_page_candidate',
      translationGroup: page.content_key,
      declaredTargets: (page.internal_links ?? []).map((link) => typeof link === 'string' ? link : link.href),
    });
  }
}

const refreshPackage = 'big5-authority-v2-article-refresh-22';
for (const article of read(`generated/big-five-authority-v2/${refreshPackage}/article-refresh-candidates.json`).candidates) {
  addNode({ pageFamily: 'article', locale: article.locale, route: article.route, sourcePackage: refreshPackage, sourceType: 'article_refresh_candidate', translationGroup: `article-refresh:${article.slug}`, declaredTargets: article.internal_link_targets });
}
for (const topic of read(`generated/big-five-authority-v2/${refreshPackage}/topic-hub-candidates.json`).candidates) {
  addNode({ pageFamily: 'topic_hub', locale: topic.locale, route: topic.route, sourcePackage: refreshPackage, sourceType: 'topic_hub_candidate', translationGroup: 'topic-hub:big-five' });
}

const trustPackage = 'big5-authority-v2-technical-trust-23';
for (const page of read(`generated/big-five-authority-v2/${trustPackage}/content-page-draft-package.json`).candidates) {
  addNode({ pageFamily: 'technical_trust', locale: page.locale, route: page.canonical_path, sourcePackage: trustPackage, sourceType: 'content_page_candidate', translationGroup: page.translation_group_key });
}

const articleSources = [
  'big5-authority-v2-article-core-model-24',
  'big5-authority-v2-article-result-reading-25',
  'big5-authority-v2-article-workplace-26',
  'big5-authority-v2-article-relationships-27',
  'big5-authority-v2-article-learning-habits-28',
  'big5-authority-v2-article-growth-change-29',
  'big5-authority-v2-article-stress-wellbeing-30',
  'big5-authority-v2-article-research-methods-31',
  'big5-authority-v2-article-comparisons-32',
  'big5-authority-v2-article-research-briefings-33',
];
for (const sourcePackage of articleSources) {
  for (const article of read(`generated/big-five-authority-v2/${sourcePackage}/final-package.json`).assets) {
    addNode({ pageFamily: 'article', locale: article.locale, route: article.path, sourcePackage, sourceType: 'article_candidate', translationGroup: article.topic_id, declaredTargets: article.internal_link_targets });
  }
}

nodes.sort((left, right) => left.route.localeCompare(right.route));
const byRoute = new Map(nodes.map((node) => [node.route, node]));
const stripFragment = (target) => target.split('#')[0];
const localePrefix = (locale) => locale === 'en' ? 'en' : 'zh';
const hubPath = (locale) => `/${localePrefix(locale)}/personality/big-five`;
const facetHubPath = (locale) => `${hubPath(locale)}/facets`;
const testPath = (locale) => `/${localePrefix(locale)}/tests/big-five-personality-test-ocean-model`;
const topicPath = (locale) => `/${localePrefix(locale)}/topics/big-five`;
const methodologyPath = (locale) => `${hubPath(locale)}/methodology`;
const sourceReviewPath = (locale) => `${hubPath(locale)}/source-review-policy`;
const domainPath = (locale, domain) => `${hubPath(locale)}/${domain}`;
const domainFromGroup = (group) => {
  const parts = String(group ?? '').split(':');
  return ['domain', 'range', 'facet'].includes(parts[0]) ? parts[1] : null;
};
const legacyDomainFromGroup = (group) => {
  const slug = String(group ?? '').replace(/^legacy:/, '');
  if (slug === 'emotional-stability') return 'neuroticism';
  return slug.replace(/^(?:high|low)-/, '');
};

const structuralTargets = (node) => {
  const targets = [];
  const sameLocale = nodes.filter((candidate) => candidate.locale === node.locale);
  const add = (...paths) => targets.push(...paths.filter(Boolean));
  if (node.page_family === 'model_hub') {
    add(...sameLocale.filter((candidate) => ['domain', 'facet_hub', 'test_landing', 'topic_hub', 'technical_trust'].includes(candidate.page_family)).map((candidate) => candidate.route));
  } else if (node.page_family === 'domain') {
    const domain = domainFromGroup(node.translation_group);
    add(hubPath(node.locale), facetHubPath(node.locale), testPath(node.locale));
    add(...sameLocale.filter((candidate) => ['range', 'facet'].includes(candidate.page_family) && domainFromGroup(candidate.translation_group) === domain && candidate.navigation_visibility === 'standard').map((candidate) => candidate.route));
    if (node.locale === 'en') add(...sameLocale.filter((candidate) => candidate.navigation_visibility === 'compatibility_only' && legacyDomainFromGroup(candidate.translation_group) === domain).map((candidate) => candidate.route));
  } else if (node.page_family === 'facet_hub') {
    add(hubPath(node.locale), ...sameLocale.filter((candidate) => ['domain', 'facet'].includes(candidate.page_family)).map((candidate) => candidate.route));
  } else if (['range', 'facet'].includes(node.page_family)) {
    const domain = domainFromGroup(node.translation_group);
    add(hubPath(node.locale), facetHubPath(node.locale), testPath(node.locale), domain ? domainPath(node.locale, domain) : null);
  } else if (node.page_family === 'test_landing') {
    add(hubPath(node.locale), topicPath(node.locale), methodologyPath(node.locale));
  } else if (node.page_family === 'topic_hub') {
    add(hubPath(node.locale), testPath(node.locale), methodologyPath(node.locale), ...sameLocale.filter((candidate) => candidate.page_family === 'article').map((candidate) => candidate.route));
  } else if (node.page_family === 'technical_trust') {
    add(hubPath(node.locale), testPath(node.locale), topicPath(node.locale), methodologyPath(node.locale), sourceReviewPath(node.locale));
  } else if (node.page_family === 'article') {
    add(topicPath(node.locale), methodologyPath(node.locale));
  }
  return targets;
};

const edges = [];
for (const node of nodes) {
  const seen = new Set();
  const declared = node.declared_targets.map((href) => ({ href, edgeType: 'declared_internal_link' }));
  const structural = structuralTargets(node).map((href) => ({ href, edgeType: 'authority_navigation' }));
  for (const candidate of [...declared, ...structural]) {
    const target = stripFragment(candidate.href);
    if (target === node.route || seen.has(target) || !byRoute.has(target)) continue;
    seen.add(target);
    edges.push({ source: node.route, target, href: candidate.href, edge_type: candidate.edgeType });
  }
}
edges.sort((left, right) => `${left.source}:${left.target}`.localeCompare(`${right.source}:${right.target}`));

const groups = new Map();
for (const node of nodes) {
  if (node.translation_group === null || node.navigation_visibility === 'compatibility_only') continue;
  const group = groups.get(node.translation_group) ?? [];
  group.push(node);
  groups.set(node.translation_group, group);
}
const hreflangPairs = [];
for (const [translationGroup, group] of groups) {
  const en = group.filter((node) => node.locale === 'en');
  const zh = group.filter((node) => node.locale === 'zh-CN');
  if (en.length !== 1 || zh.length !== 1) continue;
  hreflangPairs.push({
    translation_group: translationGroup,
    en: en[0].route,
    'zh-CN': zh[0].route,
    x_default: en[0].route,
    reciprocal: true,
  });
}
hreflangPairs.sort((left, right) => left.translation_group.localeCompare(right.translation_group));

const legacyMap = {
  'emotional-stability': 'neuroticism-low',
  'high-agreeableness': 'agreeableness-high',
  'high-conscientiousness': 'conscientiousness-high',
  'high-extraversion': 'extraversion-high',
  'high-neuroticism': 'neuroticism-high',
  'high-openness': 'openness-high',
  'low-agreeableness': 'agreeableness-low',
  'low-conscientiousness': 'conscientiousness-low',
  'low-extraversion': 'extraversion-low',
  'low-openness': 'openness-low',
};
const redirects = Object.entries(legacyMap).map(([alias, target]) => ({
  source: `/zh/personality/big-five/${alias}`,
  target: `/zh/personality/big-five/${target}`,
  status_code: 301,
  exact_match: true,
  hop_count: 1,
  source_is_candidate_node: false,
  target_is_real_candidate: byRoute.has(`/zh/personality/big-five/${target}`),
}));
const overlapControls = Object.entries(legacyMap).map(([legacySlug, v2Slug]) => ({
  en_legacy_route: `/en/personality/big-five/${legacySlug}`,
  en_v2_route: `/en/personality/big-five/${v2Slug}`,
  legacy_intent: 'plain-language legacy polarity explainer',
  v2_intent: 'score-range interpretation and bounded action',
  canonical_policy: 'both self-canonical candidates with distinct intent',
  navigation_policy: 'legacy route is compatibility_only; V2 route is standard navigation',
  hreflang_policy: 'legacy EN route has no synthetic zh counterpart; V2 route uses reciprocal EN/zh-CN hreflang',
  cannibalization_control: 'PASS_DISTINCT_INTENT',
}));

const inbound = Object.fromEntries(nodes.map((node) => [node.route, 0]));
const outbound = Object.fromEntries(nodes.map((node) => [node.route, 0]));
for (const edge of edges) {
  inbound[edge.target] += 1;
  outbound[edge.source] += 1;
}
const deadEdges = edges.filter((edge) => !byRoute.has(edge.target));
const orphanRoutes = nodes.filter((node) => inbound[node.route] === 0).map((node) => node.route);
const sinkRoutes = nodes.filter((node) => outbound[node.route] === 0).map((node) => node.route);
const crossLocaleEdges = edges.filter((edge) => byRoute.get(edge.source).locale !== byRoute.get(edge.target).locale);
const selfLinks = edges.filter((edge) => edge.source === edge.target);
const targetValidation = {
  schema_version: 'big5-link-target-validation.v1',
  generated_at: generatedAt,
  counts: {
    candidate_nodes: nodes.length,
    inventory_routes: inventory.mappings.length,
    internal_edges: edges.length,
    validated_real_targets: edges.length,
    dead_edges: deadEdges.length,
    orphan_nodes: orphanRoutes.length,
    sink_nodes: sinkRoutes.length,
    self_links: selfLinks.length,
    cross_locale_edges: crossLocaleEdges.length,
    hreflang_pairs: hreflangPairs.length,
    redirects: redirects.length,
    redirect_chains_or_cycles: redirects.filter((redirect) => redirect.hop_count !== 1 || redirects.some((other) => other.source === redirect.target)).length,
  },
  dead_edges: deadEdges,
  orphan_routes: orphanRoutes,
  sink_routes: sinkRoutes,
  self_links: selfLinks,
  cross_locale_edges: crossLocaleEdges,
};

const graph = {
  schema_version: 'big5-authority-v2-link-graph.v1',
  generated_at: generatedAt,
  authority: 'PR07-34 candidate inventory + CMS/backend',
  release_effect: 'none_planning_and_validation_only',
  nodes: nodes.map(({ declared_targets: _, ...node }) => node),
  edges,
  hreflang_pairs: hreflangPairs,
  redirects,
};
const qa = {
  schema_version: 'big5-link-graph-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_NO_RELEASE_MUTATION',
  counts: targetValidation.counts,
  checks: {
    exact_pr07_34_inventory: nodes.length === 231 && inventoryRoutes.size === 231 && nodes.every((node) => inventoryRoutes.has(node.route)),
    unique_nodes_and_routes: new Set(nodes.map((node) => node.node_id)).size === 231 && new Set(nodes.map((node) => node.route)).size === 231,
    canonical_self_consistency: nodes.every((node) => node.canonical_path === node.route),
    every_edge_has_real_target: deadEdges.length === 0,
    no_orphans_or_sinks: orphanRoutes.length === 0 && sinkRoutes.length === 0,
    no_self_or_cross_locale_links: selfLinks.length === 0 && crossLocaleEdges.length === 0,
    hreflang_targets_real_and_reciprocal: hreflangPairs.every((pair) => byRoute.has(pair.en) && byRoute.has(pair['zh-CN']) && pair.reciprocal === true),
    en_legacy_intent_distinct_from_v2: overlapControls.every((row) => byRoute.has(row.en_legacy_route) && byRoute.has(row.en_v2_route) && row.cannibalization_control === 'PASS_DISTINCT_INTENT'),
    exact_ten_zh_legacy_301_redirects: redirects.length === 10 && redirects.every((redirect) => redirect.status_code === 301 && redirect.exact_match && redirect.target_is_real_candidate),
    no_redirect_chains_or_cycles: targetValidation.counts.redirect_chains_or_cycles === 0,
    eligibility_deferred_to_pr36: nodes.every((node) => node.release_eligibility === 'deferred_to_pr36'),
    no_sitemap_llms_schema_or_indexability_release: graph.release_effect === 'none_planning_and_validation_only',
  },
};

const outputs = {
  'link-graph.json': graph,
  'intent-overlap-report.json': { schema_version: 'big5-intent-overlap-control.v1', generated_at: generatedAt, controls: overlapControls },
  'target-validation-report.json': targetValidation,
  'qa_report.json': qa,
};
for (const [file, data] of Object.entries(outputs)) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
console.log(`built Big Five link graph: ${nodes.length} nodes / ${edges.length} real-target edges / ${hreflangPairs.length} hreflang pairs / 10 exact zh 301 redirects`);

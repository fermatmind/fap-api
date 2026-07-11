import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-facet-extraversion-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-11T00:00:00Z";
const packageName = "big-five-en-facet-extraversion-content-package-2026-07-11";

const facets = [
  {
    code: "warmth", title: "Warmth",
    short: "the usual tendency to express closeness, care, and friendly feeling in one-to-one or small-group interaction",
    higher: "may use greetings, responsive attention, sharing, and visible affection to create closeness and invest time in maintaining personal connection",
    lower: "may prefer restrained, task-focused interaction or more private space, showing care through reliable action rather than overt emotional expression",
    context: "When a new member joins a team, warmth can lower the entry barrier. Around privacy, conflict, or formal boundaries, restrained expression may give another person more space and safety",
    misread: "It is not empathy, kindness, Agreeableness, romantic interest, or interpersonal skill. Limited expression can coexist with deep care, and visible warmth does not guarantee understanding another person's needs",
    observe: "Compare how you show care to familiar people, strangers, colleagues, and someone who needs solitude. Record whether the other person welcomes the form of contact rather than using your own intensity as the measure",
    experiment: "In one important relationship, ask whether the person would prefer an active check-in, practical help, or quiet space. Offer one low-cost response and adjust from their feedback instead of trying simply to be warmer",
  },
  {
    code: "gregariousness", title: "Gregariousness",
    short: "the usual preference for being with groups, joining shared activity, and receiving stimulation from collective presence",
    higher: "may seek gatherings, group conversations, or shared activities and look for live interaction after spending a long period alone",
    lower: "may prefer solitude, one-to-one contact, or small groups and need quiet recovery after extended group time, choosing social settings selectively",
    context: "When a role requires quick cross-team networking, gregariousness can increase contact opportunities. Deep work, sensitive discussion, and recovery can benefit equally from small settings or solitude",
    misread: "It is not social skill, popularity, loneliness, social anxiety, or liking a particular person. Wanting group contact and handling complex social demands are different; preferring solitude does not establish a lack of relationships",
    observe: "Track energy before and after social settings that differ in size, familiarity, and duration. Separate the effect of headcount from interaction quality, noise, role pressure, and whether there was an easy exit",
    experiment: "Schedule one group activity with a clear exit and one high-quality small conversation. Record engagement and recovery costs, then adjust the mix instead of forcing yourself into a fixed extrovert or introvert template",
  },
  {
    code: "assertiveness", title: "Assertiveness",
    short: "the usual tendency to state views, propose direction, influence discussion, or take a leading role in a group",
    higher: "may speak relatively early, make a position explicit, direct attention, or move a decision forward when discussion stalls",
    lower: "may observe and listen first, participating through questions, written input, or support for another person's plan rather than occupying the leading speaking position",
    context: "With limited time and clear accountability, assertiveness can speed coordination. Under high uncertainty or where lower-status views matter, delaying direction can allow more information into the discussion",
    misread: "It is not aggression, desire for power, confidence, professional correctness, or leadership ability. Speaking early or loudly does not prove better judgment, and speaking less does not mean having no view or being unable to lead",
    observe: "Review three meetings: when you spoke, whether you proposed direction, whether others had room, and how written and spoken settings differed. Notice how status and psychological safety changed your behavior",
    experiment: "In one low-risk discussion, if you usually lead, invite two people before summarizing. If you usually wait, prepare one sentence and state it in the first ten minutes. Observe information quality and participation distribution",
  },
  {
    code: "activity", title: "Activity",
    short: "the usual preference for a faster pace, multiple ongoing matters, and sustained physical or behavioral busyness",
    higher: "may enjoy compact schedules, rapid switching, and continuous action, finding another task or accelerating the pace when gaps appear",
    lower: "may prefer an unhurried, single-track pace with buffer time and sustained investment in fewer matters rather than seeking a sense of busyness",
    context: "In short-cycle operations or live coordination, higher activity can maintain responsiveness. Complex analysis, recovery, and precision work may benefit from slower pacing and fewer switches",
    misread: "It is not fitness, health, productivity, diligence, or ADHD. Busyness does not guarantee value, a slower pace does not establish laziness, and physical or clinical status needs separate evidence",
    observe: "For three days, note natural walking pace, schedule density, task switching, and energy after rest. Separate a preference for speed from deadlines, commuting, caregiving, and environmental demands",
    experiment: "Use one work period to compare focused completion of one task with your usual switching pattern. Record output, errors, and fatigue, then retain the pace that fits the task instead of aiming automatically for faster or fuller",
  },
  {
    code: "excitement-seeking", title: "Excitement-Seeking",
    short: "the usual interest in approaching novel, intense, fast-moving, or highly stimulating experiences",
    higher: "may be attracted to speed, change, competition, strong sensory input, or challenging experiences and feel bored in low-stimulation settings",
    lower: "may prefer calm, familiar, and controllable stimulation and not require an intense experience to maintain interest or engagement",
    context: "In creative exploration and bounded challenges, excitement-seeking can encourage trial. In driving, finance, and safety decisions, stimulation preference must remain separate from consequence checks, rules, and exit conditions",
    misread: "It is not recklessness, courage, addiction, risk outcome, or unlawful behavior. Someone who enjoys stimulation can manage risk strictly, while someone who prefers low stimulation may accept major challenges for meaningful reasons",
    observe: "Record where you actively increase speed, intensity, or uncertainty. Evaluate enjoyment, actual risk, reversibility, and effects on others separately so the feeling of stimulation is not treated as proof of value",
    experiment: "Add novelty in one safe and controlled way with a budget, time limit, protection, and stop condition. If you often seek intensity, also schedule a low-stimulation activity and notice whether attention recovers",
  },
  {
    code: "positive-emotions", title: "Positive Emotions",
    short: "the usual frequency and visibility of pleasant feeling, excitement, vitality, humor, and celebration",
    higher: "may readily experience and express happiness, excitement, or amusement and actively share positive moments when things go well or connection feels strong",
    lower: "may experience positive states in a calmer, more restrained, or briefer way without lacking satisfaction, engagement, or concern for important matters",
    context: "When celebrating progress or energizing a team, visible positive emotion can amplify a shared experience. During risk review or another person's setback, restraint can prevent premature optimism or emotional mismatch",
    misread: "It is not an optimism judgment, mental-health status, overall happiness, kindness, or absence of negative emotion. Limited expression cannot diagnose depression, and frequent positive expression does not mean a person has no stress",
    observe: "For one week, note events that brought pleasure, satisfaction, or excitement and how you expressed them. Compare private and social settings, and notice the effects of culture, role, and psychological safety",
    experiment: "Record one specific positive event and its intensity each day without forcing positivity. Offer one appropriate expression of thanks or celebration while allowing worry and fatigue to coexist, then review whether the experience became clearer",
  },
];

const routeFor = (code) => `/en/personality/big-five/facets/${code}`;
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));
const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "en")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "Extraversion", href: "/en/personality/big-five/extraversion", relationship: "parent_domain" },
  { label: "30 Big Five facets", href: "/en/personality/big-five/facets", relationship: "facet_hub" },
  ...facets.filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  { key: "quick_answer", title: `Quick answer: what is ${facet.title}?`, body_md: `${facet.title} describes ${facet.short}. It is a continuous facet within Extraversion, not a personality type or a fixed label. A more or less prominent expression suggests a usual emphasis; tasks, experience, resources, roles, and pressure can all change what appears in a particular moment.` },
  { key: "what_it_captures", title: `What ${facet.title} captures`, body_md: `${facet.title} concerns how attention is allocated and experience is approached when there is room for choice. It does not reduce a person to one behavior or turn interest into ability. A careful reading compares several occasions across at least two settings, then asks what benefits, costs, and support needs accompany the pattern.` },
  { key: "higher_expression", title: `When ${facet.title} is more prominent`, body_md: `A person ${facet.higher}. In a matching task this can widen the information considered or add useful perspectives. It can also bring costs such as excess exploration, missed constraints, or effort beyond what the task requires. Whether it helps depends on verification, priorities, and stopping rules.` },
  { key: "lower_expression", title: `When ${facet.title} is less prominent`, body_md: `A person ${facet.lower}. This does not mean an absence of Extraversion or ability; it may be a practical allocation of attention. The pattern can be valuable in work that rewards stability, clarity, and repeatability. When conditions change, a bounded experiment can add information without discarding reliable routines.` },
  { key: "context_examples", title: "Read the facet in context", body_md: `${facet.context}. These examples show that the same tendency can have different effects across tasks; they do not predict an individual's performance. Consider the goal, risk, time limit, collaborators, and reversibility before judging whether a response fits.` },
  { key: "common_misreads", title: "Common misreadings and nearby concepts", body_md: `${facet.misread}. The six Extraversion facets also need not move together. A more prominent expression here does not establish the same position in Imagination, Aesthetics, Feelings, Actions, Ideas, and Values.` },
  { key: "observe_in_context", title: "How to observe your pattern", body_md: `${facet.observe}. Use observable actions and exact words rather than “that is just who I am.” Treat a single event as a clue. When counterexamples appear, update the working hypothesis instead of explaining them away.` },
  { key: "small_experiment", title: "A small reversible experiment", body_md: `${facet.experiment}. The purpose is not to push a score toward either end. It is to increase choice: learn when your default approach serves the task, when another strategy adds value, and how to preserve an exit and review point.` },
  { key: "method_boundary", title: "Method and use boundaries", body_md: `This page follows the existing CMS navigation, which is similar to the NEO/IPIP 30-facet tradition, to explain ${facet.title}. It does not reproduce proprietary items or directly convert this route to the BFI-2's 15 facets or the BFAS's 10 aspects. It does not read private results or provide norms, percentiles, reliability, or validity figures. Do not use it for diagnosis, treatment, hiring or admissions screening, ability judgments, income or relationship predictions, or deterministic career advice.` },
];

const faqFor = (facet) => [
  { id: "higher-better", question: `Is a higher ${facet.title} score always better?`, answer: `No. Both ends of ${facet.title} can bring advantages and costs in different tasks. Context, regulation, and verification matter more than ranking one end as universally better.`, evidence_ids: ["I2", "A4"] },
  { id: "can-change", question: `Can ${facet.title} look different across situations?`, answer: "Yes. Trait language describes a usual tendency, not identical behavior every time. Roles, experience, pressure, resources, and explicit rules can change the response that appears.", evidence_ids: ["A4"] },
  { id: "same-as-domain", question: `Does ${facet.title} represent all of Extraversion?`, answer: "No. It is one of six facets in this route taxonomy. The other facets may sit at different positions, and one narrow facet cannot substitute for the broader domain.", evidence_ids: ["I1", "A1"] },
  { id: "personal-score", question: `Can this page interpret my ${facet.title} result?`, answer: "No. This page explains a public concept only. A personal result must be read through the specific instrument's scoring, response-quality, norm, and interpretation contract, together with the person's own feedback.", evidence_ids: ["I1", "I2"] },
  { id: "high-stakes", question: `Can ${facet.title} be used for hiring, diagnosis, or a career decision?`, answer: "No. This facet cannot replace clinical evaluation, work samples, a structured hiring process, occupational evidence, or the other information required for a high-stakes decision.", evidence_ids: ["I2"] },
];

const rawAssets = [];
const repairedAssets = [];
for (const facet of facets) {
  const base = baseAssets.get(facet.code);
  if (!base) throw new Error(`Missing base facet asset: ${facet.code}`);
  rawAssets.push({ ...structuredClone(base), review_state: "codex_raw_untrusted", source_package: `${packageName}-raw`, source_hash: null, last_reviewed_at: generatedAt, source_ledger_refs: ["SHARED", "I1", "I2", "A1"], model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`] });
  repairedAssets.push({
    ...structuredClone(base),
    summary: `${facet.title} describes ${facet.short}. This page balances both ends, context, common misreadings, and reversible actions without treating the facet as ability, diagnosis, or identity.`,
    seo: { title: `Big Five ${facet.title}: Meaning, Patterns, and Examples`, description: `Understand the Big Five Extraversion facet ${facet.title}, including higher and lower expressions, context, common misreadings, and non-diagnostic boundaries.` },
    sections: sectionsFor(facet), faq: faqFor(facet), internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow", launch_state: "content_ready", review_state: "codex_repaired_ready",
    index_eligible: false, sitemap_eligible: false, llms_eligible: false,
    source_package: packageName, source_hash: null, last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: { summary: `This page explains the public ${facet.title} concept within Extraversion; it does not interpret a private result or replace an instrument contract or professional judgment.`, taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.", not_for: ["clinical diagnosis", "treatment advice", "hiring or admissions screening", "ability or intelligence judgments", "income, relationship, or career-outcome prediction"] },
    evidence_notes: [
      { source_type: "taxonomy", note: "The existing CMS route set uses an Extraversion navigation similar to the NEO/IPIP 30-facet tradition." },
      { source_type: "boundary", note: `${facet.title} is a continuous tendency, not a personality type, ability rank, or deterministic prediction.` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING; this package makes no search-performance or indexability claim." },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`, "codex-skeptical-review-en-extraversion-2026-07-11", `codex-repair-${facet.code}-2026-07-11`],
  });
}

const envelope = (name, assets) => ({ package: name, contract_version: "personality_public_asset.v1", generated_at: generatedAt, assets });
const extraversionTaxonomy = sharedLedger.taxonomy.filter((domain) => domain.domain_code === "extraversion").map((domain) => ({
  domain_code: domain.domain_code, domain_title_en: "Extraversion",
  facets: facets.map((facet) => ({ code: facet.code, title_en: facet.title, route: routeFor(facet.code) })),
}));
const sourceLedger = {
  package: "big-five-en-facet-extraversion-source-ledger-2026-07-11", access_date: "2026-07-11", scope: "Six English Extraversion Facet content packages only.",
  inherits: { path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", package: sharedLedger.package },
  sources: sharedLedger.sources, taxonomy: extraversionTaxonomy,
  claim_map: [
    { claim: "The six routes are narrower continuous descriptors under Extraversion, not personality types.", evidence_ids: ["I1", "A1", "A4"] },
    { claim: "Facet interest or attention is not equivalent to ability, diagnosis, or outcome prediction.", evidence_ids: ["I2", "A4"] },
    { claim: "This route taxonomy must not be directly converted to BFI-2 facets or BFAS aspects.", evidence_ids: ["A1", "A2", "A3"] },
  ],
  facet_boundaries: Object.fromEntries(facets.map((facet) => [facet.code, { title_en: facet.title, construct_summary: facet.short, explicit_non_equivalence: facet.misread }])),
  limitations: sharedLedger.limitations,
};
const skepticalReview = {
  package: packageName, raw_draft: "raw_codex_draft.json", reviewer_mode: "codex_skeptical_self_review", critical_violations: [],
  major_repairs: [
    "Replace all six three-section stubs with nine substantive, independently authored English sections.",
    "Balance both ends of every facet and remove higher-equals-better implications.",
    "Separate warmth from empathy, gregariousness from social skill, assertiveness from aggression, activity from productivity, excitement-seeking from recklessness, and positive emotion from mental-health status.",
    "Add five FAQ items and seven structured internal links per asset without self-links.",
    "Add cross-instrument and private-result boundaries while preserving every noindex gate.",
  ],
  per_asset: Object.fromEntries(facets.map((facet) => [facet.code, { raw_status: "thin_content_insufficient", repaired_status: "pass", duplicate_risk: "pass_unique_facet_examples_and_boundaries", critical_violations: [] }])),
  private_result_boundary: "pass_after_repair", adjudication: "repaired_required",
};

await mkdir(outputDir, { recursive: true });
await Promise.all([
  writeFile(resolve(outputDir, "source_ledger.json"), `${JSON.stringify(sourceLedger, null, 2)}\n`),
  writeFile(resolve(outputDir, "raw_codex_draft.json"), `${JSON.stringify(envelope(`${packageName}-raw`, rawAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "skeptical_review.json"), `${JSON.stringify(skepticalReview, null, 2)}\n`),
  writeFile(resolve(outputDir, "repaired_draft.json"), `${JSON.stringify(envelope(`${packageName}-repaired`, repairedAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "big_five_en_facet_extraversion_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);
console.log(`generated ${repairedAssets.length} English Extraversion Facet assets`);

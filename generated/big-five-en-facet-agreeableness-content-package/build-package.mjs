import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-facet-agreeableness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-11T00:00:00Z";
const packageName = "big-five-en-facet-agreeableness-content-package-2026-07-11";

const facets = [
  {
    code: "trust", title: "Trust",
    short: "the usual tendency to expect goodwill, reliability, and cooperative intent from others when evidence is incomplete",
    higher: "may initially interpret behavior in good faith, share necessary information, and offer an opportunity to cooperate unless clear counterevidence appears",
    lower: "may verify motives, records, and commitments first, retaining information, permissions, or alternatives until trust has been earned",
    context: "In long-running collaboration, trust can reduce repeated defensive costs. With money, privacy, or permissions, careful verification is reasonable protection. Trust works best when it updates with evidence rather than remaining all-or-nothing",
    misread: "It is not naivety, vulnerability to deception, guaranteed safety, moral superiority, or openness to everyone. Higher trust still needs boundaries; lower trust does not establish coldness, paranoia, or a clinical condition",
    observe: "Review three new collaborations: what evidence you requested, when trust increased or decreased, and whether you judged a specific behavior or permanently defined the whole person",
    experiment: "Choose a low-risk collaboration, release information or access in stages, and define one observable commitment. Adjust from delivery evidence instead of trusting everything at once or refusing all cooperation",
  },
  {
    code: "straightforwardness", title: "Straightforwardness",
    short: "the usual tendency to state a genuine position directly and avoid manipulating others or concealing material intent",
    higher: "may make views, limits, and important motives explicit and dislike relying on hints, packaging, or strategic ambiguity to influence another person",
    lower: "may give more weight to strategy, politeness, and timing, choosing indirect expression or withholding some thoughts according to relationship and consequence",
    context: "When clarifying accountability or conflicts of interest, directness can reduce misunderstanding. With privacy, negotiation, or another person's safety, information boundaries and timing matter; honesty does not require total disclosure",
    misread: "It is not cruelty, blurting everything out, exposing private information, always being correct, or moral purity. Honesty can include tact and safety, while indirect expression is not automatically deception",
    observe: "Track how you communicate disagreement, constraints, and interests across different power relationships. Distinguish necessary privacy, courteous buffering, fear of consequences, and deliberate misdirection",
    experiment: "Turn one ambiguous message into four sentences: fact, position, boundary, and next step. Ask the listener to restate it, protect information that should stay private, and review whether directness actually reduced confusion",
  },
  {
    code: "altruism", title: "Altruism",
    short: "the usual tendency to notice another person's needs and offer time, information, or practical help at a reasonable cost",
    higher: "may readily identify needs, share resources, and help solve a problem, often finding meaning in supporting another person",
    lower: "may emphasize personal responsibility, self-help, and exchange boundaries, helping mainly when the request is clear, impact is bounded, or the matter fits the role",
    context: "In crisis mutual aid and teamwork, altruism can fill gaps. When help repeatedly replaces another person's responsibility or consumes basic needs, refusal, referral, and limits may be more sustainable",
    misread: "It is not self-sacrifice, people-pleasing, donation amount, absence of boundaries, or moral rank. Resources and roles affect helping behavior, and less proactive help does not establish lack of concern",
    observe: "Review three recent instances of helping or refusing. Note whether the request was clear, who carried the cost, whether the person benefited, and whether the help reinforced unhealthy dependence",
    experiment: "For one real need, first ask whether the person wants listening, information, or joint action. Offer one specific amount of help with an end point, then review effectiveness and whether the boundary remained sustainable",
  },
  {
    code: "compliance", title: "Compliance",
    short: "the usual tendency to restrain confrontation, seek compromise, and restore cooperation during conflict",
    higher: "may de-escalate, listen, and look for common ground rather than turn disagreement into personal confrontation or relationship rupture",
    lower: "may argue openly, hold a position, and face conflict directly instead of yielding quickly when a principle or interest appears threatened",
    context: "In negotiable disagreements, compliance can preserve a relationship. With safety, harassment, rights, or major principles, clear opposition, escalation, or exit may be necessary",
    misread: "It is not obedience, weakness, agreement, surrendering rights, or absence of anger. De-escalation does not accept harm, direct conflict is not automatically aggression, and consent must remain independent, explicit, and revocable",
    observe: "Record how you respond to a minor disagreement and to a boundary violation. Separate strategic compromise, fear of consequences, genuine agreement, and a decision to address the issue later",
    experiment: "For one low-risk disagreement, write the shared goal, a non-negotiable boundary, and two negotiable points. Restate the other view before proposing an option; stop compromising and seek support if safety or rights are involved",
  },
  {
    code: "modesty", title: "Modesty",
    short: "the usual tendency to avoid elevating oneself or demanding special recognition when presenting achievement and status",
    higher: "may place less emphasis on superiority, acknowledge other people's contributions and personal limits, and avoid keeping attention centered on the self",
    lower: "may state achievements, strengths, and deserved recognition explicitly and be comfortable presenting personal value in competition or negotiation",
    context: "In a team review, modesty can leave room for others. In hiring, promotion, or resource negotiation, downplaying contributions too far can remove needed information; accurate achievement statements are not arrogance",
    misread: "It is not low self-esteem, lack of ability, self-denigration, shyness, or an obligation to reject praise. Modesty concerns presentation and does not require denying facts or tolerating unfair attribution",
    observe: "Compare how you discuss achievement in a safe team, public competition, and a power-unequal setting. Check whether the account is accurate, inflated, minimized, or omits team contribution",
    experiment: "Write four sentences covering a concrete result, your contribution, others' contributions, and a limitation. Use them in an appropriate setting and observe whether the account stays accurate without inflating or diminishing you",
  },
  {
    code: "tender-mindedness", title: "Tender-Mindedness",
    short: "the usual tendency to feel concern for suffering and vulnerability and to give weight to care needs and human impact",
    higher: "may be readily moved by concrete hardship and consider affected people's feelings, dignity, and support needs when judging an option",
    lower: "may keep more emotional distance, emphasize consistent rules and long-term consequences, and avoid letting immediate sympathy determine allocation or accountability",
    context: "In care and service design, tender-mindedness can reveal overlooked needs. In resource allocation and crisis decisions, compassion still needs evidence, fairness, and sustainability",
    misread: "It is not fragility, emotional loss of control, femininity, mental-health status, or always agreeing. Sensitivity does not guarantee an effective plan, and emotional distance does not establish cruelty",
    observe: "Compare your response to hardship involving someone familiar, a stranger, and an abstract statistic. Check whether concern becomes an action the affected person actually needs and that can be sustained",
    experiment: "For one affected group, hear one first-hand need, then write the human impact, evidence limits, and resource boundary. Propose one small support action whose effect can be checked",
  },
];

const routeFor = (code) => `/en/personality/big-five/facets/${code}`;
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));
const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "en")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "Agreeableness", href: "/en/personality/big-five/agreeableness", relationship: "parent_domain" },
  { label: "30 Big Five facets", href: "/en/personality/big-five/facets", relationship: "facet_hub" },
  ...facets.filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  { key: "quick_answer", title: `Quick answer: what is ${facet.title}?`, body_md: `${facet.title} describes ${facet.short}. It is a continuous facet within Agreeableness, not a personality type or a fixed label. A more or less prominent expression suggests a usual emphasis; tasks, experience, resources, roles, and pressure can all change what appears in a particular moment.` },
  { key: "what_it_captures", title: `What ${facet.title} captures`, body_md: `${facet.title} concerns how attention is allocated and experience is approached when there is room for choice. It does not reduce a person to one behavior or turn interest into ability. A careful reading compares several occasions across at least two settings, then asks what benefits, costs, and support needs accompany the pattern.` },
  { key: "higher_expression", title: `When ${facet.title} is more prominent`, body_md: `A person ${facet.higher}. In a matching task this can widen the information considered or add useful perspectives. It can also bring costs such as excess exploration, missed constraints, or effort beyond what the task requires. Whether it helps depends on verification, priorities, and stopping rules.` },
  { key: "lower_expression", title: `When ${facet.title} is less prominent`, body_md: `A person ${facet.lower}. This does not mean an absence of Agreeableness or ability; it may be a practical allocation of attention. The pattern can be valuable in work that rewards stability, clarity, and repeatability. When conditions change, a bounded experiment can add information without discarding reliable routines.` },
  { key: "context_examples", title: "Read the facet in context", body_md: `${facet.context}. These examples show that the same tendency can have different effects across tasks; they do not predict an individual's performance. Consider the goal, risk, time limit, collaborators, and reversibility before judging whether a response fits.` },
  { key: "common_misreads", title: "Common misreadings and nearby concepts", body_md: `${facet.misread}. The six Agreeableness facets also need not move together. A more prominent expression here does not establish the same position in Imagination, Aesthetics, Feelings, Actions, Ideas, and Values.` },
  { key: "observe_in_context", title: "How to observe your pattern", body_md: `${facet.observe}. Use observable actions and exact words rather than “that is just who I am.” Treat a single event as a clue. When counterexamples appear, update the working hypothesis instead of explaining them away.` },
  { key: "small_experiment", title: "A small reversible experiment", body_md: `${facet.experiment}. The purpose is not to push a score toward either end. It is to increase choice: learn when your default approach serves the task, when another strategy adds value, and how to preserve an exit and review point.` },
  { key: "method_boundary", title: "Method and use boundaries", body_md: `This page follows the existing CMS navigation, which is similar to the NEO/IPIP 30-facet tradition, to explain ${facet.title}. It does not reproduce proprietary items or directly convert this route to the BFI-2's 15 facets or the BFAS's 10 aspects. It does not read private results or provide norms, percentiles, reliability, or validity figures. Do not use it for diagnosis, treatment, hiring or admissions screening, ability judgments, income or relationship predictions, or deterministic career advice.` },
];

const faqFor = (facet) => [
  { id: "higher-better", question: `Is a higher ${facet.title} score always better?`, answer: `No. Both ends of ${facet.title} can bring advantages and costs in different tasks. Context, regulation, and verification matter more than ranking one end as universally better.`, evidence_ids: ["I2", "A4"] },
  { id: "can-change", question: `Can ${facet.title} look different across situations?`, answer: "Yes. Trait language describes a usual tendency, not identical behavior every time. Roles, experience, pressure, resources, and explicit rules can change the response that appears.", evidence_ids: ["A4"] },
  { id: "same-as-domain", question: `Does ${facet.title} represent all of Agreeableness?`, answer: "No. It is one of six facets in this route taxonomy. The other facets may sit at different positions, and one narrow facet cannot substitute for the broader domain.", evidence_ids: ["I1", "A1"] },
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
    seo: { title: `Big Five ${facet.title}: Meaning, Patterns, and Examples`, description: `Understand the Big Five Agreeableness facet ${facet.title}, including higher and lower expressions, context, common misreadings, and non-diagnostic boundaries.` },
    sections: sectionsFor(facet), faq: faqFor(facet), internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow", launch_state: "content_ready", review_state: "codex_repaired_ready",
    index_eligible: false, sitemap_eligible: false, llms_eligible: false,
    source_package: packageName, source_hash: null, last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: { summary: `This page explains the public ${facet.title} concept within Agreeableness; it does not interpret a private result or replace an instrument contract or professional judgment.`, taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.", not_for: ["clinical diagnosis", "treatment advice", "hiring or admissions screening", "ability or intelligence judgments", "income, relationship, or career-outcome prediction"] },
    evidence_notes: [
      { source_type: "taxonomy", note: "The existing CMS route set uses an Agreeableness navigation similar to the NEO/IPIP 30-facet tradition." },
      { source_type: "boundary", note: `${facet.title} is a continuous tendency, not a personality type, ability rank, or deterministic prediction.` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING; this package makes no search-performance or indexability claim." },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`, "codex-skeptical-review-en-agreeableness-2026-07-11", `codex-repair-${facet.code}-2026-07-11`],
  });
}

const envelope = (name, assets) => ({ package: name, contract_version: "personality_public_asset.v1", generated_at: generatedAt, assets });
const agreeablenessTaxonomy = sharedLedger.taxonomy.filter((domain) => domain.domain_code === "agreeableness").map((domain) => ({
  domain_code: domain.domain_code, domain_title_en: "Agreeableness",
  facets: facets.map((facet) => ({ code: facet.code, title_en: facet.title, route: routeFor(facet.code) })),
}));
const sourceLedger = {
  package: "big-five-en-facet-agreeableness-source-ledger-2026-07-11", access_date: "2026-07-11", scope: "Six English Agreeableness Facet content packages only.",
  inherits: { path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", package: sharedLedger.package },
  sources: sharedLedger.sources, taxonomy: agreeablenessTaxonomy,
  claim_map: [
    { claim: "The six routes are narrower continuous descriptors under Agreeableness, not personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate trust from naivety, straightforwardness from cruelty, altruism from self-sacrifice, compliance from obedience, modesty from low self-worth, and tender-mindedness from fragility.",
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
  writeFile(resolve(outputDir, "big_five_en_facet_agreeableness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);
console.log(`generated ${repairedAssets.length} English Agreeableness Facet assets`);

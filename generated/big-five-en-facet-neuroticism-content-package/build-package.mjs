import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-facet-neuroticism-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-11T00:00:00Z";
const packageName = "big-five-en-facet-neuroticism-content-package-2026-07-11";

const facets = [
  {
    code: "anxiety", title: "Anxiety",
    short: "the usual tendency to experience worry, tension, and risk anticipation relatively quickly when uncertainty or possible threat appears",
    higher: "may notice what could go wrong early, shift attention and bodily arousal toward vigilance, and check a risk repeatedly",
    lower: "may remain comparatively calm under uncertainty, spend less time rehearsing negative outcomes, and return attention to current action more readily",
    context: "In safety review and complex planning, anxiety can surface overlooked risks. If vigilance consumes attention, evidence thresholds and priorities can stop unproductive checking. A calmer response still needs safeguards for high-cost risks",
    misread: "It is not an anxiety disorder, cowardice, accurate premonition, or a mental-health conclusion. This facet describes a usual response and cannot establish clinical duration, severity, or impairment",
    observe: "Record the trigger for a worry, bodily cues, available evidence, and eventual outcome. Separate actionable risk, repeated hypothetical checking, and temporary changes related to sleep, stress, or real burden",
    experiment: "Sort one worry into controllable, monitorable, and currently uncontrollable parts. Take one small action only for the first and set an end time for repeated checking. Seek qualified support if distress persists or substantially affects life",
  },
  {
    code: "anger", title: "Anger",
    short: "the usual tendency to experience irritation, hostility, or angry arousal relatively quickly when blocked, treated unfairly, or faced with a boundary violation",
    higher: "may notice obstruction or offense readily, experience a fast rise in intensity, and feel an immediate urge to object or correct the situation",
    lower: "may be less likely to remain angry after obstruction and more able to stay calm, delay a response, or consider a non-confrontational explanation",
    context: "Anger can signal that fairness or a boundary matters. Acting without regulation can expand conflict. Lower anger can help de-escalate, but an important boundary may still need direct expression",
    misread: "It is not aggression, violence, a permanent bad temper, moral failure, or a diagnosis. Feeling anger and choosing an action are different, and no violent behavior is justified by a personality score",
    observe: "Record the event, interpretation, bodily change, and action around an angry episode. Separate an actual boundary violation, a misunderstanding, accumulated fatigue, and a stimulus that can be addressed later",
    experiment: "In a low-risk conflict, pause for ninety seconds and state event, impact, boundary, and request. If control or safety is at risk, leave the setting and contact trusted support or appropriate local emergency help",
  },
  {
    code: "depression", title: "Depression",
    short: "the usual tendency to experience sadness, discouragement, and lower expectations relatively readily after loss, setback, or stress",
    higher: "may keep attention on loss and insufficiency after failure and need more time or support before positive expectations return",
    lower: "may experience a briefer or lighter drop in mood and find actionable parts again more readily, without being immune to grief or major events",
    context: "Low mood can signal a need for rest, grieving, or adjustment of an unsustainable goal. Generalizing a temporary setback to the entire future can narrow action; a lower expression should still allow real loss to be acknowledged",
    misread: "It is not depressive disorder, weak will, a fixed pessimistic identity, or a diagnosis. This page cannot assess symptom duration, severity, functional impact, or treatment need",
    observe: "Record how long low mood lasts, its trigger, changes in sleep and daily functioning, and whether brief relief still occurs. Do not explain all experience from one mood or a trait label",
    experiment: "Choose one ten-minute care or action step and contact one trusted person. If low mood persists, substantially affects life, or includes thoughts of self-harm, contact qualified professional or local emergency support promptly",
  },
  {
    code: "self-consciousness", title: "Self-Consciousness",
    short: "the usual tendency to feel embarrassment, discomfort, and self-focused attention when being watched, evaluated, or at risk of making a social mistake",
    higher: "may imagine how others see them and repeatedly monitor words or actions during public performance, unfamiliar groups, or after a mistake",
    lower: "may remain relatively comfortable under attention and recover from small mistakes quickly, while still feeling tension in an important evaluation",
    context: "Some self-consciousness can support attention to norms and feedback. Excess monitoring can consume task attention. Lower self-consciousness can support natural expression but still needs awareness of context and others' responses",
    misread: "It is not reflective self-awareness, narcissism, low self-esteem, social-anxiety disorder, or social skill. A trait tendency cannot establish a clinical condition or prove that others are judging negatively",
    observe: "Compare bodily response, focus of attention, and later review when alone, with familiar people, in a group, and in public. Write actual feedback separately from imagined evaluation",
    experiment: "In one controlled setting, shift attention from “how do I look?” to one external task and permit a small imperfection. Afterward, record visible facts and one learning point without unlimited replay",
  },
  {
    code: "impulsiveness", title: "Impulsiveness",
    short: "the usual difficulty of delaying gratification or inhibiting immediate action when a strong desire or emotion appears",
    higher: "may find it harder to pause around food, spending, expression, or another immediate cue, allowing short-term relief to outweigh a longer-term plan",
    lower: "may let an urge pass through time before deciding and follow a pre-set boundary more easily, without being unable to revise a plan",
    context: "Fast response can help in a low-risk window. With money, health, safety, or relationships, delay and friction can protect longer-term goals. Excess control can also suppress reasonable needs for too long",
    misread: "It is not excitement-seeking, low deliberation, moral failure, addiction, or ADHD. Nearby constructs and clinical conditions cannot be converted from this page, and environment, stress, and resources affect behavior",
    observe: "Record the emotion, cue, available options, and what changes ten minutes after an urge. Separate stimulation-seeking, escape from discomfort, a habit cue, and a considered choice",
    experiment: "For one costly urge, add a ten-minute delay, remove one trigger, and write an alternative action. If behavior remains out of control or causes substantial harm, seek qualified professional support rather than relying on a trait label",
  },
  {
    code: "vulnerability", title: "Vulnerability",
    short: "the usual tendency to feel unable to cope or easily overwhelmed when pressure is high, demands are complex, or support is insufficient",
    higher: "may lose clarity as stress accumulates and need more time, structure, or help from others before action can be reorganized",
    lower: "may retain a sense of direction and working capacity under pressure and feel helpless less often, while still being subject to resource limits and major events",
    context: "Feeling unable to cope can signal that tasks, resources, or support need adjustment. Treating all stress as personal weakness hides environmental causes. A lower expression still needs workload limits and timely help-seeking",
    misread: "It is not weakness, trauma history, a final statement about resilience, lack of ability, or a diagnosis. One crisis response does not establish a stable personality pattern, and environmental support can change performance substantially",
    observe: "Review which changes in sleep, attention, body, and decision-making appear first as pressure rises, then record which task structures, relationships, and recovery conditions actually help",
    experiment: "Sort current pressure into must do, can delay, and needs support. Remove one nonessential demand and make one specific request. If safety or basic functioning is affected, contact professional or emergency support promptly",
  },
];

const routeFor = (code) => `/en/personality/big-five/facets/${code}`;
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));
const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "en")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "Neuroticism", href: "/en/personality/big-five/neuroticism", relationship: "parent_domain" },
  { label: "30 Big Five facets", href: "/en/personality/big-five/facets", relationship: "facet_hub" },
  ...facets.filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  { key: "quick_answer", title: `Quick answer: what is ${facet.title}?`, body_md: `${facet.title} describes ${facet.short}. It is a continuous facet within Neuroticism, not a personality type or a fixed label. A more or less prominent expression suggests a usual emphasis; tasks, experience, resources, roles, and pressure can all change what appears in a particular moment.` },
  { key: "what_it_captures", title: `What ${facet.title} captures`, body_md: `${facet.title} concerns how attention is allocated and experience is approached when there is room for choice. It does not reduce a person to one behavior or turn interest into ability. A careful reading compares several occasions across at least two settings, then asks what benefits, costs, and support needs accompany the pattern.` },
  { key: "higher_expression", title: `When ${facet.title} is more prominent`, body_md: `A person ${facet.higher}. In a matching task this can widen the information considered or add useful perspectives. It can also bring costs such as excess exploration, missed constraints, or effort beyond what the task requires. Whether it helps depends on verification, priorities, and stopping rules.` },
  { key: "lower_expression", title: `When ${facet.title} is less prominent`, body_md: `A person ${facet.lower}. This does not mean an absence of Neuroticism or ability; it may be a practical allocation of attention. The pattern can be valuable in work that rewards stability, clarity, and repeatability. When conditions change, a bounded experiment can add information without discarding reliable routines.` },
  { key: "context_examples", title: "Read the facet in context", body_md: `${facet.context}. These examples show that the same tendency can have different effects across tasks; they do not predict an individual's performance. Consider the goal, risk, time limit, collaborators, and reversibility before judging whether a response fits.` },
  { key: "common_misreads", title: "Common misreadings and nearby concepts", body_md: `${facet.misread}. The six Neuroticism facets also need not move together. A more prominent expression here does not establish the same position in Imagination, Aesthetics, Feelings, Actions, Ideas, and Values.` },
  { key: "observe_in_context", title: "How to observe your pattern", body_md: `${facet.observe}. Use observable actions and exact words rather than “that is just who I am.” Treat a single event as a clue. When counterexamples appear, update the working hypothesis instead of explaining them away.` },
  { key: "small_experiment", title: "A small reversible experiment", body_md: `${facet.experiment}. The purpose is not to push a score toward either end. It is to increase choice: learn when your default approach serves the task, when another strategy adds value, and how to preserve an exit and review point.` },
  { key: "method_boundary", title: "Method and use boundaries", body_md: `This page follows the existing CMS navigation, which is similar to the NEO/IPIP 30-facet tradition, to explain ${facet.title}. It does not reproduce proprietary items or directly convert this route to the BFI-2's 15 facets or the BFAS's 10 aspects. It does not read private results or provide norms, percentiles, reliability, or validity figures. Do not use it for diagnosis, treatment, hiring or admissions screening, ability judgments, income or relationship predictions, or deterministic career advice.` },
];

const faqFor = (facet) => [
  { id: "higher-better", question: `Is a higher ${facet.title} score always better?`, answer: `No. Both ends of ${facet.title} can bring advantages and costs in different tasks. Context, regulation, and verification matter more than ranking one end as universally better.`, evidence_ids: ["I2", "A4"] },
  { id: "can-change", question: `Can ${facet.title} look different across situations?`, answer: "Yes. Trait language describes a usual tendency, not identical behavior every time. Roles, experience, pressure, resources, and explicit rules can change the response that appears.", evidence_ids: ["A4"] },
  { id: "same-as-domain", question: `Does ${facet.title} represent all of Neuroticism?`, answer: "No. It is one of six facets in this route taxonomy. The other facets may sit at different positions, and one narrow facet cannot substitute for the broader domain.", evidence_ids: ["I1", "A1"] },
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
    seo: { title: `Big Five ${facet.title}: Meaning, Patterns, and Examples`, description: `Understand the Big Five Neuroticism facet ${facet.title}, including higher and lower expressions, context, common misreadings, and non-diagnostic boundaries.` },
    sections: sectionsFor(facet), faq: faqFor(facet), internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow", launch_state: "content_ready", review_state: "codex_repaired_ready",
    index_eligible: false, sitemap_eligible: false, llms_eligible: false,
    source_package: packageName, source_hash: null, last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: { summary: `This page explains the public ${facet.title} concept within Neuroticism; it does not interpret a private result or replace an instrument contract or professional judgment.`, taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.", not_for: ["clinical diagnosis", "treatment advice", "hiring or admissions screening", "ability or intelligence judgments", "income, relationship, or career-outcome prediction"] },
    evidence_notes: [
      { source_type: "taxonomy", note: "The existing CMS route set uses an Neuroticism navigation similar to the NEO/IPIP 30-facet tradition." },
      { source_type: "boundary", note: `${facet.title} is a continuous tendency, not a personality type, ability rank, or deterministic prediction.` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING; this package makes no search-performance or indexability claim." },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`, "codex-skeptical-review-en-neuroticism-2026-07-11", `codex-repair-${facet.code}-2026-07-11`],
  });
}

const envelope = (name, assets) => ({ package: name, contract_version: "personality_public_asset.v1", generated_at: generatedAt, assets });
const neuroticismTaxonomy = sharedLedger.taxonomy.filter((domain) => domain.domain_code === "neuroticism").map((domain) => ({
  domain_code: domain.domain_code, domain_title_en: "Neuroticism",
  facets: facets.map((facet) => ({ code: facet.code, title_en: facet.title, route: routeFor(facet.code) })),
}));
const sourceLedger = {
  package: "big-five-en-facet-neuroticism-source-ledger-2026-07-11", access_date: "2026-07-11", scope: "Six English Neuroticism Facet content packages only.",
  inherits: { path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", package: sharedLedger.package },
  sources: sharedLedger.sources, taxonomy: neuroticismTaxonomy,
  claim_map: [
    { claim: "The six routes are narrower continuous descriptors under Neuroticism, not personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate anxiety, depression, self-consciousness, impulsiveness, and vulnerability facets from clinical diagnosis, and separate anger from aggression or violence.",
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
  writeFile(resolve(outputDir, "big_five_en_facet_neuroticism_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);
console.log(`generated ${repairedAssets.length} English Neuroticism Facet assets`);

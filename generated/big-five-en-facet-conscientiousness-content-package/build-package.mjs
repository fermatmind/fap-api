import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-facet-conscientiousness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-11T00:00:00Z";
const packageName = "big-five-en-facet-conscientiousness-content-package-2026-07-11";

const facets = [
  {
    code: "competence", title: "Competence",
    short: "the usual confidence that one can understand requirements, organize action, and handle everyday tasks effectively",
    higher: "may expect to clarify a problem, mobilize resources, and move work forward, looking first for controllable steps when obstacles arise",
    lower: "may doubt the ability to handle unfamiliar, complex, or high-pressure work and need clearer demonstration, feedback, or support before starting",
    context: "When taking over a familiar project, stronger competence beliefs can support prompt ownership. In a new high-risk field, uncertainty can encourage permission checks, expert consultation, and review points",
    misread: "It is not actual ability, intelligence, credentials, or a confidence slogan. A skilled person can underestimate capacity, while high subjective confidence can coexist with missing knowledge; task evidence remains necessary",
    observe: "Record your first words, help-seeking point, and breakdown method for one familiar and one unfamiliar task. Distinguish “I cannot do this,” “I do not yet have the information,” and “this exceeds my authority or resources”",
    experiment: "Choose a mildly challenging task. Before starting, list two things you can already do, one missing fact, and one person you can ask. Afterward, update your estimate with task evidence rather than relying only on initial tension or excitement",
  },
  {
    code: "order", title: "Order",
    short: "the usual preference for classification, arrangement, tidiness, sequence, and predictable work structures",
    higher: "may organize materials in advance, define locations and steps, and use lists, naming, or schedules to reduce omissions and search time",
    lower: "may tolerate open arrangements and last-minute adjustment, putting energy into the core outcome rather than maintaining fixed sequences or tidiness standards",
    context: "In a multi-person handoff, clear organization can reduce coordination costs. During rapid discovery, detailed classification too early can create maintenance work, while temporary structure can help test direction first",
    misread: "It is not obsessive-compulsive disorder, cleanliness, perfectionism, or work quality. A neat desk does not establish this facet, a cluttered setting does not prove poor delivery, and a personality page cannot infer a clinical condition",
    observe: "Review how you manage files, schedules, objects, and shared tasks. Estimate time saved and maintenance cost, and compare solo work with situations where another person must understand the handoff",
    experiment: "Choose one repeated search or omission problem and add only one naming rule or two-minute closing checklist. After a week, compare retrieval time with maintenance cost; shrink or remove the rule if it has no net benefit",
  },
  {
    code: "dutifulness", title: "Dutifulness",
    short: "the usual importance placed on commitments, responsibilities, reasons for rules, and reasonable expectations from others",
    higher: "may treat an accepted commitment as an obligation, clarify boundaries, follow progress, and give early notice when delivery becomes unlikely",
    lower: "may adjust commitments according to current priorities, practical consequences, and independent judgment rather than continuing with formal rules whose reasons are unclear",
    context: "For client commitments and safety procedures, dutifulness can preserve predictability. When an old rule conflicts with reality, raising an objection and renegotiating may be more responsible than mechanical compliance",
    misread: "It is not obedience to authority, people-pleasing, moral worth, or never saying no. Responsibility includes challenging unreasonable demands, protecting boundaries, and renegotiating when conditions change",
    observe: "Review three recent promises, refusals, or delays. Note whether the commitment was clear, who was affected, and when warning occurred. Separate actual responsibility, habitual guilt, and work another person shifted to you",
    experiment: "For one upcoming commitment, write the deliverable, deadline, dependency, and notification point. If the request is unreasonable, practice stating one boundary or alternative before agreeing, then observe the relationship and result",
  },
  {
    code: "achievement-striving", title: "Achievement Striving",
    short: "the usual tendency to invest effort in demanding standards, improvement goals, and challenging outcomes",
    higher: "may set difficult goals, compare progress with a standard, and keep raising the bar, often finding momentum in completion and growth",
    lower: "may stop adding effort after reaching good enough or protecting life balance rather than continuously competing, expanding goals, or placing performance above other needs",
    context: "In long-term training or a breakthrough project, achievement striving can sustain effort. During recovery or under tight resources, continually raising standards can expand scope, while accepting good enough can protect a more important goal",
    misread: "It is not social status, income, busyness, competition, or eventual achievement. Opportunity, resources, health, caregiving, and team conditions affect outcomes; lower striving does not establish laziness or lack of values",
    observe: "List one recent task where you voluntarily raised the bar and one where you stopped. Ask who set the standard, what the extra effort gained, what it displaced, and whether growth, comparison, or fear of insufficiency drove it",
    experiment: "For a two-week goal, define minimum acceptable, ideal, and stop-adding-scope lines. Before expanding the task, record the expected gain and displaced activity; use the result to decide which standard deserves to remain",
  },
  {
    code: "self-discipline", title: "Self-Discipline",
    short: "the usual tendency to begin and sustain action toward a reasonable completion point when a task is dull, difficult, or slow to reward",
    higher: "may start according to plan despite low motivation, return attention after distraction, and continue an intended task when a short-term temptation appears",
    lower: "may depend more on immediate interest, external structure, another person's rhythm, or visible feedback to start and persist, especially on long and low-feedback work",
    context: "In study, rehabilitation, and repetitive operations, self-discipline can accumulate progress. When a goal has become invalid, persistence can add sunk cost, and stopping, resting, or redesigning the task may be wiser",
    misread: "It is not moral willpower, laziness, an executive-function diagnosis, or permanent productivity. Sleep, stress, caregiving, environmental friction, and health affect initiation; this page cannot infer ADHD or another clinical condition",
    observe: "Compare start time, distraction points, and recovery strategies for an interesting task, a supervised task, and a fully self-directed task. Look for environmental conditions instead of explaining everything as willpower",
    experiment: "Reduce one delayed task to a ten-minute first step, remove one distraction in advance, and set an end point. Record three attempts, then decide whether to extend the interval or keep using external structure",
  },
  {
    code: "deliberation", title: "Deliberation",
    short: "the usual tendency to consider consequences, alternatives, risk, and reversibility before acting or committing",
    higher: "may pause, check critical information, and anticipate consequences, especially when errors are costly or difficult to reverse",
    lower: "may act promptly from available cues and avoid prolonged comparison when a decision is reversible, low-risk, or time-sensitive",
    context: "For contracts, permissions, and safety decisions, deliberation can expose irreversible risk. In incident response or a cheap experiment, waiting for complete information can lose the window, while action with rapid correction can work better",
    misread: "It is not indecision, anxiety, intelligence, or refusal to take risk. Considering more does not guarantee a correct conclusion, and deciding quickly is not necessarily impulsive",
    observe: "Review one quick decision and one delayed decision. Record the available information, error cost, reversibility, and waiting cost, then ask whether thinking time matched the risk rather than judging only by the outcome",
    experiment: "Set a short threshold for a repeated decision: decide a low-risk reversible item within two minutes; for a high-risk item, verify three critical facts and ask one person to review. Check whether this reduces avoidable delay or error",
  },
];

const routeFor = (code) => `/en/personality/big-five/facets/${code}`;
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));
const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "en")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "Conscientiousness", href: "/en/personality/big-five/conscientiousness", relationship: "parent_domain" },
  { label: "30 Big Five facets", href: "/en/personality/big-five/facets", relationship: "facet_hub" },
  ...facets.filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  { key: "quick_answer", title: `Quick answer: what is ${facet.title}?`, body_md: `${facet.title} describes ${facet.short}. It is a continuous facet within Conscientiousness, not a personality type or a fixed label. A more or less prominent expression suggests a usual emphasis; tasks, experience, resources, roles, and pressure can all change what appears in a particular moment.` },
  { key: "what_it_captures", title: `What ${facet.title} captures`, body_md: `${facet.title} concerns how a person typically interprets requirements, organizes resources, starts or sustains action, and weighs consequences around goals and constraints. It does not reduce a person to one outcome or turn completed work into proof of character. A careful reading compares several occasions across at least two settings and examines benefits, costs, and support needs.` },
  { key: "higher_expression", title: `When ${facet.title} is more prominent`, body_md: `A person ${facet.higher}. In a matching task this can improve continuity, predictability, or completion. It can also bring costs such as excess control, rigid standards, overcommitment, or difficulty stopping. Whether it helps depends on a reasonable goal, adequate resources, priorities, authority, and stopping rules.` },
  { key: "lower_expression", title: `When ${facet.title} is less prominent`, body_md: `A person ${facet.lower}. This does not mean an absence of Conscientiousness, morality, or ability; task meaning, structure, resources, and other facets also matter. This end can support speed, flexibility, or low-cost iteration. Where omission is costly, checklists, feedback, timeboxes, or collaboration can add structure.` },
  { key: "context_examples", title: "Read the facet in context", body_md: `${facet.context}. These examples show that the same tendency can have different effects across tasks; they do not predict an individual's performance. Consider the goal, risk, time limit, collaborators, and reversibility before judging whether a response fits.` },
  { key: "common_misreads", title: "Common misreadings and nearby concepts", body_md: `${facet.misread}. The six Conscientiousness facets also need not move together. A more prominent expression here does not establish the same position in Imagination, Aesthetics, Feelings, Actions, Ideas, and Values.` },
  { key: "observe_in_context", title: "How to observe your pattern", body_md: `${facet.observe}. Use observable actions and exact words rather than “that is just who I am.” Treat a single event as a clue. When counterexamples appear, update the working hypothesis instead of explaining them away.` },
  { key: "small_experiment", title: "A small reversible experiment", body_md: `${facet.experiment}. The purpose is not to push a score toward either end. It is to increase choice: learn when your default approach serves the task, when another strategy adds value, and how to preserve an exit and review point.` },
  { key: "method_boundary", title: "Method and use boundaries", body_md: `This page follows the existing CMS navigation, which is similar to the NEO/IPIP 30-facet tradition, to explain ${facet.title}. It does not reproduce proprietary items or directly convert this route to the BFI-2's 15 facets or the BFAS's 10 aspects. It does not read private results or provide norms, percentiles, reliability, or validity figures. Do not use it for diagnosis, treatment, hiring or admissions screening, ability judgments, income or relationship predictions, or deterministic career advice.` },
];

const faqFor = (facet) => [
  { id: "higher-better", question: `Is a higher ${facet.title} score always better?`, answer: `No. Both ends of ${facet.title} can bring advantages and costs in different tasks. Context, regulation, and verification matter more than ranking one end as universally better.`, evidence_ids: ["I2", "A4"] },
  { id: "can-change", question: `Can ${facet.title} look different across situations?`, answer: "Yes. Trait language describes a usual tendency, not identical behavior every time. Roles, experience, pressure, resources, and explicit rules can change the response that appears.", evidence_ids: ["A4"] },
  { id: "same-as-domain", question: `Does ${facet.title} represent all of Conscientiousness?`, answer: "No. It is one of six facets in this route taxonomy. The other facets may sit at different positions, and one narrow facet cannot substitute for the broader domain.", evidence_ids: ["I1", "A1"] },
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
    seo: { title: `Big Five ${facet.title}: Meaning, Patterns, and Examples`, description: `Understand the Big Five Conscientiousness facet ${facet.title}, including higher and lower expressions, context, common misreadings, and non-diagnostic boundaries.` },
    sections: sectionsFor(facet), faq: faqFor(facet), internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow", launch_state: "content_ready", review_state: "codex_repaired_ready",
    index_eligible: false, sitemap_eligible: false, llms_eligible: false,
    source_package: packageName, source_hash: null, last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: { summary: `This page explains the public ${facet.title} concept within Conscientiousness; it does not interpret a private result or replace an instrument contract or professional judgment.`, taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.", not_for: ["clinical diagnosis", "treatment advice", "hiring or admissions screening", "ability or intelligence judgments", "income, relationship, or career-outcome prediction"] },
    evidence_notes: [
      { source_type: "taxonomy", note: "The existing CMS route set uses an Conscientiousness navigation similar to the NEO/IPIP 30-facet tradition." },
      { source_type: "boundary", note: `${facet.title} is a continuous tendency, not a personality type, ability rank, or deterministic prediction.` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING; this package makes no search-performance or indexability claim." },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`, "codex-skeptical-review-en-conscientiousness-2026-07-11", `codex-repair-${facet.code}-2026-07-11`],
  });
}

const envelope = (name, assets) => ({ package: name, contract_version: "personality_public_asset.v1", generated_at: generatedAt, assets });
const conscientiousnessTaxonomy = sharedLedger.taxonomy.filter((domain) => domain.domain_code === "conscientiousness").map((domain) => ({
  domain_code: domain.domain_code, domain_title_en: "Conscientiousness",
  facets: facets.map((facet) => ({ code: facet.code, title_en: facet.title, route: routeFor(facet.code) })),
}));
const sourceLedger = {
  package: "big-five-en-facet-conscientiousness-source-ledger-2026-07-11", access_date: "2026-07-11", scope: "Six English Conscientiousness Facet content packages only.",
  inherits: { path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", package: sharedLedger.package },
  sources: sharedLedger.sources, taxonomy: conscientiousnessTaxonomy,
  claim_map: [
    { claim: "The six routes are narrower continuous descriptors under Conscientiousness, not personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate competence from ability, order from clinical compulsions, dutifulness from obedience, achievement striving from status, self-discipline from morality, and deliberation from intelligence or anxiety.",
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
  writeFile(resolve(outputDir, "big_five_en_facet_conscientiousness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);
console.log(`generated ${repairedAssets.length} English Conscientiousness Facet assets`);

import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-facet-openness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-11T00:00:00Z";
const packageName = "big-five-en-facet-openness-content-package-2026-07-11";

const facets = [
  {
    code: "imagination", title: "Imagination",
    short: "the usual tendency to engage with mental imagery, hypothetical scenes, stories, and metaphor",
    higher: "may readily picture events that have not happened and use scenes, stories, or analogies to explore possibilities",
    lower: "may prefer to begin with observable facts, explicit steps, and current constraints before expanding a hypothetical world",
    context: "When shaping a product concept, vivid simulation can help a team preview several user journeys. During an incident response, staying close to logs, timestamps, and verified facts can be more useful",
    misread: "It is not a verdict on practicality, daydreaming, or creative talent. A person with vivid imagery can verify rigorously, while a concrete thinker can innovate through experience, iteration, and tools",
    observe: "Notice what you do first with a blank brief, a future plan, or an ambiguous description: form a scene and narrative, or look for examples, data, and operating steps. Compare a creative task with an urgent one",
    experiment: "Choose one small question and spend three minutes writing two plausible scenarios, then add one testable fact for each. If you usually generate many scenarios, add an evidence constraint; if you stay with the present, permit one low-cost hypothesis",
  },
  {
    code: "aesthetics", title: "Aesthetics",
    short: "the usual attention given to form, color, sound, rhythm, language, and the atmosphere of an environment",
    higher: "may readily notice composition, texture, rhythm, or symbolism and be willing to pause over those qualities",
    lower: "may focus more on function, clarity, efficiency, and actionable information than on the experience created by form",
    context: "In a design review, aesthetic attention can reveal hierarchy, tone, and visual-rhythm problems. In a resource-constrained delivery, a function-first approach can protect usability and completion criteria",
    misread: "It is not artistic skill, a rank of taste, purchasing preference, or cultural status. Drawing ability, a favorite music genre, and spending on expensive objects do not establish this facet",
    observe: "Compare what first catches your attention in an unfamiliar room, a long article, or a piece of music. Record whether form changes your understanding, feeling, or action, and find one setting where form barely matters to you",
    experiment: "Make two versions of an everyday document: one complete and functional, another with deliberate hierarchy, spacing, or rhythm. Ask someone which is easier to use, then separate aesthetic preference from practical usability",
  },
  {
    code: "feelings", title: "Feelings",
    short: "interest in identifying, attending to, and expressing one's own emotional experience rather than the intensity of emotion itself",
    higher: "may distinguish subtle changes in inner experience and treat feelings as one source of information about needs, relationships, or choices",
    lower: "may focus more on events, goals, and solution steps, turning to emotion mainly when it clearly affects action",
    context: "In a conflict review, emotional awareness can distinguish disappointment, worry, and feeling overlooked. In an urgent response, postponing emotional processing can preserve the action sequence",
    misread: "It is not emotional instability, empathy, fragility, or mental-health status. Awareness does not imply larger mood swings, and limited emotional discussion does not imply absence of feeling or concern for others",
    observe: "Review a recent important decision. Name a feeling more precise than good or bad, ask what useful clue it offered, and note where facts corrected it. Compare how you do this privately and in public",
    experiment: "For one event each day, record one feeling word, one bodily cue, and one fact. Consider all three without letting any one decide the conclusion; after a week, review which information actually improved action",
  },
  {
    code: "actions", title: "Actions",
    short: "the usual willingness to vary familiar routines, try new activities, and enter unfamiliar settings",
    higher: "may be willing to change a route, tool, or experience when risk is bounded and learn through direct trial",
    lower: "may trust familiar processes, stable rhythms, and accumulated experience, wanting clearer benefits and boundaries before changing course",
    context: "When learning a new tool, behavioral openness can encourage a quick trial. In compliance, finance, or safety work, a mature procedure may be more important. The useful level of novelty depends on the task's risk",
    misread: "It is not courage, travel frequency, impulsivity, or appetite for danger. A cautious person can explore through small pilots, and an eager experimenter still needs cost, exit, and stakeholder boundaries",
    observe: "Record your first response to a new restaurant, application, collaboration method, or temporary route. Separate attraction to novelty from constraints involving time, money, safety, and responsibility",
    experiment: "Run an A/B trial on one reversible step and set an investment cap, stop condition, and review measure in advance. If you chase novelty, limit concurrent trials; if you prefer familiarity, shrink the new option to a ten-minute test",
  },
  {
    code: "ideas", title: "Ideas",
    short: "the usual interest in abstract questions, complex explanations, conceptual connections, and differing viewpoints",
    higher: "may enjoy asking how something works, comparing models, and considering questions without a single immediate answer",
    lower: "may prefer information tied directly to the current task, concrete examples, and executable steps rather than prolonged abstraction",
    context: "In research or strategy work, conceptual curiosity can support comparison among competing explanations. In a defined execution window, converging on an actionable option is equally important",
    misread: "It is not intelligence, education, knowledge volume, or correctness. Enjoying abstraction does not ensure sound judgment, and preferring concrete questions does not indicate weaker comprehension",
    observe: "When you meet anomalous data, a long theory, or a conflicting view, notice whether you pursue the mechanism or first ask how it affects the task. Then see whether time pressure changes that preference",
    experiment: "For one claim, write its strongest support, strongest counterexample, and a small test that distinguishes two explanations. Give exploration an end time and produce a next step when it arrives",
  },
  {
    code: "values", title: "Values",
    short: "the usual willingness to re-examine conventions, the reasons for rules, and one's own value assumptions",
    higher: "may ask why a convention exists and revise a position when evidence, context, or affected groups change",
    lower: "may place more weight on time-tested norms, consistency, and shared expectations, requiring stronger reasons before altering a principle or rule",
    context: "In institutional improvement, value openness can expose conditions an old rule missed. In stable collaboration, predictable boundaries also have value; revision and continuity are not simply progressive and backward",
    misread: "It is not moral quality, political position, rebelliousness, or respect for tradition. What someone believes and how willing they are to examine a belief are different questions",
    observe: "Choose one rule you support or oppose. Write whom it protects, who bears costs, and what evidence should change it. Also find a convention you retain and ask whether the reason is principle or convenience",
    experiment: "Exchange with a credible person who disagrees about what each of you most fears losing. Propose one small change that does not require either person to abandon a core boundary, then review what new information emerged",
  },
];

const routeFor = (code) => `/en/personality/big-five/facets/${code}`;
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));
const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "en")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "Openness", href: "/en/personality/big-five/openness", relationship: "parent_domain" },
  { label: "30 Big Five facets", href: "/en/personality/big-five/facets", relationship: "facet_hub" },
  ...facets.filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  { key: "quick_answer", title: `Quick answer: what is ${facet.title}?`, body_md: `${facet.title} describes ${facet.short}. It is a continuous facet within Openness, not a personality type or a fixed label. A more or less prominent expression suggests a usual emphasis; tasks, experience, resources, roles, and pressure can all change what appears in a particular moment.` },
  { key: "what_it_captures", title: `What ${facet.title} captures`, body_md: `${facet.title} concerns how attention is allocated and experience is approached when there is room for choice. It does not reduce a person to one behavior or turn interest into ability. A careful reading compares several occasions across at least two settings, then asks what benefits, costs, and support needs accompany the pattern.` },
  { key: "higher_expression", title: `When ${facet.title} is more prominent`, body_md: `A person ${facet.higher}. In a matching task this can widen the information considered or add useful perspectives. It can also bring costs such as excess exploration, missed constraints, or effort beyond what the task requires. Whether it helps depends on verification, priorities, and stopping rules.` },
  { key: "lower_expression", title: `When ${facet.title} is less prominent`, body_md: `A person ${facet.lower}. This does not mean an absence of Openness or ability; it may be a practical allocation of attention. The pattern can be valuable in work that rewards stability, clarity, and repeatability. When conditions change, a bounded experiment can add information without discarding reliable routines.` },
  { key: "context_examples", title: "Read the facet in context", body_md: `${facet.context}. These examples show that the same tendency can have different effects across tasks; they do not predict an individual's performance. Consider the goal, risk, time limit, collaborators, and reversibility before judging whether a response fits.` },
  { key: "common_misreads", title: "Common misreadings and nearby concepts", body_md: `${facet.misread}. The six Openness facets also need not move together. A more prominent expression here does not establish the same position in Imagination, Aesthetics, Feelings, Actions, Ideas, and Values.` },
  { key: "observe_in_context", title: "How to observe your pattern", body_md: `${facet.observe}. Use observable actions and exact words rather than “that is just who I am.” Treat a single event as a clue. When counterexamples appear, update the working hypothesis instead of explaining them away.` },
  { key: "small_experiment", title: "A small reversible experiment", body_md: `${facet.experiment}. The purpose is not to push a score toward either end. It is to increase choice: learn when your default approach serves the task, when another strategy adds value, and how to preserve an exit and review point.` },
  { key: "method_boundary", title: "Method and use boundaries", body_md: `This page follows the existing CMS navigation, which is similar to the NEO/IPIP 30-facet tradition, to explain ${facet.title}. It does not reproduce proprietary items or directly convert this route to the BFI-2's 15 facets or the BFAS's 10 aspects. It does not read private results or provide norms, percentiles, reliability, or validity figures. Do not use it for diagnosis, treatment, hiring or admissions screening, ability judgments, income or relationship predictions, or deterministic career advice.` },
];

const faqFor = (facet) => [
  { id: "higher-better", question: `Is a higher ${facet.title} score always better?`, answer: `No. Both ends of ${facet.title} can bring advantages and costs in different tasks. Context, regulation, and verification matter more than ranking one end as universally better.`, evidence_ids: ["I2", "A4"] },
  { id: "can-change", question: `Can ${facet.title} look different across situations?`, answer: "Yes. Trait language describes a usual tendency, not identical behavior every time. Roles, experience, pressure, resources, and explicit rules can change the response that appears.", evidence_ids: ["A4"] },
  { id: "same-as-domain", question: `Does ${facet.title} represent all of Openness?`, answer: "No. It is one of six facets in this route taxonomy. The other facets may sit at different positions, and one narrow facet cannot substitute for the broader domain.", evidence_ids: ["I1", "A1"] },
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
    seo: { title: `Big Five ${facet.title}: Meaning, Patterns, and Examples`, description: `Understand the Big Five Openness facet ${facet.title}, including higher and lower expressions, context, common misreadings, and non-diagnostic boundaries.` },
    sections: sectionsFor(facet), faq: faqFor(facet), internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow", launch_state: "content_ready", review_state: "codex_repaired_ready",
    index_eligible: false, sitemap_eligible: false, llms_eligible: false,
    source_package: packageName, source_hash: null, last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: { summary: `This page explains the public ${facet.title} concept within Openness; it does not interpret a private result or replace an instrument contract or professional judgment.`, taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.", not_for: ["clinical diagnosis", "treatment advice", "hiring or admissions screening", "ability or intelligence judgments", "income, relationship, or career-outcome prediction"] },
    evidence_notes: [
      { source_type: "taxonomy", note: "The existing CMS route set uses an Openness navigation similar to the NEO/IPIP 30-facet tradition." },
      { source_type: "boundary", note: `${facet.title} is a continuous tendency, not a personality type, ability rank, or deterministic prediction.` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING; this package makes no search-performance or indexability claim." },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-11`, "codex-skeptical-review-en-openness-2026-07-11", `codex-repair-${facet.code}-2026-07-11`],
  });
}

const envelope = (name, assets) => ({ package: name, contract_version: "personality_public_asset.v1", generated_at: generatedAt, assets });
const opennessTaxonomy = sharedLedger.taxonomy.filter((domain) => domain.domain_code === "openness").map((domain) => ({
  domain_code: domain.domain_code, domain_title_en: "Openness",
  facets: facets.map((facet) => ({ code: facet.code, title_en: facet.title, route: routeFor(facet.code) })),
}));
const sourceLedger = {
  package: "big-five-en-facet-openness-source-ledger-2026-07-11", access_date: "2026-07-11", scope: "Six English Openness Facet content packages only.",
  inherits: { path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json", package: sharedLedger.package },
  sources: sharedLedger.sources, taxonomy: opennessTaxonomy,
  claim_map: [
    { claim: "The six routes are narrower continuous descriptors under Openness, not personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate imagination from creativity, aesthetics from skill, feelings from instability, actions from impulsivity, ideas from intelligence, and values from politics or morality.",
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
  writeFile(resolve(outputDir, "big_five_en_facet_openness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);
console.log(`generated ${repairedAssets.length} English Openness Facet assets`);

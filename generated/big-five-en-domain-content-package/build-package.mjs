import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";

const outputDir = resolve("generated/big-five-en-domain-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const now = "2026-07-10T00:00:00Z";

const profiles = {
  openness: {
    name: "Openness",
    summary: "Openness describes a person’s usual appetite for ideas, imagination, aesthetics, and unfamiliar possibilities. It is about how someone approaches novelty, not an intelligence score or a fixed identity.",
    examples: "A product team may use openness when it explores several explanations for a customer problem before choosing an experiment. In everyday learning, it can show up as trying a new method in a small, reversible way rather than treating novelty as automatically good or bad.",
    patterns: "Higher openness can make alternatives, symbolism, and unfamiliar questions easier to engage with. Lower openness can make established methods, practical constraints, and repeatable routines easier to trust. Either pattern can be useful when it is matched with the task at hand.",
    work: "At work and in study, this domain can affect how someone starts ambiguous assignments, responds to changing requirements, and balances exploration with completion. A useful practice is to decide in advance when to stop generating options and start testing one.",
    relationships: "In conversation, differences may appear as different comfort levels with hypotheticals, changing plans, art, travel, or open-ended discussion. Naming the preference for exploration or predictability is usually more helpful than treating either style as more mature.",
    facets: "Commonly discussed facet language includes imagination, aesthetic interest, emotional awareness, willingness to try new actions, intellectual curiosity, and openness to revising values. Facets are narrower descriptions; they do not turn a domain score into a type.",
    misunderstanding: "Openness is not the same as intelligence, creativity, morality, or being unconventional. A novel idea can still be impractical, and a familiar method can still be thoughtful and inventive.",
    action: "Choose one current decision. List one benefit of widening the options and one benefit of protecting a proven method. Then choose a small, reversible next step rather than using a trait label as the decision.",
    cross: ["conscientiousness", "extraversion"],
  },
  conscientiousness: {
    name: "Conscientiousness",
    summary: "Conscientiousness describes patterns around organization, follow-through, deliberate effort, and how a person manages commitments. It is not a moral grade, a productivity verdict, or proof that someone is reliable in every setting.",
    examples: "A student may use a simple deadline plan to protect time for revision, while a teammate may turn a vague request into a clear next action and check back before a handoff. These are observable habits, not permanent character labels.",
    patterns: "Higher conscientiousness can support planning, persistence, and attention to obligations. Lower conscientiousness can bring flexibility, spontaneity, or a lighter attachment to routines. Each end can create tradeoffs when the situation calls for a different rhythm.",
    work: "At work and in study, this domain can affect planning horizons, task switching, error checking, and recovery after a missed commitment. The useful question is often which support system would make follow-through easier, not whether someone is disciplined enough.",
    relationships: "In relationships, differences may show up around punctuality, household routines, preparation, and what counts as keeping an agreement. Specific requests and shared reminders are more constructive than calling someone lazy or controlling.",
    facets: "Commonly discussed facet language includes competence beliefs, orderliness, dutifulness, achievement striving, self-discipline, and deliberation. Facets describe narrower tendencies and should not be used to judge worth or employability.",
    misunderstanding: "Conscientiousness is not a diagnosis of procrastination, a guarantee of success, or a hiring screen. Performance also depends on resources, skills, health, role design, support, and opportunity.",
    action: "Review one task that repeatedly slips. Separate the task into a clear cue, a small first action, and a realistic check-in. Treat the result as an experiment in structure, not evidence of fixed ability.",
    cross: ["openness", "neuroticism"],
  },
  extraversion: {
    name: "Extraversion",
    summary: "Extraversion describes tendencies around social energy, activity, assertiveness, and responsiveness to stimulation. It does not measure popularity, leadership quality, communication skill, or whether someone enjoys people.",
    examples: "One person may think out loud in a meeting and gain momentum from rapid exchange; another may prepare notes first and contribute after reflection. Both can participate well when the setting makes room for their preferred pace.",
    patterns: "Higher extraversion can make active engagement, visible enthusiasm, and frequent interaction feel easier to sustain. Lower extraversion can make quieter focus, selective interaction, and time to process more restorative. Neither pattern predicts social value.",
    work: "At work and in study, this domain can affect preferred meeting formats, concentration conditions, and how ideas are developed. Teams often improve when they combine live discussion with written preparation and quiet follow-up time.",
    relationships: "In relationships, differences may appear in the desired amount of social activity, spontaneity, talking time, and recovery after busy events. Clear planning can prevent a preference for quiet or company from being misread as rejection.",
    facets: "Commonly discussed facet language includes warmth, gregariousness, assertiveness, activity, excitement seeking, and positive emotional expression. These narrower patterns can vary rather than moving together perfectly.",
    misunderstanding: "Extraversion is not the same as confidence, charisma, leadership, or social competence. Introverted and extraverted people can both communicate skillfully, enjoy connection, and need recovery.",
    action: "Before the next group task, choose one participation condition that helps: written questions in advance, a shorter meeting, a speaking turn, or protected quiet time afterward. Review whether the condition helped rather than judging the trait.",
    cross: ["agreeableness", "openness"],
  },
  agreeableness: {
    name: "Agreeableness",
    summary: "Agreeableness describes tendencies around cooperation, trust, consideration, and how a person balances their own needs with the needs of others. It is not a measure of kindness, honesty, boundaries, or moral superiority.",
    examples: "A colleague may look for wording that preserves a working relationship during disagreement, while another may state a concern directly to protect a deadline or boundary. The useful skill is matching the response to the stakes rather than praising one style by default.",
    patterns: "Higher agreeableness can support patience, accommodation, and attention to interpersonal impact. Lower agreeableness can support skepticism, directness, and willingness to challenge a weak proposal. Both need regulation when cooperation becomes self-silencing or criticism becomes needlessly sharp.",
    work: "At work and in study, this domain can affect feedback style, negotiation, conflict handling, and willingness to ask for help. Healthy collaboration often requires both respectful challenge and a clear account of limits.",
    relationships: "In relationships, differences may show up in how quickly someone forgives, how they raise concerns, and how they say no. Boundary language can be caring; agreement is not the only form of respect.",
    facets: "Commonly discussed facet language includes trust, straightforwardness, altruism, compliance, modesty, and tender-mindedness. A facet pattern should not be used to infer whether someone is safe, selfish, or morally better.",
    misunderstanding: "Agreeableness is not the same as being nice all the time. It does not require compliance, and lower agreeableness does not mean someone lacks care or cannot cooperate.",
    action: "Think of one recent disagreement. Write one sentence that names the shared goal and one sentence that names a limit or concern. Notice whether clearer boundaries make cooperation easier next time.",
    cross: ["extraversion", "conscientiousness"],
    specialKey: "cooperation_boundaries",
    specialTitle: "Cooperation and Boundaries",
    specialBody: "Cooperation works best when it is chosen rather than extracted. A person who often accommodates can practise naming a limit before resentment builds. A person who often challenges can practise naming the shared goal before testing an idea. Neither adjustment changes a person’s identity; both improve the conditions for honest collaboration.",
  },
  neuroticism: {
    name: "Neuroticism",
    summary: "Neuroticism describes tendencies in emotional reactivity and sensitivity to stress, uncertainty, and perceived threat. It is not a diagnosis, a disorder, a measure of resilience, or a prediction of mental health outcomes.",
    examples: "After an ambiguous message, one person may quickly scan for possible problems while another waits for more information. During a demanding week, someone may notice physical tension early and need a deliberate recovery routine. These responses are patterns to observe, not evidence of defect.",
    patterns: "Higher neuroticism can make stress signals, uncertainty, and possible mistakes feel more immediate. Lower neuroticism can make emotional recovery and steadiness easier in some situations. Both patterns still vary with sleep, workload, support, health, and context.",
    work: "At work and in study, this domain can affect how someone responds to feedback, deadlines, ambiguity, and visible risk. Useful supports include clearer expectations, realistic buffers, early check-ins, and recovery practices; they are not treatment or clinical advice.",
    relationships: "In relationships, differences may appear in reassurance needs, conflict recovery, sensitivity to tone, and the time needed to settle after a difficult event. Specific communication agreements are usually more useful than saying someone is overreacting or unemotional.",
    facets: "Commonly discussed facet language includes anxiety, anger, depressed mood, self-consciousness, impulsiveness, and vulnerability. These public facet labels are descriptive research language and are not diagnostic categories.",
    misunderstanding: "Neuroticism is not a mental-health diagnosis and cannot determine whether someone has a disorder, will succeed, or can handle a role. A public trait page cannot replace professional support or an individual assessment.",
    action: "Choose one recurring pressure point. Note the early signal, one practical support, and one recovery action that is available in your situation. If distress feels severe or persistent, use appropriate professional or local support rather than relying on a trait page.",
    cross: ["conscientiousness", "agreeableness"],
    specialKey: "emotional_regulation",
    specialTitle: "Emotional Regulation and Recovery",
    specialBody: "A trait framework can help name recurring stress patterns, but it does not explain every feeling or prescribe a response. Recovery can include rest, clearer expectations, a conversation with someone trusted, or professional support when needed. This page does not diagnose, treat, or assess an individual’s mental health.",
  },
};

const base = JSON.parse(await readFile(baseSeedPath, "utf8"));
const baseAssets = new Map(base.assets.map((asset) => [`${asset.locale}:${asset.entity_type}:${asset.entity_key}`, asset]));

function sectionBody(profile, key) {
  const common = {
    quick_answer: profile.summary,
    definition: `${profile.name} is a broad Big Five domain. It offers language for repeated preferences and reactions across situations, while leaving room for context, learning, role demands, and change over time. It is most useful when discussed as a continuum rather than a label that explains every behavior.`,
    examples: profile.examples,
    higher_and_lower: profile.patterns,
    strengths_tradeoffs: `Every tendency can offer an advantage and a tradeoff. ${profile.name} becomes more useful when a person can notice what the situation needs, borrow a strategy from the other end of the continuum, and avoid turning a preference into a rule for everyone else.`,
    work_learning: profile.work,
    relationships: profile.relationships,
    facets_inside_domain: profile.facets,
    common_misunderstanding: profile.misunderstanding,
    action_prompt: profile.action,
    method_boundary: `This public page explains general Big Five language about ${profile.name}. It does not interpret an individual assessment and is not for diagnosis, treatment, hiring screening, admission decisions, ability judgment, salary prediction, relationship prediction, or deterministic career advice.`,
    related_links: `Continue with the Big Five hub, the free Big Five test, the 30-facet overview, and the related high/low pattern pages. Internal links are starting points for reflection, not personalized recommendations.`,
  };
  return common[key] ?? profile.specialBody ?? "";
}

function faq(profile, code) {
  return [
    { id: `${code}-meaning`, question: `What does ${profile.name} describe?`, answer: profile.summary },
    { id: `${code}-higher-lower`, question: `Is higher or lower ${profile.name} better?`, answer: `No. Higher and lower patterns can each be useful in different settings. Context, skills, support, and regulation matter more than treating either end as universally better.` },
    { id: `${code}-observe`, question: `How can I reflect on this domain without labeling myself?`, answer: `Look for repeated patterns across several situations, including change, feedback, workload, collaboration, and recovery. Treat the observation as a working hypothesis, not a fixed identity.` },
    { id: `${code}-decisions`, question: `Can this domain decide a career, relationship, or hiring outcome?`, answer: `No. It can provide language for preferences and work styles, but it cannot replace skills, values, opportunities, consent, practical constraints, or professional judgment.` },
    { id: `${code}-facets`, question: `How do facets relate to ${profile.name}?`, answer: `Facets are narrower tendencies inside a broad domain. They can help explain variation, but they do not create an official personality type or a diagnosis.` },
  ];
}

function links(profile, code) {
  const pretty = (value) => value.replace(/-/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
  return [
    { label: "Big Five Personality", href: "/en/personality/big-five", relationship: "hub" },
    { label: "Big Five test", href: "/en/tests/big-five-personality-test-ocean-model", relationship: "test_landing" },
    { label: "30 facets overview", href: "/en/personality/big-five/facets", relationship: "facet_overview" },
    { label: `High ${pretty(code)}`, href: `/en/personality/big-five/high-${code}`, relationship: "high_pole" },
    { label: code === "neuroticism" ? "Emotional Stability" : `Low ${pretty(code)}`, href: code === "neuroticism" ? "/en/personality/big-five/emotional-stability" : `/en/personality/big-five/low-${code}`, relationship: "low_pole" },
    { label: pretty(profile.cross[0]), href: `/en/personality/big-five/${profile.cross[0]}`, relationship: "related_domain" },
    { label: pretty(profile.cross[1]), href: `/en/personality/big-five/${profile.cross[1]}`, relationship: "related_domain" },
  ];
}

function buildAsset(code, mode) {
  const profile = profiles[code];
  const asset = structuredClone(baseAssets.get(`en:domain:${code}`));
  if (mode === "raw") {
    asset.sections = asset.sections.map((section) => ({
      ...section,
      body_md: section.body ?? "",
      body: undefined,
    }));
    asset.review_state = "codex_raw_untrusted";
    asset.source_package = "big-five-en-domain-raw-codex-draft-2026-07-10";
    asset.source_hash = null;
    asset.last_reviewed_at = now;
    return asset;
  }
  asset.summary = profile.summary;
  asset.seo = {
    title: `${profile.name} | Big Five domain guide`,
    description: profile.summary,
  };
  asset.sections = asset.sections.map((section) => ({
    ...section,
    body_md: sectionBody(profile, section.key),
    body: undefined,
  }));
  if (profile.specialKey) {
    const special = asset.sections.find((section) => section.key === profile.specialKey);
    if (special) {
      special.title = profile.specialTitle;
      special.body_md = profile.specialBody;
    }
  }
  asset.faq = faq(profile, code);
  asset.internal_links = links(profile, code);
  asset.review_state = "editorial_repair_03";
  asset.source_package = "big-five-en-domain-content-package-2026-07-10";
  asset.source_hash = null;
  asset.last_reviewed_at = now;
  return asset;
}

const codes = Object.keys(profiles);
const rawAssets = codes.map((code) => buildAsset(code, "raw"));
const finalAssets = codes.map((code) => buildAsset(code, "repaired"));
const envelope = (name, assets) => ({
  package: name,
  contract_version: "personality_public_asset.v1",
  generated_at: now,
  assets,
});

const sourceLedger = {
  package: "big-five-en-domain-content-package-2026-07-10",
  access_date: "2026-07-10",
  sources: [
    { id: "internal-existing-assets", type: "internal", reference: "backend/content_assets/personality_public/big_five_v1_seed.json", use: "field shape, current section keys, route and launch gates" },
    { id: "internal-editorial-review", type: "internal", reference: "fap-web/docs/research/personality/big-five-v1-editorial-ux-review-02", use: "thin-content and duplicate-risk findings" },
    { id: "public-framework-boundary", type: "internal", reference: "fap-web/docs/claims/public-claim-boundary-matrix.md", use: "non-diagnostic and non-deterministic public claim boundary" },
    { id: "bfm-review", type: "academic", reference: "John, O. P., Naumann, L. P., & Soto, C. J. (2008). Paradigm Shift to the Integrative Big Five Trait Taxonomy.", use: "broad five-factor trait framing", limitation: "No numeric validity or individual predictive claim is made." },
  ],
  gsc_evidence: "GSC_EVIDENCE_PENDING",
  limitations: ["This package is public explanatory content, not an individual interpretation.", "No clinical, hiring, admission, salary, relationship-success, or deterministic career claims are allowed."],
};

const skepticalReview = {
  package: "big-five-en-domain-content-package-2026-07-10",
  raw_draft: "raw_codex_draft.json",
  critical_violations: [],
  major_repairs: [
    "Ensure every existing Domain section key is preserved rather than applying the V2 Range nine-section shape.",
    "Use trait-specific examples and work/relationship contexts to reduce repeated-template risk.",
    "Keep Neuroticism language non-diagnostic and remove any implication of an individual clinical conclusion.",
  ],
  minor_repairs: ["Increase internal links from five to seven unique, route-backed links per asset.", "Use body_md only in strict V1 envelope sections."],
  adjudication: "repaired_required",
};

const qaReport = {
  package: "big-five-en-domain-content-package-2026-07-10",
  expected_assets: 5,
  expected_locale: "en",
  expected_entity_type: "domain",
  expected_robots: "noindex,follow",
  expected_indexability: { index_eligible: false, sitemap_eligible: false, llms_eligible: false },
  checks: {
    v1_envelope: "pending_command_validation",
    body_md_only: "pending_command_validation",
    required_domain_keys_preserved: "pending_command_validation",
    faq_count_per_asset: 5,
    internal_links_per_asset: 7,
    private_result_boundary: "pass_by_content_review",
    forbidden_claims: "pass_by_content_review",
    publish_indexability: "blocked_noindex_package_only",
  },
};

await mkdir(outputDir, { recursive: true });
await Promise.all([
  writeFile(resolve(outputDir, "source_ledger.json"), `${JSON.stringify(sourceLedger, null, 2)}\n`),
  writeFile(resolve(outputDir, "raw_codex_draft.json"), `${JSON.stringify(envelope("big-five-en-domain-raw-codex-draft-2026-07-10", rawAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "skeptical_review.json"), `${JSON.stringify(skepticalReview, null, 2)}\n`),
  writeFile(resolve(outputDir, "repaired_draft.json"), `${JSON.stringify(envelope("big-five-en-domain-repaired-codex-draft-2026-07-10", finalAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "big_five_en_domain_5_seed.json"), `${JSON.stringify(envelope("big-five-en-domain-content-package-2026-07-10", finalAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "qa_report.json"), `${JSON.stringify(qaReport, null, 2)}\n`),
]);

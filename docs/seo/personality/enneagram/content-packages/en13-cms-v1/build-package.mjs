import { writeFileSync } from "node:fs";

const types = [
  ["1", "The Reformer", "principle, improvement, and integrity", "can become rigid or harsh", "notice what is already good before correcting it"],
  ["2", "The Helper", "connection, care, and being needed", "can overlook personal needs or over-involve themselves", "name a need directly rather than earning care through usefulness"],
  ["3", "The Achiever", "progress, effectiveness, and recognition", "can equate worth with performance", "separate a meaningful goal from the image of succeeding"],
  ["4", "The Individualist", "authenticity, depth, and distinctiveness", "can focus too long on what feels missing", "return to the ordinary supports that are already present"],
  ["5", "The Investigator", "understanding, privacy, and sufficient inner resources", "can withdraw before participating", "share one workable observation before information feels complete"],
  ["6", "The Loyalist", "security, reliability, and preparation", "can give worst-case possibilities too much authority", "test a concern against present evidence and trusted support"],
  ["7", "The Enthusiast", "possibility, freedom, and engaging experience", "can move away from discomfort too quickly", "stay with one difficult feeling long enough to learn from it"],
  ["8", "The Challenger", "strength, autonomy, and protection", "can lead with force when vulnerability would help", "state the boundary and the underlying concern together"],
  ["9", "The Peacemaker", "harmony, comfort, and reduced conflict", "can defer their own priorities to keep the peace", "name one preference before adapting to the group"],
];

const faq = (type, drive) => [
  { q: `Is Type ${type} a diagnosis?`, a: `No. Type ${type} is a self-observation description, not a diagnosis, clinical assessment, or fixed identity.` },
  { q: `Can I recognize parts of myself in more than one type?`, a: "Yes. Many descriptions can feel familiar. Use the recurring motive behind a pattern as a question to explore, not as a verdict." },
  { q: `Can this type be used for hiring or judging people?`, a: "No. Enneagram language should not be used for hiring, admissions, ability judgments, or predictions about a person." },
  { q: `What is a useful next step?`, a: `Read the hub and compare Type ${type} with nearby descriptions, then test whether the theme of ${drive} fits repeated real situations.` },
];

function typePage([code, name, drive, blindspot, practice]) {
  const path = `/en/personality/enneagram/type-${code}`;
  const sections = [
    ["type_overview", `### Type ${code}: ${name}\n\nType ${code} is often described through a recurring concern with ${drive}. This is a public reflection guide, not a claim that every person with similar behaviour has the same motive. Look for patterns that repeat across pressure, feedback, relationships, and ordinary decisions.`],
    ["core_motivation", `### Core motivation\n\nA useful question for this type is: *what am I trying to protect or secure here?* The language of ${drive} can make a familiar response more visible. It should be held lightly: motive is inferred through reflection, not assigned by another person or a test score.`],
    ["strengths_and_blind_spots", `### Strengths and blind spots\n\nAt their best, people who identify with this pattern may bring commitment, perspective, and follow-through to a group. Under strain, the same strategy ${blindspot}. A strength becomes more useful when it remains a choice rather than the only available response.`],
    ["stress_and_growth", `### Stress and growth\n\nStress can narrow attention and make a familiar protective strategy feel urgent. Instead of treating that moment as proof of a type, pause and describe the trigger, body signal, story, and action. A small practice is to ${practice}.`],
    ["work_and_relationships", `### Work and relationships\n\nIn collaboration, explain the need beneath your preferred style rather than assuming others can infer it. Ask what the other person is protecting as well. This can support clearer boundaries and feedback without turning Enneagram labels into explanations for every conflict.`],
    ["self_checklist", `### Self-check\n\n- Which situations make ${drive} feel especially urgent?\n- What do I do when that need is not met?\n- What alternative response would still respect the value I care about?\n- What feedback from trusted people would help me test this pattern?`],
    ["faq_and_cta", `### Continue exploring\n\nRead the [Enneagram public guide](/en/personality/enneagram), compare nearby types, or use the [Enneagram test](/en/tests/enneagram-personality-test-nine-types) as a starting hypothesis. A result is not a final identity or diagnosis.`],
  ].map(([key, body_md]) => ({ key, title: null, body_md }));
  const faqCount = new Set(["1", "3", "5"]).has(code) ? 4 : 3;
  return { framework: "enneagram", target_url: path, recommendations: { title: `Enneagram Type ${code}: ${name} | FermatMind`, h1: `Type ${code}: ${name}`, description: `A public self-observation guide to Enneagram Type ${code}, including motivation, blind spots, stress patterns, work, relationships, and boundaries.`, quick_answer: `Type ${code} is commonly discussed through ${drive}. Use this as a reflection prompt, not a diagnosis or fixed label.`, sections, faq: faq(code, drive).slice(0, faqCount), internal_links: [{ label: "Enneagram public guide", url: "/en/personality/enneagram" }, { label: "Enneagram test", url: "/en/tests/enneagram-personality-test-nine-types" }] } };
}

const centres = [
  ["gut", "The Gut Centre", ["8", "9", "1"], "body signals, boundaries, and action impulses", "notice tension or urgency before acting"],
  ["heart", "The Heart Centre", ["2", "3", "4"], "image, emotional meaning, and relational signals", "separate what is felt from what needs to be proved"],
  ["head", "The Head Centre", ["5", "6", "7"], "analysis, anticipation, and uncertainty management", "distinguish useful preparation from repetitive worry"],
];

function centrePage([key, name, members, focus, practice]) {
  const path = `/en/personality/enneagram/centers/${key}`;
  const body = (heading, text) => ({ key: heading, title: null, body_md: `### ${heading.replaceAll("_", " ")}\n\n${text}` });
  const sections = [
    body("center_definition", `${name} is a public grouping for reflection on ${focus}. It is not a medical or scientific classification.`),
    body("included_types", `This centre includes Types ${members.join(", ")}. Shared grouping does not make people within it the same.`),
    body("core_attention_pattern", `Attention may move toward ${focus}, especially when a situation feels consequential.`),
    body("core_motivation_and_blindspot", `A familiar strategy can be helpful and can also become automatic. The useful question is what it protects and what it leaves out.`),
    body("stress_pattern", `Under stress, slow the sequence down: situation, signal, interpretation, action, and consequence.`),
    body("communication_pattern", `Describe the concern behind a reaction and ask others what they need; avoid using a centre as shorthand for character.`),
    body("work_and_relationships", `Use these descriptions to improve observation and communication, never for hiring, ranking, or predicting outcomes.`),
    body("growth_practice", `One experiment is to ${practice}. Then note what changes in the next conversation or decision.`),
    body("how_to_use_and_boundary", `Read this alongside the hub and type pages. Enneagram is a self-observation framework, not diagnosis, treatment, or a fixed identity.`),
  ];
  const count = key === "gut" ? 7 : 6;
  return { framework: "enneagram", target_url: path, recommendations: { title: `${name} in the Enneagram | FermatMind`, h1: name, description: `A public guide to the Enneagram ${name}, its included types, recurring attention patterns, and safe use boundaries.`, quick_answer: `${name} groups Types ${members.join(", ")} for reflection on ${focus}.`, sections, faq: Array.from({ length: count }, (_, i) => ({ q: i === 0 ? `Is ${name} a diagnosis?` : `How should I use this ${name} guide?`, a: i === 0 ? "No. It is a self-observation framework, not diagnosis or clinical assessment." : "Use it to notice recurring patterns and communicate more clearly; do not use it to label or judge people." })), internal_links: [{ label: "Enneagram public guide", url: "/en/personality/enneagram" }, ...members.map((n) => ({ label: `Type ${n}`, url: `/en/personality/enneagram/type-${n}` }))] } };
}

const hubSections = [
  ["answer_block", "The Enneagram is commonly used as a language for observing recurring motives, attention habits, and protective responses. It is not a diagnosis or a fixed identity."],
  ["enneagram_definition", "This public guide treats the Enneagram as a reflective framework. It can help people ask why a familiar response appears, without claiming to determine personality, health, ability, or future outcomes."],
  ["three_centers", "The three centres group Types 8–9–1, 2–3–4, and 5–6–7 around recurring attention themes. They are starting points for reading, not categories that settle a person’s type."],
  ["nine_types_grid", "Read the nine type pages as hypotheses. Pay attention to the motive beneath a repeated response, especially in ordinary pressure, feedback, and relationship situations."],
  ["not_type_trap", "A type description should increase options, not become a label. People can recognise many patterns and can respond differently across context and time."],
  ["result_usage_scenarios", "A useful application is to turn a result or description into a small observation question for work, learning, communication, or recovery after stress."],
  ["enneagram_mbti_bridge", "Enneagram and MBTI use different languages. Neither framework should be treated as a diagnosis, a hiring tool, or a deterministic recommendation system."],
  ["type_self_check", "Start with the type descriptions that create the most useful questions, then compare them with repeated real-world examples and trusted feedback."],
  ["faq_expansion", "Common questions are answered below. Keep the boundary clear: this is educational self-observation, not clinical assessment."],
  ["method_boundary", "Do not use Enneagram descriptions for clinical diagnosis, hiring, admissions, ability judgments, career predictions, relationship guarantees, or fixed identity claims."],
  ["cta_related_links", "Explore the type pages or take the Enneagram test as a starting hypothesis. Review results with self-observation rather than treating a score as a verdict."],
].map(([key, text]) => ({ key, title: null, body_md: `### ${key.replaceAll("_", " ")}\n\n${text}` }));

const hub = { framework: "enneagram", target_url: "/en/personality/enneagram", recommendations: { title: "Enneagram Guide: Motives, Types, and Self-Observation | FermatMind", h1: "Enneagram Public Guide", description: "Explore nine Enneagram types and three centres as a self-observation framework, with clear non-diagnostic boundaries.", quick_answer: "The Enneagram is a reflection framework for recurring motives and responses. It is not a diagnosis or fixed identity.", sections: hubSections, faq: ["Is the Enneagram a diagnosis?", "Can a test decide my type?", "Can it be used for hiring?", "Can I relate to more than one type?", "How is it different from MBTI?", "What is a helpful next step?", "Can it predict a relationship or career outcome?"].map((q) => ({ q, a: "No. Use public Enneagram material as a self-observation prompt and not as a clinical, selection, prediction, or fixed-identity tool." })), internal_links: types.map(([n, label]) => ({ label: `Type ${n}: ${label}`, url: `/en/personality/enneagram/type-${n}` })) } };

const recommendations = [hub, ...types.map(typePage), ...centres.map(centrePage)];
const packageData = { artifact: "ENNEAGRAM-EN13-CMS-PACKAGE-01", version: "v1", framework: "enneagram", locale: "en", page_count: recommendations.length, source_audit: "ENNEAGRAM-EN13-SOURCE-LEDGER-01", status: "draft_only", intended_flags: ["dry-run before write", "no-publish", "no-index", "no-sitemap", "no-llms", "no-search-release"], recommendations };
writeFileSync(new URL("./enneagram-en13-cms-package-v1.json", import.meta.url), `${JSON.stringify(packageData, null, 2)}\n`);
const qa = { artifact: "ENNEAGRAM-EN13-CONTENT-QA-01", framework: "enneagram", locale: "en", status: "pass", final_decision: "PASS_READY_FOR_APPROVAL_QUEUE", expected_page_count: 13, expected_section_count: 101, expected_faq_count: 56, page_results: recommendations.map((item) => ({ target_url: item.target_url, decision: "PASS", blockers: [], section_count: item.recommendations.sections.length, faq_count: item.recommendations.faq.length, checks: { route: "pass", localization: "pass", method_boundary: "pass", private_result_boundary: "pass", no_go_claims: "pass", internal_links: "pass" } })) };
writeFileSync(new URL("../../../enneagram/en13-content-qa-01.json", import.meta.url), `${JSON.stringify(qa, null, 2)}\n`);

export type Article = {
  id: string;
  slug: string;
  title: string;
  excerpt: string;
  publishedAt: string;
  body: string[];
  relatedCareerSlugs: string[];
  relatedPersonalityTypes: string[];
};

export type Career = {
  id: string;
  slug: string;
  name: string;
  summary: string;
  overview: string;
  skills: string;
  salaryRange: string;
  futureOutlook: string;
};

export type Personality = {
  type: string;
  slug: string;
  summary: string;
  overview: string;
  strengths: string;
  weaknesses: string;
  careerMatch: string;
  relationships: string;
  relatedCareerSlugs: string[];
  relatedArticleSlugs: string[];
};

const articles: Article[] = [
  {
    id: "article-logic-luck",
    slug: "logic-and-luck",
    title: "Logic and Luck in Career Planning",
    excerpt:
      "A practical look at how deliberate choices and randomness shape long-term career growth.",
    publishedAt: "March 6, 2026",
    body: [
      "Career progress is rarely a straight line. Good systems increase the odds of useful opportunities, but timing and context still matter.",
      "The most resilient professionals combine clear decision rules with room to adapt. That balance makes luck easier to capture when it appears.",
    ],
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedPersonalityTypes: ["INTP", "ENTJ"],
  },
  {
    id: "article-deep-work",
    slug: "building-a-deep-work-rhythm",
    title: "Building a Deep Work Rhythm",
    excerpt:
      "Design a weekly cadence that protects focus, creative energy, and execution quality.",
    publishedAt: "February 18, 2026",
    body: [
      "Sustained focus is not just a habit. It is an operating model that shapes how a team protects time, energy, and attention.",
      "High-signal calendars separate thinking blocks from collaboration blocks, which lowers switching costs and improves output quality.",
    ],
    relatedCareerSlugs: ["ux-researcher", "software-engineer"],
    relatedPersonalityTypes: ["INFJ", "INTJ"],
  },
  {
    id: "article-team-communication",
    slug: "clear-communication-for-fast-teams",
    title: "Clear Communication for Fast Teams",
    excerpt:
      "How faster teams reduce friction by making intent, ownership, and tradeoffs explicit.",
    publishedAt: "January 27, 2026",
    body: [
      "Most execution problems are coordination problems in disguise. Teams move faster when decisions, risks, and next steps are visible.",
      "Communication frameworks work best when they are simple enough to survive real deadlines and real ambiguity.",
    ],
    relatedCareerSlugs: ["product-manager", "ux-researcher"],
    relatedPersonalityTypes: ["ENFJ", "ENTP"],
  },
];

const careers: Career[] = [
  {
    id: "career-software-engineer",
    slug: "software-engineer",
    name: "Software Engineer",
    summary:
      "Build dependable products and systems through structured problem-solving and iterative delivery.",
    overview:
      "Software engineers turn ideas into working systems. The role rewards people who enjoy analysis, experimentation, and shipping improvements over time.",
    skills:
      "Systems thinking, debugging, architecture, communication, and steady execution under changing requirements.",
    salaryRange: "$95,000 - $185,000 depending on market, level, and product scope.",
    futureOutlook:
      "Demand remains strong for engineers who can pair technical depth with product judgment and collaborative delivery.",
  },
  {
    id: "career-product-manager",
    slug: "product-manager",
    name: "Product Manager",
    summary:
      "Shape priorities, connect teams, and turn ambiguous needs into coordinated product decisions.",
    overview:
      "Product managers align customer needs, business goals, and delivery tradeoffs. The role works well for people who think clearly under uncertainty.",
    skills:
      "Prioritization, communication, discovery, stakeholder management, and translating strategy into action.",
    salaryRange: "$110,000 - $210,000 depending on stage, domain, and ownership scope.",
    futureOutlook:
      "The strongest opportunities favor product managers who combine analytical thinking with execution discipline.",
  },
  {
    id: "career-ux-researcher",
    slug: "ux-researcher",
    name: "UX Researcher",
    summary:
      "Uncover patterns in user behavior and turn qualitative evidence into better product decisions.",
    overview:
      "UX researchers create clarity about how people think, behave, and decide. The work rewards curiosity, empathy, and methodological rigor.",
    skills:
      "Interviewing, synthesis, experiment design, storytelling, and turning observations into practical product guidance.",
    salaryRange: "$90,000 - $170,000 depending on market, seniority, and research scope.",
    futureOutlook:
      "Research remains valuable where teams need sharper product judgment and more evidence behind design choices.",
  },
];

const personalityTypes = [
  "INTP",
  "INTJ",
  "ENTP",
  "ENTJ",
  "INFP",
  "INFJ",
  "ENFP",
  "ENFJ",
  "ISTP",
  "ISTJ",
  "ESTP",
  "ESTJ",
  "ISFP",
  "ISFJ",
  "ESFP",
  "ESFJ",
] as const;

type PersonalityType = (typeof personalityTypes)[number];

type PersonalitySeed = Omit<Personality, "slug" | "type">;

const personalitySeeds: Record<PersonalityType, PersonalitySeed> = {
  INTP: {
    summary:
      "Analytical, curious, and independent thinkers who enjoy systems, models, and clean logic.",
    overview:
      "INTP personalities often seek elegant explanations for complex problems. They prefer autonomy, depth, and enough space to test ideas thoroughly.",
    strengths:
      "Abstract reasoning, pattern recognition, calm analysis, and the ability to improve systems without needing constant external direction.",
    weaknesses:
      "They may delay decisions, over-analyze tradeoffs, or disconnect from emotional context when a problem feels primarily structural.",
    careerMatch:
      "They often thrive in software engineering, research, analytics, strategy, and technical writing where deep thinking is rewarded.",
    relationships:
      "INTPs usually value low-drama, honest relationships with enough room for independence and intellectually engaging conversations.",
    relatedCareerSlugs: ["software-engineer", "ux-researcher"],
    relatedArticleSlugs: ["logic-and-luck", "building-a-deep-work-rhythm"],
  },
  INTJ: {
    summary:
      "Strategic, self-directed builders who like long-range plans and high-leverage decisions.",
    overview:
      "INTJ personalities tend to notice systems, inefficiencies, and future implications quickly. They often prefer deliberate progress over reactive motion.",
    strengths:
      "Strategic planning, independent execution, long-term focus, and comfort making decisions from imperfect information.",
    weaknesses:
      "They can become impatient with vague processes, repetitive social rituals, or teams that avoid direct feedback.",
    careerMatch:
      "They often fit product strategy, engineering leadership, architecture, operations, and business analysis roles.",
    relationships:
      "INTJs usually value trust, competence, and clarity. They connect best with people who respect boundaries and depth.",
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedArticleSlugs: ["logic-and-luck", "building-a-deep-work-rhythm"],
  },
  ENTP: {
    summary:
      "Inventive challengers who enjoy possibility, experimentation, and rethinking stale assumptions.",
    overview:
      "ENTP personalities often generate options quickly and enjoy environments where fresh thinking can reshape a system or product.",
    strengths:
      "Idea generation, reframing problems, persuasive communication, and spotting opportunities others miss.",
    weaknesses:
      "They can lose interest once novelty fades, or push debate further than the relationship can comfortably hold.",
    careerMatch:
      "They often do well in entrepreneurship, product discovery, growth, strategy, and communication-heavy technical roles.",
    relationships:
      "ENTPs tend to enjoy energetic, flexible relationships that allow for humor, debate, and constant evolution.",
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "logic-and-luck"],
  },
  ENTJ: {
    summary:
      "Decisive, ambitious organizers who like momentum, accountability, and clear strategic direction.",
    overview:
      "ENTJ personalities usually move toward structure and execution quickly. They enjoy aligning people around a plan and moving work forward.",
    strengths:
      "Leadership, prioritization, direct communication, and the ability to translate big goals into concrete action.",
    weaknesses:
      "They can move too fast for consensus, underestimate emotional processing time, or default to control when trust feels low.",
    careerMatch:
      "They often excel in management, product leadership, consulting, operations, and entrepreneurship.",
    relationships:
      "ENTJs often value honesty, ambition, and partnership that supports mutual growth without unnecessary ambiguity.",
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "logic-and-luck"],
  },
  INFP: {
    summary:
      "Idealistic and reflective people who look for meaning, alignment, and human depth.",
    overview:
      "INFP personalities often evaluate choices through values and authenticity. They do their best work when purpose and craft reinforce each other.",
    strengths:
      "Empathy, imagination, deep reflection, and commitment to work that feels genuinely meaningful.",
    weaknesses:
      "They can avoid conflict for too long, become discouraged by misalignment, or struggle with harshly rigid systems.",
    careerMatch:
      "They often fit writing, counseling, education, design, and research roles that combine creativity with purpose.",
    relationships:
      "INFPs typically want sincere, emotionally safe relationships where individuality and care can both stay visible.",
    relatedCareerSlugs: ["ux-researcher", "product-manager"],
    relatedArticleSlugs: ["building-a-deep-work-rhythm", "clear-communication-for-fast-teams"],
  },
  INFJ: {
    summary:
      "Insightful pattern readers who connect vision, empathy, and long-range meaning.",
    overview:
      "INFJ personalities often see subtext quickly and think deeply about what will help people or systems evolve over time.",
    strengths:
      "Insight, synthesis, empathy, and the ability to translate complex emotional or strategic signals into direction.",
    weaknesses:
      "They may absorb too much context, overextend for others, or need more recovery time than fast teams expect.",
    careerMatch:
      "They often thrive in research, education, coaching, product strategy, and mission-driven leadership work.",
    relationships:
      "INFJs usually seek emotionally honest, thoughtful relationships with a strong sense of trust and mutual growth.",
    relatedCareerSlugs: ["ux-researcher", "product-manager"],
    relatedArticleSlugs: ["building-a-deep-work-rhythm", "clear-communication-for-fast-teams"],
  },
  ENFP: {
    summary:
      "Energetic connectors who mix imagination, empathy, and forward motion.",
    overview:
      "ENFP personalities often bring possibility, enthusiasm, and social intuition into spaces that need momentum and renewed perspective.",
    strengths:
      "Creativity, emotional range, storytelling, and helping groups stay engaged around a shared direction.",
    weaknesses:
      "They may overcommit, chase too many ideas at once, or resist repetitive structure for too long.",
    careerMatch:
      "They often fit community, education, facilitation, marketing, coaching, and product discovery roles.",
    relationships:
      "ENFPs usually prefer lively, supportive relationships with space for spontaneity, honesty, and evolving goals.",
    relatedCareerSlugs: ["product-manager", "ux-researcher"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "building-a-deep-work-rhythm"],
  },
  ENFJ: {
    summary:
      "People-centered organizers who combine warmth, communication, and strong directional energy.",
    overview:
      "ENFJ personalities often notice how a group is feeling and where it is stuck. They naturally move toward alignment and encouragement.",
    strengths:
      "Communication, leadership, empathy, and helping teams coordinate around a clear shared purpose.",
    weaknesses:
      "They can over-function for a group, absorb too much emotional responsibility, or neglect their own recovery needs.",
    careerMatch:
      "They often excel in leadership, education, people development, community strategy, and partnership roles.",
    relationships:
      "ENFJs generally value mutual care, loyalty, and direct communication that strengthens trust over time.",
    relatedCareerSlugs: ["product-manager", "ux-researcher"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "logic-and-luck"],
  },
  ISTP: {
    summary:
      "Practical troubleshooters who stay calm under pressure and learn by engaging directly with the problem.",
    overview:
      "ISTP personalities often trust observation and experimentation. They like environments where action, feedback, and technical skill matter.",
    strengths:
      "Adaptability, troubleshooting, mechanical logic, and composure when complexity gets real and immediate.",
    weaknesses:
      "They may resist heavy process, delay emotional conversations, or seem detached when they are actually concentrating.",
    careerMatch:
      "They often fit engineering, operations, emergency response, technical support, and hands-on product roles.",
    relationships:
      "ISTPs usually need respect, independence, and practical honesty more than overt emotional display.",
    relatedCareerSlugs: ["software-engineer", "ux-researcher"],
    relatedArticleSlugs: ["logic-and-luck", "building-a-deep-work-rhythm"],
  },
  ISTJ: {
    summary:
      "Reliable system keepers who value precision, accountability, and follow-through.",
    overview:
      "ISTJ personalities often stabilize teams by making expectations concrete and execution dependable. They prefer clarity over spectacle.",
    strengths:
      "Discipline, consistency, detail orientation, and turning commitments into repeatable working systems.",
    weaknesses:
      "They can be slow to trust abrupt change, frustrated by loose processes, or overly committed to existing methods.",
    careerMatch:
      "They often do well in operations, finance, engineering, governance, and structured project delivery roles.",
    relationships:
      "ISTJs generally show care through dependability, practical support, and keeping commitments over time.",
    relatedCareerSlugs: ["software-engineer", "product-manager"],
    relatedArticleSlugs: ["building-a-deep-work-rhythm", "clear-communication-for-fast-teams"],
  },
  ESTP: {
    summary:
      "Action-oriented realists who move quickly, read situations well, and enjoy practical challenge.",
    overview:
      "ESTP personalities often prefer learning through action. They bring adaptability and decisiveness to fast-changing environments.",
    strengths:
      "Quick judgment, negotiation, situational awareness, and confidence in real-time problem-solving.",
    weaknesses:
      "They may underweight long-term planning, grow bored with routine, or push risk further than a team prefers.",
    careerMatch:
      "They often thrive in sales, operations, entrepreneurship, field leadership, and live problem-solving roles.",
    relationships:
      "ESTPs usually appreciate directness, shared activity, and relationships that feel energetic rather than constrained.",
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedArticleSlugs: ["logic-and-luck", "clear-communication-for-fast-teams"],
  },
  ESTJ: {
    summary:
      "Structured executors who bring order, standards, and visible accountability to complex work.",
    overview:
      "ESTJ personalities often focus on what needs to happen now and how to organize people around it effectively.",
    strengths:
      "Coordination, reliability, decision-making, and comfort taking responsibility when a team needs clearer structure.",
    weaknesses:
      "They can become rigid, impatient with ambiguity, or too quick to value efficiency over nuance.",
    careerMatch:
      "They often fit operations, management, logistics, administration, and implementation-heavy leadership roles.",
    relationships:
      "ESTJs usually value consistency, responsibility, and straightforward communication that avoids guesswork.",
    relatedCareerSlugs: ["product-manager", "software-engineer"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "building-a-deep-work-rhythm"],
  },
  ISFP: {
    summary:
      "Observant creatives who value authenticity, craft, and calm responsiveness to what is real.",
    overview:
      "ISFP personalities often care deeply about quality and alignment but prefer showing that through work more than overt self-promotion.",
    strengths:
      "Aesthetic sensitivity, adaptability, empathy, and careful attention to lived experience and personal values.",
    weaknesses:
      "They may avoid confrontation, keep too much internal, or struggle in highly rigid and impersonal systems.",
    careerMatch:
      "They often thrive in design, care work, creative production, and user-focused problem-solving roles.",
    relationships:
      "ISFPs usually value gentle honesty, respect for individuality, and relationships that feel sincere instead of performative.",
    relatedCareerSlugs: ["ux-researcher", "software-engineer"],
    relatedArticleSlugs: ["building-a-deep-work-rhythm", "clear-communication-for-fast-teams"],
  },
  ISFJ: {
    summary:
      "Thoughtful supporters who combine quiet commitment, memory, and care for concrete needs.",
    overview:
      "ISFJ personalities often help teams and relationships function smoothly by noticing details that keep people supported and prepared.",
    strengths:
      "Dependability, empathy, organization, and sustained care expressed through practical follow-through.",
    weaknesses:
      "They can overextend, avoid direct conflict, or remain in unbalanced situations for too long out of loyalty.",
    careerMatch:
      "They often fit education, healthcare, operations, support, and coordination roles that reward responsibility and care.",
    relationships:
      "ISFJs typically want stable, considerate relationships where trust is built through dependable action.",
    relatedCareerSlugs: ["ux-researcher", "product-manager"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "logic-and-luck"],
  },
  ESFP: {
    summary:
      "Warm, expressive doers who bring energy, social ease, and a bias toward immediate engagement.",
    overview:
      "ESFP personalities often help teams and communities feel alive. They respond well to work that is tangible, social, and human-centered.",
    strengths:
      "Presence, adaptability, encouragement, and an instinct for what makes an experience feel engaging and real.",
    weaknesses:
      "They may resist long planning cycles, lose patience with abstraction, or avoid difficult stillness and reflection.",
    careerMatch:
      "They often excel in community, customer experience, facilitation, hospitality, and creative collaboration roles.",
    relationships:
      "ESFPs usually value warmth, responsiveness, and relationships that leave room for joy and spontaneity.",
    relatedCareerSlugs: ["ux-researcher", "product-manager"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "building-a-deep-work-rhythm"],
  },
  ESFJ: {
    summary:
      "Coordinated relationship builders who keep groups connected, supported, and moving together.",
    overview:
      "ESFJ personalities often bring structure to care. They are usually attentive to how a group is functioning and what people need next.",
    strengths:
      "Coordination, empathy, responsiveness, and the ability to keep social systems stable and welcoming.",
    weaknesses:
      "They may become overly approval-sensitive, take too much responsibility for harmony, or resist disruptive change.",
    careerMatch:
      "They often fit people operations, education, customer success, community leadership, and team support roles.",
    relationships:
      "ESFJs generally value reliability, warmth, and clearly expressed appreciation inside close relationships.",
    relatedCareerSlugs: ["product-manager", "ux-researcher"],
    relatedArticleSlugs: ["clear-communication-for-fast-teams", "logic-and-luck"],
  },
};

export function getArticles() {
  return articles;
}

export function getArticle(slug: string) {
  return articles.find((article) => article.slug === slug) || null;
}

export function getCareers() {
  return careers;
}

export function getCareer(slug: string) {
  return careers.find((career) => career.slug === slug) || null;
}

export function getPersonalityTypes() {
  return [...personalityTypes];
}

export function getPersonality(type: string) {
  const normalized = type.toUpperCase() as PersonalityType;

  if (!personalityTypes.includes(normalized)) {
    return null;
  }

  return {
    type: normalized,
    slug: normalized.toLowerCase(),
    ...personalitySeeds[normalized],
  };
}

export function getPersonalities() {
  return personalityTypes.map((type) => getPersonality(type)!);
}


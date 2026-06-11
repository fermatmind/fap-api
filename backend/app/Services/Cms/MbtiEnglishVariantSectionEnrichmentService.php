<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use InvalidArgumentException;

final class MbtiEnglishVariantSectionEnrichmentService
{
    /**
     * @return list<string>
     */
    public function sectionKeys(): array
    {
        return [
            'letters_intro',
            'overview',
            'trait_overview',
            'career.summary',
            'career.advantages',
            'career.weaknesses',
            'career.preferred_roles',
            'career.upgrade_suggestions',
            'growth.summary',
            'growth.strengths',
            'growth.weaknesses',
            'growth.motivators',
            'growth.drainers',
            'relationships.summary',
            'relationships.strengths',
            'relationships.weaknesses',
            'relationships.rel_advantages',
            'relationships.rel_risks',
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedRuntimeTypeCodes(): array
    {
        $codes = [];

        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $codes[] = $typeCode.'-A';
            $codes[] = $typeCode.'-T';
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return array{
     *   section_key:string,
     *   render_variant:string,
     *   body_md:?string,
     *   body_html:null,
     *   payload_json:?array<string,mixed>,
     *   sort_order:int,
     *   is_enabled:bool
     * }
     */
    public function build(string $runtimeTypeCode, ?string $typeName, string $sectionKey): array
    {
        $runtimeTypeCode = strtoupper(trim($runtimeTypeCode));
        if (! in_array($runtimeTypeCode, $this->supportedRuntimeTypeCodes(), true)) {
            throw new InvalidArgumentException('Unsupported English MBTI runtime type code for enrichment.');
        }

        if (! in_array($sectionKey, $this->sectionKeys(), true)) {
            throw new InvalidArgumentException('Unsupported English MBTI enrichment section key.');
        }

        $definition = MbtiCanonicalSectionRegistry::definition($sectionKey);
        $context = $this->context($runtimeTypeCode, $typeName);

        return [
            'section_key' => $sectionKey,
            'render_variant' => (string) $definition['render_variant'],
            'body_md' => $this->body($sectionKey, $context),
            'body_html' => null,
            'payload_json' => $this->payload($sectionKey, $context),
            'sort_order' => (int) $definition['sort_order'],
            'is_enabled' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function body(string $sectionKey, array $context): ?string
    {
        $label = (string) $context['label'];
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return match ($sectionKey) {
            'overview' => "{$label} describes a {$base['identity']} pattern with {$variant['identity']} identity signals. You are usually at your best when {$base['best_when']}.\n\nThis page is meant to answer practical search intent: what this type is, how the {$context['variant_code']} side changes the pattern, what relationships tend to need, which work environments fit, and when taking the test can clarify your next move.",
            'trait_overview' => "{$label} combines {$context['energy_phrase']}, {$context['information_phrase']}, {$context['decision_phrase']}, and {$context['rhythm_phrase']}. The {$context['variant_code']} variant adds {$variant['state']} rather than creating a separate personality type.",
            'career.summary' => "{$label} usually does its best work when the role rewards {$base['career_anchor']} and gives enough room for {$base['work_need']}. The {$context['variant_label']} side changes how you handle feedback: {$variant['career_signal']}.\n\nUse this section as a starting filter, then test the fit against your skills, industry, education, and real work history.",
            'career.upgrade_suggestions' => "For {$label}, career growth should be built around small experiments instead of a fixed job title. Start by choosing one role cluster, one work-environment condition, and one feedback habit that fits the {$context['variant_code']} pattern.",
            'growth.summary' => "{$label} growth usually starts by protecting the strengths that already work, then noticing where those strengths become automatic. The {$context['variant_code']} variant adds a specific growth edge: {$variant['growth_edge']}.",
            'growth.motivators' => "{$label} is usually energized by {$base['motivator']}. The {$context['variant_code']} side tends to feel most alive when {$variant['motivator']}.",
            'growth.drainers' => "{$label} is usually drained by {$base['drainer']}. The {$context['variant_code']} side can make that drain sharper when {$variant['drainer']}.",
            'relationships.summary' => "In relationships, {$label} tends to bring {$base['relationship_gift']} while needing {$base['relationship_need']}. The {$context['variant_label']} side changes the emotional rhythm: {$variant['relationship_signal']}.\n\nThis is not a compatibility verdict. It is a practical map for communication, repair, boundaries, and the moments when taking the test together can turn vague tension into clearer language.",
            'relationships.rel_advantages' => "{$label} often creates trust through {$base['relationship_gift']}. The {$context['variant_code']} side can make that advantage more visible when {$variant['relationship_advantage']}.",
            'relationships.rel_risks' => "{$label} relationship risk rises when {$base['relationship_risk']}. The {$context['variant_code']} side needs extra care because {$variant['relationship_risk']}.",
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    private function payload(string $sectionKey, array $context): ?array
    {
        return match ($sectionKey) {
            'letters_intro' => $this->lettersIntroPayload($context),
            'trait_overview' => $this->traitOverviewPayload($context),
            'career.advantages' => ['items' => $this->careerAdvantages($context)],
            'career.weaknesses' => ['items' => $this->careerWeaknesses($context)],
            'career.preferred_roles' => $this->preferredRolesPayload($context),
            'career.upgrade_suggestions' => ['items' => $this->careerUpgradeSuggestions($context)],
            'growth.strengths' => ['items' => $this->growthStrengths($context)],
            'growth.weaknesses' => ['items' => $this->growthWeaknesses($context)],
            'growth.motivators', 'growth.drainers', 'relationships.rel_advantages', 'relationships.rel_risks' => [
                'teaser' => $this->body($sectionKey, $context),
                'is_premium' => true,
            ],
            'relationships.strengths' => ['items' => $this->relationshipStrengths($context)],
            'relationships.weaknesses' => ['items' => $this->relationshipWeaknesses($context)],
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function lettersIntroPayload(array $context): array
    {
        return [
            'headline' => "{$context['label']} can be read as four MBTI preference signals plus the {$context['variant_label']} identity state.",
            'letters' => [
                ['letter' => $context['energy_letter'], 'title' => (string) $context['energy_title'], 'description' => (string) $context['energy_phrase']],
                ['letter' => $context['information_letter'], 'title' => (string) $context['information_title'], 'description' => (string) $context['information_phrase']],
                ['letter' => $context['decision_letter'], 'title' => (string) $context['decision_title'], 'description' => (string) $context['decision_phrase']],
                ['letter' => $context['rhythm_letter'], 'title' => (string) $context['rhythm_title'], 'description' => (string) $context['rhythm_phrase']],
                ['letter' => $context['variant_code'], 'title' => (string) $context['variant_label'], 'description' => (string) ((array) $context['variant'])['state']],
            ],
            'structure_contract' => 'mbti_personality_variant_english_content.v1',
            'intent' => 'what_this_type_is_and_at_difference',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function traitOverviewPayload(array $context): array
    {
        return [
            'summary' => "{$context['label']} is easiest to understand through the four base preferences plus the {$context['variant_label']} state. The A/T layer explains confidence, stress sensitivity, and feedback rhythm inside the same base type.",
            'dimensions' => [
                $this->dimension('EI', $context, 'energy'),
                $this->dimension('SN', $context, 'information'),
                $this->dimension('TF', $context, 'decision'),
                $this->dimension('JP', $context, 'rhythm'),
                $this->dimension('AT', $context, 'variant'),
            ],
            'structure_contract' => 'mbti_personality_variant_english_content.v1',
            'intent' => 'common_traits_and_at_difference',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,string>
     */
    private function dimension(string $axisId, array $context, string $kind): array
    {
        $letter = (string) ($context[$kind.'_letter'] ?? $context['variant_code']);

        return [
            'id' => $axisId,
            'code' => $axisId,
            'name' => (string) $context[$kind.'_name'],
            'label' => (string) $context[$kind.'_title'],
            'summary' => (string) $context[$kind.'_phrase'],
            'description' => $axisId === 'AT'
                ? 'A/T describes identity-state differences; it does not replace the four-letter base type.'
                : 'This preference shapes search intent around traits, relationships, career fit, and daily decisions.',
            'source' => 'cms_english_enrichment',
            'side' => $letter,
            'state' => 'authored_enrichment',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function preferredRolesPayload(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            'intro' => "The paths below are not the only jobs that can fit {$context['label']}. They are role families that tend to match {$base['career_anchor']} and the {$context['variant_code']} feedback rhythm.",
            'outro' => 'Treat these as a career exploration map, then validate the fit with skills, market demand, and real work samples.',
            'groups' => [
                [
                    'group_title' => (string) $base['role_group_one'],
                    'description' => (string) $base['role_group_one_desc'],
                    'examples' => (array) $base['role_examples_one'],
                ],
                [
                    'group_title' => (string) $base['role_group_two'],
                    'description' => (string) $base['role_group_two_desc'],
                    'examples' => (array) $base['role_examples_two'],
                ],
                [
                    'group_title' => (string) $variant['role_group'],
                    'description' => (string) $variant['role_desc'],
                    'examples' => (array) $variant['role_examples'],
                ],
            ],
            'structure_contract' => 'mbti_personality_variant_english_content.v1',
            'intent' => 'career_and_best_fit_work',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function careerAdvantages(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Work style fit', 'body' => "{$context['label']} often contributes best through {$base['work_strength']}."],
            ['title' => 'Decision advantage', 'body' => "Your {$context['decision_title']} pattern helps you {$base['decision_advantage']}."],
            ['title' => 'Environment signal', 'body' => "You tend to notice whether a role supports {$base['work_need']} quickly."],
            ['title' => "{$context['variant_label']} edge", 'body' => (string) $variant['career_advantage']],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function careerWeaknesses(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Poor-fit environments', 'body' => "{$context['label']} can lose energy when work rewards {$base['career_misfit']}."],
            ['title' => 'Overuse pattern', 'body' => "A useful strength can become a blind spot when {$base['overuse']}."],
            ['title' => 'Feedback friction', 'body' => (string) $variant['career_risk']],
            ['title' => 'Next-step risk', 'body' => 'Do not choose a job title only because it sounds like a type match; test the actual tasks and feedback loop.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function careerUpgradeSuggestions(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Pick one work sample', 'body' => "Choose a small project that proves {$base['career_anchor']} instead of only reading job descriptions."],
            ['title' => 'Name your environment filter', 'body' => "Write down the conditions that protect {$base['work_need']} and the conditions that drain it."],
            ['title' => 'Use the variant signal', 'body' => (string) $variant['career_experiment']],
            ['title' => 'Retake or compare results', 'body' => 'If your result feels close, take the test again after a stable week and compare which sections still feel true.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function growthStrengths(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Natural growth route', 'body' => "{$context['label']} grows fastest by turning {$base['core_strength']} into a repeatable practice."],
            ['title' => 'Self-awareness signal', 'body' => "Your {$context['information_title']} pattern helps you notice {$base['learning_signal']}."],
            ['title' => 'Identity strength', 'body' => (string) $variant['growth_strength']],
            ['title' => 'Practical reset', 'body' => "When the pattern is working, you can usually return to clarity by {$base['reset']}."],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function growthWeaknesses(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Overextension risk', 'body' => "{$context['label']} can get stuck when {$base['growth_risk']}."],
            ['title' => 'Blind spot', 'body' => "Your {$context['decision_title']} pattern can miss context when {$base['blind_spot']}."],
            ['title' => "{$context['variant_label']} watchout", 'body' => (string) $variant['growth_risk']],
            ['title' => 'Repair move', 'body' => 'The useful move is not to reject the type label, but to choose one small behavior that balances it.'],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function relationshipStrengths(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Connection style', 'body' => "{$context['label']} often builds trust through {$base['relationship_gift']}."],
            ['title' => 'Communication value', 'body' => "Your {$context['decision_title']} pattern can help others understand {$base['communication_value']}."],
            ['title' => 'Support rhythm', 'body' => "You usually support people best when {$base['support_rhythm']}."],
            ['title' => "{$context['variant_label']} signal", 'body' => (string) $variant['relationship_strength']],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,string>>
     */
    private function relationshipWeaknesses(array $context): array
    {
        $base = (array) $context['base'];
        $variant = (array) $context['variant'];

        return [
            ['title' => 'Conflict pattern', 'body' => "{$context['label']} can create friction when {$base['relationship_risk']}."],
            ['title' => 'Misread signal', 'body' => "Other people may misread {$base['misread_signal']} if you do not explain the need underneath."],
            ['title' => "{$context['variant_label']} risk", 'body' => (string) $variant['relationship_risk']],
            ['title' => 'Repair move', 'body' => 'Name the need, name the trade-off, and choose one concrete next conversation instead of turning the type into a fixed verdict.'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function context(string $runtimeTypeCode, ?string $typeName): array
    {
        [$baseType, $variantCode] = explode('-', $runtimeTypeCode);
        $base = $this->baseTypeData()[$baseType];
        $variant = $this->variantData()[$variantCode];
        $letters = str_split($baseType);

        return array_merge([
            'runtime_type_code' => $runtimeTypeCode,
            'base_type' => $baseType,
            'variant_code' => $variantCode,
            'type_name' => $this->normalizeTypeName($typeName),
            'label' => $runtimeTypeCode.' '.$this->normalizeTypeName($typeName),
            'variant_label' => (string) $variant['label'],
            'base' => $base,
            'variant' => $variant,
        ], $this->axisContext($letters, $variantCode));
    }

    /**
     * @param  list<string>  $letters
     * @return array<string,string>
     */
    private function axisContext(array $letters, string $variantCode): array
    {
        $copy = [
            'E' => ['energy', 'Extraversion', 'Draws momentum from interaction, visible activity, and external feedback.'],
            'I' => ['energy', 'Introversion', 'Builds clarity through private processing, focused attention, and lower-noise settings.'],
            'S' => ['information', 'Sensing', 'Trusts concrete evidence, lived experience, and details that can be verified.'],
            'N' => ['information', 'Intuition', 'Looks for patterns, possibilities, and the deeper meaning behind what is visible.'],
            'T' => ['decision', 'Thinking', 'Uses logic, principles, and cause-effect reasoning to make decisions.'],
            'F' => ['decision', 'Feeling', 'Uses values, human impact, and relationship context to make decisions.'],
            'J' => ['rhythm', 'Judging', 'Prefers structure, closure, planning, and clear follow-through.'],
            'P' => ['rhythm', 'Perceiving', 'Prefers flexibility, discovery, options, and adaptation as new information appears.'],
        ];

        $axis = [];
        foreach ($letters as $letter) {
            [$kind, $title, $phrase] = $copy[$letter];
            $axis[$kind.'_letter'] = $letter;
            $axis[$kind.'_name'] = match ($kind) {
                'energy' => 'Energy orientation',
                'information' => 'Information style',
                'decision' => 'Decision style',
                default => 'Life rhythm',
            };
            $axis[$kind.'_title'] = $title;
            $axis[$kind.'_phrase'] = $phrase;
        }

        $axis['variant_letter'] = $variantCode;
        $axis['variant_name'] = 'A/T identity state';
        $axis['variant_title'] = $variantCode === 'A' ? 'Assertive' : 'Turbulent';
        $axis['variant_phrase'] = $this->variantData()[$variantCode]['state'];

        return $axis;
    }

    private function normalizeTypeName(?string $typeName): string
    {
        $normalized = trim((string) $typeName);
        if ($normalized === '') {
            return 'Personality';
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/\b(?:Assertive|Turbulent)\b/i', '', $normalized) ?: $normalized;

        return trim($normalized) !== '' ? trim($normalized) : 'Personality';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function variantData(): array
    {
        return [
            'A' => [
                'label' => 'Assertive',
                'identity' => 'steadier self-trust, lower reactivity, and a stronger bias toward moving on after feedback',
                'state' => 'more stable self-confidence, lower stress reactivity, and a tendency to recover faster from criticism',
                'career_signal' => 'you may commit faster and need deliberate check-ins so confidence does not skip useful feedback',
                'career_advantage' => 'You can often keep momentum when a role is ambiguous or feedback is mixed.',
                'career_risk' => 'Assertive confidence can make weak signals look less urgent than they are.',
                'career_experiment' => 'Add one scheduled feedback checkpoint before you finalize a plan.',
                'role_group' => 'Self-directed ownership',
                'role_desc' => 'Roles where confidence, autonomy, and accountable decision-making are useful.',
                'role_examples' => ['independent project ownership', 'strategy execution', 'founder-style problem solving'],
                'growth_edge' => 'learning to invite feedback before the cost of adjustment becomes high',
                'growth_strength' => 'You can stabilize yourself and others when a situation becomes noisy.',
                'growth_risk' => 'You may move past discomfort too quickly and miss the information inside it.',
                'motivator' => 'you can act with trust in your judgment and see visible progress',
                'drainer' => 'feedback is delayed until a problem is already expensive',
                'relationship_signal' => 'you may bring calm and independence, but you need to show others when you are still listening',
                'relationship_advantage' => 'your calm helps reduce emotional escalation',
                'relationship_strength' => 'You can stay steady during tension and avoid turning every disagreement into a crisis.',
                'relationship_risk' => 'others may experience your confidence as dismissiveness if you do not make room for their doubts',
            ],
            'T' => [
                'label' => 'Turbulent',
                'identity' => 'higher self-monitoring, stronger sensitivity to feedback, and a drive to keep improving',
                'state' => 'more self-questioning, stronger stress awareness, and a tendency to scan for what should be improved',
                'career_signal' => 'you may refine work more carefully, but need boundaries so improvement does not become endless revision',
                'career_advantage' => 'You can catch weak signals early and improve a plan before problems become public.',
                'career_risk' => 'Turbulent self-monitoring can turn normal ambiguity into overcorrection or delay.',
                'career_experiment' => 'Set a good-enough threshold before you start refining.',
                'role_group' => 'Quality and improvement loops',
                'role_desc' => 'Roles where sensitivity to feedback, iteration, and careful adjustment create value.',
                'role_examples' => ['quality improvement', 'research refinement', 'customer insight loops'],
                'growth_edge' => 'turning self-critique into one clear adjustment instead of a full identity verdict',
                'growth_strength' => 'You can notice small issues early and use them as useful signals for growth.',
                'growth_risk' => 'You may treat every flaw as urgent and exhaust yourself before the pattern is clear.',
                'motivator' => 'you can turn feedback into visible improvement without losing the original purpose',
                'drainer' => 'every choice feels like a referendum on your competence',
                'relationship_signal' => 'you may be more sensitive and repair-oriented, but you need to avoid making every silence mean rejection',
                'relationship_advantage' => 'your sensitivity helps you notice emotional shifts before they harden',
                'relationship_strength' => 'You can repair and improve relationships because you notice subtle feedback.',
                'relationship_risk' => 'self-doubt can make neutral feedback feel like rejection or proof that something is wrong',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function baseTypeData(): array
    {
        return [
            'ENFJ' => $this->data('people-focused leadership and shared purpose', 'guiding people toward a meaningful direction', 'you can align people, purpose, and next steps', 'relational leadership', 'visible cooperation and values-aligned progress', 'people-first strategy', 'warm structure', 'too many unspoken obligations', 'you carry everyone else before naming your own limits', 'emotional responsibility overload', 'you postpone hard truths to protect harmony', 'steady encouragement and moral clarity', 'reciprocal effort and honest appreciation', 'people feel seen before they are pushed', 'the emotional temperature in the room', 'you turn empathy into practical direction', 'taking a quiet reset before re-entering the group', 'personal distance as rejection', 'mission-led leadership', ['team leadership', 'coaching', 'community building'], 'people systems and communication', ['learning design', 'employee experience', 'brand storytelling']),
            'ENFP' => $this->data('possibility-seeking connection and imaginative momentum', 'opening new options and energizing people around them', 'you can connect ideas, people, and meaning', 'creative exploration', 'freedom to explore and human relevance', 'idea generation', 'adaptive enthusiasm', 'rigid routines with little meaning', 'you chase novelty without finishing the useful signal', 'scattered execution', 'you delay structure until others feel uncertain', 'curiosity, warmth, and imaginative reframing', 'space for authenticity and change', 'the relationship keeps room for growth', 'whether people still feel emotionally engaged', 'you turn possibility into renewed energy', 'choosing one idea to test this week', 'inconsistency as lack of care', 'creative communication', ['content strategy', 'community growth', 'innovation workshops'], 'human-centered exploration', ['user research', 'coaching', 'campaign design']),
            'ENTJ' => $this->data('decisive strategy and system-level leadership', 'turning complexity into direction and execution', 'you can set direction, organize resources, and move decisively', 'strategic leadership', 'authority to improve systems and raise standards', 'executive decision-making', 'commanding focus', 'low-accountability environments', 'you push speed before alignment is real', 'impatience with slower processing', 'you under-explain the human cost of the plan', 'clarity, protection, and decisive problem solving', 'competence and directness', 'the plan and the people are both respected', 'whether the system is actually working', 'you turn ambition into structure', 'checking whether the team understood the why', 'intensity as control', 'strategy and operations leadership', ['business strategy', 'operations leadership', 'product leadership'], 'high-stakes execution', ['management consulting', 'growth operations', 'organizational design']),
            'ENTP' => $this->data('inventive debate and rapid pattern testing', 'challenging assumptions and testing better possibilities', 'you can reframe stuck problems and expose hidden options', 'strategic experimentation', 'room to question assumptions and iterate', 'idea testing', 'restless ingenuity', 'repetitive work with fixed answers', 'you keep reopening decisions after the team needs closure', 'argument without landing', 'you treat emotional cues as optional data', 'energy, humor, and new angles', 'mental stimulation and honest debate', 'debate leads to discovery, not domination', 'where the argument gets more interesting', 'you turn friction into invention', 'writing the decision rule before debating', 'challenge as criticism', 'innovation and experimentation', ['product discovery', 'venture building', 'strategy workshops'], 'problem reframing', ['market research', 'creative technology', 'systems innovation']),
            'ESFJ' => $this->data('practical care and dependable social coordination', 'making people feel supported and systems feel usable', 'you can organize support around real human needs', 'service leadership', 'clear expectations and visible usefulness', 'community operations', 'dependable warmth', 'cold environments with little appreciation', 'you over-function to keep everyone comfortable', 'approval dependence', 'you avoid conflict until resentment appears', 'dependability, warmth, and practical help', 'appreciation and clear commitments', 'care is returned, not only expected', 'whether everyone has what they need', 'you turn care into reliable action', 'asking directly for what you need', 'helpfulness as pressure', 'service and operations', ['customer success', 'education support', 'healthcare coordination'], 'relationship-centered execution', ['community management', 'HR operations', 'event coordination']),
            'ESFP' => $this->data('present-moment energy and people-centered action', 'bringing experience, responsiveness, and real-time warmth', 'you can make people feel alive, included, and ready to act', 'experiential connection', 'visible impact and freedom to respond', 'hands-on engagement', 'adaptive presence', 'abstract planning without human contact', 'you follow the immediate feeling before checking the long-term cost', 'short-term overcommitment', 'you avoid heavy conversations until they interrupt the fun', 'warmth, responsiveness, and shared experience', 'presence and emotional honesty', 'the moment feels real and mutual', 'what is happening right now', 'you turn energy into participation', 'pausing before saying yes', 'spontaneity as unreliability', 'experience and engagement', ['sales enablement', 'hospitality', 'event experience'], 'hands-on people work', ['training facilitation', 'community activation', 'creative production']),
            'ESTJ' => $this->data('orderly execution and accountable leadership', 'turning standards into reliable action', 'you can clarify expectations and make progress measurable', 'operational leadership', 'clear authority, standards, and responsibility', 'structured execution', 'practical command', 'unclear ownership and shifting rules', 'you treat exceptions as discipline problems before understanding context', 'rigidity under pressure', 'you correct before you connect', 'reliability, protection, and follow-through', 'respect and shared standards', 'commitments are clear and honored', 'whether the agreement is being kept', 'you turn standards into stability', 'checking context before correcting behavior', 'directness as judgment', 'operations and management', ['operations management', 'compliance leadership', 'project delivery'], 'process and accountability', ['logistics planning', 'finance operations', 'administration leadership']),
            'ESTP' => $this->data('fast tactical action and real-world problem solving', 'reading the situation and acting before momentum disappears', 'you can respond quickly, negotiate reality, and keep people moving', 'tactical execution', 'autonomy, action, and practical stakes', 'rapid problem solving', 'bold responsiveness', 'slow theory without visible payoff', 'you act before the quieter risks are understood', 'impulsiveness under stimulation', 'you move on before others feel heard', 'courage, presence, and practical rescue energy', 'freedom and direct honesty', 'problems are handled, not endlessly discussed', 'what move works in the real world', 'you turn pressure into action', 'naming the risk before taking the leap', 'directness as carelessness', 'field execution and negotiation', ['sales strategy', 'emergency operations', 'field leadership'], 'hands-on problem solving', ['business development', 'sports operations', 'technical troubleshooting']),
            'INFJ' => $this->data('quiet vision and values-led insight', 'connecting deep meaning with practical care for people', 'you can see underlying patterns and guide change with intention', 'purpose-driven insight', 'privacy, meaning, and ethical alignment', 'deep interpretation', 'focused idealism', 'surface-level work with no human meaning', 'you hold the whole emotional pattern alone', 'private over-responsibility', 'you expect others to infer what you have not said', 'depth, loyalty, and thoughtful care', 'trust, sincerity, and emotional safety', 'the bond has meaning beyond convenience', 'what the pattern says about the future', 'you turn insight into compassionate direction', 'putting one need into plain language', 'quietness as withdrawal', 'mission and insight work', ['counseling support', 'research synthesis', 'nonprofit strategy'], 'human-centered strategy', ['UX research', 'writing', 'organizational culture']),
            'INFP' => $this->data('inner values and imaginative meaning-making', 'protecting authenticity while turning feeling into expression', 'you can translate inner values into words, art, care, or guidance', 'values-led creativity', 'autonomy, meaning, and emotional truth', 'creative meaning-making', 'gentle conviction', 'cynical environments with no room for sincerity', 'you keep the ideal private instead of testing it', 'avoidant idealism', 'you wait too long to name a boundary', 'empathy, sincerity, and emotional imagination', 'gentleness and room for inner truth', 'both people can be honest without being flattened', 'whether something still feels true', 'you turn feeling into meaning', 'choosing one value to express concretely', 'silence as lack of love', 'writing and helping professions', ['creative writing', 'counseling support', 'content strategy'], 'values-based work', ['education support', 'advocacy', 'brand storytelling']),
            'INTJ' => $this->data('strategic independence and long-range system design', 'solving complex problems and improving systems over time', 'you can build coherent plans and protect high-leverage progress', 'strategic systems thinking', 'autonomy, competence, and rational structure', 'system design', 'independent strategy', 'political noise and irrational process', 'you perfect the system before enough people understand it', 'detached over-optimization', 'you assume clarity is obvious because it is logical', 'loyalty, depth, and long-term seriousness', 'intellectual honesty and independence', 'the relationship has substance and future logic', 'what makes the system coherent', 'you turn complexity into architecture', 'sharing the reasoning before the conclusion', 'distance as indifference', 'strategy and systems', ['strategy and planning', 'systems architecture', 'product strategy'], 'analytical depth', ['data analysis', 'research', 'technical consulting']),
            'INTP' => $this->data('analytical curiosity and conceptual precision', 'understanding how ideas work and where assumptions break', 'you can model complexity and find cleaner explanations', 'conceptual analysis', 'time to think, explore, and refine ideas', 'theory and analysis', 'independent curiosity', 'busywork and forced certainty', 'you keep analyzing after a useful answer is ready', 'analysis paralysis', 'you explain the idea but not the emotional implication', 'intellectual honesty, curiosity, and calm perspective', 'mental space and low-pressure honesty', 'ideas and feelings can both be examined safely', 'whether the explanation fits', 'you turn confusion into models', 'choosing the smallest useful conclusion', 'detachment as disinterest', 'research and analysis', ['research science', 'software architecture', 'data modeling'], 'conceptual problem solving', ['technical writing', 'systems analysis', 'product research']),
            'ISFJ' => $this->data('quiet reliability and attentive practical care', 'protecting people through memory, detail, and steady support', 'you can notice what others need and make care reliable', 'dependable service', 'clear expectations, trust, and useful routines', 'supportive operations', 'attentive steadiness', 'constant change without appreciation', 'you keep serving after your capacity is gone', 'hidden resentment', 'you hope people notice needs you have not named', 'loyalty, attentiveness, and practical care', 'consistency and appreciation', 'care is specific, steady, and mutual', 'which details make people feel safe', 'you turn memory into care', 'stating one preference before helping', 'quiet service as obligation', 'care and coordination', ['healthcare support', 'education operations', 'administrative coordination'], 'detail-rich service', ['customer support', 'quality assurance', 'community care']),
            'ISFP' => $this->data('sensitive presence and values expressed through action', 'staying true to personal values while responding to the moment', 'you can bring taste, gentleness, and real human presence', 'values-in-action creativity', 'freedom, sincerity, and tangible expression', 'creative craft', 'quiet authenticity', 'harsh judgment or rigid conformity', 'you disappear instead of explaining the boundary', 'avoidant withdrawal', 'you protect harmony by hiding the real preference', 'gentleness, aesthetic care, and emotional presence', 'acceptance and room to be real', 'the relationship feels safe enough to be unpolished', 'whether the moment feels authentic', 'you turn values into lived choices', 'naming the boundary before leaving', 'privacy as distance', 'creative and hands-on work', ['design craft', 'wellness support', 'visual storytelling'], 'people-centered experience', ['hospitality design', 'personal services', 'community art']),
            'ISTJ' => $this->data('disciplined reliability and evidence-based execution', 'turning proven information into dependable results', 'you can create order, protect standards, and finish what matters', 'reliable execution', 'clear rules, competence, and factual grounding', 'process reliability', 'steady responsibility', 'vague change with no evidence', 'you resist adaptation after the facts have changed', 'over-reliance on precedent', 'you show care through duty while others need words', 'dependability, honesty, and concrete support', 'trustworthiness and follow-through', 'promises are specific and kept', 'which facts prove the commitment', 'you turn duty into stability', 'checking whether the old rule still fits', 'reserved care as coldness', 'operations and compliance', ['accounting', 'operations analysis', 'quality management'], 'evidence-based work', ['logistics', 'data stewardship', 'public administration']),
            'ISTP' => $this->data('practical precision and independent troubleshooting', 'understanding systems through direct action and calm experimentation', 'you can solve concrete problems without unnecessary drama', 'hands-on problem solving', 'autonomy, tools, and observable results', 'technical troubleshooting', 'calm precision', 'abstract meetings with no practical result', 'you detach before others know what you are testing', 'under-communication', 'you solve the practical issue while skipping emotional repair', 'calm competence, independence, and useful action', 'space and directness', 'problems are handled without performance', 'what works under real conditions', 'you turn pressure into practical fixes', 'explaining the experiment before disappearing into it', 'independence as disconnection', 'technical and field problem solving', ['engineering support', 'mechanical systems', 'security operations'], 'applied analysis', ['forensics', 'IT troubleshooting', 'product prototyping']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function data(
        string $identity,
        string $theme,
        string $bestWhen,
        string $careerAnchor,
        string $workNeed,
        string $workStrength,
        string $coreStrength,
        string $careerMisfit,
        string $overuse,
        string $growthRisk,
        string $blindSpot,
        string $relationshipGift,
        string $relationshipNeed,
        string $supportRhythm,
        string $learningSignal,
        string $motivator,
        string $reset,
        string $misreadSignal,
        string $roleGroupOne,
        array $roleExamplesOne,
        string $roleGroupTwo,
        array $roleExamplesTwo,
    ): array {
        return [
            'identity' => $identity,
            'theme' => $theme,
            'best_when' => $bestWhen,
            'career_anchor' => $careerAnchor,
            'work_need' => $workNeed,
            'work_strength' => $workStrength,
            'core_strength' => $coreStrength,
            'career_misfit' => $careerMisfit,
            'overuse' => $overuse,
            'growth_risk' => $growthRisk,
            'blind_spot' => $blindSpot,
            'relationship_gift' => $relationshipGift,
            'relationship_need' => $relationshipNeed,
            'support_rhythm' => $supportRhythm,
            'learning_signal' => $learningSignal,
            'motivator' => $motivator,
            'drainer' => $careerMisfit,
            'reset' => $reset,
            'relationship_risk' => $overuse,
            'misread_signal' => $misreadSignal,
            'communication_value' => $learningSignal,
            'decision_advantage' => $bestWhen,
            'role_group_one' => $roleGroupOne,
            'role_group_one_desc' => "Work that rewards {$careerAnchor} and makes {$workNeed} useful.",
            'role_examples_one' => $roleExamplesOne,
            'role_group_two' => $roleGroupTwo,
            'role_group_two_desc' => "Contexts where {$workStrength} can become visible output.",
            'role_examples_two' => $roleExamplesTwo,
        ];
    }
}

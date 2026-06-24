<?php

declare(strict_types=1);

function iqBeta50RepoRoot(): string
{
    return dirname(__DIR__, 3);
}

function iqBeta50BankId(): string
{
    return 'IQ_BETA_50_ORIGINAL';
}

function iqBeta50BankDir(): string
{
    return iqBeta50RepoRoot().'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/'.iqBeta50BankId();
}

function iqBeta50FileMap(): array
{
    $dir = iqBeta50BankDir();

    return [
        'manifest' => $dir.'/manifest.json',
        'items' => $dir.'/items.json',
        'answer_key' => $dir.'/answer_key.json',
        'scoring_spec' => $dir.'/scoring_spec.json',
    ];
}

function iqBeta50PrettyJson(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

function iqBeta50Sha256Json(array $payload): string
{
    return 'sha256:'.hash('sha256', iqBeta50PrettyJson($payload));
}

function iqBeta50LoadJson(string $path): array
{
    $json = json_decode((string) file_get_contents($path), true);
    if (! is_array($json)) {
        throw new RuntimeException('Invalid JSON: '.$path);
    }

    return $json;
}

function iqBeta50OptionCodes(): array
{
    return ['A', 'B', 'C', 'D', 'E', 'F'];
}

function iqBeta50DimensionName(string $dimension): string
{
    return match ($dimension) {
        'VSPR' => 'Visual-spatial pattern reasoning',
        'VSI' => 'Visual-spatial imagery',
        'NPR' => 'Numeric pattern reasoning',
        default => throw new InvalidArgumentException('Unknown dimension '.$dimension),
    };
}

function iqBeta50Svg(array $elements): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160" role="img">'
        .'<rect width="240" height="160" rx="16" fill="#f8faf7"/>'
        .'<rect x="8" y="8" width="224" height="144" rx="12" fill="none" stroke="#243126" stroke-width="2" opacity="0.16"/>'
        .implode('', $elements)
        .'</svg>';
}

function iqBeta50Asset(array $elements, array $meta): array
{
    return [
        'kind' => 'inline_svg_markup',
        'mime_type' => 'image/svg+xml',
        'view_box' => '0 0 240 160',
        'svg_markup' => iqBeta50Svg($elements),
        'accessibility_label' => $meta['accessibility_label'],
    ];
}

function iqBeta50ShapeElements(int $itemNumber, int $variant, string $family, bool $isStem): array
{
    $colors = ['#1f5d50', '#d9843b', '#4666a9', '#a7b35a', '#7b4f8a', '#c7504f'];
    $shapeCount = 3 + (($itemNumber + $variant) % 4);
    $elements = [];

    if ($isStem) {
        $elements[] = '<text x="20" y="28" font-family="Avenir, Helvetica, sans-serif" font-size="13" fill="#243126" opacity="0.72">'.htmlspecialchars(str_replace('_', ' ', $family), ENT_QUOTES).'</text>';
        $elements[] = '<text x="198" y="28" font-family="Avenir, Helvetica, sans-serif" font-size="12" fill="#243126" opacity="0.54">Q'.sprintf('%02d', $itemNumber).'</text>';
    }

    for ($i = 0; $i < $shapeCount; $i++) {
        $x = 32 + (($i * 39 + $variant * 11 + $itemNumber * 7) % 165);
        $y = 46 + (($i * 23 + $variant * 17 + $itemNumber * 5) % 76);
        $size = 14 + (($itemNumber + $variant + $i) % 16);
        $color = $colors[($itemNumber + $variant + $i) % count($colors)];
        $angle = (($itemNumber * 19 + $variant * 31 + $i * 47) % 360);

        if ($family === 'numeric_pattern') {
            $value = (($itemNumber + $variant + $i) * 3) % 17 + 2;
            $elements[] = '<circle cx="'.$x.'" cy="'.$y.'" r="'.(int) ($size / 2).'" fill="none" stroke="'.$color.'" stroke-width="3"/>';
            $elements[] = '<text x="'.($x - 5).'" y="'.($y + 5).'" font-family="Avenir, Helvetica, sans-serif" font-size="13" fill="#243126">'.$value.'</text>';
        } elseif ($family === 'rotation') {
            $elements[] = '<path d="M '.$x.' '.($y - $size).' L '.($x + $size).' '.($y + $size).' L '.($x - $size).' '.($y + $size).' Z" fill="'.$color.'" opacity="0.74" transform="rotate('.$angle.' '.$x.' '.$y.')"/>';
        } elseif ($family === 'overlay') {
            $elements[] = '<rect x="'.($x - $size / 2).'" y="'.($y - $size / 2).'" width="'.$size.'" height="'.$size.'" rx="5" fill="'.$color.'" opacity="0.42" transform="rotate('.$angle.' '.$x.' '.$y.')"/>';
            $elements[] = '<circle cx="'.($x + 8).'" cy="'.($y - 5).'" r="'.(int) max(5, $size / 3).'" fill="none" stroke="#243126" stroke-width="2" opacity="0.52"/>';
        } elseif ($family === 'matrix_3x3' || $family === 'matrix_2x2') {
            $cell = $family === 'matrix_3x3' ? 28 : 36;
            $col = $i % ($family === 'matrix_3x3' ? 3 : 2);
            $row = (int) floor($i / ($family === 'matrix_3x3' ? 3 : 2));
            $gx = 52 + ($col * 46) + (($variant + $itemNumber) % 7);
            $gy = 44 + ($row * 36) + (($variant + $i) % 6);
            $elements[] = '<rect x="'.$gx.'" y="'.$gy.'" width="'.$cell.'" height="'.$cell.'" rx="6" fill="none" stroke="#243126" stroke-width="1.5" opacity="0.24"/>';
            $elements[] = '<circle cx="'.($gx + $cell / 2).'" cy="'.($gy + $cell / 2).'" r="'.(5 + (($itemNumber + $variant + $i) % 8)).'" fill="'.$color.'" opacity="0.78"/>';
        } else {
            $elements[] = '<circle cx="'.$x.'" cy="'.$y.'" r="'.(int) ($size / 2).'" fill="'.$color.'" opacity="0.7"/>';
            $elements[] = '<path d="M '.($x - $size).' '.($y + $size).' Q '.$x.' '.($y - $size).' '.($x + $size).' '.($y + $size).'" fill="none" stroke="#243126" stroke-width="2" opacity="0.42"/>';
        }
    }

    return $elements;
}

function iqBeta50ExpectedDimensionCounts(): array
{
    return ['VSPR' => 22, 'VSI' => 16, 'NPR' => 12];
}

function iqBeta50ExpectedFamilyCounts(): array
{
    return [
        'matrix_3x3' => 16,
        'matrix_2x2' => 6,
        'series' => 8,
        'odd_one_out' => 6,
        'rotation' => 5,
        'overlay' => 5,
        'numeric_pattern' => 4,
    ];
}

function iqBeta50ItemDefinitions(): array
{
    $families = array_merge(
        array_fill(0, 16, ['matrix_3x3', 'VSPR']),
        array_fill(0, 6, ['matrix_2x2', 'VSPR']),
        array_fill(0, 8, ['series', 'VSI']),
        array_fill(0, 6, ['odd_one_out', 'VSI']),
        array_fill(0, 2, ['rotation', 'VSI']),
        array_fill(0, 3, ['rotation', 'NPR']),
        array_fill(0, 5, ['overlay', 'NPR']),
        array_fill(0, 4, ['numeric_pattern', 'NPR']),
    );

    $answers = iqBeta50OptionCodes();
    $definitions = [];

    foreach ($families as $index => [$family, $dimension]) {
        $number = $index + 1;
        $difficulty = min(5, (int) floor($index / 10) + 1);
        $seed = 9500 + $number;
        $ruleKey = sprintf('beta50_%s_formal_rule_%02d', $family, $number);

        $definitions[] = [
            'number' => $number,
            'question_id' => sprintf('IQB50-Q%02d', $number),
            'item_id' => sprintf('IQ_BETA_50_ORIGINAL_%02d', $number),
            'dimension' => $dimension,
            'dimension_name' => iqBeta50DimensionName($dimension),
            'difficulty_level' => $difficulty,
            'item_family' => $family,
            'correct_answer' => $answers[$index % count($answers)],
            'seed' => $seed,
            'generation_phase' => 'beta50_formal_original_pending_norms',
            'rule_key' => $ruleKey,
            'rule' => sprintf(
                'Abstract beta50 %s grammar generated from seed %d; choose the option preserving the declared multi-attribute transformation.',
                str_replace('_', ' ', $family),
                $seed
            ),
            'distractor_logic' => 'Distractors are seeded perturbations of count, position, rotation, overlay operation, or numeric step; no competitor item, answer, option, or explanation asset is copied or rewritten.',
            'reviewer' => 'codex_beta50_originality_review',
            'review_status' => 'originality_reviewed_pending_psychometric_review_and_norm_calibration',
        ];
    }

    return $definitions;
}

function iqBeta50Item(array $definition): array
{
    $number = (int) $definition['number'];
    $family = (string) $definition['item_family'];
    $correctCode = (string) $definition['correct_answer'];
    $optionCodes = iqBeta50OptionCodes();
    $correctIndex = array_search($correctCode, $optionCodes, true);
    $stemVariant = (($number + 50) * 7 + (int) $definition['seed'] + $correctIndex) % 29;

    $stem = iqBeta50Asset(
        iqBeta50ShapeElements($number, $stemVariant, $family, true),
        ['accessibility_label' => sprintf('Original beta50 %s reasoning prompt %02d.', str_replace('_', ' ', $family), $number)]
    );

    $options = [];
    $optionHashes = [];
    foreach ($optionCodes as $optionIndex => $code) {
        $variant = ($optionIndex === $correctIndex)
            ? $stemVariant + 41
            : $stemVariant + 53 + $optionIndex + (($number + $optionIndex) % 9);
        $asset = iqBeta50Asset(
            iqBeta50ShapeElements($number, $variant, $family, false),
            ['accessibility_label' => sprintf('Option %s for original beta50 IQ item %02d.', $code, $number)]
        );
        $options[] = ['code' => $code, 'asset' => $asset];
        $optionHashes[$code] = iqBeta50Sha256Json($asset);
    }

    return [
        'schema_version' => 'fm.iq.item_bank.item.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta50BankId(),
        'question_id' => $definition['question_id'],
        'item_id' => $definition['item_id'],
        'dimension' => $definition['dimension'],
        'dimension_name' => $definition['dimension_name'],
        'difficulty_level' => $definition['difficulty_level'],
        'item_family' => $family,
        'option_count' => 6,
        'assets' => [
            'stem' => $stem,
            'options' => $options,
        ],
        'correct_answer' => $correctCode,
        'solution_rule' => $definition['rule'],
        'distractor_logic' => $definition['distractor_logic'],
        'asset_hashes' => [
            'stem' => iqBeta50Sha256Json($stem),
            'options' => $optionHashes,
        ],
        'generator_metadata' => [
            'source_mode' => 'repo_generated_original',
            'copied_from_third_party' => false,
            'traced_from_third_party' => false,
            'seed' => $definition['seed'],
            'rule' => $definition['rule'],
            'rule_key' => $definition['rule_key'],
            'difficulty' => $definition['difficulty_level'],
            'reviewer' => $definition['reviewer'],
            'review_status' => $definition['review_status'],
            'generation_phase' => $definition['generation_phase'],
            'template_key' => $definition['rule_key'],
            'item_family_template' => $family,
            'generator_version' => 'iq_beta50_original_bank_v1',
            'theme_version' => 'fermatmind_soft_contrast_v1',
            'params_hash' => 'sha256:'.hash('sha256', json_encode([$definition['item_id'], $family, $definition['dimension'], $stemVariant, $definition['seed'], $definition['rule_key']], JSON_UNESCAPED_SLASHES)),
        ],
    ];
}

function iqBeta50ItemsPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank.items.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta50BankId(),
        'item_count' => 50,
        'option_count' => 6,
        'items' => array_map('iqBeta50Item', iqBeta50ItemDefinitions()),
    ];
}

function iqBeta50AnswerKeyPayload(): array
{
    $answers = [];
    foreach (iqBeta50ItemDefinitions() as $definition) {
        $answers[$definition['item_id']] = [
            'question_id' => $definition['question_id'],
            'correct_answer' => $definition['correct_answer'],
            'dimension' => $definition['dimension'],
            'difficulty_level' => $definition['difficulty_level'],
        ];
    }

    return [
        'schema_version' => 'fm.iq.item_bank.answer_key.v1',
        'bank_id' => iqBeta50BankId(),
        'public_payload' => false,
        'storage_policy' => 'backend_only_never_emit_to_public_api',
        'answers' => $answers,
    ];
}

function iqBeta50ScoringSpecPayload(): array
{
    return [
        'schema_version' => 'fm.iq.scoring_spec.v1',
        'bank_id' => iqBeta50BankId(),
        'status' => 'generated_formal_original_pending_norms',
        'raw_score' => [
            'min' => 0,
            'max' => 50,
            'correct_item_value' => 1,
            'incorrect_item_value' => 0,
        ],
        'dimension_counts' => iqBeta50ExpectedDimensionCounts(),
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
            'beta_copy_allowed_claim' => 'practice-style cognitive reasoning score only',
        ],
        'runtime_binding' => [
            'enabled' => false,
            'deferred_to_pr' => 'IQ-SCORE-50-01',
        ],
    ];
}

function iqBeta50ManifestPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank_manifest.v1',
        'bank_id' => iqBeta50BankId(),
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'legacy_compatibility' => [
            'accepts_legacy_scale_code_alias' => 'IQ_RAVEN',
            'legacy_alias_user_facing' => false,
        ],
        'status' => 'generated_formal_original_pending_norms',
        'runtime_bound' => false,
        'public_take_enabled' => false,
        'item_count_target' => 50,
        'item_count_imported' => 50,
        'option_count' => 6,
        'option_codes' => iqBeta50OptionCodes(),
        'dimension_targets' => iqBeta50ExpectedDimensionCounts(),
        'item_family_targets' => iqBeta50ExpectedFamilyCounts(),
        'files' => [
            'items' => 'items.json',
            'answer_key' => 'answer_key.json',
            'scoring_spec' => 'scoring_spec.json',
        ],
        'generation_policy' => [
            'formal_original_item_count' => 50,
            'competitor_item_capture_allowed' => false,
            'competitor_item_rewrite_allowed' => false,
            'formal_item_source_mode' => 'repo_generated_original',
            'required_item_metadata' => ['source_mode', 'seed', 'rule', 'difficulty', 'reviewer'],
        ],
        'launch_gates' => [
            'items_import_required' => false,
            'answer_key_required' => false,
            'scoring_spec_required' => false,
            'norm_authority_required' => true,
            'copyright_gate_required' => true,
            'ambiguity_gate_required' => true,
            'provenance_gate_required' => true,
        ],
        'public_payload_policy' => [
            'may_emit_items' => false,
            'may_emit_answer_key' => false,
            'may_emit_solution_rule' => false,
            'may_emit_generator_metadata' => false,
        ],
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
        ],
        'notes' => [
            'frontend_display_key' => 'beta_50',
            'current_public_bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'reason' => 'beta50 original bank generated for future calibration; runtime and public take remain disabled until norm authority gates pass.',
        ],
    ];
}

function iqBeta50BankPayloads(): array
{
    return [
        'manifest' => iqBeta50ManifestPayload(),
        'items' => iqBeta50ItemsPayload(),
        'answer_key' => iqBeta50AnswerKeyPayload(),
        'scoring_spec' => iqBeta50ScoringSpecPayload(),
    ];
}

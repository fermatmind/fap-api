<?php

declare(strict_types=1);

function iqBeta30RepoRoot(): string
{
    return dirname(__DIR__, 3);
}

function iqBeta30BankId(): string
{
    return 'IQ_BETA_30_ORIGINAL';
}

function iqBeta30BankDir(): string
{
    return iqBeta30RepoRoot().'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/'.iqBeta30BankId();
}

function iqBeta30FileMap(): array
{
    $dir = iqBeta30BankDir();

    return [
        'manifest' => $dir.'/manifest.json',
        'items' => $dir.'/items.json',
        'answer_key' => $dir.'/answer_key.json',
        'scoring_spec' => $dir.'/scoring_spec.json',
    ];
}

function iqBeta30PrettyJson(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

function iqBeta30Sha256Json(array $payload): string
{
    return 'sha256:'.hash('sha256', iqBeta30PrettyJson($payload));
}

function iqBeta30LoadJson(string $path): array
{
    $json = json_decode((string) file_get_contents($path), true);
    if (! is_array($json)) {
        throw new RuntimeException('Invalid JSON: '.$path);
    }

    return $json;
}

function iqBeta30OptionCodes(): array
{
    return ['A', 'B', 'C', 'D', 'E', 'F'];
}

function iqBeta30ExpectedDimensionCounts(): array
{
    return ['VSPR' => 14, 'VSI' => 10, 'NPR' => 6];
}

function iqBeta30ExpectedFamilyCounts(): array
{
    return [
        'matrix_3x3' => 10,
        'matrix_2x2' => 4,
        'series' => 4,
        'odd_one_out' => 4,
        'rotation' => 3,
        'overlay' => 3,
        'numeric_pattern' => 2,
    ];
}

function iqBeta30DimensionName(string $dimension): string
{
    return match ($dimension) {
        'VSPR' => 'Visual-spatial pattern reasoning',
        'VSI' => 'Visual-spatial imagery',
        'NPR' => 'Numeric pattern reasoning',
        default => throw new InvalidArgumentException('Unknown dimension '.$dimension),
    };
}

function iqBeta30ItemDefinitions(): array
{
    $definitions = [];
    $blueprints = [
        ['matrix_2x2', 'VSPR', 1, 'A', 8301, 'wave_1_formal_original', 'matrix_2x2_fill_dot_increment_v1', 'Fill state alternates across each row while dot count increases down each column.', 'Distractors preserve either the fill transition or the dot count but not both.'],
        ['series', 'VSPR', 1, 'B', 8302, 'wave_1_formal_original', 'series_rotation_mark_cycle_v1', 'The main glyph rotates 45 degrees per step while the internal mark count cycles 1, 2, 3, then 1.', 'Distractors keep the rotation or mark cycle separately, or reuse an earlier state.'],
        ['odd_one_out', 'VSI', 2, 'C', 8303, 'wave_1_formal_original', 'odd_one_out_axis_symmetry_v1', 'Five options preserve a vertical symmetry axis; the target breaks that axis with an offset marker.', 'Distractors are symmetric variants with different density, scale, or marker placement.'],
        ['rotation', 'VSI', 2, 'D', 8304, 'wave_1_formal_original', 'pure_rotation_no_mirror_v1', 'The reference polyomino is transformed by rotation only; mirrored variants are invalid.', 'Distractors include mirrored, half-rotated, and correctly rotated but internally marked near misses.'],
        ['overlay', 'VSI', 2, 'E', 8305, 'wave_1_formal_original', 'overlay_remove_overlap_v1', 'The target combines two source shapes and removes the overlapping interior segment.', 'Distractors keep union, intersection, subtraction, or single-source variants.'],
        ['matrix_3x3', 'VSPR', 3, 'F', 8306, 'wave_1_formal_original', 'matrix_3x3_row_xor_column_rotation_v1', 'Across each row, fills combine by XOR; down each column, the active glyph rotates 90 degrees.', 'Distractors satisfy only the row rule, only the column rule, or a one-step rotation error.'],
        ['numeric_pattern', 'NPR', 2, 'A', 8307, 'wave_1_formal_original', 'numeric_double_minus_one_v1', 'The sequence follows n -> 2n - 1, producing the next numeric state.', 'Distractors are adjacent arithmetic, doubling, or off-by-one values.'],
        ['series', 'VSPR', 3, 'B', 8308, 'wave_1_formal_original', 'series_corner_cycle_fill_toggle_v1', 'The marker advances clockwise through square corners while fill toggles on every step.', 'Distractors keep the corner cycle or fill toggle separately, but not the combined state.'],
        ['matrix_3x3', 'VSPR', 4, 'C', 8309, 'wave_1_formal_original', 'matrix_3x3_quantity_rotation_constant_v1', 'Rows increase quantity, columns rotate the shape family, and the accent marker remains constant.', 'Distractors break one of quantity, rotation, or marker consistency.'],
        ['odd_one_out', 'VSI', 3, 'D', 8310, 'wave_1_formal_original', 'odd_one_out_rotation_equivalence_v1', 'Five options can be rotated to overlap the reference silhouette; the target requires mirroring.', 'Distractors vary rotation angle or scale while preserving rotational equivalence.'],
        ['overlay', 'VSI', 4, 'E', 8311, 'wave_1_formal_original', 'overlay_union_subtraction_intersection_cycle_v1', 'The operation cycle alternates union, subtraction, then intersection; the missing state is the next intersection.', 'Distractors are plausible overlay outputs from the wrong operation in the cycle.'],
        ['matrix_3x3', 'VSPR', 5, 'F', 8312, 'wave_1_formal_original', 'matrix_3x3_three_attribute_composition_v1', 'The missing panel must satisfy shape rotation, point shift, and fill inversion together.', 'Distractors satisfy one or two attributes while violating the combined rule.'],
        ['matrix_3x3', 'VSPR', 3, 'A', 8313, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v1', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 3, 'B', 8314, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v2', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 3, 'C', 8315, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v3', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 4, 'D', 8316, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v4', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 4, 'E', 8317, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v5', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 4, 'F', 8318, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v6', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_3x3', 'VSPR', 4, 'A', 8319, 'beta30_extension_scaffold_original', 'matrix_3x3_progression_scaffold_v7', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_2x2', 'VSPR', 4, 'B', 8320, 'beta30_extension_scaffold_original', 'matrix_2x2_progression_scaffold_v1', 'Original deterministic matrix progression scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_2x2', 'VSI', 4, 'C', 8321, 'beta30_extension_scaffold_original', 'matrix_2x2_imagery_scaffold_v1', 'Original deterministic matrix imagery scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['matrix_2x2', 'VSI', 4, 'D', 8322, 'beta30_extension_scaffold_original', 'matrix_2x2_imagery_scaffold_v2', 'Original deterministic matrix imagery scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['series', 'VSI', 4, 'E', 8323, 'beta30_extension_scaffold_original', 'series_imagery_scaffold_v1', 'Original deterministic series scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['series', 'VSI', 4, 'F', 8324, 'beta30_extension_scaffold_original', 'series_imagery_scaffold_v2', 'Original deterministic series scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['rotation', 'VSI', 5, 'A', 8325, 'beta30_extension_scaffold_original', 'rotation_imagery_scaffold_v1', 'Original deterministic rotation scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['rotation', 'NPR', 5, 'B', 8326, 'beta30_extension_scaffold_original', 'rotation_numeric_rule_scaffold_v1', 'Original deterministic rotation-number hybrid scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['overlay', 'NPR', 5, 'C', 8327, 'beta30_extension_scaffold_original', 'overlay_numeric_rule_scaffold_v1', 'Original deterministic overlay-number hybrid scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['numeric_pattern', 'NPR', 5, 'D', 8328, 'beta30_extension_scaffold_original', 'numeric_pattern_scaffold_v1', 'Original deterministic numeric scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['odd_one_out', 'NPR', 5, 'E', 8329, 'beta30_extension_scaffold_original', 'odd_one_out_numeric_scaffold_v1', 'Original deterministic odd-one-out numeric scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
        ['odd_one_out', 'NPR', 5, 'F', 8330, 'beta30_extension_scaffold_original', 'odd_one_out_numeric_scaffold_v2', 'Original deterministic odd-one-out numeric scaffold reserved for wave-2 review.', 'Distractors vary one or more generated attributes.'],
    ];

    foreach ($blueprints as $index => [$family, $dimension, $difficulty, $answer, $seed, $phase, $ruleKey, $rule, $distractorLogic]) {
        $number = $index + 1;
        if ($phase === 'beta30_extension_scaffold_original') {
            $phase = 'beta30_formal_original';
            $rule = sprintf(
                'Abstract %s grammar generated from seed %d; choose the option preserving the declared %s transformation.',
                str_replace('_', ' ', $family),
                $seed,
                str_replace('_', ' ', $ruleKey)
            );
            $distractorLogic = 'Distractors are seeded attribute perturbations that break exactly one or more of the generated rule attributes without copying third-party item assets.';
        }

        $definitions[] = [
            'number' => $number,
            'question_id' => sprintf('IQB30-Q%02d', $number),
            'item_id' => sprintf('IQ_BETA_30_ORIGINAL_%02d', $number),
            'dimension' => $dimension,
            'dimension_name' => iqBeta30DimensionName($dimension),
            'difficulty_level' => $difficulty,
            'item_family' => $family,
            'correct_answer' => $answer,
            'seed' => $seed,
            'generation_phase' => $phase,
            'rule_key' => $ruleKey,
            'rule' => $rule,
            'distractor_logic' => $distractorLogic,
            'reviewer' => match ($phase) {
                'wave_1_formal_original' => 'codex_wave1_originality_review',
                'beta30_formal_original' => 'codex_beta30_originality_review',
                default => 'wave2_human_psychometric_review_pending',
            },
            'review_status' => in_array($phase, ['wave_1_formal_original', 'beta30_formal_original'], true)
                ? 'originality_reviewed_pending_psychometric_review'
                : 'scaffold_pending_formal_item_review',
        ];
    }

    return $definitions;
}

function iqBeta30Svg(array $elements): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 160" role="img">'
        .'<rect width="240" height="160" rx="16" fill="#f8faf7"/>'
        .'<rect x="8" y="8" width="224" height="144" rx="12" fill="none" stroke="#243126" stroke-width="2" opacity="0.16"/>'
        .implode('', $elements)
        .'</svg>';
}

function iqBeta30Asset(array $elements, array $meta): array
{
    $asset = [
        'kind' => 'inline_svg_markup',
        'mime_type' => 'image/svg+xml',
        'view_box' => '0 0 240 160',
        'svg_markup' => iqBeta30Svg($elements),
        'accessibility_label' => $meta['accessibility_label'],
    ];

    return $asset;
}

function iqBeta30TextElement(string $text, int $x, int $y, int $size = 11, string $anchor = 'start', string $opacity = '0.72'): string
{
    return '<text x="'.$x.'" y="'.$y.'" font-family="Avenir, Helvetica, sans-serif" font-size="'.$size.'" text-anchor="'.$anchor.'" fill="#243126" opacity="'.$opacity.'">'.htmlspecialchars($text, ENT_QUOTES).'</text>';
}

function iqBeta30CellFrame(int $x, int $y, int $size, bool $missing = false): array
{
    $elements = [
        '<rect x="'.$x.'" y="'.$y.'" width="'.$size.'" height="'.$size.'" rx="8" fill="#ffffff" stroke="#243126" stroke-width="1.6" opacity="0.92"/>',
    ];

    if ($missing) {
        $elements[] = iqBeta30TextElement('?', $x + (int) ($size / 2), $y + (int) ($size / 2) + 6, 22, 'middle', '0.7');
    }

    return $elements;
}

function iqBeta30DotElements(int $x, int $y, int $count, string $color = '#d9843b'): array
{
    $elements = [];
    for ($i = 0; $i < $count; $i++) {
        $elements[] = '<circle cx="'.($x + ($i * 9)).'" cy="'.$y.'" r="3.2" fill="'.$color.'" opacity="0.9"/>';
    }

    return $elements;
}

function iqBeta30FillDotCell(int $x, int $y, int $size, bool $filled, int $dots): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $inner = $size - 24;
    $elements[] = '<rect x="'.($x + 12).'" y="'.($y + 12).'" width="'.$inner.'" height="'.(int) ($inner * 0.58).'" rx="6" fill="'.($filled ? '#1f5d50' : 'none').'" stroke="#1f5d50" stroke-width="2.2" opacity="'.($filled ? '0.82' : '0.62').'"/>';
    $elements = array_merge($elements, iqBeta30DotElements($x + 18, $y + $size - 18, $dots));

    return $elements;
}

function iqBeta30GlyphElement(string $kind, int $cx, int $cy, int $size, string $fill = '#4666a9', int $angle = 0, bool $mirror = false, string $opacity = '0.82'): string
{
    $scale = $mirror ? ' scale(-1 1)' : '';
    $transform = 'translate('.$cx.' '.$cy.') rotate('.$angle.')'.$scale;
    $half = (int) ($size / 2);
    $third = (int) ($size / 3);

    $shape = match ($kind) {
        'triangle' => '<path d="M 0 -'.$half.' L '.$half.' '.$half.' L -'.$half.' '.$half.' Z" fill="'.$fill.'" stroke="#243126" stroke-width="2" stroke-linejoin="round" opacity="'.$opacity.'"/>',
        'diamond' => '<path d="M 0 -'.$half.' L '.$half.' 0 L 0 '.$half.' L -'.$half.' 0 Z" fill="'.$fill.'" stroke="#243126" stroke-width="2" stroke-linejoin="round" opacity="'.$opacity.'"/>',
        'lshape' => '<path d="M -'.$half.' -'.$half.' H -'.$third.' V '.$third.' H '.$half.' V '.$half.' H -'.$half.' Z" fill="'.$fill.'" stroke="#243126" stroke-width="2" stroke-linejoin="round" opacity="'.$opacity.'"/>',
        'bar' => '<rect x="-'.$half.'" y="-'.(int) ($third / 2).'" width="'.$size.'" height="'.$third.'" rx="4" fill="'.$fill.'" stroke="#243126" stroke-width="2" opacity="'.$opacity.'"/>',
        'square' => '<rect x="-'.$half.'" y="-'.$half.'" width="'.$size.'" height="'.$size.'" rx="6" fill="'.$fill.'" stroke="#243126" stroke-width="2" opacity="'.$opacity.'"/>',
        default => '<circle cx="0" cy="0" r="'.$half.'" fill="'.$fill.'" stroke="#243126" stroke-width="2" opacity="'.$opacity.'"/>',
    };

    return '<g transform="'.$transform.'">'.$shape.'</g>';
}

function iqBeta30RotatedMarkCell(int $x, int $y, int $size, int $angle, int $marks, bool $mirror = false, string $kind = 'diamond'): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $elements[] = iqBeta30GlyphElement($kind, $x + (int) ($size / 2), $y + (int) ($size / 2), (int) ($size * 0.44), '#4666a9', $angle, $mirror);
    $elements = array_merge($elements, iqBeta30DotElements($x + 14, $y + $size - 14, $marks, '#c7504f'));

    return $elements;
}

function iqBeta30SymmetryCell(int $x, int $y, int $size, bool $broken, int $variant): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $cx = $x + (int) ($size / 2);
    $top = $y + 16 + (($variant % 3) * 3);
    $elements[] = '<line x1="'.$cx.'" y1="'.($y + 10).'" x2="'.$cx.'" y2="'.($y + $size - 10).'" stroke="#243126" stroke-width="1.2" opacity="0.18"/>';
    $elements[] = '<circle cx="'.($cx - 15).'" cy="'.$top.'" r="6" fill="#1f5d50" opacity="0.82"/>';
    $elements[] = '<circle cx="'.($cx + 15).'" cy="'.$top.'" r="6" fill="#1f5d50" opacity="0.82"/>';
    $elements[] = '<rect x="'.($cx - 20).'" y="'.($y + 42).'" width="14" height="18" rx="4" fill="#d9843b" opacity="0.78"/>';
    $elements[] = '<rect x="'.($broken ? $cx + 6 : $cx + 6).'" y="'.($y + ($broken ? 49 : 42)).'" width="14" height="18" rx="4" fill="#d9843b" opacity="0.78"/>';
    if ($broken) {
        $elements[] = '<circle cx="'.($cx + 6).'" cy="'.($y + $size - 18).'" r="4" fill="#c7504f" opacity="0.9"/>';
    }

    return $elements;
}

function iqBeta30CornerFillCell(int $x, int $y, int $size, string $corner, bool $filled): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $elements[] = '<rect x="'.($x + 16).'" y="'.($y + 16).'" width="'.($size - 32).'" height="'.($size - 32).'" rx="6" fill="'.($filled ? '#a7b35a' : 'none').'" stroke="#1f5d50" stroke-width="2.2" opacity="0.8"/>';
    $positions = [
        'tl' => [$x + 20, $y + 20],
        'tr' => [$x + $size - 20, $y + 20],
        'br' => [$x + $size - 20, $y + $size - 20],
        'bl' => [$x + 20, $y + $size - 20],
    ];
    [$cx, $cy] = $positions[$corner];
    $elements[] = '<circle cx="'.$cx.'" cy="'.$cy.'" r="6" fill="#7b4f8a" opacity="0.9"/>';

    return $elements;
}

function iqBeta30OverlayCell(int $x, int $y, int $size, string $mode): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $cx = $x + (int) ($size / 2);
    $cy = $y + (int) ($size / 2);
    $drawSquare = in_array($mode, ['left', 'union', 'subtraction', 'remove_overlap'], true);
    $drawCircle = in_array($mode, ['right', 'union', 'intersection', 'remove_overlap'], true);

    if ($drawSquare) {
        $elements[] = '<rect x="'.($cx - 24).'" y="'.($cy - 18).'" width="40" height="40" rx="6" fill="#1f5d50" opacity="'.($mode === 'subtraction' ? '0.74' : '0.42').'" stroke="#243126" stroke-width="2"/>';
    }
    if ($drawCircle) {
        $elements[] = '<circle cx="'.($cx + 14).'" cy="'.($cy + 2).'" r="22" fill="#d9843b" opacity="'.($mode === 'intersection' ? '0.78' : '0.42').'" stroke="#243126" stroke-width="2"/>';
    }
    if ($mode === 'intersection') {
        $elements[] = '<path d="M '.$cx.' '.($cy - 18).' C '.($cx + 19).' '.($cy - 10).' '.($cx + 19).' '.($cy + 22).' '.$cx.' '.($cy + 30).' C '.($cx - 10).' '.($cy + 18).' '.($cx - 10).' '.($cy - 7).' '.$cx.' '.($cy - 18).' Z" fill="#4666a9" opacity="0.78"/>';
    }
    if ($mode === 'remove_overlap') {
        $elements[] = '<path d="M '.($cx - 2).' '.($cy - 18).' C '.($cx + 10).' '.($cy - 9).' '.($cx + 10).' '.($cy + 19).' '.($cx - 2).' '.($cy + 27).'" fill="none" stroke="#f8faf7" stroke-width="8" stroke-linecap="round" opacity="0.95"/>';
    }

    return $elements;
}

function iqBeta30NumericCell(int $x, int $y, int $size, int $value): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $elements[] = iqBeta30TextElement((string) $value, $x + (int) ($size / 2), $y + (int) ($size / 2) + 8, 24, 'middle', '0.88');
    $elements[] = '<circle cx="'.($x + 16).'" cy="'.($y + $size - 16).'" r="4" fill="#1f5d50" opacity="0.74"/>';
    $elements[] = '<circle cx="'.($x + $size - 16).'" cy="'.($y + 16).'" r="4" fill="#d9843b" opacity="0.74"/>';

    return $elements;
}

function iqBeta30QuantityCell(int $x, int $y, int $size, int $count, string $kind, int $angle, bool $marker = false): array
{
    $elements = iqBeta30CellFrame($x, $y, $size);
    $startX = $x + 18;
    $startY = $y + 24;
    for ($i = 0; $i < $count; $i++) {
        $elements[] = iqBeta30GlyphElement($kind, $startX + (($i % 3) * 18), $startY + ((int) floor($i / 3) * 22), 15, '#4666a9', $angle);
    }
    if ($marker) {
        $elements[] = '<circle cx="'.($x + $size - 16).'" cy="'.($y + $size - 16).'" r="5" fill="#c7504f" opacity="0.9"/>';
    }

    return $elements;
}

function iqBeta30CompositeCell(int $x, int $y, int $size, int $angle, string $corner, bool $filled, string $kind): array
{
    $elements = iqBeta30CornerFillCell($x, $y, $size, $corner, $filled);
    $elements[] = iqBeta30GlyphElement($kind, $x + (int) ($size / 2), $y + (int) ($size / 2), (int) ($size * 0.34), '#4666a9', $angle, false, '0.62');

    return $elements;
}

function iqBeta30Wave1StemElements(array $definition): array
{
    $number = (int) $definition['number'];
    $family = (string) $definition['item_family'];
    $elements = [
        iqBeta30TextElement('wave-1 '.$family, 20, 28, 13),
        iqBeta30TextElement('Q'.sprintf('%02d', $number), 204, 28, 12, 'start', '0.54'),
    ];

    return array_merge($elements, match ($number) {
        1 => array_merge(
            iqBeta30FillDotCell(42, 42, 42, true, 1),
            iqBeta30FillDotCell(96, 42, 42, false, 1),
            iqBeta30FillDotCell(42, 94, 42, true, 2),
            iqBeta30CellFrame(96, 94, 42, true)
        ),
        2 => array_merge(
            iqBeta30RotatedMarkCell(36, 70, 34, 0, 1),
            iqBeta30RotatedMarkCell(82, 70, 34, 45, 2),
            iqBeta30RotatedMarkCell(128, 70, 34, 90, 3),
            iqBeta30CellFrame(174, 70, 34, true)
        ),
        3 => [
            iqBeta30TextElement('Choose the panel that breaks the shared axis.', 120, 76, 12, 'middle', '0.76'),
            '<line x1="120" y1="90" x2="120" y2="126" stroke="#243126" stroke-width="1.5" opacity="0.2"/>',
        ],
        4 => array_merge(
            [iqBeta30TextElement('Rotate the reference without mirroring.', 120, 48, 12, 'middle', '0.76')],
            iqBeta30RotatedMarkCell(96, 66, 48, 0, 1, false, 'lshape'),
            iqBeta30CellFrame(154, 66, 48, true)
        ),
        5 => array_merge(
            [iqBeta30TextElement('Combine panels and remove the overlap.', 120, 44, 12, 'middle', '0.76')],
            iqBeta30OverlayCell(58, 66, 42, 'left'),
            iqBeta30OverlayCell(110, 66, 42, 'right'),
            iqBeta30CellFrame(162, 66, 42, true)
        ),
        6 => array_merge(
            [iqBeta30TextElement('Rows combine fill; columns rotate.', 120, 42, 12, 'middle', '0.76')],
            iqBeta30QuantityCell(55, 58, 34, 1, 'triangle', 0, true),
            iqBeta30QuantityCell(97, 58, 34, 2, 'triangle', 90, true),
            iqBeta30QuantityCell(139, 58, 34, 3, 'triangle', 180, true),
            iqBeta30CellFrame(139, 100, 34, true)
        ),
        7 => array_merge(
            iqBeta30NumericCell(42, 70, 34, 3),
            iqBeta30NumericCell(88, 70, 34, 5),
            iqBeta30NumericCell(134, 70, 34, 9),
            iqBeta30CellFrame(180, 70, 34, true)
        ),
        8 => array_merge(
            iqBeta30CornerFillCell(36, 70, 34, 'tl', true),
            iqBeta30CornerFillCell(82, 70, 34, 'tr', false),
            iqBeta30CornerFillCell(128, 70, 34, 'br', true),
            iqBeta30CellFrame(174, 70, 34, true)
        ),
        9 => array_merge(
            [iqBeta30TextElement('Quantity rises; shape rotates; marker stays.', 120, 42, 12, 'middle', '0.76')],
            iqBeta30QuantityCell(48, 60, 38, 2, 'triangle', 0, true),
            iqBeta30QuantityCell(94, 60, 38, 3, 'triangle', 45, true),
            iqBeta30CellFrame(140, 60, 38, true)
        ),
        10 => array_merge(
            [iqBeta30TextElement('All but one overlap by rotation only.', 120, 46, 12, 'middle', '0.76')],
            iqBeta30RotatedMarkCell(96, 66, 48, 0, 0, false, 'lshape')
        ),
        11 => array_merge(
            [iqBeta30TextElement('Union, subtraction, intersection cycle.', 120, 44, 12, 'middle', '0.76')],
            iqBeta30OverlayCell(48, 66, 42, 'union'),
            iqBeta30OverlayCell(100, 66, 42, 'subtraction'),
            iqBeta30CellFrame(152, 66, 42, true)
        ),
        12 => array_merge(
            [iqBeta30TextElement('Satisfy rotation, point shift, and fill inversion.', 120, 42, 11, 'middle', '0.76')],
            iqBeta30CompositeCell(46, 62, 38, 0, 'tl', true, 'diamond'),
            iqBeta30CompositeCell(92, 62, 38, 90, 'tr', false, 'diamond'),
            iqBeta30CellFrame(138, 62, 38, true)
        ),
        default => [],
    });
}

function iqBeta30Wave1OptionElements(array $definition, string $code): array
{
    $number = (int) $definition['number'];
    $variant = (int) array_search($code, iqBeta30OptionCodes(), true);

    return match ($number) {
        1 => iqBeta30FillDotCell(82, 38, 76, ...[
            'A' => [false, 2],
            'B' => [true, 2],
            'C' => [false, 1],
            'D' => [false, 3],
            'E' => [true, 1],
            'F' => [true, 3],
        ][$code]),
        2 => iqBeta30RotatedMarkCell(82, 38, 76, ...[
            'A' => [90, 1, false, 'diamond'],
            'B' => [135, 1, false, 'diamond'],
            'C' => [135, 2, false, 'diamond'],
            'D' => [180, 1, false, 'diamond'],
            'E' => [135, 3, false, 'diamond'],
            'F' => [45, 1, false, 'diamond'],
        ][$code]),
        3 => iqBeta30SymmetryCell(82, 38, 76, $code === 'C', $variant),
        4 => iqBeta30RotatedMarkCell(82, 38, 76, ...[
            'A' => [0, 1, false, 'lshape'],
            'B' => [90, 1, true, 'lshape'],
            'C' => [180, 1, false, 'lshape'],
            'D' => [90, 1, false, 'lshape'],
            'E' => [270, 2, false, 'lshape'],
            'F' => [90, 0, false, 'lshape'],
        ][$code]),
        5 => iqBeta30OverlayCell(82, 38, 76, [
            'A' => 'union',
            'B' => 'intersection',
            'C' => 'subtraction',
            'D' => 'left',
            'E' => 'remove_overlap',
            'F' => 'right',
        ][$code]),
        6 => iqBeta30QuantityCell(82, 38, 76, ...[
            'A' => [2, 'triangle', 90, true],
            'B' => [3, 'triangle', 0, true],
            'C' => [3, 'diamond', 90, true],
            'D' => [4, 'triangle', 90, false],
            'E' => [2, 'triangle', 180, true],
            'F' => [3, 'triangle', 90, true],
        ][$code]),
        7 => iqBeta30NumericCell(82, 38, 76, [
            'A' => 17,
            'B' => 15,
            'C' => 18,
            'D' => 14,
            'E' => 19,
            'F' => 16,
        ][$code]),
        8 => iqBeta30CornerFillCell(82, 38, 76, ...[
            'A' => ['bl', true],
            'B' => ['bl', false],
            'C' => ['tl', false],
            'D' => ['br', false],
            'E' => ['tr', true],
            'F' => ['tr', false],
        ][$code]),
        9 => iqBeta30QuantityCell(82, 38, 76, ...[
            'A' => [4, 'triangle', 45, true],
            'B' => [3, 'triangle', 90, true],
            'C' => [4, 'triangle', 90, true],
            'D' => [4, 'diamond', 90, true],
            'E' => [5, 'triangle', 90, true],
            'F' => [4, 'triangle', 90, false],
        ][$code]),
        10 => iqBeta30RotatedMarkCell(82, 38, 76, ...[
            'A' => [0, 0, false, 'lshape'],
            'B' => [90, 0, false, 'lshape'],
            'C' => [180, 0, false, 'lshape'],
            'D' => [0, 0, true, 'lshape'],
            'E' => [270, 0, false, 'lshape'],
            'F' => [45, 0, false, 'lshape'],
        ][$code]),
        11 => iqBeta30OverlayCell(82, 38, 76, [
            'A' => 'union',
            'B' => 'subtraction',
            'C' => 'remove_overlap',
            'D' => 'right',
            'E' => 'intersection',
            'F' => 'left',
        ][$code]),
        12 => iqBeta30CompositeCell(82, 38, 76, ...[
            'A' => [90, 'tr', true, 'diamond'],
            'B' => [180, 'br', false, 'diamond'],
            'C' => [180, 'tr', false, 'diamond'],
            'D' => [270, 'br', true, 'diamond'],
            'E' => [180, 'bl', false, 'triangle'],
            'F' => [180, 'br', false, 'diamond'],
        ][$code]),
        default => iqBeta30ShapeElements($number, $variant, (string) $definition['item_family'], false),
    };
}

function iqBeta30ShapeElements(int $itemNumber, int $variant, string $family, bool $isStem): array
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

function iqBeta30Item(array $definition): array
{
    $number = $definition['number'];
    $family = $definition['item_family'];
    $correctCode = $definition['correct_answer'];
    $optionCodes = iqBeta30OptionCodes();
    $correctIndex = array_search($correctCode, $optionCodes, true);
    $stemVariant = ($number * 3 + $correctIndex) % 13;
    $isFormalWave1 = ($definition['generation_phase'] ?? '') === 'wave_1_formal_original';

    $stem = iqBeta30Asset(
        $isFormalWave1
            ? iqBeta30Wave1StemElements($definition)
            : iqBeta30ShapeElements($number, $stemVariant, $family, true),
        ['accessibility_label' => sprintf('Original %s reasoning prompt %02d.', str_replace('_', ' ', $family), $number)]
    );

    $options = [];
    $optionHashes = [];
    foreach ($optionCodes as $optionIndex => $code) {
        $variant = ($optionIndex === $correctIndex)
            ? $stemVariant + 17
            : $stemVariant + 23 + $optionIndex + (($number + $optionIndex) % 5);
        $asset = iqBeta30Asset(
            $isFormalWave1
                ? iqBeta30Wave1OptionElements($definition, $code)
                : iqBeta30ShapeElements($number, $variant, $family, false),
            ['accessibility_label' => sprintf('Option %s for original IQ item %02d.', $code, $number)]
        );
        $options[] = ['code' => $code, 'asset' => $asset];
        $optionHashes[$code] = iqBeta30Sha256Json($asset);
    }

    return [
        'schema_version' => 'fm.iq.item_bank.item.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
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
            'stem' => iqBeta30Sha256Json($stem),
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
            'generator_version' => 'iq_beta30_original_bank_v2',
            'theme_version' => 'fermatmind_soft_contrast_v1',
            'params_hash' => 'sha256:'.hash('sha256', json_encode([$definition['item_id'], $family, $definition['dimension'], $stemVariant, $definition['seed'], $definition['rule_key']], JSON_UNESCAPED_SLASHES)),
        ],
    ];
}

function iqBeta30ItemsPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank.items.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
        'item_count' => 30,
        'option_count' => 6,
        'items' => array_map('iqBeta30Item', iqBeta30ItemDefinitions()),
    ];
}

function iqBeta30AnswerKeyPayload(): array
{
    $answers = [];
    foreach (iqBeta30ItemDefinitions() as $definition) {
        $answers[$definition['item_id']] = [
            'question_id' => $definition['question_id'],
            'correct_answer' => $definition['correct_answer'],
            'dimension' => $definition['dimension'],
            'difficulty_level' => $definition['difficulty_level'],
        ];
    }

    return [
        'schema_version' => 'fm.iq.item_bank.answer_key.v1',
        'bank_id' => iqBeta30BankId(),
        'public_payload' => false,
        'storage_policy' => 'backend_only_never_emit_to_public_api',
        'answers' => $answers,
    ];
}

function iqBeta30ScoringSpecPayload(): array
{
    return [
        'schema_version' => 'fm.iq.scoring_spec.v1',
        'bank_id' => iqBeta30BankId(),
        'status' => 'beta_internal_validation',
        'raw_score' => [
            'min' => 0,
            'max' => 30,
            'correct_item_value' => 1,
            'incorrect_item_value' => 0,
        ],
        'dimension_counts' => iqBeta30ExpectedDimensionCounts(),
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
            'beta_copy_allowed_claim' => 'practice-style cognitive reasoning score only',
        ],
        'runtime_binding' => [
            'enabled' => false,
            'deferred_to_pr' => 'IQ-SCORE-30-01',
        ],
    ];
}

function iqBeta30ManifestPayload(): array
{
    return [
        'schema_version' => 'fm.iq.item_bank.manifest.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => iqBeta30BankId(),
        'name' => 'FermatMind IQ Beta 30 Original',
        'status' => 'beta_internal_validation',
        'runtime_bound' => false,
        'commerce_unlock_required' => false,
        'locale' => 'zh-CN',
        'market' => 'CN_MAINLAND',
        'item_count' => 30,
        'option_count' => 6,
        'option_codes' => iqBeta30OptionCodes(),
        'target_minutes' => 20,
        'dimension_targets' => iqBeta30ExpectedDimensionCounts(),
        'item_family_targets' => iqBeta30ExpectedFamilyCounts(),
        'files' => [
            'items' => 'items.json',
            'answer_key' => 'answer_key.json',
            'scoring_spec' => 'scoring_spec.json',
        ],
        'copyright_policy' => [
            'source' => 'repo_generated_original',
            'copied_from_third_party' => false,
            'traced_from_third_party' => false,
            'third_party_license_required' => false,
            'myiq_science_requires_license_verification_gate_before_use' => true,
        ],
        'generation_policy' => [
            'wave_1_formal_original_item_count' => 12,
            'beta30_formal_original_extension_item_count' => 18,
            'formal_original_item_count' => 30,
            'competitor_item_capture_allowed' => false,
            'competitor_item_rewrite_allowed' => false,
            'formal_item_source_mode' => 'repo_generated_original',
            'required_item_metadata' => ['source_mode', 'seed', 'rule', 'difficulty', 'reviewer'],
        ],
        'public_payload_policy' => [
            'may_emit_items' => true,
            'may_emit_answer_key' => false,
            'may_emit_solution_rule' => false,
            'may_emit_generator_metadata' => false,
        ],
        'norm_policy' => [
            'iq_claims_enabled' => false,
            'percentile_claims_enabled' => false,
            'population_norm_table_required_before_production' => true,
        ],
        'review_gates' => [
            'copyright_gate',
            'technical_svg_gate',
            'answer_key_gate',
            'ambiguity_gate',
            'difficulty_gate',
            'claim_gate',
            'provenance_gate',
            'contract_gate',
        ],
        'deferred_prs' => ['IQ-BANK-30-03', 'IQ-SCORE-30-01', 'IQ-CLAIM-SEO-01', 'IQ-CMS-LANDING-01', 'IQ-LIVE-SMOKE-01'],
    ];
}

function iqBeta30BankPayloads(): array
{
    return [
        'manifest' => iqBeta30ManifestPayload(),
        'items' => iqBeta30ItemsPayload(),
        'answer_key' => iqBeta30AnswerKeyPayload(),
        'scoring_spec' => iqBeta30ScoringSpecPayload(),
    ];
}

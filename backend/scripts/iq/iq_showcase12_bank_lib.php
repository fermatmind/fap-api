<?php

declare(strict_types=1);

require_once __DIR__.'/iq_svg_provenance_lib.php';

const IQ_SHOWCASE12_SCHEMA_VERSION = 'fm.iq.item_bank_manifest.v1';
const IQ_SHOWCASE12_ITEM_SCHEMA_VERSION = 'fm.iq.item_bank.items.v1';
const IQ_SHOWCASE12_ANSWER_KEY_SCHEMA_VERSION = 'fm.iq.answer_key.v1';
const IQ_SHOWCASE12_BANK_ID = 'IQ_SHOWCASE_12_BETA';
const IQ_SHOWCASE12_GENERATOR_VERSION = 'iq_showcase12_generator_v1';
const IQ_SHOWCASE12_THEME_VERSION = 'iq_showcase12_theme_v1';

function iqShowcase12BankDir(): string
{
    return iqSvgRepoRoot().'/content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/'.IQ_SHOWCASE12_BANK_ID;
}

/**
 * @return array<string, string>
 */
function iqShowcase12FileMap(): array
{
    $bankDir = iqShowcase12BankDir();

    return [
        'manifest' => $bankDir.'/manifest.json',
        'items' => $bankDir.'/items.json',
        'answer_key' => $bankDir.'/answer_key.json',
        'scoring_spec' => $bankDir.'/scoring_spec.json',
    ];
}

/**
 * @return array<string, string>
 */
function iqShowcase12DimensionNames(): array
{
    return [
        'VSPR' => '视觉空间模式推理',
        'VSI' => '视觉空间洞察',
        'NPR' => '数字规律推理',
    ];
}

function iqShowcase12Escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function iqShowcase12Asset(string $viewBox, array $elements): array
{
    $markup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="'.$viewBox.'" fill="none">'
        .implode('', $elements)
        .'</svg>';

    return [
        'kind' => 'inline_svg_markup',
        'mime_type' => 'image/svg+xml',
        'view_box' => $viewBox,
        'svg_markup' => $markup,
    ];
}

function iqShowcase12Rect(
    int $x,
    int $y,
    int $width,
    int $height,
    string $fill = 'none',
    string $stroke = '#111111',
    int $strokeWidth = 4,
    int $radius = 0
): string {
    return sprintf(
        '<rect x="%d" y="%d" width="%d" height="%d" rx="%d" fill="%s" stroke="%s" stroke-width="%d"/>',
        $x,
        $y,
        $width,
        $height,
        $radius,
        iqShowcase12Escape($fill),
        iqShowcase12Escape($stroke),
        $strokeWidth
    );
}

function iqShowcase12Circle(
    int $cx,
    int $cy,
    int $radius,
    string $fill = 'none',
    string $stroke = '#111111',
    int $strokeWidth = 4
): string {
    return sprintf(
        '<circle cx="%d" cy="%d" r="%d" fill="%s" stroke="%s" stroke-width="%d"/>',
        $cx,
        $cy,
        $radius,
        iqShowcase12Escape($fill),
        iqShowcase12Escape($stroke),
        $strokeWidth
    );
}

function iqShowcase12Line(
    int $x1,
    int $y1,
    int $x2,
    int $y2,
    string $stroke = '#111111',
    int $strokeWidth = 4
): string {
    return sprintf(
        '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="%d" stroke-linecap="round"/>',
        $x1,
        $y1,
        $x2,
        $y2,
        iqShowcase12Escape($stroke),
        $strokeWidth
    );
}

function iqShowcase12Polygon(
    string $points,
    string $fill = 'none',
    string $stroke = '#111111',
    int $strokeWidth = 4
): string {
    return sprintf(
        '<polygon points="%s" fill="%s" stroke="%s" stroke-width="%d" stroke-linejoin="round"/>',
        iqShowcase12Escape($points),
        iqShowcase12Escape($fill),
        iqShowcase12Escape($stroke),
        $strokeWidth
    );
}

function iqShowcase12Text(
    int $x,
    int $y,
    string $text,
    int $fontSize = 14,
    string $weight = '600',
    string $anchor = 'middle'
): string {
    return sprintf(
        '<text x="%d" y="%d" font-size="%d" font-family="Arial, sans-serif" font-weight="%s" text-anchor="%s" fill="#111111">%s</text>',
        $x,
        $y,
        $fontSize,
        iqShowcase12Escape($weight),
        iqShowcase12Escape($anchor),
        iqShowcase12Escape($text)
    );
}

function iqShowcase12SquareCountAsset(int $count): array
{
    $elements = [];
    for ($index = 0; $index < $count; $index++) {
        $elements[] = iqShowcase12Rect(12 + ($index * 20), 44, 14, 14, '#111111', '#111111', 2, 2);
    }

    return iqShowcase12Asset('0 0 120 120', $elements);
}

function iqShowcase12ArrowAsset(int $rotationDegrees): array
{
    $elements = [
        sprintf(
            '<g transform="rotate(%d 60 60)">%s%s</g>',
            $rotationDegrees,
            iqShowcase12Line(24, 60, 92, 60, '#111111', 6),
            iqShowcase12Polygon('92,60 74,48 74,72', '#111111', '#111111', 2)
        ),
    ];

    return iqShowcase12Asset('0 0 120 120', $elements);
}

function iqShowcase12CornerDotAsset(string $corner): array
{
    $positions = [
        'TL' => [28, 28],
        'TR' => [92, 28],
        'BR' => [92, 92],
        'BL' => [28, 92],
    ];

    [$cx, $cy] = $positions[$corner];

    return iqShowcase12Asset('0 0 120 120', [
        iqShowcase12Rect(16, 16, 88, 88, 'none', '#111111', 4, 6),
        iqShowcase12Circle($cx, $cy, 10, '#111111', '#111111', 2),
    ]);
}

function iqShowcase12OutlineShapeAsset(string $shape, bool $filled): array
{
    $fill = $filled ? '#111111' : 'none';
    $strokeWidth = $filled ? 2 : 4;

    return match ($shape) {
        'circle' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Circle(60, 60, 26, $fill, '#111111', $strokeWidth),
        ]),
        'square' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Rect(32, 32, 56, 56, $fill, '#111111', $strokeWidth, 4),
        ]),
        'triangle' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Polygon('60,24 92,88 28,88', $fill, '#111111', $strokeWidth),
        ]),
        default => throw new RuntimeException('unsupported shape: '.$shape),
    };
}

function iqShowcase12HiddenShapeOption(string $variant): array
{
    return match ($variant) {
        'correct' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(28, 28, 28, 88),
            iqShowcase12Line(28, 88, 88, 88),
            iqShowcase12Line(28, 58, 88, 58),
        ]),
        'rotated' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(28, 28, 88, 28),
            iqShowcase12Line(88, 28, 88, 88),
            iqShowcase12Line(58, 28, 58, 88),
        ]),
        'mirror' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(88, 28, 88, 88),
            iqShowcase12Line(28, 88, 88, 88),
            iqShowcase12Line(28, 58, 88, 58),
        ]),
        'other' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(28, 28, 88, 88),
            iqShowcase12Line(88, 28, 28, 88),
        ]),
        default => throw new RuntimeException('unsupported hidden-shape variant: '.$variant),
    };
}

function iqShowcase12PolyominoAsset(string $transform): array
{
    $base = [
        iqShowcase12Rect(34, 34, 20, 20, '#111111', '#111111', 2, 3),
        iqShowcase12Rect(34, 56, 20, 20, '#111111', '#111111', 2, 3),
        iqShowcase12Rect(56, 56, 20, 20, '#111111', '#111111', 2, 3),
    ];

    return iqShowcase12Asset('0 0 120 120', [
        sprintf('<g transform="%s">%s</g>', iqShowcase12Escape($transform), implode('', $base)),
    ]);
}

function iqShowcase12AssemblyPieceAsset(string $piece): array
{
    return match ($piece) {
        'small_square' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Rect(40, 40, 28, 28, '#111111', '#111111', 2, 3),
        ]),
        'vertical_bar' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Rect(46, 24, 20, 64, '#111111', '#111111', 2, 3),
        ]),
        'l_piece' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Rect(28, 28, 20, 52, '#111111', '#111111', 2, 3),
            iqShowcase12Rect(48, 60, 28, 20, '#111111', '#111111', 2, 3),
        ]),
        'triangle' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Polygon('28,88 88,88 88,28', '#111111', '#111111', 2),
        ]),
        default => throw new RuntimeException('unsupported assembly piece: '.$piece),
    };
}

function iqShowcase12PatternOption(string $variant): array
{
    return match ($variant) {
        'cross' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(60, 20, 60, 100, '#111111', 8),
            iqShowcase12Line(20, 60, 100, 60, '#111111', 8),
        ]),
        'x' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Line(24, 24, 96, 96, '#111111', 8),
            iqShowcase12Line(96, 24, 24, 96, '#111111', 8),
        ]),
        'concentric' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Circle(60, 60, 28, 'none', '#111111', 6),
            iqShowcase12Circle(60, 60, 10, '#111111', '#111111', 2),
        ]),
        'asymmetric' => iqShowcase12Asset('0 0 120 120', [
            iqShowcase12Circle(42, 60, 12, '#111111', '#111111', 2),
            iqShowcase12Circle(78, 42, 12, '#111111', '#111111', 2),
            iqShowcase12Circle(78, 78, 12, '#111111', '#111111', 2),
        ]),
        default => throw new RuntimeException('unsupported pattern option: '.$variant),
    };
}

function iqShowcase12NumberCardAsset(string $value): array
{
    return iqShowcase12Asset('0 0 120 120', [
        iqShowcase12Rect(16, 20, 88, 80, '#F8FAFC', '#111111', 4, 8),
        iqShowcase12Text(60, 72, $value, 26, '700'),
    ]);
}

function iqShowcase12PromptCardAsset(array $lines): array
{
    $elements = [
        iqShowcase12Rect(10, 14, 100, 92, '#F8FAFC', '#111111', 3, 8),
    ];

    foreach (array_values($lines) as $index => $line) {
        $elements[] = iqShowcase12Text(60, 36 + ($index * 18), $line, 13, '600');
    }

    return iqShowcase12Asset('0 0 120 120', $elements);
}

/**
 * @param  array<int, array<string, mixed>>  $optionAssets
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function iqShowcase12Item(
    string $questionId,
    string $itemId,
    string $dimension,
    string $itemFamily,
    string $difficultyLevel,
    string $correctAnswer,
    string $templateKey,
    string $solutionRule,
    string $distractorLogic,
    array $params,
    array $stemAsset,
    array $optionAssets
): array {
    $dimensionNames = iqShowcase12DimensionNames();
    $assetHashes = [
        'stem' => iqSvgSha256Json($stemAsset),
        'options' => [],
    ];

    foreach ($optionAssets as $optionAsset) {
        $code = (string) ($optionAsset['code'] ?? '');
        $assetHashes['options'][$code] = iqSvgSha256Json($optionAsset['asset'] ?? []);
    }

    return [
        'schema_version' => IQ_SHOWCASE12_ITEM_SCHEMA_VERSION,
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => IQ_SHOWCASE12_BANK_ID,
        'status' => 'beta',
        'question_id' => $questionId,
        'item_id' => $itemId,
        'dimension' => $dimension,
        'dimension_name' => $dimensionNames[$dimension],
        'item_family' => $itemFamily,
        'difficulty_level' => $difficultyLevel,
        'correct_answer' => $correctAnswer,
        'solution_rule' => $solutionRule,
        'distractor_logic' => $distractorLogic,
        'option_count' => count($optionAssets),
        'assets' => [
            'stem' => $stemAsset,
            'options' => $optionAssets,
        ],
        'asset_hashes' => $assetHashes,
        'generator_metadata' => [
            'generator_version' => IQ_SHOWCASE12_GENERATOR_VERSION,
            'theme_version' => IQ_SHOWCASE12_THEME_VERSION,
            'seed' => strtolower($questionId).'_seed',
            'params_hash' => iqSvgSha256Json($params),
            'template_key' => $templateKey,
            'source_mode' => 'repo_generated',
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function iqShowcase12Items(): array
{
    return [
        iqShowcase12Item(
            'IQS12_Q01',
            'FM-IQ-VSPR-MX-L2-SH001',
            'VSPR',
            'matrix_reasoning',
            'L2',
            'A',
            'matrix_square_count_v1',
            'The square count increases by one across the sequence, so the next panel needs four filled squares.',
            'The distractors keep the same visual style but use lower or higher square counts to catch undercounting.',
            ['counts' => [1, 2, 3, 4]],
            iqShowcase12PromptCardAsset(['1 square', '2 squares', '3 squares', 'next = ?']),
            [
                ['code' => 'A', 'asset' => iqShowcase12SquareCountAsset(4)],
                ['code' => 'B', 'asset' => iqShowcase12SquareCountAsset(1)],
                ['code' => 'C', 'asset' => iqShowcase12SquareCountAsset(5)],
                ['code' => 'D', 'asset' => iqShowcase12SquareCountAsset(2)],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q02',
            'FM-IQ-VSPR-MX-L2-SH002',
            'VSPR',
            'matrix_reasoning',
            'L2',
            'B',
            'arrow_rotation_v1',
            'The arrow rotates 90 degrees clockwise on each step, so the next state is the 270-degree orientation.',
            'Distractors reuse prior orientations or partial rotations to test whether the rotation rule was tracked consistently.',
            ['rotation_sequence' => [0, 90, 180, 270]],
            iqShowcase12PromptCardAsset(['Rotate 90° each step', 'choose the next arrow']),
            [
                ['code' => 'A', 'asset' => iqShowcase12ArrowAsset(90)],
                ['code' => 'B', 'asset' => iqShowcase12ArrowAsset(270)],
                ['code' => 'C', 'asset' => iqShowcase12ArrowAsset(0)],
                ['code' => 'D', 'asset' => iqShowcase12ArrowAsset(180)],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q03',
            'FM-IQ-VSPR-SEQ-L3-SH003',
            'VSPR',
            'shape_sequence',
            'L3',
            'C',
            'corner_dot_cycle_v1',
            'The dot moves clockwise around the four corners of the square, so the missing position is bottom-left.',
            'Distractors place the dot on earlier positions in the cycle or repeat the start position.',
            ['corner_cycle' => ['TL', 'TR', 'BR', 'BL']],
            iqShowcase12PromptCardAsset(['Dot moves clockwise', 'around the square']),
            [
                ['code' => 'A', 'asset' => iqShowcase12CornerDotAsset('TR')],
                ['code' => 'B', 'asset' => iqShowcase12CornerDotAsset('BR')],
                ['code' => 'C', 'asset' => iqShowcase12CornerDotAsset('BL')],
                ['code' => 'D', 'asset' => iqShowcase12CornerDotAsset('TL')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q04',
            'FM-IQ-VSPR-ANL-L3-SH004',
            'VSPR',
            'shape_analogy',
            'L3',
            'D',
            'outline_fill_analogy_v1',
            'The left pair changes an outlined circle into a filled circle; applying the same rule to an outlined square yields a filled square.',
            'Distractors preserve the wrong shape or fail to apply the fill transformation consistently.',
            ['rule' => 'outline_to_filled', 'target_shape' => 'square'],
            iqShowcase12PromptCardAsset(['Outline -> Filled', 'apply the same rule']),
            [
                ['code' => 'A', 'asset' => iqShowcase12OutlineShapeAsset('triangle', true)],
                ['code' => 'B', 'asset' => iqShowcase12OutlineShapeAsset('square', false)],
                ['code' => 'C', 'asset' => iqShowcase12OutlineShapeAsset('circle', true)],
                ['code' => 'D', 'asset' => iqShowcase12OutlineShapeAsset('square', true)],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q05',
            'FM-IQ-VSI-HS-L2-SH005',
            'VSI',
            'hidden_shape',
            'L2',
            'A',
            'hidden_l_shape_v1',
            'The target is the same L-shape orientation shown in the stem, and only option A contains that orientation unchanged.',
            'The distractors mirror or rotate the target shape so they look similar without preserving the original orientation.',
            ['target' => 'L', 'orientation' => 'upright'],
            iqShowcase12PromptCardAsset(['Find the same', 'upright L-shape']),
            [
                ['code' => 'A', 'asset' => iqShowcase12HiddenShapeOption('correct')],
                ['code' => 'B', 'asset' => iqShowcase12HiddenShapeOption('rotated')],
                ['code' => 'C', 'asset' => iqShowcase12HiddenShapeOption('mirror')],
                ['code' => 'D', 'asset' => iqShowcase12HiddenShapeOption('other')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q06',
            'FM-IQ-VSI-MR-L3-SH006',
            'VSI',
            'mental_rotation',
            'L3',
            'B',
            'polyomino_rotation_v1',
            'The base polyomino is rotated without mirroring, and only option B matches a pure 90-degree rotation.',
            'Distractors mirror the shape or keep the original orientation, which is visually close but rule-inconsistent.',
            ['rotation' => 90, 'mirror' => false],
            iqShowcase12PromptCardAsset(['Choose the same shape', 'after rotation only']),
            [
                ['code' => 'A', 'asset' => iqShowcase12PolyominoAsset('translate(0 0) scale(-1 1) translate(-120 0)')],
                ['code' => 'B', 'asset' => iqShowcase12PolyominoAsset('rotate(90 60 60)')],
                ['code' => 'C', 'asset' => iqShowcase12PolyominoAsset('rotate(180 60 60) scale(-1 1) translate(-120 0)')],
                ['code' => 'D', 'asset' => iqShowcase12PolyominoAsset('rotate(0 60 60)')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q07',
            'FM-IQ-VSI-ASM-L3-SH007',
            'VSI',
            'shape_assembly',
            'L3',
            'C',
            'missing_piece_square_v1',
            'The gap in the square needs one long vertical segment plus a short horizontal foot, which matches the L-shaped piece.',
            'Distractors supply pieces with the wrong footprint, missing the horizontal foot or overfilling the gap.',
            ['target_piece' => 'l_piece'],
            iqShowcase12PromptCardAsset(['Fill the missing', 'corner piece']),
            [
                ['code' => 'A', 'asset' => iqShowcase12AssemblyPieceAsset('small_square')],
                ['code' => 'B', 'asset' => iqShowcase12AssemblyPieceAsset('vertical_bar')],
                ['code' => 'C', 'asset' => iqShowcase12AssemblyPieceAsset('l_piece')],
                ['code' => 'D', 'asset' => iqShowcase12AssemblyPieceAsset('triangle')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q08',
            'FM-IQ-VSI-ODD-L4-SH008',
            'VSI',
            'odd_one_out',
            'L4',
            'D',
            'symmetry_exception_v1',
            'Three options are mirror-symmetric around at least one axis, while option D breaks that symmetry with an offset cluster.',
            'The distractors all preserve simple reflection symmetry, so the odd one out is the only asymmetric pattern.',
            ['symmetry_target' => 'mirror'],
            iqShowcase12PromptCardAsset(['Which option is', 'different?']),
            [
                ['code' => 'A', 'asset' => iqShowcase12PatternOption('cross')],
                ['code' => 'B', 'asset' => iqShowcase12PatternOption('x')],
                ['code' => 'C', 'asset' => iqShowcase12PatternOption('concentric')],
                ['code' => 'D', 'asset' => iqShowcase12PatternOption('asymmetric')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q09',
            'FM-IQ-NPR-NS-L2-SH009',
            'NPR',
            'number_sequence',
            'L2',
            'A',
            'doubling_sequence_v1',
            'Each term doubles the previous term, so the next value after 16 is 32.',
            'Distractors include nearby even numbers and repeated powers to catch skipped or partial doubling.',
            ['sequence' => [2, 4, 8, 16, 32]],
            iqShowcase12PromptCardAsset(['2, 4, 8, 16, ?']),
            [
                ['code' => 'A', 'asset' => iqShowcase12NumberCardAsset('32')],
                ['code' => 'B', 'asset' => iqShowcase12NumberCardAsset('24')],
                ['code' => 'C', 'asset' => iqShowcase12NumberCardAsset('34')],
                ['code' => 'D', 'asset' => iqShowcase12NumberCardAsset('36')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q10',
            'FM-IQ-NPR-GRD-L3-SH010',
            'NPR',
            'number_grid',
            'L3',
            'B',
            'row_double_grid_v1',
            'In each row the second number is double the first, so the row starting with 5 must end with 10.',
            'Distractors keep the numbers close but break the row-doubling rule by under- or over-shooting the product.',
            ['rows' => [[2, 4], [3, 6], [5, 10]]],
            iqShowcase12PromptCardAsset(['2  4', '3  6', '5  ?']),
            [
                ['code' => 'A', 'asset' => iqShowcase12NumberCardAsset('8')],
                ['code' => 'B', 'asset' => iqShowcase12NumberCardAsset('10')],
                ['code' => 'C', 'asset' => iqShowcase12NumberCardAsset('12')],
                ['code' => 'D', 'asset' => iqShowcase12NumberCardAsset('15')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q11',
            'FM-IQ-NPR-SYM-L3-SH011',
            'NPR',
            'symbol_operation',
            'L3',
            'C',
            'symbol_value_addition_v1',
            'The stem assigns values triangle=2 and circle=4, so adding them yields 6.',
            'Distractors reuse one of the symbol values or add an extra step to test whether the mapping was applied cleanly.',
            ['triangle' => 2, 'square' => 3, 'circle' => 4, 'target' => 'triangle+circle'],
            iqShowcase12PromptCardAsset(['triangle = 2', 'square = 3', 'circle = 4', 'triangle + circle = ?']),
            [
                ['code' => 'A', 'asset' => iqShowcase12NumberCardAsset('4')],
                ['code' => 'B', 'asset' => iqShowcase12NumberCardAsset('5')],
                ['code' => 'C', 'asset' => iqShowcase12NumberCardAsset('6')],
                ['code' => 'D', 'asset' => iqShowcase12NumberCardAsset('7')],
            ]
        ),
        iqShowcase12Item(
            'IQS12_Q12',
            'FM-IQ-NPR-CNT-L4-SH012',
            'NPR',
            'shape_counting',
            'L4',
            'D',
            'triangle_count_v1',
            'The figure can be decomposed into eight distinct small triangles when counted systematically from top to bottom.',
            'Distractors represent common under-counts that miss overlapping or edge triangles in the figure.',
            ['triangle_count' => 8],
            iqShowcase12PromptCardAsset(['Count all triangles', 'in the figure']),
            [
                ['code' => 'A', 'asset' => iqShowcase12NumberCardAsset('5')],
                ['code' => 'B', 'asset' => iqShowcase12NumberCardAsset('6')],
                ['code' => 'C', 'asset' => iqShowcase12NumberCardAsset('7')],
                ['code' => 'D', 'asset' => iqShowcase12NumberCardAsset('8')],
            ]
        ),
    ];
}

/**
 * @return array<string, mixed>
 */
function iqShowcase12Manifest(array $items): array
{
    $answerDistribution = [];
    $dimensionCounts = [];
    $families = [];

    foreach ($items as $item) {
        $answer = (string) ($item['correct_answer'] ?? '');
        $dimension = (string) ($item['dimension'] ?? '');
        $family = (string) ($item['item_family'] ?? '');
        $answerDistribution[$answer] = ($answerDistribution[$answer] ?? 0) + 1;
        $dimensionCounts[$dimension] = ($dimensionCounts[$dimension] ?? 0) + 1;
        $families[$family] = true;
    }

    ksort($answerDistribution, SORT_STRING);
    ksort($dimensionCounts, SORT_STRING);

    return [
        'schema_version' => IQ_SHOWCASE12_SCHEMA_VERSION,
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => IQ_SHOWCASE12_BANK_ID,
        'status' => 'beta',
        'activation' => [
            'runtime_bound' => false,
            'reason' => 'showcase_only_until_beta50_and_norms_are_ready',
        ],
        'content_package_version' => 'v0.3.0-DEMO',
        'bank_dir' => iqSvgRelativePath(iqShowcase12BankDir()),
        'item_count' => count($items),
        'dimension_counts' => $dimensionCounts,
        'answer_distribution' => $answerDistribution,
        'item_families' => array_values(array_keys($families)),
        'files' => [
            'items' => iqSvgRelativePath(iqShowcase12FileMap()['items']),
            'answer_key' => iqSvgRelativePath(iqShowcase12FileMap()['answer_key']),
            'scoring_spec' => iqSvgRelativePath(iqShowcase12FileMap()['scoring_spec']),
        ],
        'beta_50_status' => 'future_work',
        'notes' => [
            'showcase_12_imported' => true,
            'beta_50_imported' => false,
            'commerce_required' => false,
            'norm_table_required_before_production' => true,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function iqShowcase12AnswerKey(array $items): array
{
    $entries = [];
    foreach ($items as $item) {
        $entries[] = [
            'item_id' => (string) $item['item_id'],
            'question_id' => (string) $item['question_id'],
            'dimension' => (string) $item['dimension'],
            'correct_answer' => (string) $item['correct_answer'],
            'raw_points' => 1,
        ];
    }

    return [
        'schema_version' => IQ_SHOWCASE12_ANSWER_KEY_SCHEMA_VERSION,
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'bank_id' => IQ_SHOWCASE12_BANK_ID,
        'answer_key_version' => 'showcase12.v1',
        'status' => 'beta',
        'items' => $entries,
    ];
}

/**
 * @return array<string, mixed>
 */
function iqShowcase12ScoringSpec(array $items): array
{
    $scoringItems = [];
    foreach ($items as $item) {
        $scoringItems[] = [
            'item_id' => (string) $item['item_id'],
            'question_id' => (string) $item['question_id'],
            'status' => 'beta',
            'dimension' => (string) $item['dimension'],
            'correct_answer' => (string) $item['correct_answer'],
            'item_family' => (string) $item['item_family'],
            'difficulty_level' => (string) $item['difficulty_level'],
            'solution_rule' => (string) $item['solution_rule'],
            'distractor_logic' => (string) $item['distractor_logic'],
            'raw_points' => 1,
            'asset_hashes' => $item['asset_hashes'],
            'generator_metadata' => $item['generator_metadata'],
        ];
    }

    return [
        'version' => 'showcase12.v1',
        'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        'scoring_mode' => 'scored',
        'answer_key_version' => 'showcase12.v1',
        'norm_table_version' => 'unavailable',
        'scoring_engine_version' => 'iq_scoring_v2',
        'item_bank' => [
            'bank_id' => IQ_SHOWCASE12_BANK_ID,
            'schema_version' => IQ_SHOWCASE12_ITEM_SCHEMA_VERSION,
            'status' => 'beta',
            'production_ready' => false,
            'canonical_scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'item_count' => count($items),
            'option_codes' => ['A', 'B', 'C', 'D'],
            'dimensions' => ['VSPR', 'VSI', 'NPR'],
        ],
        'quality_rules' => [
            'speeding_seconds_lt' => 30,
            'straightlining_run_len_gte' => 8,
            'abnormal_quality_policy' => 'review_with_caution',
        ],
        'items' => $scoringItems,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function iqShowcase12BankPayloads(): array
{
    $items = iqShowcase12Items();

    return [
        'manifest' => iqShowcase12Manifest($items),
        'items' => [
            'schema_version' => IQ_SHOWCASE12_ITEM_SCHEMA_VERSION,
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => IQ_SHOWCASE12_BANK_ID,
            'status' => 'beta',
            'items' => $items,
        ],
        'answer_key' => iqShowcase12AnswerKey($items),
        'scoring_spec' => iqShowcase12ScoringSpec($items),
    ];
}

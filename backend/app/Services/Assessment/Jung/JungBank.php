<?php

declare(strict_types=1);

namespace App\Services\Assessment\Jung;

use InvalidArgumentException;
use RuntimeException;

final class JungBank
{
    public const ARCHETYPES = 'JUNG_ARCHETYPES';

    public const FUNCTIONS = 'JUNG_COGNITIVE_FUNCTIONS';

    public const ARCHETYPE_DIMENSIONS = ['hero', 'sage', 'explorer', 'creator', 'ruler', 'caregiver', 'magician', 'lover', 'jester', 'everyperson', 'innocent', 'rebel'];

    public const FUNCTION_DIMENSIONS = ['Ni', 'Ne', 'Ti', 'Te', 'Fi', 'Fe', 'Si', 'Se'];

    public readonly array $data;

    public readonly string $hash;

    public readonly int $itemsPerDimension;

    public function __construct(public readonly string $scale)
    {
        $dimensions = match ($scale) {
            self::ARCHETYPES => self::ARCHETYPE_DIMENSIONS,
            self::FUNCTIONS => self::FUNCTION_DIMENSIONS,
            default => throw new InvalidArgumentException('Unknown Jung scale.'),
        };
        $this->itemsPerDimension = $scale === self::ARCHETYPES ? 4 : 8;
        $bytes = file_get_contents(__DIR__.'/../../../../content_packs/'.$scale.'/v1/bank.json');
        if ($bytes === false) {
            throw new RuntimeException('Missing Jung question bank.');
        }
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        $form = $scale === self::ARCHETYPES ? 'jung_archetypes_48' : 'jung_cognitive_functions_64';
        $scoring = $scale === self::ARCHETYPES ? 'archetypes_range_v1' : 'functions_range_grant_fit_v1';
        if ($data['schema'] !== 'fm.jung.likert-bank.v1' || $data['scaleCode'] !== $scale
            || $data['formCode'] !== $form || $data['scoringVersion'] !== $scoring
            || $data['itemBankVersion'] !== '1.0.0' || $data['dimensions'] !== $dimensions
            || $data['responseSetId'] !== ($scale === self::ARCHETYPES ? 'typicality_5' : 'frequency_5')) {
            throw new RuntimeException('Invalid Jung question bank identity.');
        }

        $counts = array_fill_keys($dimensions, 0);
        $seen = [];
        $prompts = ['zh-CN' => [], 'en' => []];
        foreach ($data['items'] as $item) {
            $id = $item['id'];
            $dimension = $item['dimension'];
            if (! is_string($id) || ! preg_match('/^[A-Z][A-Z0-9_]+$/D', $id)
                || isset($seen[$id]) || ! isset($counts[$dimension])
                || ! in_array($item['direction'], ['forward', 'reverse'], true)) {
                throw new RuntimeException('Invalid Jung question key.');
            }
            $counts[$dimension]++;
            $seen[$id] = true;
            foreach ($prompts as $locale => $used) {
                $prompt = $item['prompt'][$locale] ?? null;
                if (! is_string($prompt) || trim($prompt) === '' || isset($used[$prompt])) {
                    throw new RuntimeException('Invalid Jung question translation.');
                }
                $prompts[$locale][$prompt] = true;
            }
        }
        if (array_unique($counts) !== [$dimensions[0] => $this->itemsPerDimension]) {
            throw new RuntimeException('Invalid Jung dimension coverage.');
        }
        $this->data = $data;
        $this->hash = hash('sha256', $bytes);
    }

    public static function supports(string $scale): bool
    {
        return in_array($scale, [self::ARCHETYPES, self::FUNCTIONS], true);
    }

    public function questions(string $locale): array
    {
        $anchors = match ([$this->scale, $locale]) {
            [self::ARCHETYPES, 'zh-CN'] => ['完全不符合', '不太符合', '一般符合', '比较符合', '非常符合'],
            [self::ARCHETYPES, 'en'] => ['Not at all like me', 'A little like me', 'Somewhat like me', 'Mostly like me', 'Very much like me'],
            [self::FUNCTIONS, 'zh-CN'] => ['从不', '很少', '有时', '经常', '几乎每次'],
            [self::FUNCTIONS, 'en'] => ['Never', 'Rarely', 'Sometimes', 'Often', 'Almost always'],
            default => throw new InvalidArgumentException('Unsupported Jung locale.'),
        };
        $options = array_map(
            static fn (int $index, string $label): array => ['code' => (string) ($index + 1), 'text' => $label],
            array_keys($anchors),
            $anchors,
        );

        return array_map(static fn (array $item): array => [
            'question_id' => $item['id'],
            'text' => $item['prompt'][$locale],
            'options' => $options,
        ], $this->data['items']);
    }

    public function totals(array $answers): array
    {
        $items = array_column($this->data['items'], null, 'id');
        $totals = array_fill_keys($this->data['dimensions'], 0);
        $seen = [];
        foreach ($answers as $answer) {
            $id = $answer['question_id'] ?? null;
            $code = $answer['code'] ?? null;
            if (! is_string($id) || ! isset($items[$id]) || isset($seen[$id])
                || ! in_array($code, ['1', '2', '3', '4', '5'], true)) {
                throw new InvalidArgumentException('Invalid or duplicate Jung response.');
            }
            $seen[$id] = true;
            $item = $items[$id];
            $totals[$item['dimension']] += $item['direction'] === 'reverse' ? 6 - (int) $code : (int) $code;
        }
        if (count($seen) !== count($items)) {
            throw new InvalidArgumentException('Complete the Jung questionnaire before submitting.');
        }

        return $totals;
    }

    public function rangeScores(array $totals): array
    {
        $count = $this->itemsPerDimension;

        return array_map(static fn (int $total): float => 100 * ($total - $count) / (4 * $count), $totals);
    }
}

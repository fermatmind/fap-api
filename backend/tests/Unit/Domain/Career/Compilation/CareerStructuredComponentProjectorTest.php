<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerStructuredComponentProjector;
use PHPUnit\Framework\TestCase;

final class CareerStructuredComponentProjectorTest extends TestCase
{
    public function test_it_projects_english_headings_order_and_all_table_variants(): void
    {
        $definition = [
            'qa3_q' => 'Question 3',
            'qa3_a' => 'Answer 3',
            'qa3_table' => [['k' => 'K label', 'v' => 'K value']],
            'qa2_q' => 'Question 2',
            'qa2_a' => 'Answer 2',
            'qa2_table' => [['label' => 'Label', 'value' => 'Value']],
            'qa1_q' => 'Question 1',
            'qa1_a' => 'Answer 1',
            'qa1_table' => [['label' => 'Alternate', 'v' => 'V value']],
            'onet_struct' => [['label' => 'Extended', 'value' => 'Primary', 'value2' => 'Secondary']],
        ];
        $projector = new CareerStructuredComponentProjector;

        $quick = $projector->quickAnswers($definition, 'en');
        $onet = $projector->onetStructuredFields($definition, 'en');

        self::assertSame('Career quick answers', $quick['heading']);
        self::assertSame(['qa3', 'qa2', 'qa1'], array_column($quick['items'], 'key'));
        self::assertSame('K label', $quick['items'][0]['table']['rows'][0]['label']);
        self::assertSame('Value', $quick['items'][1]['table']['rows'][0]['value']);
        self::assertSame('V value', $quick['items'][2]['table']['rows'][0]['value']);
        self::assertSame('O*NET structured fields', $onet['heading']);
        self::assertSame('Secondary', $onet['rows'][0]['secondary_value']);
    }

    public function test_english_claim_bindings_use_the_sealed_registry_keys(): void
    {
        $definition = [
            'qa3_q' => 'Question 3', 'qa3_a' => 'Answer 3', 'qa3_table' => [['k' => 'L', 'v' => 'V']],
            'qa2_q' => 'Question 2', 'qa2_a' => 'Answer 2', 'qa2_table' => [['k' => 'L', 'v' => 'V']],
            'qa1_q' => 'Question 1', 'qa1_a' => 'Answer 1', 'qa1_table' => [['k' => 'L', 'v' => 'V']],
            'onet_struct' => [['k' => 'L', 'v' => 'V']],
        ];

        $bindings = (new CareerStructuredComponentProjector)->evidenceBindings($definition, 'en');

        self::assertSame([
            'career.ten_block.en.definition.quick_answers',
            'career.ten_block.en.definition.onet_struct',
        ], array_column($bindings['bindings'], 'source_registry_key'));
    }
}

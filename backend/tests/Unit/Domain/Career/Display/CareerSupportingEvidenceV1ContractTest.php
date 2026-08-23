<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerSupportingEvidenceV1Contract;
use Tests\TestCase;

final class CareerSupportingEvidenceV1ContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(CareerCurrentAuthorityPackage::class);
    }

    public function test_accountant_registry_item_satisfies_the_strict_contract(): void
    {
        $item = $this->accountantItem();

        CareerSupportingEvidenceV1Contract::assert($item['evidence'], $item['sources']);

        self::assertCount(3, $item['evidence']['quick_answers']);
        self::assertCount(6, $item['evidence']['onet']['tables']);
        self::assertSame([], $item['evidence']['ai_cases']);
        self::assertNull($item['evidence']['china_reference']);
    }

    public function test_contract_rejects_an_unresolved_source_key(): void
    {
        $item = $this->accountantItem();
        $item['evidence']['market_facts'][0]['source_keys'] = ['missing.source'];

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_SUPPORTING_EVIDENCE_V1_INVALID');

        CareerSupportingEvidenceV1Contract::assert($item['evidence'], $item['sources']);
    }

    public function test_contract_rejects_an_incomplete_onet_table_set(): void
    {
        $item = $this->accountantItem();
        array_pop($item['evidence']['onet']['tables']);

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_SUPPORTING_EVIDENCE_V1_INVALID');

        CareerSupportingEvidenceV1Contract::assert($item['evidence'], $item['sources']);
    }

    /** @return array{sources:list<array<string,mixed>>,evidence:array<string,mixed>} */
    private function accountantItem(): array
    {
        $registry = json_decode(
            (string) file_get_contents(base_path('content_assets/career/current/supporting-evidence-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $registry['items']['accountants-and-auditors'];
    }
}

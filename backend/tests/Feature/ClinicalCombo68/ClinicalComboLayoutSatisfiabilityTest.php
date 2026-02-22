<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use App\Services\Content\ClinicalComboContentLintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboLayoutSatisfiabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_satisfiability_lint_passes_for_both_locales(): void
    {
        /** @var ClinicalComboContentLintService $lint */
        $lint = app(ClinicalComboContentLintService::class);
        $result = $lint->lint('v1');

        $this->assertTrue((bool) ($result['ok'] ?? false), json_encode($result['errors'] ?? [], JSON_UNESCAPED_UNICODE));

        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $unsatisfied = array_values(array_filter($errors, static function (array $row): bool {
            return str_contains((string) ($row['message'] ?? ''), 'unsatisfied');
        }));
        $this->assertSame([], $unsatisfied);
    }
}

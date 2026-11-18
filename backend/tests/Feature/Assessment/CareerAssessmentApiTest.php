<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Http\Controllers\API\V0_5\Career\CareerAssessmentController;
use App\Http\Middleware\LimitApiPublicPayloadSize;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CareerAssessmentApiTest extends TestCase
{
    private const PATH = '/api/v0.5/career-assessment';

    public function test_public_questionnaire_and_stateless_submission(): void
    {
        // A valid submission needs no database connection or attempt row.
        config(['database.default' => 'unconfigured']);

        $form = $this->getJson(self::PATH.'/questions')
            ->assertOk()
            ->assertJsonPath('form_version', 'career_v1')
            ->assertJsonCount(94, 'items')
            ->assertJsonPath('items.0.text.en', 'Repair a door that will not close using hand tools')
            ->json();

        $answers = array_fill_keys(array_column($form['items'], 'id'), 3);
        foreach ($form['items'] as $item) {
            if ($item['section'] === 'context') {
                $answers[$item['id']] = null;
            }
            if ($item['axis'] === 'I') {
                $answers[$item['id']] = 5;
            }
        }
        $answers['career_v1_001'] = null;
        $answers['career_v1_007'] = null;
        $answers['career_v1_043'] = 5;
        $answers['career_v1_053'] = 1;
        $answers['career_v1_058'] = null;
        $answers['career_v1_094'] = 'cannot';

        $response = $this->postJson(self::PATH.'/score', [
            'form_version' => $form['form_version'],
            'answers' => $answers,
        ])->assertOk()
            ->assertJsonPath('model_version', 'career_profile_v1')
            ->assertJsonPath('axes.interest.I.score', 100)
            ->assertJsonPath('axes.interest.R.score', null)
            ->assertJsonPath('axes.interest.R.answered', 5)
            ->assertJsonPath('axes.style.coordination.score', 83.3333)
            ->assertJsonPath('axes.value.stable_income.score', 50)
            ->assertJsonPath('context.travel', 'cannot')
            ->assertJsonMissingPath('answers');

        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
    }

    public function test_native_holland_report_uses_career_interest_scores_and_preserves_missing_dimensions(): void
    {
        $form = $this->getJson(self::PATH.'/questions')->assertOk()->json();
        $ratings = ['R' => 5, 'I' => 4, 'A' => 2, 'S' => 3, 'E' => 2, 'C' => 1];
        $answers = [];
        foreach ($form['items'] as $item) {
            $answers[$item['id']] = match ($item['section']) {
                'interest' => $ratings[$item['axis']],
                'context' => null,
                default => 3,
            };
        }

        $projection = 'riasec_public_projection_v2';
        $payload = ['form_version' => 'career_v1', 'include_report' => true, 'answers' => $answers];
        $this->postJson(self::PATH.'/score', $payload)
            ->assertOk()
            ->assertJsonPath($projection.'.form.form_code', 'career_v1')
            ->assertJsonPath($projection.'.form.question_count', 94)
            ->assertJsonPath($projection.'.holland_code.code', 'RIS')
            ->assertJsonPath($projection.'.scores.dimensions.0.score', 100)
            ->assertJsonPath($projection.'.measurement_evidence.snapshot_bound', false)
            ->assertJsonCount(6, $projection.'.scores.dimensions')
            ->assertJsonStructure([$projection => ['deep_content_slots_v1' => ['slots' => [['content']]]]]);

        $payload['locale'] = 'en';
        $payload['answers']['career_v1_001'] = null;
        $payload['answers']['career_v1_007'] = null;
        $this->postJson(self::PATH.'/score', $payload)
            ->assertOk()
            ->assertJsonPath($projection.'.locale', 'en')
            ->assertJsonPath($projection.'.scores.dimensions.0.score', null)
            ->assertJsonPath($projection.'.holland_code.code', '');
    }

    public function test_public_scoring_is_bound_to_the_expected_action_and_request_limits(): void
    {
        $route = app('router')->getRoutes()->match(Request::create(self::PATH.'/score', 'POST'));

        $this->assertSame(CareerAssessmentController::class.'@score', $route->getActionName());
        $this->assertContains(LimitApiPublicPayloadSize::class, $route->gatherMiddleware());
        $this->assertContains('throttle:api_attempt_submit', $route->gatherMiddleware());

        $this->postJson(self::PATH.'/score', ['extra' => str_repeat('x', 16385)])
            ->assertStatus(413)
            ->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE');
    }

    public function test_requires_every_answer_key_and_rejects_invalid_or_legacy_payloads(): void
    {
        $form = $this->getJson(self::PATH.'/questions')->assertOk()->json();
        $answers = array_fill_keys(array_column($form['items'], 'id'), null);
        $this->postJson(self::PATH.'/score', ['form_version' => 'career_v1', 'answers' => []])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INCOMPLETE_ASSESSMENT');

        $this->postJson(self::PATH.'/score', ['form_version' => 'riasec_140', 'answers' => $answers])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INVALID_ASSESSMENT');

        $answers['career_v1_002'] = '3';
        $this->postJson(self::PATH.'/score', ['form_version' => 'career_v1', 'answers' => $answers])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INVALID_ASSESSMENT');

        $this->call('POST', self::PATH.'/score', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{')->assertBadRequest()->assertJsonPath('error_code', 'INVALID_JSON');
    }
}

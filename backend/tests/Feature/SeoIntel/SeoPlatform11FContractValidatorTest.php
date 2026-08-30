<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Measurement\CommercialFunnelCROMode;
use App\Services\SeoCouncil\Measurement\MeasurementContractValidator;
use App\Services\SeoCouncil\Measurement\SearchMeasurementMode;
use Tests\Feature\SeoIntel\Concerns\BuildsMeasurementV2Context;
use Tests\TestCase;

final class SeoPlatform11FContractValidatorTest extends TestCase
{
    use BuildsMeasurementV2Context;

    public function test_v2_validator_accepts_only_exact_hash_bound_contracts(): void
    {
        $validator = app(MeasurementContractValidator::class);
        $context = $this->measurementContext();
        $output = app(SearchMeasurementMode::class)->review($context);

        $this->assertTrue($validator->context($context));
        $this->assertTrue($validator->output($output));
        $this->assertTrue($validator->finding($output['findings'][0]));

        foreach (['extra_field', 'missing_field', 'wrong_type', 'wrong_version', 'forged_hash'] as $case) {
            $mutated = $context;
            if ($case === 'extra_field') {
                $mutated['extra'] = true;
            } elseif ($case === 'missing_field') {
                unset($mutated['locale']);
            } elseif ($case === 'wrong_type') {
                $mutated['windows'] = ['7', '28', '90'];
            } elseif ($case === 'wrong_version') {
                $mutated['version'] = 'seo.measurement_evidence_context.v1';
            } else {
                $mutated['context_hash'] = str_repeat('f', 64);
            }
            $this->assertFalse($validator->context($mutated), $case);
        }

        $tamperedOutput = $output;
        $tamperedOutput['execution_allowed'] = true;
        $tamperedOutput['output_hash'] = $this->rehash($tamperedOutput, 'output_hash');
        $this->assertFalse($validator->output($tamperedOutput));

        $tamperedFinding = $output['findings'][0];
        $tamperedFinding['role_id'] = 'seo.expert.commercial_funnel_cro';
        $tamperedFinding['finding_hash'] = $this->rehash($tamperedFinding, 'finding_hash');
        $this->assertFalse($validator->finding($tamperedFinding));
    }

    public function test_request_and_candidate_reject_role_version_type_hash_and_permission_forgery(): void
    {
        $validator = app(MeasurementContractValidator::class);
        $context = $this->measurementContext('commercial_funnel_cro');
        $output = app(CommercialFunnelCROMode::class)->review($context);
        $candidate = $output['candidates'][0];
        $this->assertTrue($validator->candidate($candidate));

        foreach (['hypothesis', 'falsification_rule', 'primary_metric', 'guardrail_metrics', 'stop_conditions'] as $field) {
            $mutated = $candidate;
            unset($mutated[$field]);
            $this->assertFalse($validator->candidate($mutated));
        }
        $candidate['execution_allowed'] = true;
        $candidate['candidate_hash'] = $this->rehash($candidate, 'candidate_hash');
        $this->assertFalse($validator->candidate($candidate));

        $request = app(MeasurementContractValidator::class)->sealRequest([
            'version' => 'seo.measurement_request.v2', 'mission_id' => 'mission:contract', 'run_id' => 'run:contract',
            'role_id' => 'seo.expert.search_analytics_measurement', 'mode_id' => 'search_measurement',
            'page_family' => 'tests', 'locale' => 'en', 'windows' => [7, 28, 90],
            'evidence_bundle_refs' => [[
                'bundle_id' => 'bundle:contract', 'bundle_version' => 2, 'bundle_hash' => str_repeat('a', 64),
                'source_type' => 'gsc_aggregate', 'authority_type' => 'measurement_readmodel',
            ]],
            'authority_revision' => str_repeat('b', 64), 'execution_allowed' => false,
        ]);
        $this->assertTrue($validator->request($request));
        foreach ([
            ['role_id', 'seo.expert.commercial_funnel_cro'], ['version', 'seo.measurement_request.v1'],
            ['windows', [7, 28]], ['execution_allowed', true], ['request_hash', str_repeat('f', 64)],
        ] as [$field, $value]) {
            $mutated = $request;
            $mutated[$field] = $value;
            if ($field !== 'request_hash') {
                $mutated['request_hash'] = $this->rehash($mutated, 'request_hash');
            }
            $this->assertFalse($validator->request($mutated));
        }
    }

    /** @param array<string, mixed> $value */
    private function rehash(array $value, string $field): string
    {
        return app(SeoRegistryHasher::class)->hash(array_diff_key($value, [$field => true]));
    }
}

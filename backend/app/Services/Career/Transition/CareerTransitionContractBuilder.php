<?php

declare(strict_types=1);

namespace App\Services\Career\Transition;

use App\Domain\Career\Transition\TransitionPathPayload;

final class CareerTransitionContractBuilder
{
    public const TIME_HORIZON_DAYS_0_30 = 'days_0_30';

    public const TIME_HORIZON_DAYS_31_60 = 'days_31_60';

    public const TIME_HORIZON_DAYS_61_90 = 'days_61_90';

    /**
     * @return array<string, mixed>
     */
    public function build(TransitionPathPayload $payload): array
    {
        $contract = [];

        if ($payload->rationaleCodes !== []) {
            $contract['rationale_codes'] = array_values($payload->rationaleCodes);
            $contract['why_this_path'] = $this->summaryFromRationaleCodes($payload->rationaleCodes);
        }

        if ($payload->tradeoffCodes !== []) {
            $contract['tradeoff_codes'] = array_values($payload->tradeoffCodes);
            $contract['what_is_lost'] = $this->summaryFromTradeoffCodes($payload->tradeoffCodes);
        }

        $bridgeSteps = $this->bridgeStepsFromStepCodes($payload->steps);
        if ($bridgeSteps !== []) {
            $contract['bridge_steps_90d'] = $bridgeSteps;
        }

        return $contract;
    }

    /**
     * @param  list<string>  $rationaleCodes
     */
    private function summaryFromRationaleCodes(array $rationaleCodes): string
    {
        $labels = [];
        foreach ($rationaleCodes as $code) {
            $labels[] = match ($code) {
                TransitionPathPayload::STEP_SKILL_OVERLAP => 'skill overlap',
                TransitionPathPayload::STEP_TASK_OVERLAP => 'task overlap',
                TransitionPathPayload::STEP_TOOL_OVERLAP => 'tool overlap',
                TransitionPathPayload::RATIONALE_SAME_FAMILY_TARGET => 'same-family target',
                TransitionPathPayload::RATIONALE_PUBLISH_READY_TARGET => 'publish-ready target',
                TransitionPathPayload::RATIONALE_INDEX_ELIGIBLE_TARGET => 'index-eligible target',
                TransitionPathPayload::RATIONALE_APPROVED_REVIEWER_TARGET => 'approved reviewer status',
                TransitionPathPayload::RATIONALE_SAFE_CROSSWALK_TARGET => 'safe crosswalk mode',
                default => null,
            };
        }

        $labels = array_values(array_filter($labels, static fn (mixed $label): bool => is_string($label) && $label !== ''));

        if ($labels === []) {
            return 'transition rationale available';
        }

        return 'Path selected from: '.implode('; ', $labels).'.';
    }

    /**
     * @param  list<string>  $tradeoffCodes
     */
    private function summaryFromTradeoffCodes(array $tradeoffCodes): string
    {
        $labels = [];
        foreach ($tradeoffCodes as $code) {
            $labels[] = match ($code) {
                TransitionPathPayload::TRADEOFF_HIGHER_ENTRY_EDUCATION_REQUIRED => 'higher entry education requirement',
                TransitionPathPayload::TRADEOFF_HIGHER_WORK_EXPERIENCE_REQUIRED => 'higher work experience requirement',
                TransitionPathPayload::TRADEOFF_HIGHER_TRAINING_REQUIRED => 'higher training requirement',
                default => null,
            };
        }

        $labels = array_values(array_filter($labels, static fn (mixed $label): bool => is_string($label) && $label !== ''));

        if ($labels === []) {
            return 'tradeoff summary available';
        }

        return 'Tradeoff emphasis: '.implode('; ', $labels).'.';
    }

    /**
     * @param  list<string>  $steps
     * @return list<array{step_key:string,title:string,description:string,time_horizon:string}>
     */
    private function bridgeStepsFromStepCodes(array $steps): array
    {
        $bridge = [];
        foreach ($steps as $step) {
            $bridgeStep = match ($step) {
                TransitionPathPayload::STEP_SKILL_OVERLAP => [
                    'step_key' => TransitionPathPayload::STEP_SKILL_OVERLAP,
                    'title' => 'Validate overlapping skills',
                    'description' => 'Document source-role strengths that transfer directly to the target role.',
                    'time_horizon' => self::TIME_HORIZON_DAYS_0_30,
                ],
                TransitionPathPayload::STEP_TASK_OVERLAP => [
                    'step_key' => TransitionPathPayload::STEP_TASK_OVERLAP,
                    'title' => 'Translate task overlap',
                    'description' => 'Map repeated target-role tasks to proof from recent source-role execution.',
                    'time_horizon' => self::TIME_HORIZON_DAYS_31_60,
                ],
                TransitionPathPayload::STEP_TOOL_OVERLAP => [
                    'step_key' => TransitionPathPayload::STEP_TOOL_OVERLAP,
                    'title' => 'Close tooling gap',
                    'description' => 'Build explicit exposure to core target-role tools through scoped practice.',
                    'time_horizon' => self::TIME_HORIZON_DAYS_61_90,
                ],
                default => null,
            };

            if (is_array($bridgeStep)) {
                $bridge[] = $bridgeStep;
            }
        }

        return array_values($bridge);
    }
}

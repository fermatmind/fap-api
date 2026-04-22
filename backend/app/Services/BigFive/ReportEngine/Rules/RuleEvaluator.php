<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Rules;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;

final class RuleEvaluator
{
    public function __construct(
        private readonly RuleOperators $operators = new RuleOperators,
    ) {}

    /**
     * @param  array<string,mixed>  $rule
     */
    public function evaluate(array $rule, ReportContext $context): bool
    {
        if (isset($rule['all']) && is_array($rule['all'])) {
            foreach ($rule['all'] as $child) {
                if (! is_array($child) || ! $this->evaluate($child, $context)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($rule['any']) && is_array($rule['any'])) {
            foreach ($rule['any'] as $child) {
                if (is_array($child) && $this->evaluate($child, $context)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($rule['expr'])) {
            return $this->evaluateExpression((string) $rule['expr'], $context);
        }

        if (($rule['op'] ?? null) === 'abs_diff_ge') {
            $left = $this->valueFor((string) ($rule['left'] ?? ''), $context);
            $right = $this->valueFor((string) ($rule['right'] ?? ''), $context);

            return $this->operators->absDiffGe($left, $right, (float) ($rule['value'] ?? 0));
        }

        $subject = (string) ($rule['trait'] ?? $rule['facet'] ?? '');
        $operator = (string) ($rule['op'] ?? '');
        if ($subject === '' || $operator === '') {
            return false;
        }

        $actual = isset($rule['facet'])
            ? $context->facetPercentile($subject)
            : $context->domainPercentile($subject);

        return $this->operators->compare((float) $actual, $operator, $rule['value'] ?? 0);
    }

    private function evaluateExpression(string $expression, ReportContext $context): bool
    {
        if (! preg_match('/^abs\(([A-Z])-([A-Z])\)\s*>=\s*(\d+)$/', trim($expression), $matches)) {
            return false;
        }

        return $this->operators->absDiffGe(
            (float) $context->domainPercentile($matches[1]),
            (float) $context->domainPercentile($matches[2]),
            (float) $matches[3],
        );
    }

    private function valueFor(string $subject, ReportContext $context): float
    {
        if (preg_match('/^[A-Z]\d$/', $subject) === 1) {
            return (float) $context->facetPercentile($subject);
        }

        return (float) $context->domainPercentile($subject);
    }
}

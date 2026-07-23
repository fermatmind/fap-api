<?php

declare(strict_types=1);

namespace App\Services\Career;

use RuntimeException;
use Throwable;

final class CareerJobDetailWarmFailure extends RuntimeException
{
    private function __construct(
        public readonly string $stage,
        public readonly string $safeCode,
        public readonly string $causeClass,
        public readonly float $buildMs,
        public readonly float $publishMs,
    ) {
        parent::__construct($safeCode);
    }

    public static function buildException(Throwable $cause, float $buildMs): self
    {
        return new self(
            'build_exception',
            'CAREER_DETAIL_BUILD_EXCEPTION',
            self::safeCauseClass($cause),
            $buildMs,
            0.0,
        );
    }

    public static function buildBudgetExceeded(float $buildMs): self
    {
        return new self(
            'build_budget_exceeded',
            'CAREER_DETAIL_BUILD_BUDGET_EXCEEDED',
            self::class,
            $buildMs,
            0.0,
        );
    }

    public static function publishException(Throwable $cause, float $buildMs, float $publishMs): self
    {
        return new self(
            'publish_exception',
            'CAREER_DETAIL_PUBLISH_EXCEPTION',
            self::safeCauseClass($cause),
            $buildMs,
            $publishMs,
        );
    }

    /** @return array{failure_stage: string, safe_code: string, cause_class: string, build_ms: float, publish_ms: float} */
    public function safeEvidence(): array
    {
        return [
            'failure_stage' => $this->stage,
            'safe_code' => $this->safeCode,
            'cause_class' => $this->causeClass,
            'build_ms' => $this->buildMs,
            'publish_ms' => $this->publishMs,
        ];
    }

    public static function safeCauseClass(Throwable $cause): string
    {
        $class = ltrim($cause::class, '\\');
        if (
            str_contains($class, '@anonymous')
            || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class) !== 1
        ) {
            return 'anonymous_throwable';
        }

        return $class;
    }
}

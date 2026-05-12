<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilitySidecar
{
    public const OWNER_REPO_FAP_API = 'fap-api';

    public const OWNER_REPO_FAP_WEB = 'fap-web';

    public const OWNER_REPO_EXTERNAL = 'external';

    public const SCOPE_RELATION_EXTERNAL = 'external_to_current_pr';

    public const SCOPE_RELATION_INSIDE = 'inside_current_pr';

    /**
     * @param  list<string>  $affectedSlugs
     * @param  list<string>  $affectedLocales
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $sidecarId,
        public readonly string $title,
        public readonly string $ownerRepo,
        public readonly string $scopeRelation,
        public readonly bool $introducedByCurrentPr,
        public readonly array $affectedSlugs,
        public readonly array $affectedLocales,
        public readonly array $evidence,
        public readonly string $severity,
        public readonly string $nextGoal,
        public readonly bool $mayContinueTrain,
    ) {
        self::assertNonEmptyString($this->sidecarId, 'sidecar_id');
        self::assertNonEmptyString($this->title, 'title');
        self::assertValidOwnerRepo($this->ownerRepo);
        self::assertValidScopeRelation($this->scopeRelation);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertNonEmptyString($this->nextGoal, 'next_goal');
        self::assertListOfStrings('affected_slugs', $this->affectedSlugs);
        self::assertListOfStrings('affected_locales', $this->affectedLocales);
        self::assertList('evidence', $this->evidence);

        if ($this->severity !== CareerCanonicalEligibilitySeverity::INFO && $this->evidence === []) {
            throw new InvalidArgumentException('Career canonical eligibility sidecar evidence is required for non-info severity.');
        }

        if ($this->introducedByCurrentPr && $this->mayContinueTrain) {
            throw new InvalidArgumentException('Career canonical eligibility sidecar introduced by the current PR cannot continue the train.');
        }

        if (
            $this->scopeRelation === self::SCOPE_RELATION_INSIDE
            && CareerCanonicalEligibilitySeverity::blocksInsideCurrentPr($this->severity)
            && $this->mayContinueTrain
        ) {
            throw new InvalidArgumentException('Career canonical eligibility sidecar inside the current PR with high/blocker severity cannot continue the train.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            sidecarId: self::requiredString($value, 'sidecar_id'),
            title: self::requiredString($value, 'title'),
            ownerRepo: self::requiredString($value, 'owner_repo'),
            scopeRelation: self::requiredString($value, 'scope_relation'),
            introducedByCurrentPr: self::requiredBool($value, 'introduced_by_current_pr'),
            affectedSlugs: self::requiredListOfStrings($value, 'affected_slugs'),
            affectedLocales: self::requiredListOfStrings($value, 'affected_locales'),
            evidence: self::requiredList($value, 'evidence'),
            severity: self::requiredString($value, 'severity'),
            nextGoal: self::requiredString($value, 'next_goal'),
            mayContinueTrain: self::requiredBool($value, 'may_continue_train'),
        );
    }

    /**
     * @return array{sidecar_id: string, title: string, owner_repo: string, scope_relation: string, introduced_by_current_pr: bool, affected_slugs: list<string>, affected_locales: list<string>, evidence: list<mixed>, severity: string, next_goal: string, may_continue_train: bool}
     */
    public function toArray(): array
    {
        return [
            'sidecar_id' => $this->sidecarId,
            'title' => $this->title,
            'owner_repo' => $this->ownerRepo,
            'scope_relation' => $this->scopeRelation,
            'introduced_by_current_pr' => $this->introducedByCurrentPr,
            'affected_slugs' => $this->affectedSlugs,
            'affected_locales' => $this->affectedLocales,
            'evidence' => $this->evidence,
            'severity' => $this->severity,
            'next_goal' => $this->nextGoal,
            'may_continue_train' => $this->mayContinueTrain,
        ];
    }

    public function canContinueTrain(): bool
    {
        return $this->mayContinueTrain;
    }

    /**
     * @return list<string>
     */
    public static function ownerRepos(): array
    {
        return [
            self::OWNER_REPO_FAP_API,
            self::OWNER_REPO_FAP_WEB,
            self::OWNER_REPO_EXTERNAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function scopeRelations(): array
    {
        return [
            self::SCOPE_RELATION_EXTERNAL,
            self::SCOPE_RELATION_INSIDE,
        ];
    }

    private static function assertValidOwnerRepo(string $value): void
    {
        if (! in_array($value, self::ownerRepos(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility sidecar owner_repo [%s].', $value));
        }
    }

    private static function assertValidScopeRelation(string $value): void
    {
        if (! in_array($value, self::scopeRelations(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career canonical eligibility sidecar scope_relation [%s].', $value));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (! array_key_exists($key, $value) || ! is_string($value[$key]) || trim($value[$key]) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar requires non-empty [%s].', $key));
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredBool(array $value, string $key): bool
    {
        if (! array_key_exists($key, $value) || ! is_bool($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar requires boolean [%s].', $key));
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private static function requiredListOfStrings(array $value, string $key): array
    {
        if (! array_key_exists($key, $value) || ! is_array($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar requires list [%s].', $key));
        }

        self::assertListOfStrings($key, $value[$key]);

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<mixed>
     */
    private static function requiredList(array $value, string $key): array
    {
        if (! array_key_exists($key, $value) || ! is_array($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar requires list [%s].', $key));
        }

        self::assertList($key, $value[$key]);

        return $value[$key];
    }

    private static function assertList(string $key, array $value): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar [%s] must be a list.', $key));
        }
    }

    private static function assertListOfStrings(string $key, array $value): void
    {
        self::assertList($key, $value);

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career canonical eligibility sidecar [%s] must contain only non-empty strings.', $key));
            }
        }
    }
}

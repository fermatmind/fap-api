<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalPromotionTransaction
{
    public const TRANSACTION_VERSION = 'career.canonical_promotion.transaction.v1';

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $rollbackGroup
     */
    public function __construct(
        public readonly string $batchId,
        public readonly array $slugs,
        public readonly array $locales,
        public readonly array $rollbackGroup,
        public readonly string $currentState,
        public readonly string $targetState,
        public readonly bool $dryRun,
    ) {}

    public static function fromManifest(
        array $manifestPayload,
        string $targetState = CanonicalExpansionManifestService::ROLLOUT_STATE_PUBLISHED,
        bool $dryRun = true,
    ): self {
        $manifest = self::manifest($manifestPayload);

        return new self(
            batchId: trim((string) ($manifest['batch_id'] ?? '')),
            slugs: self::strings($manifest['slugs'] ?? []),
            locales: self::strings($manifest['locales'] ?? []),
            rollbackGroup: self::strings($manifest['rollback_group'] ?? []),
            currentState: trim((string) ($manifest['rollout_state'] ?? '')),
            targetState: trim($targetState),
            dryRun: $dryRun,
        );
    }

    /**
     * @return list<array{slug: string, locale: string}>
     */
    public function expectedLocaleRows(): array
    {
        $rows = [];

        foreach ($this->slugs as $slug) {
            foreach ($this->locales as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_version' => self::TRANSACTION_VERSION,
            'batch_id' => $this->batchId,
            'slugs' => $this->slugs,
            'locales' => $this->locales,
            'rollback_group' => $this->rollbackGroup,
            'current_state' => $this->currentState,
            'target_state' => $this->targetState,
            'dry_run' => $this->dryRun,
            'writes_database' => false,
            'expected_locale_rows' => $this->expectedLocaleRows(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(array $manifestPayload): array
    {
        $manifest = $manifestPayload['manifest'] ?? $manifestPayload;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $string = strtolower(trim((string) $item));
            if ($string !== '') {
                $strings[$string] = $string;
            }
        }

        ksort($strings);

        return array_values($strings);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

final class PersonalityCurrentPageReader
{
    /** @var array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>}|null */
    private static ?array $runtimeIndex = null;

    public function __construct(private readonly PersonalityCurrentAuthorityPackage $package) {}

    /** @return array<string,mixed> */
    public function payload(string $framework, string $pageKind, string $entityKey, string $locale): array
    {
        $page = $this->package->pageFromIndex(
            $this->index(),
            $framework,
            $pageKind,
            $entityKey,
            $locale,
        );

        return $page['payload'];
    }

    /** @return array<string,mixed>|null */
    public function payloadOrNull(string $framework, string $pageKind, string $entityKey, string $locale): ?array
    {
        $identityKey = strtolower(trim($framework)).'|'.strtolower(trim($pageKind)).'|'.strtolower(trim($entityKey)).'|'.$locale;
        if (! isset($this->index()['entries'][$identityKey])) {
            return null;
        }

        return $this->payload($framework, $pageKind, $entityKey, $locale);
    }

    /** @return array<string,mixed>|null */
    public function payloadBySlugOrNull(string $framework, string $slug, string $locale): ?array
    {
        $normalizedFramework = strtolower(trim($framework));
        $normalizedSlug = strtolower(trim($slug));
        foreach ($this->index()['entries'] as $entry) {
            if ($entry['framework'] === $normalizedFramework
                && $entry['locale'] === $locale
                && strtolower((string) $entry['slug']) === $normalizedSlug) {
                return $this->payload($normalizedFramework, $entry['page_kind'], $entry['entity_key'], $locale);
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    public function payloads(string $framework, ?string $pageKind, string $locale): array
    {
        $payloads = [];
        foreach ($this->index()['entries'] as $entry) {
            if ($entry['framework'] !== $framework
                || $entry['locale'] !== $locale
                || ($pageKind !== null && $entry['page_kind'] !== $pageKind)) {
                continue;
            }
            $payloads[] = $this->payload($framework, $entry['page_kind'], $entry['entity_key'], $locale);
        }

        return $payloads;
    }

    public function aggregateSha256(): string
    {
        $index = $this->index();

        return (string) $index['manifest']['aggregate_sha256'];
    }

    /** @return array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>} */
    private function index(): array
    {
        return self::$runtimeIndex ??= $this->package->runtimeIndex(base_path());
    }
}

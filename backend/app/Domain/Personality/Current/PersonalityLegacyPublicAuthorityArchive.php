<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

use RuntimeException;

final class PersonalityLegacyPublicAuthorityArchive
{
    public const TEST_LEGACY_DB_FIXTURE_CONFIG = 'fap.testing_personality_legacy_public_db_fixture';

    public static function shouldUseCurrentAuthority(
        int $orgId,
        bool $runningUnitTests,
        bool $legacyDbFixtureRequested,
    ): bool {
        if ($orgId !== 0) {
            return false;
        }

        return ! $runningUnitTests || ! $legacyDbFixtureRequested;
    }

    public static function assertLegacyWriteIsArchived(
        bool $writeRequested,
        bool $runningUnitTests,
        string $operation,
    ): void {
        if (! $writeRequested || $runningUnitTests) {
            return;
        }

        throw new RuntimeException(
            "{$operation} is archived: org_id=0 public personality content is authoritative only in personality.page.content.v1."
        );
    }
}

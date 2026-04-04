<?php

declare(strict_types=1);

namespace App\Services\Attempts\InviteUnlock;

final class InviteUnlockStatus
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::EXPIRED,
            self::CANCELLED,
        ];
    }
}

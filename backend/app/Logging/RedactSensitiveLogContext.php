<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactSensitiveLogContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(
            static fn (LogRecord $record): LogRecord => $record->with(
                message: SensitiveDiagnosticRedactor::redactString($record->message),
                context: SensitiveDiagnosticRedactor::redactArray($record->context),
                extra: SensitiveDiagnosticRedactor::redactArray($record->extra),
            )
        );
    }
}

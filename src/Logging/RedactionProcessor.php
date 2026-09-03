<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactionProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SecretRedactor::redact($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param array $data
     * @return array
     */
    private function redactArray(array $data): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && SecretRedactor::isSensitiveKey($key)) {
                $redacted[$key] = SecretRedactor::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);
                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = SecretRedactor::redact($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }
}

<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

final class SecretRedactor
{
    public const REDACTED = '[REDACTED]';

    public const SENSITIVE_KEY = '/(password|passwd|secret|token|authorization|cookie|api[_-]?key|credit[_-]?card|card[_-]?number|cvv)/i';

    private const QUERY_SECRET = '/(?i)(?<![A-Za-z0-9_])(?:password|passwd|secret|token|api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|cookie)(?:=|%3[Dd])[^\s&"\']*/';

    private const BEARER = '/(?i)Bearer\s+[^\s"\']+/';

    public static function isSensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY, $key) === 1;
    }

    public static function redact(string $value): string
    {
        $redacted = preg_replace(self::QUERY_SECRET, self::REDACTED, $value) ?? $value;
        $redacted = preg_replace(self::BEARER, self::REDACTED, $redacted) ?? $redacted;

        foreach ($_ENV as $key => $secret) {
            if (!is_string($key) || !is_string($secret) || $secret === '') {
                continue;
            }
            if (self::isSensitiveKey($key)) {
                $redacted = str_replace($secret, self::REDACTED, $redacted);
            }
        }

        return $redacted;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $out[$key] = self::REDACTED;
                    continue;
                }
                $out[$key] = self::redactValue($item);
            }

            return $out;
        }

        if (is_string($value)) {
            return self::redact($value);
        }

        return $value;
    }
}

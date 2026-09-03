<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Fig\Http\Message\StatusCodeInterface;
use Monolog\Level;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Правила выбора уровня логирования.
 *
 * 1. Одинаковая ситуация — один уровень.
 * 2. Ожидаемые бизнес-ситуации («не найдено», валидация, 4xx) — WARNING или INFO, не ERROR.
 * 3. Логировать ошибку в месте возникновения. Не повторять при пробросе
 *    repository → use-case → action → middleware.
 * 4. HTTP 4xx → WARNING (или INFO). HTTP 5xx → ERROR.
 * 5. Необработанный Error / fatal / невозможность продолжить работу потока или сервиса → CRITICAL.
 */
final class LogLevelPolicy
{
    /**
     * @param int $statusCode
     * @return string
     */
    public static function forHttpStatus(int $statusCode): string
    {
        if ($statusCode >= StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR) {
            return LogLevel::ERROR;
        }

        if ($statusCode >= StatusCodeInterface::STATUS_BAD_REQUEST) {
            return LogLevel::WARNING;
        }

        return LogLevel::INFO;
    }

    /**
     * @param Throwable $exception
     * @param int $statusCode
     * @return string
     */
    public static function forUncaught(Throwable $exception, int $statusCode): string
    {
        if ($exception instanceof \Error) {
            return LogLevel::CRITICAL;
        }

        return self::forHttpStatus($statusCode);
    }

    /**
     * @return Level
     */
    public static function fromEnv(): Level
    {
        $name = strtoupper(self::envString('APP_LOG_LEVEL'));

        return match ($name) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'WARNING', 'WARN' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL', 'FATAL' => Level::Critical,
            default => self::debugEnabled() ? Level::Debug : Level::Info,
        };
    }

    /**
     * @return bool
     */
    public static function isDebug(): bool
    {
        return self::fromEnv() === Level::Debug;
    }

    /**
     * Query/body в extra: всегда при DEBUG; иначе только для 4xx/5xx.
     *
     * @param int|null $statusCode
     * @return bool
     */
    public static function shouldIncludeHttpPayload(?int $statusCode): bool
    {
        if (self::isDebug()) {
            return true;
        }

        return $statusCode !== null && $statusCode >= StatusCodeInterface::STATUS_BAD_REQUEST;
    }

    /**
     * @return bool
     */
    private static function debugEnabled(): bool
    {
        $debug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? false;
        if (is_bool($debug)) {
            return $debug;
        }

        return in_array(strtolower((string)$debug), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param string $name
     * @return string
     */
    private static function envString(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if ($value === false || $value === null) {
            return '';
        }

        return trim((string)$value);
    }
}

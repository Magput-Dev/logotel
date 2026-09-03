<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use Fig\Http\Message\StatusCodeInterface;
use Magput\Logotel\Logging\LogLevelPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use TypeError;

/**
 * @internal
 */
#[CoversClass(LogLevelPolicy::class)]
final class LogLevelPolicyTest extends TestCase
{
    #[DataProvider('httpStatusProvider')]
    public function testForHttpStatus(int $status, string $expected): void
    {
        self::assertSame($expected, LogLevelPolicy::forHttpStatus($status));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function httpStatusProvider(): array
    {
        return [
            'ok' => [StatusCodeInterface::STATUS_OK, LogLevel::INFO],
            'not found' => [StatusCodeInterface::STATUS_NOT_FOUND, LogLevel::WARNING],
            'validation' => [StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, LogLevel::WARNING],
            'server error' => [StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR, LogLevel::ERROR],
            'bad gateway' => [StatusCodeInterface::STATUS_BAD_GATEWAY, LogLevel::ERROR],
        ];
    }

    public function testUncaughtErrorIsCritical(): void
    {
        self::assertSame(
            LogLevel::CRITICAL,
            LogLevelPolicy::forUncaught(new TypeError('bad type'), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR),
        );
    }

    public function testUncaughtExceptionFollowsHttpStatus(): void
    {
        self::assertSame(
            LogLevel::ERROR,
            LogLevelPolicy::forUncaught(new RuntimeException('failed'), StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR),
        );
        self::assertSame(
            LogLevel::WARNING,
            LogLevelPolicy::forUncaught(new RuntimeException('not found'), StatusCodeInterface::STATUS_NOT_FOUND),
        );
    }

    public function testShouldIncludeHttpPayload(): void
    {
        $previousLevel = $_ENV['APP_LOG_LEVEL'] ?? false;
        $previousDebug = $_ENV['APP_DEBUG'] ?? false;

        try {
            $_ENV['APP_LOG_LEVEL'] = 'INFO';
            $_SERVER['APP_LOG_LEVEL'] = 'INFO';
            putenv('APP_LOG_LEVEL=INFO');
            $_ENV['APP_DEBUG'] = '0';
            $_SERVER['APP_DEBUG'] = '0';
            putenv('APP_DEBUG=0');

            self::assertFalse(LogLevelPolicy::shouldIncludeHttpPayload(StatusCodeInterface::STATUS_OK));
            self::assertFalse(LogLevelPolicy::shouldIncludeHttpPayload(null));
            self::assertTrue(LogLevelPolicy::shouldIncludeHttpPayload(StatusCodeInterface::STATUS_BAD_REQUEST));
            self::assertTrue(LogLevelPolicy::shouldIncludeHttpPayload(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR));

            $_ENV['APP_LOG_LEVEL'] = 'DEBUG';
            $_SERVER['APP_LOG_LEVEL'] = 'DEBUG';
            putenv('APP_LOG_LEVEL=DEBUG');

            self::assertTrue(LogLevelPolicy::isDebug());
            self::assertTrue(LogLevelPolicy::shouldIncludeHttpPayload(StatusCodeInterface::STATUS_OK));
            self::assertTrue(LogLevelPolicy::shouldIncludeHttpPayload(null));
        } finally {
            unset($_ENV['APP_LOG_LEVEL'], $_SERVER['APP_LOG_LEVEL'], $_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
            putenv('APP_LOG_LEVEL');
            putenv('APP_DEBUG');
            if (is_string($previousLevel)) {
                $_ENV['APP_LOG_LEVEL'] = $previousLevel;
                $_SERVER['APP_LOG_LEVEL'] = $previousLevel;
                putenv('APP_LOG_LEVEL=' . $previousLevel);
            }
            if (is_string($previousDebug)) {
                $_ENV['APP_DEBUG'] = $previousDebug;
                $_SERVER['APP_DEBUG'] = $previousDebug;
                putenv('APP_DEBUG=' . $previousDebug);
            }
        }
    }
}

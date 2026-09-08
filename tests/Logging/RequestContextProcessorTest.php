<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use DateTimeImmutable;
use Fig\Http\Message\StatusCodeInterface;
use Magput\Logotel\Logging\RequestContextProcessor;
use Magput\Logotel\Logging\RequestLogContext;
use Magput\Logotel\Logging\RequestPayload;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RequestContextProcessor::class)]
final class RequestContextProcessorTest extends TestCase
{
    private mixed $previousLogLevel;
    private mixed $previousDebug;

    protected function setUp(): void
    {
        $this->previousLogLevel = $_ENV['APP_LOG_LEVEL'] ?? false;
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? false;
    }

    protected function tearDown(): void
    {
        $this->restore('APP_LOG_LEVEL', $this->previousLogLevel);
        $this->restore('APP_DEBUG', $this->previousDebug);
    }

    public function testDebugIncludesQueryAndBodyOnSuccess(): void
    {
        $this->setEnv('APP_LOG_LEVEL', 'DEBUG');
        $this->setEnv('APP_DEBUG', '0');

        $context = new RequestLogContext();
        $context->query = 'foo=1';
        $context->body = '{"q":"ok"}';
        $context->statusCode = StatusCodeInterface::STATUS_OK;

        $record = (new RequestContextProcessor($context))($this->record());

        self::assertSame('foo=1', $record->extra[RequestPayload::QUERY_KEY]);
        self::assertSame('{"q":"ok"}', $record->extra[RequestPayload::BODY_KEY]);
    }

    public function testInfoSuccessOmitsQueryAndBody(): void
    {
        $this->setEnv('APP_LOG_LEVEL', 'INFO');
        $this->setEnv('APP_DEBUG', '0');

        $context = new RequestLogContext();
        $context->query = 'foo=1';
        $context->body = '{"q":"ok"}';
        $context->statusCode = StatusCodeInterface::STATUS_OK;

        $record = (new RequestContextProcessor($context))($this->record());

        self::assertArrayNotHasKey(RequestPayload::QUERY_KEY, $record->extra);
        self::assertArrayNotHasKey(RequestPayload::BODY_KEY, $record->extra);
    }

    public function testInfoErrorIncludesQueryAndBody(): void
    {
        $this->setEnv('APP_LOG_LEVEL', 'INFO');
        $this->setEnv('APP_DEBUG', '0');

        $context = new RequestLogContext();
        $context->query = 'foo=1';
        $context->body = '{"q":"ok"}';
        $context->statusCode = StatusCodeInterface::STATUS_BAD_REQUEST;

        $record = (new RequestContextProcessor($context))($this->record());

        self::assertSame('foo=1', $record->extra[RequestPayload::QUERY_KEY]);
        self::assertSame('{"q":"ok"}', $record->extra[RequestPayload::BODY_KEY]);
    }

    private function record(): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'API',
            level: Level::Info,
            message: 'msg',
        );
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    /**
     * @param string $name
     * @param mixed $previous
     * @return void
     */
    private function restore(string $name, mixed $previous): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
        if (is_string($previous)) {
            $_ENV[$name] = $previous;
            $_SERVER[$name] = $previous;
            putenv($name . '=' . $previous);
        }
    }
}

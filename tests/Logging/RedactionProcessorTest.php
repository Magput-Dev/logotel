<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use DateTimeImmutable;
use Magput\Logotel\Logging\RedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(RedactionProcessor::class)]
final class RedactionProcessorTest extends TestCase
{
    private ?string $previousToken;

    protected function setUp(): void
    {
        $this->previousToken = isset($_ENV['RT_OPERATOR_TOKEN']) && is_string($_ENV['RT_OPERATOR_TOKEN'])
            ? $_ENV['RT_OPERATOR_TOKEN']
            : null;
        $_ENV['RT_OPERATOR_TOKEN'] = 'rt-operator-secret-value';
    }

    protected function tearDown(): void
    {
        if ($this->previousToken === null) {
            unset($_ENV['RT_OPERATOR_TOKEN']);
            return;
        }

        $_ENV['RT_OPERATOR_TOKEN'] = $this->previousToken;
    }

    public function testRedactsSensitiveKeysRecursively(): void
    {
        $processor = new RedactionProcessor();
        $record = $processor(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'API',
            level: Level::Info,
            message: 'payload',
            context: [
                'password' => 'secret-password',
                'RT_OPERATOR_TOKEN' => 'abc',
                'Authorization' => 'Bearer xyz',
                'nested' => [
                    'access_token' => 'tok',
                    'safe' => 'ok',
                ],
            ],
            extra: [
                'cookie' => 'sid=1',
                'http.request.method' => 'GET',
            ],
        ));

        self::assertSame('[REDACTED]', $record->context['password']);
        self::assertSame('[REDACTED]', $record->context['RT_OPERATOR_TOKEN']);
        self::assertSame('[REDACTED]', $record->context['Authorization']);
        self::assertSame('[REDACTED]', $record->context['nested']['access_token']);
        self::assertSame('ok', $record->context['nested']['safe']);
        self::assertSame('[REDACTED]', $record->extra['cookie']);
        self::assertSame('GET', $record->extra['http.request.method']);
    }

    public function testRedactsTokenQueryInMessageAndContext(): void
    {
        $processor = new RedactionProcessor();
        $message = 'cURL error 28: timeout for https://www.rtoperator.ru/export.html?token=rt-operator-secret-value&data_type=json';
        $record = $processor(new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'API',
            level: Level::Error,
            message: $message,
            context: [
                'exception.stacktrace' => $message,
            ],
        ));

        $haystack = $record->message . json_encode($record->context, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('token=', $haystack);
        self::assertStringNotContainsString('rt-operator-secret-value', $haystack);
        self::assertStringContainsString('[REDACTED]', $record->message);
        self::assertStringContainsString('data_type=json', $record->message);
    }
}

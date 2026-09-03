<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use Magput\Logotel\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SecretRedactor::class)]
final class SecretRedactorTest extends TestCase
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

    #[DataProvider('secretTextProvider')]
    public function testRedactsSecretsInFreeText(string $input): void
    {
        $redacted = SecretRedactor::redact($input);

        self::assertStringNotContainsString('token=', $redacted);
        self::assertStringNotContainsString('TOKEN=', $redacted);
        self::assertStringNotContainsString('rt-operator-secret-value', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }

    public function testRedactValueMasksSensitiveKeys(): void
    {
        $redacted = SecretRedactor::redactValue([
            'password' => 'p@ss',
            'nested' => ['token' => 'abc', 'safe' => 'ok'],
            'url' => 'https://example.test?token=rt-operator-secret-value',
        ]);

        self::assertSame('[REDACTED]', $redacted['password']);
        self::assertSame('[REDACTED]', $redacted['nested']['token']);
        self::assertSame('ok', $redacted['nested']['safe']);
        self::assertStringNotContainsString('token=', $redacted['url']);
        self::assertStringNotContainsString('rt-operator-secret-value', $redacted['url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretTextProvider(): array
    {
        return [
            'guzzle curl url' => [
                'cURL error 6: Could not resolve host (see https://curl.haxx.se) for https://www.rtoperator.ru/export.html?token=rt-operator-secret-value&data_type=json&entity=tour',
            ],
            'uppercase query' => [
                'GET https://example.test/api?TOKEN=rt-operator-secret-value',
            ],
            'encoded query' => [
                'https://example.test/api?token%3Drt-operator-secret-value&entity=tour',
            ],
            'bearer header' => [
                'Authorization: Bearer rt-operator-secret-value',
            ],
            'raw env value' => [
                'upstream failed: rt-operator-secret-value',
            ],
        ];
    }

    public function testKeepsSafeText(): void
    {
        $input = 'cURL error 6: Could not resolve host for https://www.rtoperator.ru/export.html?data_type=json&entity=tour';

        self::assertSame($input, SecretRedactor::redact($input));
    }
}

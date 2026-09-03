<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use Magput\Logotel\Logging\RequestPayload;
use Magput\Logotel\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * @internal
 */
#[CoversClass(RequestPayload::class)]
final class RequestPayloadTest extends TestCase
{
    private mixed $previousBodyMax;

    protected function setUp(): void
    {
        $this->previousBodyMax = $_ENV['HTTP_LOG_BODY_MAX'] ?? false;
        unset($_ENV['HTTP_LOG_BODY_MAX'], $_SERVER['HTTP_LOG_BODY_MAX']);
        putenv('HTTP_LOG_BODY_MAX');
        $_ENV['RT_OPERATOR_TOKEN'] = 'rt-operator-secret-value';
    }

    protected function tearDown(): void
    {
        unset($_ENV['HTTP_LOG_BODY_MAX'], $_SERVER['HTTP_LOG_BODY_MAX']);
        putenv('HTTP_LOG_BODY_MAX');
        if (is_string($this->previousBodyMax)) {
            $_ENV['HTTP_LOG_BODY_MAX'] = $this->previousBodyMax;
            $_SERVER['HTTP_LOG_BODY_MAX'] = $this->previousBodyMax;
            putenv('HTTP_LOG_BODY_MAX=' . $this->previousBodyMax);
        }
        unset($_ENV['RT_OPERATOR_TOKEN']);
    }

    public function testFieldsAlwaysPresentAndRedacted(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/items?foo=1&token=rt-operator-secret-value')
            ->withParsedBody([
                'password' => 'p@ss',
                'q' => 'ok',
            ]);

        $fields = RequestPayload::fields($request);

        self::assertArrayHasKey(RequestPayload::QUERY_KEY, $fields);
        self::assertArrayHasKey(RequestPayload::BODY_KEY, $fields);
        self::assertStringNotContainsString('token=', $fields[RequestPayload::QUERY_KEY]);
        self::assertStringNotContainsString('rt-operator-secret-value', $fields[RequestPayload::QUERY_KEY]);
        self::assertStringContainsString('foo=1', $fields[RequestPayload::QUERY_KEY]);
        self::assertSame(SecretRedactor::REDACTED, json_decode($fields[RequestPayload::BODY_KEY], true)['password']);
        self::assertSame('ok', json_decode($fields[RequestPayload::BODY_KEY], true)['q']);
    }

    public function testEmptyQueryAndBodyAreEmptyStrings(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://test/health-check');
        $fields = RequestPayload::fields($request);

        self::assertSame('', $fields[RequestPayload::QUERY_KEY]);
        self::assertSame('', $fields[RequestPayload::BODY_KEY]);
    }

    public function testReadsRawJsonBodyAndRewindsStream(): void
    {
        $stream = (new StreamFactory())->createStream('{"token":"rt-operator-secret-value","n":1}');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $body = RequestPayload::body($request);

        self::assertStringNotContainsString('rt-operator-secret-value', $body);
        self::assertStringContainsString('[REDACTED]', $body);
        self::assertSame('{"token":"rt-operator-secret-value","n":1}', (string)$request->getBody());
    }

    public function testTruncatesBody(): void
    {
        $_ENV['HTTP_LOG_BODY_MAX'] = '16';
        $_SERVER['HTTP_LOG_BODY_MAX'] = '16';
        putenv('HTTP_LOG_BODY_MAX=16');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/')
            ->withParsedBody(['payload' => str_repeat('a', 100)]);

        $body = RequestPayload::body($request);

        self::assertSame(16 + strlen(RequestPayload::TRUNCATED_SUFFIX), strlen($body));
        self::assertStringEndsWith(RequestPayload::TRUNCATED_SUFFIX, $body);
    }

    public function testOmitsBinaryBody(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/')
            ->withHeader('Content-Type', 'image/png')
            ->withBody((new StreamFactory())->createStream('not-an-image'));

        self::assertSame(RequestPayload::OMITTED_BINARY, RequestPayload::body($request));
    }

    public function testUploadedFilesMetadataWithoutContent(): void
    {
        $file = $this->createStub(UploadedFileInterface::class);
        $file->method('getClientFilename')->willReturn('secret.bin');
        $file->method('getSize')->willReturn(12);
        $file->method('getClientMediaType')->willReturn('application/octet-stream');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/')
            ->withParsedBody(['note' => 'hi'])
            ->withUploadedFiles(['file' => $file]);

        $decoded = json_decode(RequestPayload::body($request), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('hi', $decoded['note']);
        self::assertSame('secret.bin', $decoded['_files']['file']['clientFilename']);
        self::assertSame(12, $decoded['_files']['file']['size']);
        self::assertArrayNotHasKey('stream', $decoded['_files']['file']);
    }
}

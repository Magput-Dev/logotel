<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Logging;

use Fig\Http\Message\StatusCodeInterface;
use Magput\Logotel\Logging\HttpErrorLog;
use Magput\Logotel\Logging\RequestPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * @internal
 */
#[CoversClass(HttpErrorLog::class)]
final class HttpErrorLogTest extends TestCase
{
    public function testContextAlwaysIncludesQueryAndBody(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://test/items?foo=1&token=secret')
            ->withParsedBody([
                'password' => 'p@ss',
                'q' => 'ok',
            ]);

        $context = HttpErrorLog::context(
            new RuntimeException('failed'),
            $request,
            StatusCodeInterface::STATUS_BAD_REQUEST,
        );

        self::assertArrayHasKey(RequestPayload::QUERY_KEY, $context);
        self::assertArrayHasKey(RequestPayload::BODY_KEY, $context);
        self::assertStringNotContainsString('token=', $context[RequestPayload::QUERY_KEY]);
        self::assertStringContainsString('foo=1', $context[RequestPayload::QUERY_KEY]);
        self::assertStringContainsString('[REDACTED]', $context[RequestPayload::BODY_KEY]);
        self::assertStringContainsString('"q":"ok"', $context[RequestPayload::BODY_KEY]);
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $context['http.response.status_code']);
    }

    public function testContextIncludesEmptyQueryAndBody(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://test/missing');
        $context = HttpErrorLog::context(
            new RuntimeException('not found'),
            $request,
            StatusCodeInterface::STATUS_NOT_FOUND,
        );

        self::assertSame('', $context[RequestPayload::QUERY_KEY]);
        self::assertSame('', $context[RequestPayload::BODY_KEY]);
    }
}

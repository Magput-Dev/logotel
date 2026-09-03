<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Http;

use Magput\Logotel\Http\TracingMiddleware;
use Magput\Logotel\Logging\RequestLogContext;
use Magput\Logotel\Logging\RequestPayload;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * @internal
 */
#[CoversClass(TracingMiddleware::class)]
final class TracingMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Globals::reset();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter()));
        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        Globals::reset();
    }

    public function testAddsTraceAndRequestHeaders(): void
    {
        $middleware = new TracingMiddleware(new RequestLogContext(), new NullLogger());
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://test/health-check');
        $response = $middleware->process($request, $handler);

        $traceId = $response->getHeaderLine('X-Trace-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        self::assertNotSame('', $response->getHeaderLine('X-Request-Id'));
    }

    public function testContinuesIncomingW3cTrace(): void
    {
        $middleware = new TracingMiddleware(new RequestLogContext(), new NullLogger());
        $seenTraceId = null;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function ($request) use (&$seenTraceId) {
                $seenTraceId = $request->getHeaderLine('X-Trace-Id');
                return (new ResponseFactory())->createResponse();
            }
        );

        $incomingTraceId = str_repeat('a', 32);
        $incomingSpanId = str_repeat('b', 16);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://test/rtoperator/program-info')
            ->withHeader('traceparent', sprintf('00-%s-%s-01', $incomingTraceId, $incomingSpanId));

        $response = $middleware->process($request, $handler);

        self::assertSame($incomingTraceId, $response->getHeaderLine('X-Trace-Id'));
        self::assertSame($incomingTraceId, $seenTraceId);
    }

    public function testContinuesIncomingXTraceId(): void
    {
        $middleware = new TracingMiddleware(new RequestLogContext(), new NullLogger());
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

        $incomingTraceId = str_repeat('c', 32);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://test/')
            ->withHeader('X-Trace-Id', $incomingTraceId);

        $response = $middleware->process($request, $handler);

        self::assertSame($incomingTraceId, $response->getHeaderLine('X-Trace-Id'));
    }

    public function testDebugLogsQueryAndBodyOnSuccess(): void
    {
        $previousLevel = $_ENV['APP_LOG_LEVEL'] ?? false;
        $previousDebug = $_ENV['APP_DEBUG'] ?? false;
        $_ENV['APP_LOG_LEVEL'] = 'DEBUG';
        $_SERVER['APP_LOG_LEVEL'] = 'DEBUG';
        putenv('APP_LOG_LEVEL=DEBUG');
        $_ENV['APP_DEBUG'] = '0';
        $_SERVER['APP_DEBUG'] = '0';
        putenv('APP_DEBUG=0');

        try {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('debug')->with(
                'HTTP request',
                self::callback(static function (array $context): bool {
                    return ($context['http.response.status_code'] ?? null) === 200
                        && ($context[RequestPayload::QUERY_KEY] ?? null) === 'foo=1'
                        && array_key_exists(RequestPayload::BODY_KEY, $context);
                }),
            );

            $middleware = new TracingMiddleware(new RequestLogContext(), $logger);
            $handler = $this->createStub(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://test/health-check?foo=1');
            $middleware->process($request, $handler);
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

    public function testInfoDoesNotLogQueryAndBodyOnSuccess(): void
    {
        $previousLevel = $_ENV['APP_LOG_LEVEL'] ?? false;
        $previousDebug = $_ENV['APP_DEBUG'] ?? false;
        $_ENV['APP_LOG_LEVEL'] = 'INFO';
        $_SERVER['APP_LOG_LEVEL'] = 'INFO';
        putenv('APP_LOG_LEVEL=INFO');
        $_ENV['APP_DEBUG'] = '0';
        $_SERVER['APP_DEBUG'] = '0';
        putenv('APP_DEBUG=0');

        try {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::never())->method('debug');
            $logger->expects(self::never())->method('log');

            $context = new RequestLogContext();
            $middleware = new TracingMiddleware($context, $logger);
            $handler = $this->createStub(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://test/health-check?foo=1');
            $middleware->process($request, $handler);

            self::assertSame('foo=1', $context->query);
            self::assertSame(200, $context->statusCode);
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

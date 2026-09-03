<?php

declare(strict_types=1);

namespace Magput\Logotel\Http;

use Fig\Http\Message\StatusCodeInterface;
use Magput\Logotel\Logging\HttpErrorLog;
use Magput\Logotel\Logging\LogLevelPolicy;
use Magput\Logotel\Logging\RequestLogContext;
use Magput\Logotel\Logging\RequestPayload;
use Magput\Logotel\Logging\SecretRedactor;
use Magput\Logotel\Telemetry\OpenTelemetryEnv;
use Override;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

final class TracingMiddleware implements MiddlewareInterface
{
    public const HEADER_TRACE_ID = 'X-Trace-Id';
    public const HEADER_REQUEST_ID = 'X-Request-Id';

    public function __construct(
        private readonly RequestLogContext $requestLogContext,
        private readonly LoggerInterface $logger,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = $this->resolveRequestId($request);
        $this->requestLogContext->reset();
        $this->requestLogContext->requestId = $requestId;
        $this->requestLogContext->method = $request->getMethod();
        $this->requestLogContext->route = HttpErrorLog::route($request);
        $payload = RequestPayload::fields($request);
        $this->requestLogContext->query = $payload[RequestPayload::QUERY_KEY];
        $this->requestLogContext->body = $payload[RequestPayload::BODY_KEY];

        $parent = $this->extractParentContext($request);
        $span = Globals::tracerProvider()
            ->getTracer($this->tracerName())
            ->spanBuilder($this->spanName($request))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->getMethod())
            ->setAttribute('url.path', $request->getUri()->getPath())
            ->setAttribute('url.scheme', $request->getUri()->getScheme())
            ->setAttribute('user_agent.original', $request->getHeaderLine('User-Agent'))
            ->startSpan();

        $scope = $span->activate();
        $traceId = $span->getContext()->getTraceId();
        $traceparent = $this->formatTraceparent($span);

        $_SERVER['X-Trace-Id'] = $traceId;
        $_SERVER['X-Trace-ID'] = $traceId;
        $_SERVER['HTTP_TRACEPARENT'] = $traceparent;

        $request = $request
            ->withHeader(self::HEADER_TRACE_ID, $traceId)
            ->withHeader(self::HEADER_REQUEST_ID, $requestId)
            ->withHeader('traceparent', $traceparent);

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();
            $route = HttpErrorLog::route($request);

            $this->requestLogContext->route = $route;
            $this->requestLogContext->statusCode = $status;
            $this->logSuccessfulRequest($request, $status, $route);

            $span->setAttribute('http.response.status_code', $status);
            $span->setAttribute('http.route', $route);
            if (method_exists($span, 'updateName')) {
                $span->updateName($request->getMethod() . ' ' . $route);
            }
            if ($status >= StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response
                ->withHeader(self::HEADER_TRACE_ID, $traceId)
                ->withHeader(self::HEADER_REQUEST_ID, $requestId);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, SecretRedactor::redact($e->getMessage()));
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param int $status
     * @param string $route
     * @return void
     */
    private function logSuccessfulRequest(ServerRequestInterface $request, int $status, string $route): void
    {
        if (
            $status >= StatusCodeInterface::STATUS_BAD_REQUEST
            || !LogLevelPolicy::isDebug()
        ) {
            return;
        }

        $this->logger->debug('HTTP request', [
            'http.response.status_code' => $status,
            'http.route' => $route,
            'http.request.method' => $request->getMethod(),
            RequestPayload::QUERY_KEY => $this->requestLogContext->query ?? '',
            RequestPayload::BODY_KEY => $this->requestLogContext->body ?? '',
        ]);
    }

    private function tracerName(): string
    {
        return OpenTelemetryEnv::read('OTEL_SERVICE_NAME') ?: 'app';
    }

    private function extractParentContext(ServerRequestInterface $request): Context
    {
        $carrier = [];
        foreach ($request->getHeaders() as $name => $values) {
            $carrier[strtolower((string)$name)] = $values[0] ?? '';
        }

        $extracted = TraceContextPropagator::getInstance()->extract($carrier);
        $extractedSpan = Span::fromContext($extracted);
        if ($extractedSpan->getContext()->isValid()) {
            return $extracted;
        }

        $incomingTraceId = $this->header($request, self::HEADER_TRACE_ID);
        if ($incomingTraceId !== '' && preg_match('/^[0-9a-f]{32}$/i', $incomingTraceId) === 1) {
            $remote = SpanContext::createFromRemoteParent(
                strtolower($incomingTraceId),
                bin2hex(random_bytes(8)),
                TraceFlags::SAMPLED,
            );

            return Span::wrap($remote)->storeInContext(Context::getCurrent());
        }

        return Context::getCurrent();
    }

    private function resolveRequestId(ServerRequestInterface $request): string
    {
        $incoming = $this->header($request, self::HEADER_REQUEST_ID);
        if ($incoming !== '') {
            return $incoming;
        }

        return Uuid::uuid4()->toString();
    }

    private function spanName(ServerRequestInterface $request): string
    {
        return $request->getMethod() . ' ' . HttpErrorLog::route($request);
    }

    private function formatTraceparent(SpanInterface $span): string
    {
        $context = $span->getContext();
        $flags = $context->isSampled() ? '01' : '00';

        return sprintf('00-%s-%s-%s', $context->getTraceId(), $context->getSpanId(), $flags);
    }

    private function header(ServerRequestInterface $request, string $name): string
    {
        return trim($request->getHeaderLine($name));
    }
}

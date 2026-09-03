<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class HttpErrorLog
{
    /**
     * @param LoggerInterface $logger
     * @param Throwable $exception
     * @param ServerRequestInterface $request
     * @param int $statusCode
     * @param string $level
     * @param string|null $message
     * @return void
     */
    public static function write(
        LoggerInterface $logger,
        Throwable $exception,
        ServerRequestInterface $request,
        int $statusCode,
        string $level,
        ?string $message = null,
    ): void {
        $logger->log(
            $level,
            SecretRedactor::redact($message ?? $exception->getMessage()),
            self::context($exception, $request, $statusCode),
        );
    }

    /**
     * @param Throwable $exception
     * @param ServerRequestInterface $request
     * @param int $statusCode
     * @return array
     */
    public static function context(
        Throwable $exception,
        ServerRequestInterface $request,
        int $statusCode,
    ): array {
        return array_merge([
            'exception.type' => $exception::class,
            'exception.stacktrace' => SecretRedactor::redact($exception->getTraceAsString()),
            'http.response.status_code' => $statusCode,
            'http.route' => self::route($request),
            'http.request.method' => $request->getMethod(),
        ], RequestPayload::fields($request));
    }

    /**
     * @param ServerRequestInterface $request
     * @return string
     */
    public static function route(ServerRequestInterface $request): string
    {
        $route = $request->getAttribute('__route__');
        if (is_object($route) && method_exists($route, 'getPattern')) {
            $pattern = $route->getPattern();
            if (is_string($pattern) && $pattern !== '') {
                return $pattern;
            }
        }

        return $request->getUri()->getPath();
    }

    /**
     * @param int $code
     * @param int $fallback
     * @return int
     */
    public static function httpStatus(
        int $code,
        int $fallback = StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
    ): int {
        if (
            $code >= StatusCodeInterface::STATUS_BAD_REQUEST
            && $code <= StatusCodeInterface::STATUS_NETWORK_AUTHENTICATION_REQUIRED
        ) {
            return $code;
        }

        return $fallback;
    }
}

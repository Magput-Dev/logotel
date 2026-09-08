<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestLogContext $requestLogContext,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        if ($this->requestLogContext->requestId !== null) {
            $extra['request_id'] = $this->requestLogContext->requestId;
        }
        if ($this->requestLogContext->method !== null) {
            $extra['http.request.method'] = $this->requestLogContext->method;
        }
        if ($this->requestLogContext->route !== null) {
            $extra['http.route'] = $this->requestLogContext->route;
        }
        if ($this->requestLogContext->statusCode !== null) {
            $extra['http.response.status_code'] = $this->requestLogContext->statusCode;
        }
        if (LogLevelPolicy::shouldIncludeHttpPayload($this->requestLogContext->statusCode)) {
            if ($this->requestLogContext->query !== null) {
                $extra[RequestPayload::QUERY_KEY] = $this->requestLogContext->query;
            }
            if ($this->requestLogContext->body !== null) {
                $extra[RequestPayload::BODY_KEY] = $this->requestLogContext->body;
            }
        }

        return $record->with(extra: $extra);
    }
}

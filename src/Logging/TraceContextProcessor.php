<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

final class TraceContextProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $spanContext = Span::getCurrent()->getContext();
        if (!$spanContext->isValid()) {
            return $record;
        }

        $extra = $record->extra;
        $extra['trace_id'] = $spanContext->getTraceId();
        $extra['span_id'] = $spanContext->getSpanId();

        return $record->with(extra: $extra);
    }
}

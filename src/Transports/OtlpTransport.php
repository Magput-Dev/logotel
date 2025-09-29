<?php

declare(strict_types=1);

namespace Magput\Debug\Transports;

use Magput\Debug\Contracts\TransportInterface;
use Magput\Debug\Contracts\EntryInterface;
use Magput\Debug\DataKeeper\NameDataKeeper;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

final class OtlpTransport implements TransportInterface
{
    private TracerInterface $tracer;

    public function __construct(TracerProviderInterface $provider, string $serviceName = 'magput')
    {
        $this->tracer = $provider->getTracer(NameDataKeeper::LOGGER_PREFIX . ':' . $serviceName . ':' . NameDataKeeper::LOGGER_TRACES_SUFFIX);
    }

    public function send(EntryInterface $entry): void
    {
        $span = $this->tracer->spanBuilder('debug.'.$entry->panel())
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $span->setAttribute('manifest.id', $entry->manifestId());
        if ($entry->level()) {
            $span->setAttribute('log.level', $entry->level());
        }
        foreach ($entry->payload() as $k => $v) {
            $span->setAttribute('payload.' . $k, is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        $span->end();
    }
}

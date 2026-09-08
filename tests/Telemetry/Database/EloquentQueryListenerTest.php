<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Telemetry\Database;

use Magput\Logotel\Telemetry\Database\DbSpanConfig;
use Magput\Logotel\Telemetry\Database\EloquentQueryListener;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EloquentQueryListener::class)]
final class EloquentQueryListenerTest extends TestCase
{
    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        Globals::reset();
        $this->exporter = new InMemoryExporter();
        Sdk::builder()
            ->setTracerProvider(new TracerProvider(new SimpleSpanProcessor($this->exporter)))
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        Globals::reset();
    }

    public function testListenCreatesClientChildWithSameTraceId(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $parent = $tracer->spanBuilder('GET /rtoperator/program-info')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $scope = $parent->activate();

        $event = new class {
            public string $sql = 'select * from `users` where `id` = ?';
            public float $time = 3.5;
            public object $connection;

            public function __construct()
            {
                $this->connection = new class {
                    public function getDatabaseName(): string
                    {
                        return 'mp_dbs_madmin';
                    }

                    public function getDriverName(): string
                    {
                        return 'mysql';
                    }
                };
            }
        };

        try {
            $listener = new EloquentQueryListener(new DbSpanConfig());
            $listener($event);
        } finally {
            $scope->detach();
            $parent->end();
        }

        $spans = array_values(array_filter(
            $this->exporter->getSpans(),
            static fn (SpanDataInterface $span): bool => $span->getKind() === SpanKind::KIND_CLIENT,
        ));
        self::assertCount(1, $spans);
        $db = $spans[0];
        self::assertSame($parent->getContext()->getTraceId(), $db->getTraceId());
        self::assertSame($parent->getContext()->getSpanId(), $db->getParentSpanId());
        self::assertSame('mysql', $db->getAttributes()->get('db.system'));
        self::assertSame('mp_dbs_madmin', $db->getAttributes()->get('db.name'));
        self::assertSame('SELECT', $db->getAttributes()->get('db.operation'));
        self::assertSame('select * from `users` where `id` = ?', $db->getAttributes()->get('db.statement'));
    }
}

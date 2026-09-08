<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Telemetry\Database;

use Magput\Logotel\Telemetry\Database\DbSpan;
use Magput\Logotel\Telemetry\Database\DbSpanConfig;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(DbSpan::class)]
#[CoversClass(DbSpanConfig::class)]
final class DbSpanTest extends TestCase
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

    public function testTraceCreatesClientChildWithSameTraceId(): void
    {
        $config = new DbSpanConfig(system: 'mysql', name: 'mp_dbs_madmin');
        $tracer = Globals::tracerProvider()->getTracer('test');
        $parent = $tracer->spanBuilder('GET /health-check')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $scope = $parent->activate();

        try {
            $result = DbSpan::trace(
                'select * from integration_rtoperator_prog_sync where progs_prog_id = ?',
                $config,
                static fn (): int => 42,
            );
            self::assertSame(42, $result);
        } finally {
            $scope->detach();
            $parent->end();
        }

        $spans = $this->clientSpans();
        self::assertCount(1, $spans);
        $db = $spans[0];
        self::assertSame($parent->getContext()->getTraceId(), $db->getTraceId());
        self::assertSame($parent->getContext()->getSpanId(), $db->getParentSpanId());
        self::assertSame(SpanKind::KIND_CLIENT, $db->getKind());
        self::assertSame('SELECT integration_rtoperator_prog_sync', $db->getName());
        self::assertSame('mysql', $db->getAttributes()->get('db.system'));
        self::assertSame('mp_dbs_madmin', $db->getAttributes()->get('db.name'));
        self::assertSame('SELECT', $db->getAttributes()->get('db.operation'));
        self::assertSame(
            'select * from integration_rtoperator_prog_sync where progs_prog_id = ?',
            $db->getAttributes()->get('db.statement'),
        );
    }

    public function testRecordCompletedOmitsStatementForBegin(): void
    {
        DbSpan::recordCompleted('BEGIN', 1.2, new DbSpanConfig(system: 'mysql', name: 'app'));
        $spans = $this->clientSpans();
        self::assertCount(1, $spans);
        self::assertSame('BEGIN', $spans[0]->getAttributes()->get('db.operation'));
        self::assertNull($spans[0]->getAttributes()->get('db.statement'));
    }

    public function testTraceRecordsException(): void
    {
        try {
            DbSpan::trace('UPDATE users SET name = ?', new DbSpanConfig(), static function (): void {
                throw new RuntimeException('deadlock');
            });
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('deadlock', $e->getMessage());
        }

        $spans = $this->clientSpans();
        self::assertCount(1, $spans);
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testFromArrayMapsDriver(): void
    {
        $config = DbSpanConfig::fromArray(['driver' => 'pgsql', 'database' => 'magput']);
        self::assertSame('postgresql', $config->system);
        self::assertSame('magput', $config->name);
    }

    /**
     * @return list<SpanDataInterface>
     */
    private function clientSpans(): array
    {
        return array_values(array_filter(
            $this->exporter->getSpans(),
            static fn (SpanDataInterface $span): bool => $span->getKind() === SpanKind::KIND_CLIENT,
        ));
    }
}

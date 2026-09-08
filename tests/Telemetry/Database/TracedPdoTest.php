<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Telemetry\Database;

use Magput\Logotel\Telemetry\Database\DbSpanConfig;
use Magput\Logotel\Telemetry\Database\TracedPdo;
use Magput\Logotel\Telemetry\Database\TracedPdoStatement;
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
#[CoversClass(TracedPdo::class)]
#[CoversClass(TracedPdoStatement::class)]
final class TracedPdoTest extends TestCase
{
    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required');
        }

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

    public function testPrepareExecuteAndExecAreClientChildrenOfHttpSpan(): void
    {
        $config = new DbSpanConfig(system: 'sqlite', name: 'memory');
        $tracer = Globals::tracerProvider()->getTracer('test');
        $parent = $tracer->spanBuilder('GET /items')->setSpanKind(SpanKind::KIND_SERVER)->startSpan();
        $scope = $parent->activate();

        try {
            $pdo = new TracedPdo('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->enableTracing($config);
            $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
            $stmt = $pdo->prepare('INSERT INTO items (name) VALUES (?)');
            self::assertInstanceOf(TracedPdoStatement::class, $stmt);
            $stmt->execute(['n']);
            $rows = $pdo->query('SELECT name FROM items');
            self::assertNotFalse($rows);
            self::assertSame(['name' => 'n'], $rows->fetch());
        } finally {
            $scope->detach();
            $parent->end();
        }

        $dbSpans = array_values(array_filter(
            $this->exporter->getSpans(),
            static fn (SpanDataInterface $span): bool => $span->getKind() === SpanKind::KIND_CLIENT,
        ));
        self::assertGreaterThanOrEqual(3, count($dbSpans));

        $traceId = $parent->getContext()->getTraceId();
        $parentId = $parent->getContext()->getSpanId();
        foreach ($dbSpans as $span) {
            self::assertSame($traceId, $span->getTraceId());
            self::assertSame($parentId, $span->getParentSpanId());
            self::assertSame('sqlite', $span->getAttributes()->get('db.system'));
            self::assertSame('memory', $span->getAttributes()->get('db.name'));
            self::assertNotNull($span->getAttributes()->get('db.operation'));
        }

        $operations = array_map(
            static fn (SpanDataInterface $span): string => (string)$span->getAttributes()->get('db.operation'),
            $dbSpans,
        );
        self::assertContains('CREATE', $operations);
        self::assertContains('INSERT', $operations);
        self::assertContains('SELECT', $operations);

        $statements = array_map(
            static fn (SpanDataInterface $span): ?string => $span->getAttributes()->get('db.statement'),
            $dbSpans,
        );
        self::assertTrue(in_array('INSERT INTO items (name) VALUES (?)', $statements, true));
        self::assertTrue(in_array('SELECT name FROM items', $statements, true));
    }
}

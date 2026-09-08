<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry\Database;

use Magput\Logotel\Logging\SecretRedactor;
use Magput\Logotel\Telemetry\OpenTelemetryEnv;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

final class DbSpan
{
    private const STATEMENT_OPERATIONS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'WITH',
        'SHOW', 'SET', 'CALL', 'EXPLAIN', 'TRUNCATE', 'ALTER', 'CREATE', 'DROP',
    ];

    /**
     * @template T
     * @param string $sql
     * @param DbSpanConfig $config
     * @param callable(): T $operation
     * @return T
     */
    public static function trace(string $sql, DbSpanConfig $config, callable $operation): mixed
    {
        $span = null;
        $scope = null;
        try {
            $span = self::start($sql, $config);
            $scope = $span->activate();
        } catch (Throwable $e) {
            fwrite(STDERR, 'DB span start failed: ' . $e->getMessage() . PHP_EOL);
        }

        try {
            return $operation();
        } catch (Throwable $e) {
            if ($span !== null) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, SecretRedactor::redact($e->getMessage()));
            }
            throw $e;
        } finally {
            $scope?->detach();
            $span?->end();
        }
    }

    /**
     * Span после факта (Eloquent QueryExecuted): query уже выполнен.
     *
     * @param string $sql
     * @param float $durationMs
     * @param DbSpanConfig $config
     * @return void
     */
    public static function recordCompleted(string $sql, float $durationMs, DbSpanConfig $config): void
    {
        try {
            $endNanos = self::nowNanos();
            $startNanos = $endNanos - (int) round(max($durationMs, 0.0) * 1_000_000);
            if ($startNanos < 0) {
                $startNanos = 0;
            }

            $span = self::start($sql, $config, $startNanos);
            $span->end($endNanos);
        } catch (Throwable $e) {
            fwrite(STDERR, 'DB span record failed: ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * @param string $sql
     * @param DbSpanConfig $config
     * @param int|null $startTimestampNanos
     * @return SpanInterface
     */
    private static function start(string $sql, DbSpanConfig $config, ?int $startTimestampNanos = null): SpanInterface
    {
        $operation = self::operation($sql);
        $builder = Globals::tracerProvider()
            ->getTracer(self::tracerName())
            ->spanBuilder(self::spanName($operation, $sql))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', $config->system)
            ->setAttribute('db.operation', $operation);

        if ($config->name !== '') {
            $builder->setAttribute('db.name', $config->name);
        }

        if ($config->includeStatement && self::shouldRecordStatement($operation)) {
            $builder->setAttribute('db.statement', self::sanitizeStatement($sql, $config));
        }

        if ($startTimestampNanos !== null) {
            $builder->setStartTimestamp($startTimestampNanos);
        }

        return $builder->startSpan();
    }

    /**
     * @param string $sql
     * @return string
     */
    public static function operation(string $sql): string
    {
        $trimmed = ltrim($sql, " \t\n\r\0\x0B(");
        if (preg_match('/^([A-Za-z]+)/', $trimmed, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'QUERY';
    }

    /**
     * @param string $operation
     * @param string $sql
     * @return string
     */
    public static function spanName(string $operation, string $sql): string
    {
        $table = self::table($sql);
        if ($table !== null) {
            return $operation . ' ' . $table;
        }

        return $operation;
    }

    /**
     * @return string
     */
    private static function tracerName(): string
    {
        return OpenTelemetryEnv::read('OTEL_SERVICE_NAME') ?: 'app';
    }

    /**
     * @param string $sql
     * @return string|null
     */
    private static function table(string $sql): ?string
    {
        if (preg_match('/\b(?:from|into|update|join)\s+[`"\[]?([A-Za-z0-9_.]+)/i', $sql, $matches) !== 1) {
            return null;
        }

        $table = $matches[1];
        $dot = strrpos($table, '.');
        if ($dot !== false) {
            $table = substr($table, $dot + 1);
        }

        return $table !== '' ? $table : null;
    }

    /**
     * @param string $operation
     * @return bool
     */
    private static function shouldRecordStatement(string $operation): bool
    {
        return in_array($operation, self::STATEMENT_OPERATIONS, true);
    }

    /**
     * @param string $sql
     * @param DbSpanConfig $config
     * @return string
     */
    private static function sanitizeStatement(string $sql, DbSpanConfig $config): string
    {
        $redacted = SecretRedactor::redact($sql);
        $max = $config->statementMaxBytes;
        if ($max <= 0 || strlen($redacted) <= $max) {
            return $redacted;
        }

        return substr($redacted, 0, $max) . '...[truncated]';
    }

    /**
     * @return int
     */
    private static function nowNanos(): int
    {
        return (int) round(microtime(true) * 1_000_000_000);
    }
}

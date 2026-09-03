<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Telemetry;

use Magput\Logotel\Telemetry\OpenTelemetryEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OpenTelemetryEnv::class)]
final class OpenTelemetryEnvTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testMapsAppEnvAndFillsResourceAttributes(): void
    {
        $this->clearOtelEnv();
        $_ENV['APP_ENV'] = 'dev';
        $_SERVER['APP_ENV'] = 'dev';
        putenv('OTEL_SERVICE_VERSION=1.0.0');
        $_ENV['OTEL_SERVICE_VERSION'] = '1.0.0';
        $_SERVER['OTEL_SERVICE_VERSION'] = '1.0.0';

        OpenTelemetryEnv::apply('integration-api');

        self::assertSame('integration-api', getenv('OTEL_SERVICE_NAME'));
        self::assertSame('1.0.0', getenv('OTEL_SERVICE_VERSION'));
        self::assertStringContainsString('deployment.environment.name=local', (string)getenv('OTEL_RESOURCE_ATTRIBUTES'));
        self::assertStringContainsString('service.version=1.0.0', (string)getenv('OTEL_RESOURCE_ATTRIBUTES'));
        self::assertSame('http/protobuf', getenv('OTEL_EXPORTER_OTLP_PROTOCOL'));
        self::assertSame('stderr', getenv('OTEL_PHP_LOG_DESTINATION'));
        self::assertSame('otel', getenv('OTEL_PHP_MONOLOG_ATTRIB_MODE'));
        self::assertSame('500', getenv('OTEL_EXPORTER_OTLP_TIMEOUT'));
        self::assertSame('500', getenv('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT'));
        self::assertSame('500', getenv('OTEL_EXPORTER_OTLP_LOGS_TIMEOUT'));
    }

    #[RunInSeparateProcess]
    public function testExplicitResourceAttributesWin(): void
    {
        $this->clearOtelEnv();
        putenv('OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=stage,service.namespace=magput');
        $_ENV['OTEL_RESOURCE_ATTRIBUTES'] = 'deployment.environment.name=stage,service.namespace=magput';
        $_SERVER['OTEL_RESOURCE_ATTRIBUTES'] = 'deployment.environment.name=stage,service.namespace=magput';
        $_ENV['APP_ENV'] = 'prod';
        $_SERVER['APP_ENV'] = 'prod';
        putenv('OTEL_SERVICE_VERSION=1.0.0');
        $_ENV['OTEL_SERVICE_VERSION'] = '1.0.0';
        $_SERVER['OTEL_SERVICE_VERSION'] = '1.0.0';

        OpenTelemetryEnv::apply('integration-api');

        $resource = (string)getenv('OTEL_RESOURCE_ATTRIBUTES');
        self::assertStringContainsString('deployment.environment.name=stage', $resource);
        self::assertStringNotContainsString('deployment.environment.name=prod', $resource);
        self::assertStringContainsString('service.namespace=magput', $resource);
    }

    private function clearOtelEnv(): void
    {
        foreach (array_keys(getenv() ?: []) as $name) {
            if (str_starts_with((string)$name, 'OTEL_')) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
        foreach (array_keys($_ENV) as $name) {
            if (is_string($name) && str_starts_with($name, 'OTEL_')) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Magput\Logotel\Tests\Telemetry;

use Magput\Logotel\Telemetry\OpenTelemetryEnv;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OpenTelemetryHandler;
use OpenTelemetry\SDK\SdkAutoloader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Group('integration')]
#[CoversNothing]
final class OtlpExportTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testApplicationLogReachesMockCollector(): void
    {
        if (!class_exists(SdkAutoloader::class)) {
            self::markTestSkipped('OpenTelemetry SDK is not installed');
        }

        $captureDir = sys_get_temp_dir() . '/otlp-capture-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($captureDir, 0777, true));

        $port = self::freePort();
        $router = __DIR__ . '/mock-otlp-receiver.php';
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['file', $captureDir . '/server.log', 'w'],
            2 => ['file', $captureDir . '/server.log', 'w'],
        ];
        $env = getenv() ?: [];
        $env['OTLP_CAPTURE_DIR'] = $captureDir;
        $process = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, $router],
            $descriptor,
            $pipes,
            __DIR__,
            $env,
        );
        if (!is_resource($process)) {
            self::markTestSkipped('Unable to start mock OTLP receiver');
        }
        fclose($pipes[0]);

        try {
            self::waitForServer($port);

            putenv('OTEL_SDK_DISABLED=false');
            putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
            putenv('OTEL_SERVICE_NAME=logotel');
            putenv('OTEL_SERVICE_VERSION=1.0.0');
            putenv('OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=local');
            putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:' . $port);
            putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
            putenv('OTEL_EXPORTER_OTLP_TIMEOUT=500');
            putenv('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT=500');
            putenv('OTEL_EXPORTER_OTLP_LOGS_TIMEOUT=500');
            putenv('OTEL_TRACES_EXPORTER=otlp');
            putenv('OTEL_LOGS_EXPORTER=otlp');
            putenv('OTEL_METRICS_EXPORTER=none');
            putenv('OTEL_BSP_SCHEDULE_DELAY=50');
            putenv('OTEL_BLRP_SCHEDULE_DELAY=50');
            putenv('OTEL_PHP_LOG_DESTINATION=none');
            putenv('OTEL_PHP_MONOLOG_ATTRIB_MODE=otel');
            $_ENV['OTEL_SDK_DISABLED'] = 'false';
            $_ENV['OTEL_PHP_AUTOLOAD_ENABLED'] = 'true';
            $_ENV['OTEL_SERVICE_NAME'] = 'logotel';
            $_ENV['OTEL_SERVICE_VERSION'] = '1.0.0';
            $_ENV['OTEL_RESOURCE_ATTRIBUTES'] = 'deployment.environment.name=local';
            $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'http://127.0.0.1:' . $port;
            $_ENV['OTEL_EXPORTER_OTLP_PROTOCOL'] = 'http/protobuf';
            $_ENV['OTEL_EXPORTER_OTLP_TIMEOUT'] = '500';
            $_ENV['OTEL_EXPORTER_OTLP_TRACES_TIMEOUT'] = '500';
            $_ENV['OTEL_EXPORTER_OTLP_LOGS_TIMEOUT'] = '500';
            $_ENV['OTEL_TRACES_EXPORTER'] = 'otlp';
            $_ENV['OTEL_LOGS_EXPORTER'] = 'otlp';

            OpenTelemetryEnv::apply('logotel');
            self::assertTrue(SdkAutoloader::autoload());

            $logger = new Logger('API');
            $logger->pushHandler(new OpenTelemetryHandler(Globals::loggerProvider(), Level::Debug));
            $logger->warning('otel-integration-probe', [
                'probe' => true,
            ]);

            $loggerProvider = Globals::loggerProvider();
            if (method_exists($loggerProvider, 'forceFlush')) {
                $loggerProvider->forceFlush();
            }
            if (method_exists($loggerProvider, 'shutdown')) {
                $loggerProvider->shutdown();
            }

            $logsFile = $captureDir . '/otlp-v1_logs.bin';
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && !is_file($logsFile)) {
                usleep(50000);
            }

            self::assertFileExists($logsFile, 'Mock collector did not receive /v1/logs');
            $payload = (string)file_get_contents($logsFile);
            self::assertNotSame('', $payload);
            self::assertTrue(
                str_contains($payload, 'otel-integration-probe')
                || str_contains($payload, 'logotel'),
                'OTLP payload must contain the probe message or service.name',
            );
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    #[RunInSeparateProcess]
    public function testUnavailableCollectorDoesNotBreakLogging(): void
    {
        putenv('OTEL_SDK_DISABLED=false');
        putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
        putenv('OTEL_SERVICE_NAME=logotel');
        putenv('OTEL_SERVICE_VERSION=1.0.0');
        putenv('OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=local');
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
        putenv('OTEL_EXPORTER_OTLP_TIMEOUT=500');
        putenv('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT=500');
        putenv('OTEL_EXPORTER_OTLP_LOGS_TIMEOUT=500');
        putenv('OTEL_TRACES_EXPORTER=otlp');
        putenv('OTEL_LOGS_EXPORTER=otlp');
        putenv('OTEL_METRICS_EXPORTER=none');
        putenv('OTEL_PHP_LOG_DESTINATION=none');
        $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'http://127.0.0.1:1';
        $_ENV['OTEL_EXPORTER_OTLP_TIMEOUT'] = '500';
        $_ENV['OTEL_EXPORTER_OTLP_TRACES_TIMEOUT'] = '500';
        $_ENV['OTEL_EXPORTER_OTLP_LOGS_TIMEOUT'] = '500';

        OpenTelemetryEnv::apply('logotel');
        SdkAutoloader::autoload();

        $logger = new Logger('API');
        $logger->pushHandler(new OpenTelemetryHandler(Globals::loggerProvider(), Level::Debug));
        $logger->error('collector-down-probe');

        $loggerProvider = Globals::loggerProvider();
        if (method_exists($loggerProvider, 'forceFlush')) {
            $loggerProvider->forceFlush();
        }

        $this->addToAssertionCount(1);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $parts = explode(':', $name);

        return (int)end($parts);
    }

    private static function waitForServer(int $port): void
    {
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);
                return;
            }
            usleep(50000);
        }

        self::fail('Mock OTLP receiver did not start on port ' . $port);
    }
}

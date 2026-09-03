<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry;

use Composer\InstalledVersions;
use Throwable;

/**
 * Нормализует OTEL_* / APP_* и записывает их через putenv(), чтобы SDK читал стандартные переменные.
 *
 * OTEL_RESOURCE_ATTRIBUTES нельзя прогонять через парсеры формата «k=v,k=v» —
 * запятые сломают значение.
 */
final class OpenTelemetryEnv
{
    /**
     * @param string|null $defaultServiceName fallback, если OTEL_SERVICE_NAME не задан
     * @return void
     */
    public static function apply(?string $defaultServiceName = null): void
    {
        $serviceName = self::read('OTEL_SERVICE_NAME') ?: ($defaultServiceName ?? 'app');
        $serviceVersion = self::read('OTEL_SERVICE_VERSION') ?: self::composerVersion();
        $appEnv = self::read('APP_ENV') ?: 'prod';
        $deploymentEnv = self::mapDeploymentEnvironment($appEnv);

        $resource = self::read('OTEL_RESOURCE_ATTRIBUTES');
        $resource = self::ensureResourceAttribute($resource, 'deployment.environment.name', $deploymentEnv);
        $resource = self::ensureResourceAttribute($resource, 'service.version', $serviceVersion);

        self::put('OTEL_SERVICE_NAME', $serviceName);
        self::put('OTEL_SERVICE_VERSION', $serviceVersion);
        self::put('OTEL_RESOURCE_ATTRIBUTES', $resource);

        self::putDefault('OTEL_SDK_DISABLED', 'false');
        self::putDefault('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318');
        self::putDefault('OTEL_EXPORTER_OTLP_PROTOCOL', 'http/protobuf');
        self::putDefault('OTEL_EXPORTER_OTLP_TIMEOUT', '500');
        self::putDefault('OTEL_EXPORTER_OTLP_TRACES_TIMEOUT', '500');
        self::putDefault('OTEL_EXPORTER_OTLP_LOGS_TIMEOUT', '500');
        self::putDefault('OTEL_TRACES_EXPORTER', 'otlp');
        self::putDefault('OTEL_LOGS_EXPORTER', 'otlp');
        self::putDefault('OTEL_METRICS_EXPORTER', 'none');
        self::putDefault('OTEL_BSP_SCHEDULE_DELAY', '5000');
        self::putDefault('OTEL_BLRP_SCHEDULE_DELAY', '5000');
        self::putDefault('OTEL_PHP_TRACES_PROCESSOR', 'batch');
        self::putDefault('OTEL_PHP_LOG_DESTINATION', 'stderr');
        self::putDefault('OTEL_PHP_MONOLOG_ATTRIB_MODE', 'otel');

        $sdkDisabled = self::isTrue(self::read('OTEL_SDK_DISABLED'));
        self::putDefault('OTEL_PHP_AUTOLOAD_ENABLED', $sdkDisabled ? 'false' : 'true');

        $headers = self::read('OTEL_EXPORTER_OTLP_HEADERS');
        if ($headers !== '') {
            self::put('OTEL_EXPORTER_OTLP_HEADERS', $headers);
        }
    }

    /**
     * @return bool
     */
    public static function isSdkDisabled(): bool
    {
        return self::isTrue(self::read('OTEL_SDK_DISABLED'));
    }

    /**
     * @param string $appEnv
     * @return string
     */
    public static function mapDeploymentEnvironment(string $appEnv): string
    {
        return match (strtolower($appEnv)) {
            'dev', 'development', 'local' => 'local',
            'stage', 'staging' => 'stage',
            'preview' => 'preview',
            'prod', 'production' => 'prod',
            default => strtolower($appEnv) !== '' ? strtolower($appEnv) : 'local',
        };
    }

    /**
     * @param string $name
     * @return string
     */
    public static function read(string $name): string
    {
        if (array_key_exists($name, $_ENV)) {
            return self::toString($_ENV[$name]);
        }

        if (array_key_exists($name, $_SERVER)) {
            return self::toString($_SERVER[$name]);
        }

        $value = getenv($name);
        if ($value === false) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function toString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(',', array_map(static fn (mixed $item): string => (string)$item, $value));
        }

        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private static function put(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private static function putDefault(string $name, string $value): void
    {
        if (self::read($name) === '') {
            self::put($name, $value);
        }
    }

    /**
     * @param string $resource
     * @param string $key
     * @param string $value
     * @return string
     */
    private static function ensureResourceAttribute(string $resource, string $key, string $value): string
    {
        if ($value === '') {
            return $resource;
        }

        if ($resource === '') {
            return $key . '=' . $value;
        }

        if (str_contains($resource, $key . '=')) {
            return $resource;
        }

        return $resource . ',' . $key . '=' . $value;
    }

    /**
     * @return string
     */
    private static function composerVersion(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return '1.0.0';
        }

        try {
            $version = InstalledVersions::getRootPackage()['pretty_version'] ?? '';
            if (is_string($version) && $version !== '' && $version !== 'N/A') {
                return ltrim($version, 'v');
            }
        } catch (Throwable) {
        }

        return '1.0.0';
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function isTrue(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}

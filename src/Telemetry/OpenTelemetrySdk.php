<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry;

use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\SdkAutoloader;
use Throwable;

/**
 * Подключает OpenTelemetry SDK после загрузки ENV.
 */
final class OpenTelemetrySdk
{
    private static bool $registered = false;

    public static function register(?string $defaultServiceName = null): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        try {
            OpenTelemetryEnv::apply($defaultServiceName);

            if (OpenTelemetryEnv::isSdkDisabled() || (class_exists(Sdk::class) && Sdk::isDisabled())) {
                return;
            }

            if (!class_exists(SdkAutoloader::class) ||
                !method_exists(SdkAutoloader::class, 'autoload')
            ) {
                return;
            }

            SdkAutoloader::autoload();
        } catch (Throwable $e) {
            fwrite(STDERR, 'OpenTelemetry SDK initialization failed: ' . $e->getMessage() . PHP_EOL);
        }
    }
}

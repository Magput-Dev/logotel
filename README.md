# magput/logotel

Общая библиотека логирования и OpenTelemetry для Magput.

- **Ядро Slim/PSR-15** (`Magput\Logotel`): processors Monolog, маскирование секретов, OTEL ENV/SDK, `TracingMiddleware`.
- **Yii2 debug hub** (`Magput\Debug`): адаптер `yii\log\Target`, MongoDB, OTLP-транспорт.

## Magput\Logotel (Slim / PSR-15)

Пакет даёт:

- `SecretRedactor` / `RedactionProcessor` — ключи и текст (`token=`, Bearer, значения ENV)
- `LogLevelPolicy`, `HttpErrorLog`, `RequestPayload` (`http.request.query` / `http.request.body`)
- `TraceContextProcessor`, `RequestContextProcessor`, `RequestLogContext`
- `OpenTelemetryEnv`, `OpenTelemetrySdk` (дефолт `OTEL_EXPORTER_OTLP_TIMEOUT=500` мс и `*_TRACES_TIMEOUT` / `*_LOGS_TIMEOUT`)
- `TracingMiddleware` (`X-Trace-Id`, W3C `traceparent`)
- DB CLIENT spans: `EloquentQueryListener` (`Connection::listen()`), `TracedPdo` (prepare/query/exec)

```php
use Magput\Logotel\Telemetry\OpenTelemetrySdk;

require __DIR__ . '/vendor/autoload.php';
OpenTelemetrySdk::register('integration-api');
```

В PHP-DI:

```php
use Magput\Logotel\Http\TracingMiddleware;
use Magput\Logotel\Logging\RedactionProcessor;
use Magput\Logotel\Logging\RequestContextProcessor;
use Magput\Logotel\Logging\RequestLogContext;
use Magput\Logotel\Logging\TraceContextProcessor;

$app->add(TracingMiddleware::class);
```

SQL CLIENT spans (child HTTP SERVER span, тот же `trace_id`):

```php
use Magput\Logotel\Telemetry\Database\DbSpanConfig;
use Magput\Logotel\Telemetry\Database\EloquentQueryListener;
use Magput\Logotel\Telemetry\Database\TracedPdo;

$spanConfig = DbSpanConfig::fromArray($config + ['driver' => 'mysql']);

$pdo = new TracedPdo($dsn, $user, $pass);
$pdo->enableTracing($spanConfig);

$capsule->bootEloquent();
EloquentQueryListener::register($capsule->getConnection(), $spanConfig);
```

Атрибуты: `db.system`, `db.name`, `db.operation`, `db.statement` (для SELECT/DML; BEGIN/COMMIT без statement). Span kind: `CLIENT`.

HTTP query/body в логах:

- 4xx/5xx — всегда `http.request.query` и `http.request.body` (через `HttpErrorLog`)
- `APP_LOG_LEVEL=DEBUG` — те же поля на каждый запрос, включая 200
- `INFO` и выше + 200 — полей нет
- секреты маскируются, body обрезается (`HTTP_LOG_BODY_MAX`, по умолчанию 4096 байт)

## Yii2 debug hub

- Хранение логов в MongoDB (split-manifest).
- Адаптер `DebugTarget`, интеграция с `Yii::error` / `Yii::info` / `Yii::warning` / `Yii::debug`.
- PSR-3 `PsrLogger`, перехват PHP ошибок (`ErrorHandler`).

## Установка

```bash
composer require magput/logotel
```

Локально (path-репозиторий):

```json
{
  "repositories": [
    { "type": "path", "url": "../logotel" }
  ],
  "require": {
    "magput/logotel": "^1.0"
  }
}
```

## Тесты

```bash
composer install
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

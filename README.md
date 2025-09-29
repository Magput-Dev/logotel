# magput/logotel

Yii2 Debug/Tracing компонент с поддержкой **OpenTelemetry** и **MongoDB**.  
Фреймворк-агностичное ядро + адаптер для Yii2 (`yii\log\Target`).

---

## Возможности

- Хранение логов в MongoDB (split-manifest: манифесты и записи в отдельных коллекциях).
- Отправка логов и событий в OpenTelemetry через OTLP-транспорт.
- Адаптер для Yii2 (`DebugTarget`), интеграция с `Yii::error`, `Yii::info`, `Yii::warning`, `Yii::debug`.
- PSR-3 логгер (`PsrLogger`) — можно использовать в сервисах.
- Перехват PHP ошибок и исключений (`ErrorHandler`).
- Поддержка InMemory-хранилища для тестов.

---

## Установка

```bash
composer require magput/logotel

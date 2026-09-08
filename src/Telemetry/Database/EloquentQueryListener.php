<?php

declare(strict_types=1);

namespace Magput\Logotel\Telemetry\Database;

use Closure;
use InvalidArgumentException;
use Throwable;

final class EloquentQueryListener
{
    public function __construct(
        private readonly DbSpanConfig $config,
    ) {}

    /**
     * @param object $connection Illuminate\Database\Connection
     * @param DbSpanConfig $config
     * @return void
     */
    public static function register(object $connection, DbSpanConfig $config): void
    {
        if (!method_exists($connection, 'listen')) {
            throw new InvalidArgumentException('Connection must implement listen().');
        }

        self::ensureDispatcher($connection);
        $listener = new self($config);
        $connection->listen(Closure::fromCallable($listener));
    }

    /**
     * @param object $query Illuminate\Database\Events\QueryExecuted
     * @return void
     */
    public function __invoke(object $query): void
    {
        try {
            $sql = isset($query->sql) && is_string($query->sql) ? $query->sql : '';
            if ($sql === '') {
                return;
            }

            $durationMs = isset($query->time) && is_numeric($query->time) ? (float)$query->time : 0.0;
            DbSpan::recordCompleted($sql, $durationMs, $this->configFor($query));
        } catch (Throwable $e) {
            fwrite(STDERR, 'Eloquent DB span failed: ' . $e->getMessage() . PHP_EOL);
        }
    }

    /**
     * @param object $connection
     * @return void
     */
    private static function ensureDispatcher(object $connection): void
    {
        if (
            !method_exists($connection, 'getEventDispatcher')
            || !method_exists($connection, 'setEventDispatcher')
        ) {
            return;
        }

        if ($connection->getEventDispatcher() !== null) {
            return;
        }

        if (!interface_exists('Illuminate\\Contracts\\Events\\Dispatcher')) {
            return;
        }

        $connection->setEventDispatcher(new class implements \Illuminate\Contracts\Events\Dispatcher {
            /** @var array<string, list<callable>> */
            private array $listeners = [];

            public function listen($events, $listener = null)
            {
                if ($listener === null) {
                    return;
                }
                foreach ((array)$events as $event) {
                    if (is_string($event)) {
                        $this->listeners[$event][] = $listener;
                    }
                }
            }

            public function hasListeners($eventName)
            {
                return isset($this->listeners[$eventName]) && $this->listeners[$eventName] !== [];
            }

            public function subscribe($subscriber)
            {
            }

            public function until($event, $payload = [])
            {
                return $this->dispatch($event, $payload, true);
            }

            public function dispatch($event, $payload = [], $halt = false)
            {
                $name = is_object($event) ? $event::class : (string)$event;
                $responses = [];
                foreach ($this->listeners[$name] ?? [] as $listener) {
                    $response = $listener($event, $payload);
                    if ($halt && $response !== null) {
                        return $response;
                    }
                    $responses[] = $response;
                }

                return $responses;
            }

            public function push($event, $payload = [])
            {
            }

            public function flush($event)
            {
            }

            public function forget($event)
            {
                unset($this->listeners[$event]);
            }

            public function forgetPushed()
            {
            }
        });
    }

    /**
     * @param object $query
     * @return DbSpanConfig
     */
    private function configFor(object $query): DbSpanConfig
    {
        $system = $this->config->system;
        $name = $this->config->name;
        $connection = $query->connection ?? null;
        if (is_object($connection)) {
            if ($name === '' && method_exists($connection, 'getDatabaseName')) {
                $dbName = $connection->getDatabaseName();
                if (is_string($dbName) && $dbName !== '') {
                    $name = $dbName;
                }
            }
            if (method_exists($connection, 'getDriverName')) {
                $driver = $connection->getDriverName();
                if (is_string($driver) && $driver !== '') {
                    $system = DbSpanConfig::systemFromDriver($driver);
                }
            }
        }

        return new DbSpanConfig(
            system: $system,
            name: $name,
            includeStatement: $this->config->includeStatement,
            statementMaxBytes: $this->config->statementMaxBytes,
        );
    }
}

<?php

declare(strict_types=1);

namespace Magput\Debug\Integrations;

use Magput\Debug\Contracts\DebugHubInterface;
use Throwable;

final class ErrorHandler
{
    private DebugHubInterface $hub;

    public function __construct(DebugHubInterface $hub)
    {
        $this->hub = $hub;
    }

    public function register(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $this->hub->add('php-error', [
            'errno' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ], 'error');
        return false; // пробросить дальше стандартному обработчику
    }

    public function handleException(Throwable $e): void
    {
        $this->hub->add('exception', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ], 'error');
    }
}

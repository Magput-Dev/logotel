<?php

declare(strict_types=1);

namespace Magput\Logotel\Logging;

final class RequestLogContext
{
    public ?string $requestId = null;
    public ?string $method = null;
    public ?string $route = null;
    public ?int $statusCode = null;
    public ?string $query = null;
    public ?string $body = null;

    public function reset(): void
    {
        $this->requestId = null;
        $this->method = null;
        $this->route = null;
        $this->statusCode = null;
        $this->query = null;
        $this->body = null;
    }
}

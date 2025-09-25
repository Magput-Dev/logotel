<?php

declare(strict_types=1);

namespace Magput\Debug\Integrations;

use Magput\Debug\Contracts\DebugHubInterface;
use Psr\Log\AbstractLogger;

final class PsrLogger extends AbstractLogger
{
    private DebugHubInterface $hub;

    public function __construct(DebugHubInterface $hub)
    {
        $this->hub = $hub;
    }

    public function log($level, $message, array $context = []): void
    {
        $payload = [
            'message' => $this->interpolate($message, $context),
            'context' => $context,
        ];
        $this->hub->add('log', $payload, $level);
    }

    private function interpolate($message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = (string) $val;
        }
        return strtr($message, $replace);
    }
}

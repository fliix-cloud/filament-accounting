<?php

namespace FilamentAccounting\Banking\FinTs\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

final class RedactingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly LoggerInterface $inner,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $this->redact((string) $message), $this->redactContext($context));
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/PIN[:\s=]+\S+/i', 'PIN=[redacted]', $message) ?? $message;
        $message = preg_replace('/TAN[:\s=]+\S+/i', 'TAN=[redacted]', $message) ?? $message;
        $message = preg_replace('/HNVSD:[^\']+/', 'HNVSD:[redacted]', $message) ?? $message;

        if (! config('filament-accounting.banking.fints.security.protocol_debug')) {
            $message = preg_replace('/HNHBK:.*/', '[fints-frame-redacted]', $message) ?? $message;
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        foreach (['pin', 'tan', 'password', 'credentials', 'persisted', 'action'] as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }
}

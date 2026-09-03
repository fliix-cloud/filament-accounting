<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use FilamentAccounting\Banking\FinTs\Exceptions\FintsValidationException;
use FilamentAccounting\Banking\FinTs\Support\EndpointValidator;
use FilamentAccounting\Banking\FinTs\Support\RedactingLogger;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

class SecurityBoundaryTest extends TestCase
{
    #[Test]
    public function endpoint_validation_enforces_https_credentials_private_networks_and_allowlists(): void
    {
        config()->set('filament-accounting.banking.fints.security.https_only', true);
        config()->set('filament-accounting.banking.fints.security.allow_private_endpoints', false);
        config()->set('filament-accounting.banking.fints.security.allowed_hosts', []);

        $this->assertSame(
            'https://fints.example-bank.de/cgi/fints',
            EndpointValidator::validate('https://fints.example-bank.de/cgi/fints'),
        );

        foreach ([
            'http://fints.example-bank.de/cgi/fints',
            'https://user:pass@fints.example-bank.de/cgi/fints',
            'https://127.0.0.1/cgi/fints',
        ] as $invalid) {
            try {
                EndpointValidator::validate($invalid);
                $this->fail("The unsafe endpoint {$invalid} must be rejected.");
            } catch (FintsValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        config()->set('filament-accounting.banking.fints.security.allowed_hosts', ['fints.example-bank.de']);
        $this->expectException(FintsValidationException::class);
        EndpointValidator::validate('https://fints.other-bank.de/cgi/fints');
    }

    #[Test]
    public function protocol_logging_redacts_credentials_state_and_frames(): void
    {
        config()->set('filament-accounting.banking.fints.security.protocol_debug', false);
        $calls = [];
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function ($level, $message, array $context) use (&$calls): void {
                $calls[] = [$level, (string) $message, $context];
            });
        $logger = new RedactingLogger($inner);

        $logger->debug('PIN=123456 TAN=998877 HNVSD:private-payload', [
            'pin' => '123456',
            'tan' => '998877',
            'persisted' => 'dialog-secret',
        ]);
        $logger->debug('HNHBK:1:3+frame-secret');

        $serialized = serialize($calls);
        foreach (['123456', '998877', 'private-payload', 'dialog-secret', 'frame-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertStringContainsString('[redacted]', $serialized);
        $this->assertStringContainsString('[fints-frame-redacted]', $serialized);
    }
}

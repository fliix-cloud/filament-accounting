<?php

namespace FilamentAccounting\Contracts;

interface InvoiceRenderer
{
    public function key(): string;

    public function version(): string;

    /** @param array<string, mixed> $snapshot */
    public function render(array $snapshot): string;
}

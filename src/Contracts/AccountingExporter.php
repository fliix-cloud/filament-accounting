<?php

namespace FilamentAccounting\Contracts;

use FilamentAccounting\Models\LegalEntity;

interface AccountingExporter
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function export(LegalEntity $entity, string $from, string $to, array $options = []): string;
}

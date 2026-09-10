<?php

namespace FilamentAccounting\Catalog\ImportExport;

final readonly class CatalogImportResult
{
    public function __construct(public int $created, public int $updated, public int $skipped) {}

    public function message(): string
    {
        return __('filament-accounting::catalog_transfer.summary', [
            'created' => $this->created, 'updated' => $this->updated, 'skipped' => $this->skipped,
        ]);
    }
}

<?php

namespace FilamentAccounting\Catalog\ImportExport;

use RuntimeException;

final class CatalogImportException extends RuntimeException
{
    /** @param array<string, int|string> $replace */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('filament-accounting::catalog_transfer.'.$key, $replace));
    }
}

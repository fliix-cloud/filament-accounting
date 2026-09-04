<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum CatalogItemType: string implements HasLabel
{
    case Product = 'product';
    case Service = 'service';

    public function getLabel(): string
    {
        return __('filament-accounting::fields.catalog_types.'.$this->value);
    }
}

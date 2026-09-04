<?php

namespace FilamentAccounting\Enums;

use Filament\Support\Contracts\HasLabel;

enum CatalogUnit: string implements HasLabel
{
    case Piece = 'C62';
    case Hour = 'HUR';
    case Day = 'DAY';
    case Month = 'MON';
    case Kilogram = 'KGM';
    case Gram = 'GRM';
    case Meter = 'MTR';
    case SquareMeter = 'MTK';
    case CubicMeter = 'MTQ';
    case Liter = 'LTR';
    case Tonne = 'TNE';
    case KilowattHour = 'KWH';
    case LumpSum = 'LS';

    public function getLabel(): string
    {
        return __('filament-accounting::fields.catalog_units.'.$this->value);
    }
}

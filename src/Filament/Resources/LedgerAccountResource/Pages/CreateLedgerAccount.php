<?php

namespace FilamentAccounting\Filament\Resources\LedgerAccountResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Ownership\LegalEntityScope;

class CreateLedgerAccount extends CreateRecord
{
    protected static string $resource = LedgerAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['legal_entity_id'] = app(LegalEntityScope::class)->require()->getKey();

        return $data;
    }
}

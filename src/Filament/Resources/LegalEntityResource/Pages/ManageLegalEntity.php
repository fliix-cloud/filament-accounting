<?php

namespace FilamentAccounting\Filament\Resources\LegalEntityResource\Pages;

use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\LegalEntityResource;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;

class ManageLegalEntity extends EditRecord
{
    protected static string $resource = LegalEntityResource::class;

    public function mount(int|string|null $record = null): void
    {
        $entity = app(SingleLegalEntityResolver::class)->resolve();

        if (! $entity instanceof LegalEntity) {
            $this->record = new LegalEntity;
            $this->redirect(LegalEntityResource::getUrl('create'), navigate: true);

            return;
        }

        parent::mount($entity->getRouteKey());
    }
}

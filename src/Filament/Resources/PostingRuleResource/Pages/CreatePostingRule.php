<?php

namespace FilamentAccounting\Filament\Resources\PostingRuleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
use FilamentAccounting\Ownership\LegalEntityScope;

class CreatePostingRule extends CreateRecord
{
    protected static string $resource = PostingRuleResource::class;

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

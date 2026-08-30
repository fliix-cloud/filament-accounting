<?php

namespace FilamentAccounting\Filament\Resources\PostingRuleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\PostingRuleResource;

class ListPostingRules extends ListRecords
{
    protected static string $resource = PostingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

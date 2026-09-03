<?php

namespace FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource;

class ListReconciliationLearningRules extends ListRecords
{
    protected static string $resource = ReconciliationLearningRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

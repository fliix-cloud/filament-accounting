<?php

namespace FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Filament\Resources\ReconciliationLearningRuleResource;

class EditReconciliationLearningRule extends EditRecord
{
    protected static string $resource = ReconciliationLearningRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

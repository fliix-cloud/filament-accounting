<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankConnectionResource;
use FilamentAccounting\Banking\FinTs\Services\InstituteDirectoryService;

class ListBankConnections extends ListRecords
{
    protected static string $resource = BankConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncInstitutes')
                ->label(__('filament-accounting::banking/fints/actions.sync_institutes'))
                ->action(function (InstituteDirectoryService $directory): void {
                    $result = $directory->sync();
                    Notification::make()
                        ->title(__('filament-accounting::banking/fints/notifications.institutes_synced'))
                        ->body(__('filament-accounting::banking/fints/notifications.institutes_synced_body', [
                            'count' => $result['with_pin_tan'],
                        ]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}

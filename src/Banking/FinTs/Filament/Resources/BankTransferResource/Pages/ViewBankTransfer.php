<?php

namespace FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use FilamentAccounting\Banking\FinTs\Filament\Resources\BankTransferResource;
use FilamentAccounting\Banking\FinTs\Models\BankTransfer;

class ViewBankTransfer extends ViewRecord
{
    protected static string $resource = BankTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resumeSca')
                ->label(__('filament-accounting::banking/fints/actions.resume_sca'))
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return $record instanceof BankTransfer && $record->status->isInteractive();
                })
                ->url(function (): ?string {
                    $record = $this->getRecord();

                    return $record instanceof BankTransfer
                        ? BankTransferResource::resumeScaUrl($record)
                        : null;
                }),
            DeleteAction::make()
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return $record instanceof BankTransfer && $record->status->isDeletable();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('recipient_name'),
            TextEntry::make('recipient_iban'),
            TextEntry::make('amount'),
            TextEntry::make('purpose'),
            TextEntry::make('status')->badge(),
            TextEntry::make('bank_status_text'),
            TextEntry::make('error_message'),
        ]);
    }
}

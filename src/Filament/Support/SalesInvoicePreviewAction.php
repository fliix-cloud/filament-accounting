<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use FilamentAccounting\Services\IssueSalesInvoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SalesInvoicePreviewAction
{
    public static function make(): Action
    {
        return Action::make('previewPdf')
            ->label(__('filament-accounting::actions.preview_invoice_pdf'))
            ->icon('heroicon-o-document-magnifying-glass')
            ->action(function (CreateRecord|EditRecord $livewire): StreamedResponse {
                $pdf = app(IssueSalesInvoice::class)->preview($livewire->form->getState());

                return response()->streamDownload(fn () => print ($pdf), 'invoice-preview.pdf', ['Content-Type' => 'application/pdf']);
            });
    }
}

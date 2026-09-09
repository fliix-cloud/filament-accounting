<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Filament\Resources\SalesInvoiceResource;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use Livewire\Component;

final class SalesInvoiceCompletionAction
{
    public static function make(): Action
    {
        return Action::make('completeIssuance')
            ->label(__('filament-accounting::actions.complete_issuance'))
            ->databaseTransaction(false)
            ->visible(fn (Document $record): bool => self::pending($record)
                && (string) $record->legal_entity_id === (string) app(LegalEntityScope::class)->current()?->getKey()
                && app(AccountingAuthorizer::class)->can('issue_invoices', app(LegalEntityScope::class)->current())
                && app(AccountingAuthorizer::class)->can('post_documents', $record))
            ->action(function (Document $record, Component $livewire): void {
                try {
                    app(IssueSalesInvoice::class)->issue($record);
                    Notification::make()->success()->title(__('filament-accounting::notifications.invoice_issued'))->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title(__('filament-accounting::errors.invoice_completion_failed'))->send();
                }
                $livewire->redirect(SalesInvoiceResource::getUrl('view', ['record' => $record]));
            });
    }

    public static function pending(Document $document): bool
    {
        return $document->document_status === DocumentStatus::Issued
            && ($document->posting_status === PostingStatus::Unposted
                || ((data_get($document->e_invoice_meta, 'artifacts_required', false) || $document->artifactSet !== null)
                    && $document->artifactSet?->completed_at === null));
    }
}

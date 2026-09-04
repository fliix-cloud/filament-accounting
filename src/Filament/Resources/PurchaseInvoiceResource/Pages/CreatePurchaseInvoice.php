<?php

namespace FilamentAccounting\Filament\Resources\PurchaseInvoiceResource\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Filament\Resources\PurchaseInvoiceResource;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\ImportPurchaseInvoice;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-accounting::fields.upload_original_invoice'))
                ->description(__('filament-accounting::fields.upload_original_invoice_help'))
                ->schema([
                    FileUpload::make('original_pdf')
                        ->label(__('filament-accounting::fields.original_invoice'))
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(15 * 1024)
                        ->storeFiles(false)
                        ->required(),
                    FileUpload::make('e_invoice_xml')
                        ->label(__('filament-accounting::fields.e_invoice_xml'))
                        ->helperText(__('filament-accounting::fields.e_invoice_xml_help'))
                        ->acceptedFileTypes(['application/xml', 'text/xml'])
                        ->maxSize(15 * 1024)
                        ->storeFiles(false),
                ])->columns(2),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $pdf = $data['original_pdf'] ?? null;
        if (is_array($pdf)) {
            $pdf = reset($pdf);
        }
        if (! $pdf instanceof TemporaryUploadedFile) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }
        $pdfContents = file_get_contents($pdf->getRealPath());
        if (! is_string($pdfContents)) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }

        $xml = $data['e_invoice_xml'] ?? null;
        if (is_array($xml)) {
            $xml = reset($xml);
        }
        if ($xml !== null && ! $xml instanceof TemporaryUploadedFile) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }
        $xmlContents = $xml instanceof TemporaryUploadedFile
            ? file_get_contents($xml->getRealPath())
            : null;
        if ($xml instanceof TemporaryUploadedFile && ! is_string($xmlContents)) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }

        return app(ImportPurchaseInvoice::class)->handle(
            app(LegalEntityScope::class)->require(),
            $pdf->getClientOriginalName(),
            $pdfContents,
            $xml instanceof TemporaryUploadedFile ? $xml->getClientOriginalName() : null,
            is_string($xmlContents) ? $xmlContents : null,
        )->document;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

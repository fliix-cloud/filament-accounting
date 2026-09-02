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
                    FileUpload::make('original_file')
                        ->label(__('filament-accounting::fields.original_invoice'))
                        ->acceptedFileTypes(['application/pdf', 'application/xml', 'text/xml'])
                        ->maxSize(15 * 1024)
                        ->storeFiles(false)
                        ->required(),
                ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $file = $data['original_file'] ?? null;
        if (is_array($file)) {
            $file = reset($file);
        }
        if (! $file instanceof TemporaryUploadedFile) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents)) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }

        return app(ImportPurchaseInvoice::class)->handle(
            app(LegalEntityScope::class)->require(),
            $file->getClientOriginalName(),
            $contents,
        )->document;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}

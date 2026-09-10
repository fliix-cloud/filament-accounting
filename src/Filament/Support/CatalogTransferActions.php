<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use FilamentAccounting\Catalog\ImportExport\CatalogExporter;
use FilamentAccounting\Catalog\ImportExport\CatalogImporter;
use FilamentAccounting\Catalog\ImportExport\CatalogImportException;
use FilamentAccounting\Catalog\ImportExport\CatalogImportResult;
use FilamentAccounting\Catalog\ImportExport\CatalogTransferSchema;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource\Pages\ListCatalogItems;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CatalogTransferActions
{
    public static function import(): Action
    {
        return Action::make('importCatalog')->label(__('filament-accounting::catalog_transfer.import'))
            ->visible(fn (): bool => CatalogItemResource::canCreate())
            ->steps([
                Step::make(__('filament-accounting::catalog_transfer.upload'))->schema([
                    FileUpload::make('file')->label(__('filament-accounting::catalog_transfer.upload'))
                        ->helperText(__('filament-accounting::catalog_transfer.formats'))
                        // MIME guesses vary across browsers, Excel versions and fileinfo databases.
                        // The selected reader verifies the actual contents before preview or persistence.
                        ->rule('extensions:'.implode(',', array_keys(CatalogTransferSchema::FORMATS)))
                        ->validationMessages(['extensions' => __('filament-accounting::catalog_transfer.unsupported')])
                        ->storeFiles(false)->maxSize(10240)->required(),
                    Toggle::make('update_existing')->label(__('filament-accounting::catalog_transfer.update_existing'))->default(true),
                ])->afterValidation(function (Get $get, ListCatalogItems $livewire): void {
                    $livewire->catalogImportPreview = self::importFile($get('file'), (bool) $get('update_existing'), true)->message();
                }),
                Step::make(__('filament-accounting::catalog_transfer.confirm'))->schema([
                    TextEntry::make('preview')->label(__('filament-accounting::catalog_transfer.preview'))
                        ->state(fn (ListCatalogItems $livewire): string => $livewire->catalogImportPreview),
                ]),
            ])->action(function (array $data): void {
                $result = self::importFile($data['file'], (bool) $data['update_existing'], false);
                Notification::make()->success()->title(__('filament-accounting::catalog_transfer.success'))->body($result->message())->send();
            });
    }

    private static function importFile(mixed $file, bool $update, bool $preview): CatalogImportResult
    {
        // FileUpload's raw state during wizard validation can still be keyed by upload UUID.
        if (is_array($file)) {
            $file = reset($file);
        }
        if (! $file instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['file' => __('filament-accounting::catalog_transfer.unreadable')]);
        }
        $path = tempnam(sys_get_temp_dir(), 'catalog-import-');
        try {
            $contents = $file->get();
            if (! is_string($contents) || strlen($contents) > CatalogTransferSchema::MAX_BYTES) {
                throw CatalogImportException::because('unreadable');
            }
            file_put_contents($path, $contents);
            $format = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            $importer = app(CatalogImporter::class);

            return $preview ? $importer->preview($path, $format, $update) : $importer->import($path, $format, $update);
        } catch (CatalogImportException $e) {
            Notification::make()->danger()->title(__('filament-accounting::catalog_transfer.failed'))->body($e->getMessage())->send();
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        } finally {
            unlink($path);
        }
    }

    public static function export(bool $template = false): Action
    {
        return Action::make($template ? 'catalogTemplate' : 'exportCatalog')
            ->label(__('filament-accounting::catalog_transfer.'.($template ? 'template' : 'export')))
            ->visible(fn (): bool => CatalogItemResource::canViewAny())
            ->schema([
                Select::make('format')->label(__('filament-accounting::catalog_transfer.format'))
                    ->options(CatalogTransferSchema::FORMATS)->default('xlsx')->required(),
            ])->action(function (array $data) use ($template): ?StreamedResponse {
                $path = tempnam(sys_get_temp_dir(), 'catalog-export-');
                try {
                    app(CatalogExporter::class)->export($path, $data['format'], $template);
                    $contents = file_get_contents($path);
                } catch (CatalogImportException $e) {
                    Notification::make()->danger()->title(__('filament-accounting::catalog_transfer.failed'))->body($e->getMessage())->send();

                    return null;
                } finally {
                    unlink($path);
                }

                Notification::make()->success()->title(__('filament-accounting::catalog_transfer.export_success'))->send();

                return response()->streamDownload(static function () use ($contents): void {
                    echo $contents;
                }, ($template ? 'catalog-template.' : 'catalog.').$data['format'], ['Content-Type' => match ($data['format']) {
                    'json' => 'application/json; charset=UTF-8',
                    'csv' => 'text/csv; charset=UTF-8',
                    'xls' => 'application/vnd.ms-excel',
                    default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                }]);
            });
    }
}

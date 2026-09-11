<?php

use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\Route;

Route::name('filament-accounting.')->group(function (): void {
    Route::get('accounting/audit/export/{legalEntity}', function (LegalEntity $legalEntity, LegalEntityScope $scope) {
        $scope->assertModel($legalEntity);
        if (! app(\FilamentAccounting\Contracts\AccountingAuthorizer::class)->can('export_audit', $legalEntity)) {
            abort(403, __('filament-accounting::errors.unauthorized', ['ability' => 'export_audit']));
        }

        $result = app(\FilamentAccounting\Export\StreamDatasetExporter::class)->build($legalEntity, anchor: true);

        return response()->streamDownload(function () use ($result): void {
            $stream = $result['stream'];
            rewind($stream);
            while (! feof($stream)) {
                echo fread($stream, 65536);
                flush();
            }
            fclose($stream);
        }, 'accounting-export-'.$legalEntity->uuid.'-'.now()->format('Ymd-His').'.ndjson', [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => 'attachment',
        ]);
    })->name('audit-export');
});
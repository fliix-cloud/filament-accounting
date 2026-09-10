<?php

namespace FilamentAccounting\Catalog\ImportExport;

use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\DB;

final class CatalogImporter
{
    public function __construct(private CatalogSerializer $serializer, private LegalEntityScope $scope, private AccountingAuthorizer $authorizer) {}

    public function import(string $path, string $format, bool $updateExisting = true): CatalogImportResult
    {
        return $this->transfer($path, $format, $updateExisting, false);
    }

    public function preview(string $path, string $format, bool $updateExisting = true): CatalogImportResult
    {
        return $this->transfer($path, $format, $updateExisting, true);
    }

    private function transfer(string $path, string $format, bool $updateExisting, bool $preview): CatalogImportResult
    {
        $this->authorizer->authorize('manage_catalog');
        $entity = $this->scope->require();
        $rows = $this->serializer->read($path, $format);

        return DB::transaction(function () use ($entity, $rows, $format, $updateExisting, $preview): CatalogImportResult {
            // Serialize catalog imports for this entity, including imports of previously absent SKUs.
            $entity->newQuery()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $normalized = [];
            $skus = [];
            foreach ($rows as $position => $row) {
                $data = CatalogTransferSchema::normalize($row, $format === 'json', $position, $entity->getKey());
                if ($data['sku'] !== null) {
                    if (isset($skus[$data['sku']])) {
                        throw CatalogImportException::because('duplicate', ['position' => $position, 'sku' => $data['sku']]);
                    }
                    $skus[$data['sku']] = true;
                }
                $normalized[] = $data;
            }
            $created = $updated = $skipped = 0;
            foreach ($normalized as $data) {
                $item = $data['sku'] === null ? null : CatalogItem::query()->where('legal_entity_id', $entity->getKey())
                    ->where('sku', $data['sku'])->lockForUpdate()->first();
                if ($item && ! $updateExisting) {
                    $skipped++;

                    continue;
                }
                if ($item) {
                    $updated++;
                } else {
                    $created++;
                    $item = new CatalogItem;
                    $item->legal_entity_id = $entity->getKey();
                }
                if (! $preview) {
                    $item->fill($data);
                    if (! $item->save()) {
                        throw CatalogImportException::because('persistence');
                    }
                }
            }

            return new CatalogImportResult($created, $updated, $skipped);
        });
    }
}

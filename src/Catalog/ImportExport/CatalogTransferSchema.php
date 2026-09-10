<?php

namespace FilamentAccounting\Catalog\ImportExport;

use FilamentAccounting\Enums\CatalogItemType;
use FilamentAccounting\Enums\CatalogUnit;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\ReferenceData;

final class CatalogTransferSchema
{
    public const NAME = 'filament-accounting.catalog';

    public const VERSION = 1;

    public const FIELDS = ['sku', 'name', 'description', 'type', 'unit', 'quantity', 'sales_price', 'purchase_price', 'currency', 'tax_code', 'ean', 'active'];

    public const OPTIONAL = ['sku', 'description', 'purchase_price', 'tax_code', 'ean'];

    public const FORMATS = ['xlsx' => 'Excel (.xlsx)', 'xls' => 'Excel 97–2003 (.xls)', 'csv' => 'CSV (.csv)', 'json' => 'JSON (.json)'];

    public const MAX_ROWS = 10000;

    public const MAX_BYTES = 10485760;

    public static function format(string $format): void
    {
        if (! array_key_exists($format, self::FORMATS)) {
            throw CatalogImportException::because('unsupported');
        }
    }

    /** @param array<mixed> $fields */
    public static function headers(array $fields): void
    {
        if ($fields !== self::FIELDS) {
            throw CatalogImportException::because('headers', [
                'missing' => implode(', ', array_diff(self::FIELDS, $fields)),
                'unknown' => implode(', ', array_diff($fields, self::FIELDS)),
                'expected' => implode(';', self::FIELDS),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row, bool $json, int $position, int|string $entityId): array
    {
        $fail = static function (string $field) use ($position, $json): never {
            throw CatalogImportException::because($json ? 'item' : 'row', ['position' => $position, 'field' => $field]);
        };
        $unknown = array_diff(array_keys($row), self::FIELDS);
        $missing = array_diff(self::FIELDS, self::OPTIONAL, array_keys($row));
        if ($unknown || $missing) {
            $fail(implode(', ', array_merge($unknown, $missing)));
        }
        $row = array_replace(array_fill_keys(self::FIELDS, null), $row);
        foreach (self::FIELDS as $field) {
            if ($field === 'active') {
                continue;
            }
            $value = $row[$field];
            if ($value === null && in_array($field, self::OPTIONAL, true)) {
                continue;
            }
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                $fail($field);
            }
            $limit = match ($field) {
                'description' => 32767,
                'quantity' => 32,
                'type', 'unit' => 16,
                'currency' => 3,
                default => 255,
            };
            if (mb_strlen($value) > $limit || ($field === 'description' && strlen($value) > 65535)) {
                $fail($field);
            }
            if (trim($value) === '') {
                if (! in_array($field, self::OPTIONAL, true)) {
                    $fail($field);
                }
                $row[$field] = null;
            }
        }
        if (CatalogItemType::tryFrom($row['type']) === null) {
            $fail('type');
        }
        $row['unit'] = CatalogTransferUnits::code($row['unit']);
        if ($row['unit'] === null) {
            $fail('unit');
        }
        if (! array_key_exists($row['currency'], ReferenceData::currencies())) {
            $fail('currency');
        }
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $row['quantity'])) {
            $fail('quantity');
        }
        foreach (['sales_price', 'purchase_price'] as $field) {
            if ($row[$field] === null && $field === 'purchase_price') {
                continue;
            }
            if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $row[$field])) {
                $fail($field);
            }
            try {
                $row[$field] = ExactMoney::ofString($row[$field], $row['currency'])->minorAmount;
            } catch (\Throwable) {
                $fail($field);
            }
        }
        if ($json ? ! is_bool($row['active']) : ! in_array($row['active'], ['0', '1'], true)) {
            $fail('active');
        }
        if ($row['tax_code'] !== null && ! TaxCode::query()->where('legal_entity_id', $entityId)
            ->where('code', $row['tax_code'])->where('is_active', true)->exists()) {
            $fail('tax_code');
        }

        return [
            'sku' => $row['sku'], 'name' => $row['name'], 'description' => $row['description'],
            'type' => $row['type'], 'unit' => $row['unit'], 'default_quantity' => $row['quantity'],
            'default_unit_price_minor' => $row['sales_price'], 'purchase_price_minor' => $row['purchase_price'],
            'currency' => $row['currency'], 'default_tax_code' => $row['tax_code'], 'ean' => $row['ean'],
            'is_active' => $json ? $row['active'] : $row['active'] === '1',
        ];
    }

    /** @return array<string, mixed> */
    public static function example(): array
    {
        return array_combine(self::FIELDS, [
            '000123', 'Müller & Söhne – Größe 20 × 30 cm, 19,00 €', "Erste Zeile\nZweite Zeile: ä ö ü Ä Ö Ü ß é è á ñ € „ “ – — ×",
            CatalogItemType::Product->value, CatalogTransferUnits::label(CatalogUnit::Piece->value), '1', '119.00', '80.00', 'EUR', null, '04012345678901', true,
        ]);
    }
}

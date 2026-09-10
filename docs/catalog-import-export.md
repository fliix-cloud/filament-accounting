# Catalog import and export

The catalog list provides **Import**, **Export** and **Download import template**.
All three require catalog management permission and an active Legal Entity.
Choose **XLSX** (recommended and default), XLS (Excel 97–2003), CSV or JSON.
Templates contain one example item, use the same schema as exports, and are available
in all four formats. Replace the example before importing your own catalog.

Import uploads and validates the entire file, then shows a created/updated/skipped
preview for confirmation. Confirmation validates again against current data and
writes in one transaction. Invalid datasets write nothing; persistence failures
roll back the entire import. Non-empty SKUs identify existing items only within
the current Legal Entity. Updating existing SKUs is enabled by default; disabling
it skips those items. Duplicate non-empty SKUs in one file are rejected. Empty
SKUs always create new items. Names are never used as identity.

## Canonical fields

Spreadsheet and CSV headers must match this exact order, without duplicates,
unknown columns, aliases or a mapping step:

```text
sku;name;description;type;unit;quantity;sales_price;purchase_price;currency;tax_code;ean;active
```

| Field | Contract |
| --- | --- |
| `sku` | Optional text, at most 255 characters; leading zeroes preserved. |
| `name` | Required text, at most 255 characters. |
| `description` | Optional multiline text; follows the catalog's existing sanitized rich-text convention. Supported HTML formatting is retained. |
| `type` | Required `product` or `service`, never a translated label. |
| `unit` | Required readable unit, e.g. `Stück` / `Piece` or `Stunde` / `Hour`. See the accepted values below. |
| `quantity` | Required decimal string, at most 32 characters, e.g. `1` or `0.125`. |
| `sales_price` | Required decimal major units, e.g. `119.00`. Exact conversion to internal minor units. |
| `purchase_price` | Optional decimal major units, stored separately as exact minor units. |
| `currency` | Required uppercase ISO 4217 code from package reference data, e.g. `EUR`. |
| `tax_code` | Optional existing **active** tax code of the current Legal Entity. No inference or tax-code creation. |
| `ean` | Optional text, at most 255 characters; leading zeroes preserved. |
| `active` | Required: `1`/`0` in CSV/Excel; native `true`/`false` in JSON. |

Decimal values use a dot and no thousands separator or exponent. Money must fit
the currency's minor-unit precision and a signed 64-bit integer without rounding.
Blank optional values become null. Whitespace-only required values are invalid.
Descriptions may contain up to 32,767 characters / 65,535 UTF-8 bytes. Other text
fields follow the model's storage limits. SKU and EAN must be **text cells** in
Excel, not numbers with display masks. Formulas and error cells are rejected.

Every spreadsheet has exactly one sheet, one header row and 12 columns. CSV uses
semicolons, double-quote enclosures, doubled embedded quotes, and CRLF record
endings on export. Quoted LF/CRLF multiline descriptions are supported. Error
messages identify the physical starting row, including preceding multiline cells.
Excel may normalize CRLF line breaks to LF; the text remains semantically equivalent.

Files are limited to 10 MiB and 10,000 items. An empty/unreadable file is rejected;
a valid header-only file or JSON envelope with `items: []` is a valid empty export
and imports as a no-op. Split larger catalogs into separate files. Each file is
atomic. Exports contain the current Legal Entity's complete catalog, including
inactive items, without internal IDs, UUIDs, timestamps or account-role fields.
An existing account role is preserved on update. Legacy values outside the current
schema (including inactive tax codes) must be corrected before export; the export
reports those values instead of producing a file that cannot be re-imported.

## Readable units

Templates and exports use German unit names when the interface language is German,
and English names otherwise. Excel files include a unit dropdown for the input rows.
All four formats accept the exact German and English names, regardless of the current
interface language, as well as the existing internal codes for older files.
Unknown names and approximate spellings are rejected. Internally, units remain stored
as codes; no database migration is needed for this presentation change.

| German | English | Existing code |
| --- | --- | --- |
| Stück | Piece | C62 |
| Stunde | Hour | HUR |
| Tag | Day | DAY |
| Monat | Month | MON |
| Kilogramm | Kilogram | KGM |
| Gramm | Gram | GRM |
| Meter | Meter | MTR |
| Quadratmeter | Square meter | MTK |
| Kubikmeter | Cubic meter | MTQ |
| Liter | Liter | LTR |
| Tonne | Tonne | TNE |
| Kilowattstunde | Kilowatt hour | KWH |
| Pauschale | Lump sum | LS |

## JSON

JSON requires this versioned object. Item key order is immaterial; unknown keys
at either level are rejected. Required item keys must be present. Optional keys
may be omitted or null. Text, quantity and money values are JSON strings.

```json
{
  "schema": "filament-accounting.catalog",
  "version": 1,
  "items": [{
    "sku": "000123",
    "name": "Müller & Söhne – Größe 20 × 30 cm, 19,00 €",
    "description": "Erste Zeile\nZweite Zeile",
    "type": "product",
    "unit": "Stück",
    "quantity": "1",
    "sales_price": "119.00",
    "purchase_price": "80.00",
    "currency": "EUR",
    "tax_code": null,
    "ean": "04012345678901",
    "active": true
  }]
}
```

## Encoding

CSV exports are deterministic **UTF-8 without BOM**. CSV imports remove an optional
UTF-8 BOM, validate UTF-8 first and otherwise try only Windows-1252 with a lossless
reverse-conversion check. Undefined Windows-1252 bytes, disallowed control bytes
(including UTF-16 NUL bytes) and replacement characters are rejected. No ambiguous
auto-detection, transliteration or replacement with question marks is used.
ISO-8859-1 is not a separate fallback; its common Western European characters
outside the C1 range coincide with Windows-1252. Arbitrary corrupted byte streams
cannot always be distinguished from valid Windows-1252; use UTF-8 exports/templates
as the reliable starting point.

JSON is strictly UTF-8 and uses readable Unicode output; malformed JSON or invalid
UTF-8 is rejected. Excel Unicode is handled directly by PhpSpreadsheet, with no
manual legacy conversion. Umlauts, `ß`, accents, `€`, typographic quotes, dashes
and `×` are preserved through all four formats.

After updating the package, run the host application's `php artisan migrate` to
add nullable EAN and purchase-price columns.

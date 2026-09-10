# Codex Implementation Prompt — Catalog Import / Export

## Goal

Implement a strict, simple and maintainable catalog import/export workflow directly in the Filament catalog item area of `filament-fints-accounting`.

The package owns one canonical catalog schema. The same schema must be used for import, export and downloadable examples/templates.

There must be **no mapping wizard, no fuzzy matching, no vendor-specific mappings and no automatic interpretation of foreign column names**. Files that do not follow the canonical schema must be rejected before any data is written, with a clear and actionable error message.

The implementation must follow the current repository architecture, Legal Entity scoping, authorization, translations, testing conventions and code style. Inspect the current codebase before implementing anything.

---

## Supported file formats

Support the same canonical schema in all of the following formats:

- `.xlsx` — preferred spreadsheet format
- `.xls` — legacy Excel compatibility
- `.csv` — text interchange format
- `.json` — structured interchange format

Do not create separate semantic schemas per format. All four formats are merely different serializations of the same catalog fields.

### Import

The Filament Import action must accept all four formats.

Determine the reader from the actual uploaded file type/extension using the selected spreadsheet/serialization library. Reject unsupported or unreadable files cleanly.

### Export

The Export action must allow the user to select one of:

- Excel (`.xlsx`)
- Excel 97-2003 (`.xls`)
- CSV (`.csv`)
- JSON (`.json`)

Default to `.xlsx`.

### Template / example download

Provide a `Download import template` / `Download example` action.

At minimum provide `.xlsx` as the default template. Prefer allowing the same four output formats if this can be implemented without duplicating logic.

The template and export must be generated from the same centralized schema definition used by import validation.

---

## Fixed product decisions

These are requirements, not suggestions:

- No import mapping UI.
- No automatic mapping.
- No WISO-specific importer.
- No Lexware/DATEV/vendor-specific aliases.
- No fuzzy header detection.
- No silent acceptance of unknown fields.
- No import of internal database IDs.
- No import of `legal_entity_id`.
- Exact schema validation must happen before persistence.
- Invalid files must not produce partial imports.
- Import writes must be transactional.
- Exported data must be directly re-importable.
- Multiline descriptions must survive round trips in every supported format.
- SKU and EAN must be treated as strings so leading zeroes survive.
- Money must be represented in human-readable major units externally and exact minor units internally.

---

## Current catalog domain

Inspect the current implementation first.

At specification time, `accounting_catalog_items` contains fields including:

- `legal_entity_id`
- `sku`
- `type`
- `name`
- `description`
- `unit`
- `default_quantity`
- `default_unit_price_minor`
- `currency`
- `default_account_role`
- `default_tax_code`
- `is_active`

The Filament resource is `CatalogItemResource`.

Use the actual current model/enums/helpers on the branch. In particular reuse existing exact-money handling such as `ExactMoney` if still present.

If they are still missing, extend the catalog model with:

- `ean` — nullable string
- `purchase_price_minor` — nullable exact minor-unit amount using the project's existing money storage convention

Do not add WISO-specific fields such as `DBINDEX`, `MWSTART` or `INDIVIDUELL1..20`.

---

## Canonical schema

The canonical field order is exactly:

```text
sku
name
description
type
unit
quantity
sales_price
purchase_price
currency
tax_code
ean
active
```

Centralize this schema in one service/value definition and reuse it for:

- spreadsheet headers
- CSV headers
- JSON field validation
- exports
- templates/examples
- import validation

Do not duplicate the schema across unrelated classes.

### Field rules

#### `sku`

- String.
- Optional only if the current domain model permits missing SKU.
- Preserve leading zeroes.
- Natural update key within the active Legal Entity.
- Never use a DB ID as import identity.

#### `name`

- Required string.

#### `description`

- Optional multiline string.
- Preserve line breaks exactly enough for semantic round-trip equivalence.

#### `type`

- Required.
- Accept only canonical values supported by the current `CatalogItemType` enum/domain implementation.
- Export enum values, never translated labels.

#### `unit`

- Required.
- Accept only canonical values supported by the current `CatalogUnit` enum/domain implementation.
- Export enum values, never translated labels.

#### `quantity`

- Required.
- Compatible with current `default_quantity` semantics.
- Use exact decimal handling where appropriate.

#### `sales_price`

- Required.
- External representation is a decimal major-unit value such as `119.00`.
- Internally convert exactly to `default_unit_price_minor`.
- Never rely on binary floating-point money arithmetic.

#### `purchase_price`

- Optional.
- Same external money rules as `sales_price`.
- Persist to `purchase_price_minor` if the field is introduced.

#### `currency`

- Required ISO 4217 code such as `EUR`.
- Validate using current package reference data.

#### `tax_code`

- Optional if the current catalog permits it.
- If present, it must reference an existing active Tax Code belonging to the current Legal Entity.
- Never create a tax code from an import.
- Never infer a tax code from a percentage.

#### `ean`

- Optional string.
- Preserve leading zeroes.
- Do not coerce to number.

#### `active`

- Required canonical boolean representation.
- Spreadsheet/CSV exports should use `1` and `0`.
- JSON exports should use native JSON booleans `true` / `false`.
- Import must validate these strictly per format and normalize them internally.

---

## Format-specific contracts

### XLSX / XLS

Use exactly one worksheet and exactly one header row.

The first row must contain the canonical columns in the exact canonical order.

Preserve SKU and EAN as text cells. Preserve multiline descriptions. Use decimal major-unit values for money.

### CSV

CSV must use the same exact header row and field order.

Use a standards-compliant CSV writer/reader that correctly quotes values containing:

- separators
- quotes
- CR/LF or LF line breaks

Multiline descriptions are valid and must not create false records.

Use UTF-8. Prefer a stable documented delimiter and quoting convention; semicolon is acceptable if that fits the package's intended European defaults, but reader and writer must use the exact same canonical convention. Do not attempt delimiter auto-detection if that would weaken strictness.

### JSON

Use a canonical top-level object, not an undocumented ad-hoc array.

Preferred structure:

```json
{
  "schema": "filament-accounting.catalog",
  "version": 1,
  "items": [
    {
      "sku": "ART-001",
      "name": "Example item",
      "description": "Example description",
      "type": "product",
      "unit": "piece",
      "quantity": "1",
      "sales_price": "119.00",
      "purchase_price": "80.00",
      "currency": "EUR",
      "tax_code": "DE_19",
      "ean": "04012345678901",
      "active": true
    }
  ]
}
```

Requirements:

- `schema` must match exactly.
- `version` must currently be `1`.
- `items` must be an array.
- Every item must contain only canonical fields.
- Required fields must be present.
- Unknown item keys must be rejected.
- Do not accept arbitrary vendor JSON shapes.

The explicit JSON schema/version envelope is intentional so future format evolution can be handled safely.

---

## Structural validation

Validate the full structure before processing persistence.

For XLSX/XLS/CSV reject when:

- required header is missing
- unknown header exists
- duplicate header exists
- header order differs
- file is empty
- file is unreadable
- format is unsupported

For JSON reject when:

- invalid JSON
- wrong `schema`
- unsupported `version`
- missing/invalid `items`
- unknown top-level contract fields unless explicitly part of the versioned format
- missing canonical item fields where required
- unknown item fields

Do not normalize external names such as:

```text
ARTIKELNR -> sku
KURZBEZEICHNUNG -> name
VK PREIS -> sales_price
```

A WISO export with those columns must be rejected. The user must transform it once into the documented canonical format or use an exported/template file from this package.

### Error UX

Use translated Filament validation/notification messages.

Example:

> Import not possible. The uploaded file does not match the expected catalog import format.
>
> Missing columns: `type`, `unit`, `currency`
>
> Unknown columns: `ARTIKELNR`, `KURZBEZEICHNUNG`, `VK PREIS`
>
> Please use the downloadable import template or a catalog export created by this package.

Errors should be actionable and include row/item information where possible.

---

## Import behavior

Add `Import` to the `CatalogItemResource` list page.

Flow:

1. Upload supported file.
2. Detect supported serialization by file type.
3. Validate structure/schema.
4. Parse and validate all rows/items.
5. Show a compact preview/summary if cleanly supported by the current Filament version.
6. Confirm import.
7. Persist transactionally.
8. Show created/updated/skipped summary.

### Existing SKU handling

Provide a simple option:

```text
Update existing items with the same SKU
```

Recommended default: enabled.

Behavior:

- SKU not found: create.
- SKU found + update enabled: update.
- SKU found + update disabled: skip and report.
- Duplicate non-empty SKU within one file: reject before writes.
- Empty SKU: only permit when the current model allows it.
- Never use name as fallback identity.

### Validation

Validate all data before writing anything, including:

- required fields
- string lengths
- enum values
- quantity format
- exact money format
- currency
- tax code scope/existence/activity
- duplicate SKUs
- boolean representation
- current model/domain constraints

For spreadsheet/CSV errors include physical row number. For JSON errors include item index and field.

### Transaction safety

The dataset import is atomic. Any validation failure means zero writes. Any unexpected persistence failure rolls back the transaction.

---

## Legal Entity scoping and authorization

All imports and exports are scoped to the currently active/required Legal Entity using existing project conventions and `LegalEntityScope`.

Never allow a file to specify or override `legal_entity_id`.

Reuse the existing catalog authorization ability. Users without catalog management permission must not be able to import/export catalog data.

---

## Export behavior

Add `Export` to the catalog list page and allow selection of `.xlsx`, `.xls`, `.csv` or `.json`.

Exports must:

- contain only current Legal Entity catalog items
- use exactly the canonical schema
- use human-readable major-unit money values
- preserve multiline descriptions
- preserve leading zeroes in SKU/EAN
- use canonical enum/tax-code values
- contain no database IDs, UUIDs, Legal Entity IDs or timestamps
- be directly re-importable without editing

Round-trip invariant:

```text
catalog items -> export.<format> -> import -> semantically equivalent catalog items
```

---

## Filament UX

Expected catalog list header actions:

- Create catalog item
- Import
- Export
- Download import template / example

Keep the UX compact and consistent with the current package.

Export may use a small format-select modal. Import should accept all supported file extensions in one upload field.

Do not build a mapping step.

---

## Suggested implementation structure

Adapt to current repository conventions, but separate responsibilities.

A reasonable shape is:

```text
src/
  Catalog/
    ImportExport/
      CatalogTransferSchema.php
      CatalogImporter.php
      CatalogExporter.php
      CatalogImportResult.php
      CatalogImportException.php
      Readers/
        SpreadsheetCatalogReader.php
        CsvCatalogReader.php
        JsonCatalogReader.php
      Writers/
        SpreadsheetCatalogWriter.php
        CsvCatalogWriter.php
        JsonCatalogWriter.php
```

Do not put parsing, validation and persistence directly into Filament Action closures.

Prefer shared normalization/validation after format-specific decoding so all formats follow identical domain rules.

---

## Dependency guidance

Inspect current Composer dependencies first.

Choose the smallest maintained solution compatible with supported PHP/Laravel versions that can reliably read/write `.xlsx` and `.xls`. Reuse an existing spreadsheet library if present.

For CSV, prefer PHP-native/league-style robust CSV handling or capabilities already provided by the selected library. It must support quoted multiline fields correctly.

JSON should use native PHP JSON encoding/decoding with strict exception handling; do not add a JSON-specific dependency without a concrete reason.

---

## Tests

Add comprehensive automated tests using the repository's current Pest/PHPUnit conventions.

### Shared schema tests

Cover:

- canonical field list/order
- missing field/header rejected
- unknown field/header rejected
- duplicate header rejected where applicable
- wrong spreadsheet/CSV order rejected
- empty input rejected
- unsupported format rejected

### XLSX tests

- valid import
- multiline description
- SKU/EAN leading zeroes
- exact money conversion
- export/import round trip

### XLS tests

- valid import
- export/import round trip

### CSV tests

- valid import
- UTF-8 content
- commas/semicolons/quotes in text as appropriate
- embedded quotes
- multiline description does not create extra records
- export/import round trip

### JSON tests

- valid versioned JSON import
- invalid JSON rejected
- wrong schema rejected
- unsupported version rejected
- unknown item field rejected
- native boolean handling
- multiline description
- export/import round trip

### Domain/import tests

Cover at minimum:

- product and service
- sales/purchase price exact minor conversion
- valid/invalid tax codes
- foreign Legal Entity tax code rejected
- invalid type/unit/currency
- duplicate SKU rejected
- existing SKU update enabled
- existing SKU skip when disabled
- Legal Entity isolation
- invalid dataset causes zero writes
- runtime failure rolls back

### Cross-format equivalence

Create one representative dataset and verify that exports to all four formats decode to the same canonical semantic records.

This is important to prove that `.xlsx`, `.xls`, `.csv` and `.json` are just serializers around one schema.

### Filament tests

Where practical:

- authorized actions visible
- unauthorized actions denied/hidden
- supported extensions accepted
- invalid file shows useful error
- successful import summary
- export format choice works

---

## Translations

All user-facing text must use the existing package translation system.

Add/update German and English strings for at least:

- Import
- Export
- Download import template/example
- file format selection
- supported formats
- upload
- update existing SKU option
- schema mismatch
- missing fields/columns
- unknown fields/columns
- invalid JSON schema/version
- invalid row/item
- created / updated / skipped counts
- successful import/export

---

## Documentation

Update public docs concisely.

Document:

- supported formats: `.xlsx`, `.xls`, `.csv`, `.json`
- `.xlsx` is the recommended/default format
- exact canonical columns/JSON keys
- JSON envelope (`schema`, `version`, `items`)
- required/optional fields
- canonical enum values rather than translated labels
- major-unit decimal money representation
- tax codes must already exist
- strict rejection of mismatched schemas
- exported files and provided templates are the recommended starting point

Do not add WISO-specific public documentation.

---

## Explicit non-goals

Do not implement:

- source-to-target mapping wizard
- automatic column matching
- WISO-specific imports
- Lexware-specific imports
- DATEV catalog mappings
- field alias heuristics
- tax percentage inference
- creation of missing tax codes during catalog import
- silent coercion of malformed files

---

## Completion criteria

Do not stop at planning or scaffolding.

Inspect the repository, implement the feature completely, add migrations/model changes if needed, implement Filament actions and services/readers/writers, add translations and docs, add automated tests, run the relevant test/static-analysis/formatting commands, fix failures, and leave the branch in a reviewable state.

If a detail in this specification conflicts with a newer established project convention, preserve the product behavior defined here while adapting the implementation structure to the current convention. Do not silently drop requirements.

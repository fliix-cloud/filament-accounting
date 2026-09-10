# Codex Implementation Prompt — Catalog Import / Export

## Goal

Implement a simple, strict and maintainable import/export workflow for catalog items in `filament-fints-accounting`.

The feature must live directly in the Filament catalog item area and must **not** contain any column mapping wizard, fuzzy matching, vendor-specific mapping logic or heuristics.

The package defines one canonical spreadsheet format. Exported catalog data must use exactly the same format that the importer accepts. Users who want to import data from another system are expected to transform their source file into this canonical format first.

If the uploaded file does not match the expected schema, reject it before importing anything and show a clear, informative validation message.

The implementation must fit the existing package architecture, tenancy/legal-entity scoping, authorization model, translations, coding style and test setup.

---

## Important product decisions

These decisions are fixed requirements:

- No import mapping UI.
- No automatic mapping.
- No WISO-specific importer.
- No vendor-specific aliases for column names.
- No fuzzy column detection.
- No silent acceptance of unknown columns.
- The import format is package-owned and documented.
- Export uses the exact same canonical format as import.
- A downloadable template/sample file must be available.
- Structural validation happens before any records are written.
- Invalid files must not result in partial imports.
- The primary spreadsheet format should be `.xlsx`.
- Do not build CSV as the primary format. Multiline descriptions must work reliably.
- Do not expose internal database IDs or minor-unit implementation details in the spreadsheet.

---

## Existing catalog model

Inspect the current implementation before changing anything.

At the time this specification was written, the catalog is based on `accounting_catalog_items` and includes fields such as:

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

Do not blindly implement against this list. First inspect the current branch and use the actual current models, enums, support classes, authorization and Filament APIs in the repository.

---

## Canonical spreadsheet schema

Use a single worksheet with a single header row.

The canonical column order must be exactly:

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

### Column rules

#### `sku`

- Optional only if the current domain model allows catalog items without an SKU.
- String.
- Must be treated as text in Excel so leading zeroes are not lost.
- Used as the update key within the current legal entity when present.
- Never use a database ID as an import key.

#### `name`

- Required.
- String.
- Must map to the catalog item name.

#### `description`

- Optional.
- String / multiline text.
- Preserve line breaks.
- Must round-trip correctly through export and import.

#### `type`

- Required.
- Must accept only values supported by the current `CatalogItemType` enum or equivalent current domain implementation.
- Export enum values, not translated display labels.

#### `unit`

- Required.
- Must accept only canonical values supported by the current `CatalogUnit` enum or equivalent current implementation.
- Export enum values, not translated labels.

#### `quantity`

- Required.
- Decimal/string value compatible with the current `default_quantity` implementation.
- Default template value may be `1`.
- Do not use floating-point arithmetic if the current project already uses exact decimal handling.

#### `sales_price`

- Required.
- Human-readable decimal major-unit amount, e.g. `119.00`.
- Do not export `11900` for 119 EUR.
- Convert safely to/from `default_unit_price_minor` using existing money helpers such as `ExactMoney` if available.
- Never use binary floating-point for monetary conversion if an exact helper already exists.

#### `purchase_price`

- Optional.
- Human-readable decimal major-unit amount.
- If the catalog model does not yet support purchase price, add a dedicated internal minor-unit field, preferably `purchase_price_minor`, following the existing money storage pattern.
- Keep it nullable unless the existing project conventions strongly require otherwise.

#### `currency`

- Required.
- ISO 4217 code, e.g. `EUR`.
- Validate against the package's current currency reference data.

#### `tax_code`

- Optional if the current catalog model allows no default tax code.
- If present, it must reference an existing active tax code for the current legal entity.
- Do not create tax codes during import.
- Do not infer tax codes from percentages.
- Do not accept arbitrary tax percentages in place of a tax code.

#### `ean`

- Optional.
- Treat as string, not numeric, so leading zeroes remain intact.
- If the catalog model does not yet have an EAN/GTIN field, add a nullable string field, preferably `ean`.
- Do not enforce a specific GTIN length unless the project already has a documented domain rule requiring it. Basic sane validation is sufficient.

#### `active`

- Required.
- Canonical exported values must be `1` or `0`.
- Import may accept only the explicitly documented canonical values. Keep parsing strict.

---

## Schema validation

The importer must validate the workbook structure before processing rows.

### Exact header validation

The first row must match the canonical header list exactly.

Reject the file when:

- a required header is missing,
- an unknown header exists,
- a header is duplicated,
- the header order is different,
- the worksheet is empty,
- the workbook cannot be read,
- the file type is unsupported.

Do not normalize arbitrary external column names into canonical names.

Do not accept aliases such as:

- `ARTIKELNR` for `sku`
- `KURZBEZEICHNUNG` for `name`
- `VK PREIS` for `sales_price`

Those files must be rejected and the user should be told to use the provided template or an export from this package.

### Error UX

Provide a clear Filament notification or validation error, for example:

> Import not possible. The uploaded file does not match the expected catalog import format.
>
> Missing columns: `type`, `unit`, `currency`
>
> Unknown columns: `ARTIKELNR`, `KURZBEZEICHNUNG`, `VK PREIS`
>
> Please use the downloadable import template or a catalog export created by this package.

Use package translations instead of hard-coded user-facing strings.

The wording does not need to be byte-for-byte identical to this example, but the message must be actionable.

---

## Import behavior

Add an `Import` action to the Filament catalog list page.

The workflow should remain intentionally simple.

Recommended flow:

1. User uploads `.xlsx` file.
2. System validates workbook and exact header schema.
3. System validates rows.
4. Show a compact preview/summary before final import if this can be implemented cleanly with the current Filament version.
5. User confirms import.
6. Import runs transactionally.
7. Show final summary.

Do not add a mapping wizard.

### Transaction safety

The import must be atomic for the uploaded dataset.

If structural validation or row validation fails, do not write any catalog records.

If an unexpected exception occurs during the write phase, roll back the import.

### Legal entity scoping

All imported data belongs to the currently active/required legal entity.

Use the existing `LegalEntityScope` and existing ownership conventions.

Never allow the spreadsheet to specify or override `legal_entity_id`.

### Authorization

Reuse the existing catalog authorization/ability model.

A user who cannot manage catalog items must not be able to import or export them.

Do not introduce a parallel authorization system unless the current project architecture clearly requires a dedicated import/export ability.

### Existing items / SKU behavior

Use SKU as the natural external key **within the current legal entity** when SKU is present.

The import UI should provide a simple option similar to:

```text
Update existing items with the same SKU
```

Recommended default: enabled.

Behavior:

- SKU not found -> create new item.
- SKU found and update option enabled -> update that item.
- SKU found and update option disabled -> skip that row and report it.
- Duplicate non-empty SKU values inside the same uploaded file -> validation error before any write occurs.
- Empty SKU -> create a new item only if the current model permits empty SKU.

Do not use `name` as a fallback identity key.

### Row validation

Validate all rows before writing anything.

At minimum validate:

- required fields,
- supported enum values,
- decimal money format,
- quantity format,
- currency,
- tax code ownership/availability,
- duplicate SKUs inside the workbook,
- string length constraints from the current database schema,
- boolean format,
- any current model/domain rules.

Errors must include the spreadsheet row number and field where possible.

Example:

```text
Row 14: currency "EURO" is invalid. Expected an ISO currency code such as "EUR".
Row 23: tax_code "DE_19" does not exist for the current legal entity.
Row 41: sku "ABC-100" occurs more than once in this file.
```

Do not silently coerce clearly invalid data.

---

## Export behavior

Add an `Export` action to the Filament catalog list page.

The export must generate `.xlsx` using the exact canonical header list and order defined above.

Export only catalog items belonging to the current legal entity.

The output must be directly importable again without manual changes.

This round-trip invariant is important:

```text
catalog items -> export.xlsx -> import -> equivalent catalog items
```

### Spreadsheet representation

Use user-facing major-unit values for money:

```text
119.00
```

not internal minor units:

```text
11900
```

Preserve:

- multiline descriptions,
- leading zeroes in SKU where applicable,
- leading zeroes in EAN,
- exact enum values,
- exact tax code strings,
- active state.

Do not include:

- database IDs,
- UUIDs unless there is an existing public contract requiring them,
- `legal_entity_id`,
- timestamps,
- internal accounting implementation fields not part of the canonical schema.

---

## Import template / sample download

Add a `Download import template` action in the catalog area.

The template must use the same generator/schema as the real export to prevent format drift.

Prefer one shared schema definition/service that powers:

- header generation,
- export,
- template generation,
- import header validation.

Do not duplicate the column list in multiple unrelated classes if it can be avoided.

The template may contain either:

- only the canonical header row, or
- the header row plus one clearly marked/example data row.

Prefer the smallest approach that gives users enough information.

If an example row is included, use valid canonical values and make it obvious that it is sample data.

---

## Filament UX

Integrate the feature directly into the existing `CatalogItemResource` list page.

Expected header actions:

- Create catalog item
- Import
- Export
- Download import template

Use the existing Filament version and project patterns. Do not introduce a custom frontend framework.

Keep the UI compact and consistent with the rest of the package.

After import, display a summary such as:

```text
Import completed.
Created: 120
Updated: 35
Skipped: 4
```

If validation fails, show validation details and do not claim that anything was imported.

---

## Model / migration changes

Inspect the current schema first.

If still missing, add support for:

```text
ean
purchase_price_minor
```

Recommended characteristics:

### `ean`

- nullable string
- no global uniqueness requirement unless existing domain rules require it
- consider an index only if justified by actual usage

### `purchase_price_minor`

- nullable big integer, or whatever exact monetary storage convention the project currently uses
- do not use SQL floating-point money storage

Update the model's fillable/casts/domain accessors as required by the current project conventions.

Update the Filament catalog create/edit form if appropriate so these fields can also be maintained manually.

Do not add WISO-specific fields such as `DBINDEX`, `MWSTART`, `INDIVIDUELL1` ... `INDIVIDUELL20`.

---

## Spreadsheet library

Inspect existing dependencies before adding a package.

Choose the smallest appropriate maintained library that supports reliable `.xlsx` read/write and works with the project's supported PHP/Laravel versions.

Do not add a large abstraction solely because it is popular if a smaller dependency cleanly satisfies the requirement.

If the repository already uses a spreadsheet library, reuse it unless there is a strong technical reason not to.

Keep spreadsheet-specific code behind package services/classes so Filament pages are not responsible for parsing workbook internals.

---

## Suggested architecture

Adapt names to current project conventions, but keep responsibilities separated.

A reasonable structure could look like:

```text
src/
  Catalog/
    ImportExport/
      CatalogSpreadsheetSchema.php
      CatalogSpreadsheetExporter.php
      CatalogSpreadsheetImporter.php
      CatalogImportResult.php
      CatalogImportException.php
```

This is a suggestion, not a mandatory namespace.

The key requirement is that:

- schema definition is centralized,
- parsing/validation is separate from Filament UI,
- persistence is testable without browser/UI tests,
- money conversion uses existing exact-money infrastructure,
- legal entity scoping is explicit and testable.

Do not place all parsing, validation and persistence logic directly inside the Filament page action closure.

---

## Testing requirements

Add comprehensive automated tests using the repository's existing Pest/PHPUnit conventions.

At minimum cover the following.

### Schema tests

- canonical header list is stable,
- correct workbook accepted,
- missing column rejected,
- unknown column rejected,
- wrong column order rejected,
- duplicate header rejected,
- empty workbook rejected.

### Import tests

- imports a valid product,
- imports a valid service,
- multiline description survives import,
- sales price converts exactly to minor units,
- purchase price converts exactly to minor units,
- EAN with leading zero survives,
- SKU with leading zero survives,
- valid tax code accepted,
- invalid tax code rejected,
- tax code from another legal entity rejected,
- unsupported type rejected,
- unsupported unit rejected,
- invalid currency rejected,
- invalid boolean rejected,
- duplicate SKU in workbook rejected,
- existing SKU updates when enabled,
- existing SKU skips when update disabled,
- import is restricted to active legal entity,
- invalid file causes zero database writes,
- runtime failure rolls back transaction.

### Export tests

- exact header order,
- only current legal entity is exported,
- money is exported in major units,
- internal IDs are not exported,
- EAN/SKU text formatting preserves leading zeroes,
- multiline description survives.

### Round-trip test

Create representative catalog items, export them, import the generated workbook into a clean legal entity/context and verify semantic equivalence of all canonical fields.

This is one of the most important tests.

### Filament tests

Where practical using the current test setup:

- actions are visible to authorized user,
- actions are hidden/denied for unauthorized user,
- invalid upload shows informative error,
- successful import shows result summary.

Do not over-test Filament internals when service-level tests provide better coverage.

---

## Translation requirements

All new user-facing strings must use the existing package localization system.

Add at least English and German translations if those are the currently maintained package languages.

Include strings for:

- Import
- Export
- Download import template
- upload field
- update existing items option
- validation errors
- schema mismatch
- missing columns
- unknown columns
- invalid row messages
- created / updated / skipped counts
- successful import/export notifications

Follow current translation file organization instead of creating a new structure without reason.

---

## Documentation

Update the public package documentation with a short, practical section describing catalog import/export.

Keep it concise.

Document:

- supported file format (`.xlsx`),
- canonical columns,
- required/optional fields,
- enum values are canonical/internal values, not translated labels,
- money uses major-unit decimal values,
- `tax_code` must already exist,
- import rejects mismatched schemas,
- users should use the downloadable template or an export as their starting point.

Do not document WISO-specific instructions in the package's public docs.

---

## Explicit non-goals

Do **not** implement any of the following:

- WISO Mein Büro mapper,
- Lexware mapper,
- DATEV catalog mapper,
- generic mapping wizard,
- manual source-to-target column assignment,
- automatic header aliases,
- AI-assisted mapping,
- fuzzy matching,
- accepting arbitrary additional columns,
- importing tax definitions from the spreadsheet,
- exposing legal entity IDs in the spreadsheet,
- database backup/restore functionality,
- generic arbitrary-model import framework.

This feature is intentionally narrow.

---

## Quality requirements

Before completing the implementation:

1. Inspect the current repository and reuse current abstractions where possible.
2. Keep implementation package-quality and suitable for a public open-source project.
3. Prefer simple, conventional Laravel/Filament code.
4. Avoid speculative abstractions.
5. Avoid duplicated schema definitions.
6. Preserve exact monetary values.
7. Preserve tenant/legal-entity isolation.
8. Keep all writes transactional.
9. Ensure imports are deterministic.
10. Ensure exported files are directly re-importable.
11. Run the complete relevant test suite.
12. Run formatting/static analysis tools already configured in the repository.
13. Fix all failures caused by the implementation.
14. Do not weaken existing tests, PHPStan rules or validation to make the feature pass.

---

## Definition of done

The task is complete when all of the following are true:

- Catalog list page has Import, Export and Download Template actions.
- Export produces canonical `.xlsx` files.
- Template uses the exact same schema as export.
- Import accepts those files without mapping.
- Import rejects mismatched schemas with a useful message.
- Unknown columns are rejected.
- Missing columns are rejected.
- No WISO-specific code exists.
- Legal entity isolation is preserved.
- Existing SKUs can be updated or skipped according to the import option.
- Money round-trips exactly.
- Multiline descriptions round-trip correctly.
- EAN and purchase price are supported if not already present.
- All new UI text is translated consistently.
- Automated tests cover validation, import, export, scoping and round-trip behavior.
- Existing tests continue to pass.
- Documentation is updated concisely.

---

## Final Codex instruction

Implement this feature completely on the current feature branch.

Do not stop after scaffolding or planning. Inspect the existing codebase, make the required migrations/model/service/Filament/translation/test/documentation changes, run the relevant test and quality commands, fix issues, and leave the branch in a reviewable state.

If an implementation detail in this specification conflicts with a newer established project convention in the repository, preserve the product requirements above but adapt the code structure to the current convention. Do not silently drop requirements.

# Mandatory Encoding Requirements — Catalog Import / Export

This document is a mandatory addendum to `docs/catalog-import-export-codex-prompt.md` on branch `feature/catalog-import-export-spec`.

Codex must treat the requirements below as part of the implementation specification.

## Canonical encoding

The package-owned canonical text encoding is **UTF-8**.

All package-generated text-based export formats must be emitted as UTF-8:

- CSV: UTF-8
- JSON: UTF-8

Spreadsheet formats (`.xlsx`, `.xls`) are handled through the selected spreadsheet library and must preserve Unicode text correctly. Users must not need to configure a character encoding for spreadsheet import/export.

The implementation must preserve German umlauts and other Unicode/special characters, including at minimum:

```text
ä ö ü Ä Ö Ü ß é è á ñ € „ “ – — ×
```

Example test value:

```text
Müller & Söhne – Größe 20 × 30 cm, 19,00 €
```

## CSV encoding rules

### Export

CSV exports produced by the package must always be UTF-8.

Use the canonical CSV contract already defined in the main prompt. Preserve quoted multiline fields correctly.

UTF-8 without BOM is preferred for package-generated exports. UTF-8 with BOM may be supported if the selected library requires it for reliable Excel interoperability, but writer behavior must be deterministic and documented.

### Import

The canonical expected encoding is UTF-8, but the importer should be tolerant of common legacy encodings from German/European business software.

Import handling must follow this order:

1. Detect and remove an optional UTF-8 BOM.
2. Validate whether the remaining input is valid UTF-8.
3. If valid UTF-8, continue without conversion.
4. If not valid UTF-8, attempt a controlled conversion from Windows-1252 to UTF-8.
5. If that cannot be handled safely, optionally attempt ISO-8859-1 as a final explicitly supported legacy fallback.
6. After decoding/conversion, all further internal processing must use UTF-8 only.
7. If the file cannot be decoded safely, reject the import with a clear translated error message.

Do **not** blindly use ambiguous auto-detection such as unrestricted `mb_convert_encoding(..., 'UTF-8', 'auto')` if it can silently reinterpret damaged or unknown input.

Do not silently replace invalid byte sequences with `?` or the Unicode replacement character and then continue as if the import were valid.

Prefer explicit validation plus an intentionally small list of supported legacy encodings.

## JSON encoding rules

JSON import/export must use UTF-8.

JSON output should remain human-readable and preserve Unicode characters directly where possible. Use native JSON encoding with `JSON_UNESCAPED_UNICODE` unless an existing project convention provides an equivalent safe approach.

For example, prefer:

```json
{
  "name": "Überprüfung Gerät",
  "description": "Größe: 25 × 30 cm"
}
```

over unnecessarily escaped Unicode such as:

```json
{
  "name": "\u00dcberpr\u00fcfung Ger\u00e4t"
}
```

Invalid UTF-8 input must cause a clear import failure; do not silently repair arbitrary malformed JSON text.

Use exception-based JSON decoding/encoding (`JSON_THROW_ON_ERROR` or current project equivalent) so encoding/parsing failures are explicit.

## XLSX / XLS Unicode rules

The spreadsheet reader/writer must preserve Unicode text through import/export.

Ensure that:

- umlauts survive unchanged,
- Unicode punctuation survives unchanged,
- multiline descriptions survive unchanged,
- SKU/EAN remain strings,
- no lossy conversion to an ANSI code page is introduced by application code.

Do not apply CSV-style manual encoding conversion to `.xlsx` or `.xls` contents after the spreadsheet library has decoded cells into PHP strings.

## Internal normalization

Once a file has been decoded successfully, the application must operate on UTF-8 strings internally.

Do not transliterate or strip valid Unicode characters merely to simplify validation.

Do not convert:

```text
Müller -> Muller
Größe -> Grosse
€ -> EUR
```

unless the user explicitly supplied such values in the source data.

Normalization such as trimming ordinary surrounding whitespace may follow existing import/domain rules, but character content must otherwise be preserved.

## Error UX

Add translated German and English error messages for unsupported/invalid text encoding.

Example meaning:

> Import not possible. The file encoding could not be read safely. Please use UTF-8. CSV files exported by older software may also be accepted when encoded as Windows-1252.

The exact wording can follow project conventions, but it must be actionable.

Do not expose low-level PHP encoding warnings to end users.

## Tests

Add encoding-focused automated tests in addition to the tests already required by the main prompt.

At minimum cover:

- UTF-8 CSV import with German umlauts
- UTF-8 CSV export
- optional UTF-8 BOM CSV import
- Windows-1252 CSV input is converted safely to UTF-8
- ISO-8859-1 fallback if implemented
- malformed/unsupported CSV encoding is rejected without writes
- JSON with German umlauts/special Unicode characters
- JSON export uses valid UTF-8 and preserves Unicode semantics
- invalid UTF-8 JSON input is rejected
- XLSX round trip preserves Unicode characters
- XLS round trip preserves Unicode characters
- multiline descriptions plus umlauts survive round trip
- package exports followed by re-import preserve the example string exactly/semantically

Use a representative value such as:

```text
Müller & Söhne – Größe 20 × 30 cm, 19,00 €
```

The final implementation must prove that this value survives export/import through every supported format without mojibake such as:

```text
MÃ¼ller
GrÃ¶ÃŸe
â‚¬
```

## Completion requirement

Do not consider the catalog import/export implementation complete until these encoding requirements are implemented, tested and documented together with the main specification.

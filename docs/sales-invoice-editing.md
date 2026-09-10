# Sales invoice editing

Invoice PDFs use `resources/views/documents/invoice.blade.php`, rendered by
`BladeInvoiceRenderer` through Dompdf. The layout includes a company heading and
logo, recipient and contact blocks, customer number, item/SKU table, tax totals,
payment information and a bank/tax footer. Override the view in the host at
`resources/views/vendor/filament-accounting/documents/invoice.blade.php` to customize
it. Preview uses the same view; issued PDFs continue through the existing PDF/A-3
and embedded ZUGFeRD XML generation. Existing archived files are retained.

New invoices support bank transfer and SEPA direct debit. A direct-debit draft can
be previewed without a mandate; issuance requires an active mandate belonging to
the customer and company, signed by the invoice date. The creditor identifier,
mandate reference and debtor IBAN are frozen in the invoice snapshot and exported
as payment means code 59. Choosing this method does not submit a bank collection.
Company subtitle/contact, customer number, article SKU and PNG/JPEG logo bytes are
also captured for reproducible invoices. Run Composer install/update and the
forward migrations when upgrading.

For private demo data, set `ACCOUNTING_DEMO_PROFILE` to an absolute local JSON
file path. `AccountingDemoSeeder` then runs the invoice profile seeder instead of
the generic fixtures. The profile accepts `company` (company model fields),
`customer` (including `external_reference`), `customer_address` (`line1`,
`postal_code`, `city`, `country_code`), optional `logo_file` (local PNG), and
optional `invoice` dates. It updates the current demo company and customer and
maintains one lastschrift draft with a neutral sample position. It does not invent
a mandate or customer bank account. The seeder requires a local/testing
environment and an authenticated, authorized actor. Keep real profiles and logos
outside version control; rerunning the seeder reapplies the profile to the demo.

New invoices start with today's issue and supply dates. New customers default to
seven payment days; existing customer terms remain unchanged. Selecting a customer
or changing the issue date recalculates the due date. A manually entered due date
is saved as entered and is recalculated only on the next customer or issue-date
change. Existing invoice dates are preserved when opening the edit form.

Quantity and unit price accept either a decimal comma or point, without thousands
separators. Calculation and persistence use decimal strings and integer minor
units. Each line displays its net total, including any existing discount. The
PDF preview downloads a marked draft using current form values without creating
a document, allocating a number, posting, or archiving files.

Drafts can be edited and deleted. The deletion is logged. Editing an issued invoice
requires an issuance permission and a nonempty reason in addition to the draft
permission. Saving creates a draft of the next version using the same invoice
number; it does not change the previous version or its files. Each version is
retained as a separate immutable document once issued, with its own file set and
internal identifier. The detail page shows a version history with reasons, issue
timestamps and links to each version. Delete an unissued version draft to abandon
the change and edit the preceding version again.

Issuing the next version preserves the invoice number and does not advance the
number sequence. It generates a corrected-invoice XML (type 384) with a preceding
invoice reference and version note, plus a PDF identifying the version and reason.
Posting reverses the preceding version's journal, marks its
open item reversed, and posts the replacement in one database transaction.
Reversal and replacement use the correction's issue date, subject to the existing
period controls. The preceding version is marked as archived in the history. Original
commercial data, journals, files, and audit evidence remain intact.

Payment allocations must be reversed before correction; this is checked again
at issuance and posting. Only one correction can exist for an original at a time;
subsequent corrections target the latest invoice. If file generation or posting
fails, the existing completion action resumes issuance without duplicating
numbers or reversals.

“Generate PDF/XML” creates or completes the files for the selected issued invoice.
A completed original pair is verified and reused. Regeneration after a commercial
change belongs to the new version; this action does not overwrite
archived originals or conceal missing/corrupt files.

Downloads of versions after the first include a version suffix, such as
`RE2026-000001-v2.pdf`. The invoice number inside the PDF/XML remains
`RE2026-000001`.

Run `php artisan migrate` when upgrading. The forward migration assigns existing
documents version 1 and changes invoice-number uniqueness to include the version.
The migration refuses rollback once subsequent versions exist, so that a schema
rollback cannot discard version history.

These controls support traceable correction, not a blanket certification of GoBD
compliance. The preservation requirement is described in the
[BMF GoBD guidance, section 3.2.5](https://ao.bundesfinanzministerium.de/ao/2021/Anhaenge/BMF-Schreiben-und-gleichlautende-Laendererlasse/Anhang-64/inhalt.html).
See [the project's compliance scope](gobd.md) and [operating requirements](operations.md).

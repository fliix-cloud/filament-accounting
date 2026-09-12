# Security, bug, and logic review

Reviewed: 11 September 2026, working tree at `9c90ecd` (clean `main`).
Remediation: 11 September 2026, branch `security/s1-s10-prod-hardening`.

This is a source-level review of this Laravel package against `docs/architecture.md`, `docs/operations.md`, `docs/gobd.md`, `docs/install.md`, and `docs/sales-invoice-editing.md`. It is not a production pentest, legal opinion, or GoBD certification. Composer audit reported no known advisories.

Prior GoBD findings F1–F12 remain the compliance baseline; none are fully closed (`docs/gobd.md`). This register adds security/logic items found now. Package vs operator is kept separate: the package must not ship broken or fail-open controls; operators still configure Gates, storage, and host auth.

Status: ⬜ Open | 🔧 In progress | ✅ Fixed | ⚠️ Accepted

---

## Critical

None verified as currently exploitable without a further code change. S-1 would become Critical if isolation is “fixed” without adding `web`+`auth` middleware.

---

## High

### S-1 · ✅ Fixed · High

- **Location:** `routes/accounting.php:9-30`, `src/Ownership/LegalEntityScope.php:64-67`, `src/Filament/Support/AuditExportAction.php:20`
- **Description:** The HTTP audit-dataset export is registered **without** `web` or `auth` middleware (unlike `routes/fints.php`). The closure calls `$scope->assertModel($legalEntity)` with the default column `legal_entity_id`. `LegalEntity` has no such attribute, so the check compares `null` to the current entity id and always throws `EntityIsolationException` (unhandled `RuntimeException` → 500). Authorization (`export_audit`) runs **after** that check, so it never runs. The Filament action still links to this route. Operations documents it as the UI export path.
- **Attack scenario:** As shipped, guests and authorized users both fail before a dataset is built (DoS/broken feature, not a working dump). If isolation is changed to `assertSame($legalEntity->getKey())` without adding session auth, a GET to `/accounting/audit/export/{uuid}` becomes an unauthenticated full-company dump (journals, invoices, originals, IBANs). Without `web` middleware, even a logged-in Filament session cookie is not applied.
- **Recommended fix:** Wrap the route in `middleware(['web', 'auth'])` (or Filament authenticate). Isolate with `$scope->assertSame($legalEntity->getKey())` (or a dedicated `assertEntity($legalEntity)`). Authorize **before** building the stream. Abort 404 on isolation failure. Add tests: guest → 401/403, other entity → 404, authorized → 200 stream. Do not rely on the broken assert as “protection”.

### S-2 · ✅ Fixed · High

- **Location:** `src/Banking/FinTs/Filament/Resources/BankTransferResource.php`, `BankDirectDebitResource.php`, `BankConnectionResource.php`, `DirectDebitMandateResource.php`, `DirectDebitCreditorProfileResource.php`; Filament `get_authorization_response()` allow-when-no-policy (`vendor/filament/filament/src/helpers.php:80-92`)
- **Description:** These resources do **not** use `HasAccountingNavigation` and have **no** model policies except `BankConnectionPolicy` (connections only). Filament’s default when no policy exists is **allow** any authenticated panel user. Create pages for transfers/connections re-check Gates in `mutateFormDataBeforeCreate`; list/view/edit for transfers, direct debits, mandates, and creditor profiles do not. GoBD F3 still requires a complete authorization audit of public mutation paths.
- **Attack scenario:** A host user who may access the Filament panel (or any `Gate::before` that is not an accounting Gate) but is not granted `accounting.bank.*` can open `/bank/transfers`, `/bank/direct-debits`, `/bank/direct-debit-mandates`, `/bank/direct-debit-creditors`, and connection settings, read payment/mandate/IBAN data, and on resources without a create-time Gate, mutate them. Architecture: Filament is not the security boundary — missing resource Gates still leak the banking UI.
- **Recommended fix:** Apply the same ability mapping as accounting resources (`view_bank`, `create_bank_transfer`, `create_bank_direct_debit`, `manage_bank_connections`). Deny `canViewAny`/`canCreate`/`canEdit`/`canDelete` when the Gate is missing. Add policies or `HasAccountingNavigation` overrides. Tests: panel user without bank Gates → 403 on those URLs; with Gates → 200.

### S-3 · ✅ Fixed · High (conditional on S-2)

- **Location:** `src/Banking/FinTs/Filament/Resources/BankConnectionResource/Pages/CreateBankConnection.php:47-59`
- **Description:** `syncInstitutes` is a header action on the connection **create** page. That page is reachable if Filament allows `create` (no resource `canCreate` override → allow). The action calls `InstituteDirectoryService::sync()` with no `manage_bank_connections` check.
- **Attack scenario:** Combined with S-2, any panel user can trigger an outbound HTTP fetch of the institute directory (and write `fints_bank_institutes`) without a banking permission.
- **Recommended fix:** `->authorize()` / `visible()` on the action using `manage_bank_connections`. Gate `canCreate` on `BankConnectionResource`.

---

## Medium

### S-4 · ✅ Fixed · Medium

- **Location:** `src/Banking/FinTs/Services/InstituteDirectoryService.php:122-136`, `src/Banking/FinTs/Commands/SyncInstitutesCommand.php:18-24`
- **Description:** `download($url)` has no scheme/host allowlist, HTTPS enforcement, or private-IP rejection. Artisan `--url=` passes through. The default GitHub raw URL is operator-chosen; a custom URL is SSRF from the app server. Parsed `pin_tan_url` values are stored and later used as FinTS endpoints (then `EndpointValidator` applies).
- **Attack scenario:** Anyone who can run `php artisan filament-accounting:sync-institutes --url=http://169.254.169.254/` (or an internal FinTS host) makes the app fetch it. Console is operator-trusted per `docs/operations.md`; still a package footgun if host RBAC on artisan is weak. Do not treat console as in-app authorization.
- **Recommended fix:** Reuse `EndpointValidator` (or a stricter directory allowlist: `https` + host allowlist). Reject `--url` unless it matches `FINTS_INSTITUTES_URL` / allowed hosts. Never fetch `http://` or link-local/metadata IPs.

### S-5 · ✅ Fixed · Medium

- **Location:** `src/Banking/FinTs/Support/EndpointValidator.php:51-64`
- **Description:** Private-host detection uses `gethostbyname()` (one A record) at validation time. DNS can later resolve to a different address (rebinding). Localhost aliases beyond `localhost`/`127.0.0.1`/`::1` depend on that lookup. Skill-known gap vs `gethostbynamel()` + explicit localhost blocklist.
- **Attack scenario:** A user with `manage_bank_connections` sets `endpoint_url` to a name that is public at save time and later points at an internal FinTS/HTTP service. Default `https_only` reduces but does not remove this.
- **Recommended fix:** Resolve all A/AAAA records (`gethostbynamel` / `dns_get_record`), reject any private/reserved hit, pin or re-validate immediately before connect. Document residual DNS rebinding as operator TLS/network policy.

### S-6 · ✅ Fixed · Medium

- **Location:** `src/Export/GenericJournalCsvExporter.php:46-67`, `src/Catalog/ImportExport/CatalogSerializer.php:217-220`
- **Description:** CSV cells are written with `fputcsv` and no formula prefix. Journal descriptions and catalog names/descriptions are attacker- or user-controlled. XLSX catalog export uses `DataType::TYPE_STRING` (better).
- **Attack scenario:** An invoice line description `=HYPERLINK("https://evil/","x")` is posted, then an operator opens the journal CSV in Excel. The formula runs in the auditor’s spreadsheet context.
- **Recommended fix:** Prefix cells that start with `=`, `+`, `-`, `@`, tab, or CR with `'`. Same for catalog CSV. Add a regression with a leading-`=` description.

### S-7 · ✅ Fixed · Medium

- **Location:** `src/Banking/FinTs/Support/SerializedFintsPayload.php:9-18`, `src/Banking/FinTs/Services/PhpFintsClient.php:201-205`
- **Description:** SCA/dialog resume `unserialize`s persisted FinTS payloads. A regex allowlist runs first, then `unserialize(..., ['allowed_classes' => true])` **enables all classes**. `hasOpenDialog()` unserializes with `allowed_classes => true` and no allowlist. Payloads are stored with Laravel `encrypted` casts (needs `APP_KEY` to forge).
- **Attack scenario:** Privileged DB write plus `APP_KEY`, or a gadget in an allowed `Fhp\` class, turns stored state into object injection. Defense in depth is the allowlist; `allowed_classes => true` undoes it at the PHP API.
- **Recommended fix:** `unserialize` with an explicit class list (or `allowed_classes => false` after reconstructing via a safe format). Never call `unserialize` on persist() only to detect an open dialog — inspect structured state instead.

### S-8 · ✅ Fixed · Medium (logic / GoBD F9)

- **Location:** `src/Banking/FinTs/Services/TransferService.php:39,105`, `DirectDebitService.php:41,99`, `StrongAuthenticationCoordinator.php:656,690`
- **Description:** Remaining `DB::transaction()` on the **default** connection while models use `UsesPackageConnection`. Invoice/reconciliation paths were moved to the accounting connection; payment/SCA claim+finalize were not. With `ACCOUNTING_DB_CONNECTION` set, a crash can commit payment status on one connection and roll back the other (`docs/gobd.md` F9, `docs/install.md` warns not to set a separate connection yet).
- **Attack scenario / failure mode:** Duplicate or dropped SEPA submissions after partial commit; ambiguous `Initiating` rows. Not a remote exploit; it is a correctness/integrity bug on a supported config flag.
- **Recommended fix:** `$model->getConnection()->transaction()` everywhere money or SCA state is claimed. Connection-isolation tests as for reconciliation.

### S-9 · ✅ Fixed · Medium

- **Location:** `src/Livewire/ReconciliationAssistant.php` (no `canAccess`), `src/Services/SuggestReconciliationMatches.php:20-24`, `src/FilamentAccountingServiceProvider.php:129-131`
- **Description:** The Livewire assistant is registered globally. `mount`/`finalize` do not call `AccountingAuthorizer`. Suggestions only `assertSame` the line’s entity. `finalize` eventually hits `FinalizeReconciliation` which does authorize. Direct Livewire requests can still load statement-line details and suggestions for any UUID in the current entity if the user is authenticated enough to hit Livewire.
- **Attack scenario:** A panel user without `draft_reconciliation` / `finalize_reconciliation` invokes `filament-accounting.reconciliation-assistant` with a known line UUID and reads counterparty/purpose/amount. Posting should still 403 via the service.
- **Recommended fix:** Authorize in `mount` and `finalize` (`draft_reconciliation` / `finalize_reconciliation`). Hide the component from users who fail `can()`. Tests for unauthorized Livewire calls.

### S-10 · ✅ Fixed · Medium (logic)

- **Location:** `src/Filament/Resources/SalesInvoiceResource.php:96-98`
- **Description:** List/view of sales invoices uses ability `create_draft_invoices`, not a view ability. Users granted only `issue_invoices` or a future view-only Gate cannot list invoices; users who may only draft can see all issued invoices.
- **Attack scenario:** Least-privilege host setup from `docs/install.md` cannot express “issue but not create” or “view sales without drafting”.
- **Recommended fix:** `canViewAny` → `view` (or a dedicated `view_invoices`); keep create/edit on `create_draft_invoices`.

---

## Low

### S-11 · ✅ Fixed · Low

- **Location:** `src/Support/RichText.php:7-17`, `resources/views/documents/invoice.blade.php:268`
- **Description:** Invoice HTML uses `{!! $line['description'] !!}` after `RichText::sanitize()` (allowlist + attribute strip). Tests cover `<script>` and `file://` images. Residual risk is sanitizer bypass (mutated tags, unquoted attributes PHP `strip_tags` leaves). Dompdf has remote/PHP/JS disabled, so impact is mostly PDF content spoofing, not browser XSS in the panel.
- **Recommended fix:** Keep sanitizing at persist; consider `e()` plus a tiny safe subset, or a real HTML purifier. Do not pass unsanitized RichEditor output to `{!! !!}`.

### S-12 · ⚠️ Accepted · Low

- **Location:** `src/Services/SuggestReconciliationMatches.php` (no authorizer); `src/Services/StoreAttachment.php`, `src/Services/CreateAuditAnchor.php` (trusted callers)
- **Description:** Several services rely entirely on callers for authorization. Fine for internal use; unsafe if a host wires them to a public job/controller.
- **Recommended fix:** Document required abilities on each public `handle()`; add authorize() on remaining public mutation services (F3 remainder).

### S-13 · ✅ Fixed · Low

- **Location:** `src/Models/LedgerAccount.php:58-74`
- **Description:** Role-assigned accounts are identity-locked; `code` is locked once journal lines exist; `name` / `type` / `normal_balance` can still change. Historical CSV uses snapshots (good); UI/master data can still rewrite meaning (GoBD F8).
- **Recommended fix:** Treat used accounts as immutable except `is_active` / `valid_to`, or version them like tax rules.

### S-14 · ⚠️ Accepted · Low

- **Location:** package HTTP routes and Filament banking actions
- **Description:** No package-level rate limiters on SCA confirm, institute sync, audit export, or payment submit. Host may add them; the package does not.
- **Recommended fix:** Named limiters (per user + entity) on SCA and export; document in operations.

### S-15 · ⚠️ Accepted · Low

- **Location:** `composer.json` `nemiah/php-fints: dev-master`; `config/filament-accounting.php` default institutes URL
- **Description:** Already in GoBD operating requirements: lockfile must be retained; `dev-master` is a supply-chain moving target. Institute file contents become bank endpoints.
- **Recommended fix:** Pin a tagged php-fints release before any production claim. Pin/hash the institute directory.

### S-16 · ✅ Fixed · Low

- **Location:** `src/Documents/UblEInvoiceParser.php:48` (`(float) $percent`)
- **Description:** Tax percent from XML uses float then `round` to basis points. Conflicts with architecture (“never floats”) and leftover F6/F7 tax-edge work.
- **Recommended fix:** `ExactMoney` / `BigDecimal` parsing; reject unparseable percents.

---

## Verified OK (this pass)

| Area | Evidence | Notes |
| --- | --- | --- |
| Undefined Gates | `DefaultAccountingAuthorizer` returns false | Hosts must still define Gates; tests use permissive fixtures |
| SCA challenge HTTP | `routes/fints.php` `web`+`auth`; owner-scoped UUID; MIME allowlist; CSP sandbox; `no-store` | Confirm Gate still required |
| FinTS secrets at rest | `BankConnection` / SCA session `encrypted` casts; PIN not shown on edit | |
| FinTS endpoint policy | HTTPS-only default; credentials in URL rejected; private hosts rejected unless env | See S-5 |
| XML / XXE | `LIBXML_NONET`; DOCTYPE rejected on UBL/intake | |
| Command execution | No `shell_exec` / `exec` / `passthru` in `src/` | `PDO::exec` only on internal SQLite inspection DDL |
| Raw SQL | `whereRaw` with bound `?`; static `1=0`; identifier allowlist in importer | |
| Attachments | Private disk required; UUID paths; hash verify on read | Orphans still operator (F11) |
| Party IDOR on invoices | `IssueSalesInvoice::party()` scopes `legal_entity_id` + `is_customer` | |
| Dataset inspection SQL | Parameterized inserts; table names from schema constants | |
| PDF renderer | Dompdf remote/PHP/JS off; logo MIME allowlist | |
| Composer advisories | `composer audit` clean | Does not cover app code |
| Ledger posting | Balance, period hard-close, idempotency, accounting connection | F6 tax/FX still open |
| Reconciliation finalize | Authorizer + entity lock + accounting connection | |

---

## Open GoBD items (not re-litigated)

From `docs/gobd.md`: F1–F12 remain not fully closed. Highest remaining engineering overlap with this review: **F3** (authorization of all public paths — S-2, S-3, S-9), **F9** (FinTS `DB::transaction` — S-8), **F8** (master-data history — S-13), **F12** (sync range clipping).

---

## Fix log

Branch `security/s1-s10-prod-hardening` (11 September 2026):

- S-1: `web`+`auth` on audit export; isolate by LegalEntity key; authorize before stream; 404 on mismatch. Tests: guest 401, no export Gate 403, authorized reaches exporter.
- S-2/S-3/S-10: FinTS resources use `HasAccountingNavigation` with view/create abilities; institute sync actions require `manage_bank_connections`; sales invoices list with `view` or draft. `AuthorizationException` extends Laravel’s 403 type.
- S-4/S-5: institute directory download uses `EndpointValidator`; private-host check uses all A/AAAA records.
- S-6: journal CSV prefixes formula-like cells.
- S-7: FinTS unserialize allowlist is the `unserialize()` class list; dialog detect uses `allowed_classes => false`.
- S-8: Transfer/direct-debit/SCA transactions use the model connection.
- S-9: reconciliation Livewire authorizes `draft_reconciliation` / `finalize_reconciliation`.

Quality gate: **458 tests, 3449 assertions** (22 MySQL skips), PHPStan 0 errors, Pint dirty clean.

Follow-up on the same branch:

- S-11: `RichText::sanitize()` strips `script`/`style` element bodies before the tag allowlist.
- S-13: ledger accounts used in journal lines cannot change code, name, type, or normal balance; `is_active` remains editable.
- S-16: UBL tax percent uses exact decimal conversion to basis points (no float).
- S-12 accepted: remaining services without Gates (`StoreAttachment`, `CreateAuditAnchor`) are internal/console helpers on already-authorized or operator-trusted paths. `SuggestReconciliationMatches` is no longer in this group: it now self-authorizes `draft_reconciliation` (see the F3 follow-up).
- S-14 accepted: no additional unauthenticated HTTP surface in the package; HTTP rate limits belong to the host.
- S-15 accepted: `nemiah/php-fints:dev-master` is a documented package constraint; the host lockfile is the pin. No tagged release was required for package completeness.

Quality gate for this follow-up: **460 tests, 3457 assertions** (22 MySQL skips), PHPStan 0 errors, Pint dirty clean.

F3 authorization follow-up (12 September 2026, F3): the payment submission
services carried only entity scope and Filament resource Gates. `TransferService`
now authorizes `accounting.bank.transfer.create` and `DirectDebitService`
authorizes `accounting.bank.direct-debit.create` at the service entry point,
before any claim; `SuggestReconciliationMatches` authorizes
`accounting.reconciliation.draft`. New [PaymentAuthorizationTest](../tests/Banking/FinTs/PaymentAuthorizationTest.php)
denies missing Gates before any state change or bank call and confirms an
authorized transfer proceeds to submission. Thin delegates to an already
authorized service (`AssignStatementLine`, `SplitStatementLine` → finalize) are
unchanged. `StoreAttachment`, `CreateAuditAnchor`, `CreateOpenItem`, `Seed*`,
and verify helpers remain operator/internal and documented as accepted.

GoBD F1–F12 remain a separate compliance track, not host-config debt. Catalog CSV is bidirectional and is not formula-prefixed; XLSX already uses `TYPE_STRING`.

<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Contracts\InvoiceRenderer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Exceptions\InvalidMoneyException;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Support\DecimalInput;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Tax\SalesTaxSuggestionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class IssueSalesInvoice
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly AllocateDocumentNumber $numbers,
        private readonly PostDocument $poster,
        private readonly AuditLogger $audit,
        private readonly ResolveTaxRuleVersion $taxRules,
        private readonly SalesTaxSuggestionService $taxSuggestions,
        private readonly GenerateInvoiceArtifacts $artifacts,
        private readonly LegalEntityScope $entities,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(LegalEntity $entity, array $payload, bool $post = true): Document
    {
        $draft = $this->createDraft($entity, $payload);

        return $this->issue($draft, $post);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(LegalEntity $entity, array $payload): Document
    {
        $this->authorizer->authorize('create_draft_invoices', $entity);

        return $entity->getConnection()->transaction(function () use ($entity, $payload): Document {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            if (filled($payload['idempotency_key'] ?? null)) {
                $existing = Document::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->first();

                if ($existing instanceof Document) {
                    return $existing;
                }
            }

            $party = $this->party($entity, $payload['party_id'] ?? null);
            $type = $this->salesType($payload['type'] ?? null);
            $currency = strtoupper((string) ($payload['currency'] ?? $entity->base_currency));
            $this->assertBaseCurrency($entity, $currency);
            $issueDate = (string) ($payload['issue_date'] ?? now()->toDateString());
            $taxDate = (string) ($payload['supply_date'] ?? $issueDate);
            $paymentTerms = (int) ($payload['payment_terms_days'] ?? $party->payment_terms_days ?? 7);
            $actor = $this->actors->resolve();

            $document = new Document;
            $document->fill([
                'legal_entity_id' => $entity->getKey(),
                'type' => $type,
                'direction' => $type->direction(),
                'document_status' => DocumentStatus::Draft,
                'posting_status' => PostingStatus::Unposted,
                'party_id' => $party->getKey(),
                'party_snapshot' => null,
                'issue_date' => $issueDate,
                'supply_date' => $taxDate,
                'due_date' => array_key_exists('due_date', $payload) ? $payload['due_date'] : Carbon::parse($issueDate)->addDays($paymentTerms)->toDateString(),
                'payment_terms_days' => $paymentTerms,
                'currency' => $currency,
                'exchange_rate' => $payload['exchange_rate'] ?? '1',
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'created_by_type' => $actor?->getMorphClass(),
                'created_by_id' => $actor ? (string) $actor->getKey() : null,
            ]);
            $document->save();
            $document->fill($this->writeLines($entity, $party, $document, $payload['lines'] ?? [], $taxDate, $currency));
            $document->save();

            $this->audit->log($entity, 'document.draft_created', $document, [
                'type' => $document->type->value,
            ]);

            return $document->fresh(['lines']) ?? $document;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(Document $document, array $payload): Document
    {
        $this->entities->assertModel($document);
        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $this->authorizer->authorize('create_draft_invoices', $document);

        return $entity->getConnection()->transaction(function () use ($document, $entity, $payload): Document {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $document = Document::query()->lockForUpdate()->whereKey($document->getKey())->firstOrFail();

            if ($document->document_status !== DocumentStatus::Draft) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_editable'));
            }

            $party = $this->party($entity, $payload['party_id'] ?? $document->party_id);
            $currency = strtoupper((string) ($payload['currency'] ?? $document->currency));
            $this->assertBaseCurrency($entity, $currency);
            $issueDate = (string) ($payload['issue_date'] ?? $document->issue_date?->toDateString());
            $taxDate = (string) ($payload['supply_date'] ?? $issueDate);

            $document->fill([
                'party_id' => $party->getKey(),
                'issue_date' => $issueDate,
                'supply_date' => $taxDate,
                'due_date' => $payload['due_date'] ?? null,
                'payment_terms_days' => $payload['payment_terms_days'] ?? $party->payment_terms_days,
                'currency' => $currency,
                'exchange_rate' => $payload['exchange_rate'] ?? $document->exchange_rate,
            ]);
            $document->save();
            $document->lines()->delete();
            $document->fill($this->writeLines($entity, $party, $document, $payload['lines'] ?? [], $taxDate, $currency));
            $document->save();

            return $document->fresh(['lines']) ?? $document;
        });
    }

    /** @param array<string, mixed> $payload */
    public function correct(Document $original, array $payload, string $reason): Document
    {
        $this->entities->assertModel($original);
        $this->authorizer->authorize('issue_invoices', $original);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DocumentException(__('filament-accounting::errors.reason_required'));
        }

        return $original->getConnection()->transaction(function () use ($original, $payload, $reason): Document {
            $entity = LegalEntity::query()->lockForUpdate()->findOrFail($original->legal_entity_id);
            $original = Document::query()->lockForUpdate()->findOrFail($original->getKey());
            $this->entities->assertModel($original);
            if ($original->type !== DocumentType::SalesInvoice || $original->document_status !== DocumentStatus::Issued) {
                throw new DocumentException(__('filament-accounting::errors.only_issued_sales_invoice_exportable'));
            }
            if ($original->settlements()->exists()) {
                throw new DocumentException(__('filament-accounting::errors.invoice_correction_has_settlements'));
            }
            if (Document::query()->where('corrected_document_id', $original->getKey())->exists()) {
                throw new DocumentException(__('filament-accounting::errors.invoice_correction_exists'));
            }
            $draft = $this->createDraft($entity, array_intersect_key($payload, array_flip([
                'party_id', 'issue_date', 'supply_date', 'due_date', 'currency', 'lines',
            ])));
            $draft->corrected_document_id = $original->getKey();
            $draft->invoice_version = $original->invoice_version + 1;
            $draft->number = $original->number;
            $draft->e_invoice_meta = ['correction_reason' => $reason];
            $draft->save();
            $this->audit->log($entity, 'document.correction_created', $draft, [
                'original_document_id' => $original->getKey(),
                'original_number' => $original->number,
                'previous_version' => $original->invoice_version,
                'invoice_version' => $draft->invoice_version,
                'before' => $original->load('lines')->toArray(),
                'after' => $draft->load('lines')->toArray(),
            ], $reason);

            return $draft;
        });
    }

    public function deleteDraft(Document $document): bool
    {
        $this->entities->assertModel($document);
        $this->authorizer->authorize('create_draft_invoices', $document);

        return $document->getConnection()->transaction(function () use ($document): bool {
            $entity = LegalEntity::query()->lockForUpdate()->findOrFail($document->legal_entity_id);
            $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
            $this->entities->assertModel($document);
            if ($document->type !== DocumentType::SalesInvoice || $document->document_status !== DocumentStatus::Draft
                || $document->posting_status !== PostingStatus::Unposted) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_editable'));
            }
            $this->audit->log($entity, 'document.draft_deleted', $document, ['before' => $document->load('lines')->toArray()]);
            $document->lines()->delete();

            return (bool) $document->delete();
        });
    }

    public function issue(Document $document, bool $post = true): Document
    {
        $entity = $this->entities->require();
        $this->entities->assertSame($document->legal_entity_id, $entity);
        $this->authorizer->authorize('issue_invoices', $entity);
        $document = Document::query()->where('legal_entity_id', $entity->getKey())->findOrFail($document->getKey());
        $needsArtifacts = (bool) ($document->document_status === DocumentStatus::Draft
            ? config('filament-accounting.e_invoice.generate_on_issue', true)
            : data_get($document->e_invoice_meta, 'artifacts_required', config('filament-accounting.e_invoice.generate_on_issue', true)))
            || $document->artifactSet()->exists();
        if ($needsArtifacts && $entity->getConnection()->transactionLevel() !== 0) {
            throw new DocumentException(__('filament-accounting::errors.artifacts_require_independent_commit'));
        }

        $document = $entity->getConnection()->transaction(function () use ($document, $entity, $needsArtifacts): Document {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $document = Document::query()->lockForUpdate()->with(['lines', 'party'])->whereKey($document->getKey())->firstOrFail();

            if ($document->document_status === DocumentStatus::Issued) {
                return $document;
            }

            if ($document->document_status !== DocumentStatus::Draft
                || ! in_array($document->type, [DocumentType::SalesInvoice, DocumentType::SalesCreditNote], true)) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_issuable'));
            }

            if ($document->lines->isEmpty()) {
                throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
            }

            $party = $this->party($entity, $document->party_id);
            if ($document->corrected_document_id !== null) {
                $original = Document::query()->where('legal_entity_id', $entity->getKey())->findOrFail($document->corrected_document_id);
                if ($original->settlements()->exists()) {
                    throw new DocumentException(__('filament-accounting::errors.invoice_correction_has_settlements'));
                }
            }
            $actor = $this->actors->resolve();
            $issueDate = $document->issue_date?->toDateString() ?? now()->toDateString();

            $document->party_snapshot = $party->snapshot();
            $document->legal_entity_snapshot = $entity->invoiceSnapshot();
            if ($document->corrected_document_id !== null) {
                if ($document->number !== $original->number || $document->invoice_version !== $original->invoice_version + 1) {
                    throw new DocumentException(__('filament-accounting::errors.invoice_version_invalid'));
                }
            } else {
                $document->number = $this->numbers->next($entity, $document->type, $issueDate);
            }
            $document->document_status = DocumentStatus::Issued;
            $document->issued_by_type = $actor?->getMorphClass();
            $document->issued_by_id = $actor ? (string) $actor->getKey() : null;
            $document->issued_at = now();
            $document->e_invoice_meta = array_merge($document->e_invoice_meta ?? [], ['artifacts_required' => $needsArtifacts]);
            $document->save();

            $this->audit->log($entity, 'document.issued', $document, [
                'number' => $document->number,
                'type' => $document->type->value,
                'invoice_version' => $document->invoice_version,
            ]);

            return $document->fresh(['lines', 'openItem']) ?? $document;
        });

        if (data_get($document->e_invoice_meta, 'artifacts_required', $needsArtifacts) || $document->artifactSet()->exists()) {
            $this->artifacts->handle($document);
        }

        return $post ? $this->poster->handle($document) : $document;
    }

    /** @param array<string, mixed> $payload */
    public function preview(array $payload): string
    {
        $entity = $this->entities->require();
        $this->authorizer->authorize('create_draft_invoices', $entity);
        $party = $this->party($entity, $payload['party_id'] ?? null);
        $currency = strtoupper((string) ($payload['currency'] ?? $entity->base_currency));
        $this->assertBaseCurrency($entity, $currency);
        $document = new Document([
            'number' => __('filament-accounting::fields.invoice_preview'),
            'issue_date' => $payload['issue_date'] ?? now()->toDateString(),
            'supply_date' => $payload['supply_date'] ?? $payload['issue_date'] ?? now()->toDateString(),
            'due_date' => $payload['due_date'] ?? null,
            'currency' => $currency,
            'party_snapshot' => $party->snapshot(),
            'legal_entity_snapshot' => $entity->invoiceSnapshot(),
        ]);
        $document->setRelation('lines', new Collection);
        $document->fill($this->writeLines($entity, $party, $document, $payload['lines'] ?? [], $document->supply_date->toDateString(), $currency, persist: false));

        return app(InvoiceRenderer::class)->render($this->artifacts->snapshot($document));
    }

    private function salesType(mixed $type): DocumentType
    {
        if ($type === null || $type === '') {
            return DocumentType::SalesInvoice;
        }

        $resolved = $type instanceof DocumentType ? $type : DocumentType::tryFrom((string) $type);
        if (! $resolved instanceof DocumentType
            || ! in_array($resolved, [DocumentType::SalesInvoice, DocumentType::SalesCreditNote], true)) {
            throw new DocumentException(__('filament-accounting::errors.unsupported_document_type'));
        }

        return $resolved;
    }

    private function assertBaseCurrency(LegalEntity $entity, string $currency): void
    {
        if (strtoupper($currency) !== strtoupper((string) $entity->base_currency)) {
            throw new DocumentException(__('filament-accounting::errors.foreign_currency_unsupported'));
        }
    }

    private function party(LegalEntity $entity, mixed $partyId): Party
    {
        $party = Party::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_customer', true)
            ->whereKey($partyId ?? 0)
            ->first();

        if (! $party instanceof Party) {
            throw new DocumentException(__('filament-accounting::errors.party_not_found'));
        }

        return $party;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{net_minor: int, tax_minor: int, gross_minor: int}
     */
    private function writeLines(LegalEntity $entity, Party $party, Document $document, array $lines, string $date, string $currency, bool $persist = true): array
    {
        if ($lines === []) {
            throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
        }

        $net = 0;
        $tax = 0;
        $position = 1;

        foreach ($lines as $input) {
            $catalog = null;
            if (! empty($input['catalog_item_id'])) {
                $catalog = CatalogItem::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->whereKey($input['catalog_item_id'])
                    ->first();
            }

            $quantity = DecimalInput::normalize((string) ($input['quantity'] ?? ($catalog instanceof CatalogItem ? $catalog->default_quantity : '1')));
            $unitPrice = array_key_exists('unit_price_minor', $input)
                ? (int) $input['unit_price_minor']
                : (array_key_exists('unit_price', $input)
                    ? ExactMoney::ofString(DecimalInput::normalize((string) $input['unit_price']), $currency)->minorAmount
                    : ($catalog instanceof CatalogItem ? $catalog->default_unit_price_minor : 0));
            $lineNet = LineMoneyCalculator::netMinor($quantity, $unitPrice);
            try {
                $lineNet = LineMoneyCalculator::netAfterDiscount(
                    $lineNet,
                    array_key_exists('discount', $input) ? (string) $input['discount'] : null,
                    $currency,
                );
            } catch (InvalidMoneyException $e) {
                throw new DocumentException(__('filament-accounting::errors.invalid_line_discount'), 0, $e);
            }
            $suggestion = $catalog instanceof CatalogItem
                ? $this->taxSuggestions->suggest($entity, $party, $catalog->type, $date, $catalog->default_tax_code)
                : null;
            $taxCodeValue = $input['tax_code'] ?? null;
            if ($taxCodeValue === null && $suggestion !== null) {
                $taxCodeValue = $suggestion->taxCode;
            }
            if ($suggestion?->requiresConfirmation && ! ($input['tax_confirmed'] ?? false)) {
                throw new DocumentException(__('filament-accounting::errors.tax_suggestion_confirmation_required'));
            }
            $version = $this->taxRules->handle($entity, $taxCodeValue, $date);
            $rateBp = (int) $version->rate_bp;

            $lineTax = LineMoneyCalculator::taxMinor($lineNet, $rateBp);

            $line = new DocumentLine;
            $line->fill([
                'document_id' => $document->getKey(),
                'position' => $position++,
                'description' => (string) ($input['description'] ?? ($catalog instanceof CatalogItem ? $catalog->name : '')),
                'quantity' => $quantity,
                'unit' => $input['unit'] ?? $catalog?->unit,
                'unit_price_minor' => $unitPrice,
                'discount' => $input['discount'] ?? null,
                'net_minor' => $lineNet,
                'tax_code' => $taxCodeValue,
                'tax_rule_version_id' => $version->getKey(),
                'tax_rate_bp' => $rateBp,
                'tax_category' => $version->category,
                'tax_reason' => $version->reason,
                'tax_recoverable' => $version->recoverable,
                'tax_export_mapping' => $version->export_mapping,
                'tax_minor' => $lineTax,
                'gross_minor' => $lineNet + $lineTax,
                'account_role' => $input['account_role'] ?? $catalog?->default_account_role,
                'ledger_account_id' => $input['ledger_account_id'] ?? null,
                'catalog_item_id' => $catalog?->getKey(),
                'service_from' => $input['service_from'] ?? null,
                'service_to' => $input['service_to'] ?? null,
            ]);
            if ($persist) {
                $line->save();
            } else {
                $document->lines->push($line);
            }

            $net += $lineNet;
            $tax += $lineTax;
        }

        return [
            'net_minor' => $net,
            'tax_minor' => $tax,
            'gross_minor' => $net + $tax,
        ];
    }
}

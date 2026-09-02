<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\LineMoneyCalculator;
use Illuminate\Support\Facades\DB;

final class RegisterPurchaseInvoice
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly AllocateDocumentNumber $numbers,
        private readonly PostDocument $poster,
        private readonly AuditLogger $audit,
        private readonly ResolveTaxRuleVersion $taxRules,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(LegalEntity $entity, array $payload, bool $post = true): Document
    {
        $draft = $this->createDraft($entity, $payload);

        return $this->receive($draft, $post);
    }

    /** @param array<string, mixed> $payload */
    public function createDraft(LegalEntity $entity, array $payload = []): Document
    {
        $this->authorizer->authorize('register_purchase_invoices', $entity);

        return DB::transaction(function () use ($entity, $payload): Document {
            if (filled($payload['idempotency_key'] ?? null)) {
                $existing = Document::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->first();
                if ($existing instanceof Document) {
                    return $existing;
                }
            }

            $party = $this->party($entity, $payload['party_id'] ?? null, false);
            $currency = strtoupper((string) ($payload['currency'] ?? $entity->base_currency));
            $issueDate = filled($payload['issue_date'] ?? null) ? (string) $payload['issue_date'] : null;
            $actor = $this->actors->resolve();

            $document = new Document;
            $document->fill([
                'legal_entity_id' => $entity->getKey(),
                'type' => DocumentType::PurchaseInvoice,
                'direction' => DocumentDirection::Incoming,
                'supplier_invoice_number' => $payload['supplier_invoice_number'] ?? null,
                'document_status' => DocumentStatus::Draft,
                'posting_status' => PostingStatus::Unposted,
                'party_id' => $party?->getKey(),
                'party_snapshot' => null,
                'issue_date' => $issueDate,
                'receipt_date' => $payload['receipt_date'] ?? now()->toDateString(),
                'supply_date' => $payload['supply_date'] ?? $issueDate,
                'due_date' => $payload['due_date'] ?? null,
                'payment_terms_days' => $payload['payment_terms_days'] ?? $party?->payment_terms_days,
                'currency' => $currency,
                'exchange_rate' => $payload['exchange_rate'] ?? '1',
                'e_invoice_meta' => $payload['e_invoice_meta'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'created_by_type' => $actor?->getMorphClass(),
                'created_by_id' => $actor ? (string) $actor->getKey() : null,
            ]);
            $document->save();

            $lines = $payload['lines'] ?? [];
            $totals = $lines === []
                ? ['net_minor' => 0, 'tax_minor' => 0, 'gross_minor' => 0]
                : $this->writeLines($entity, $document, $lines, $issueDate ?? now()->toDateString(), $currency);
            $document->fill($totals);
            $document->save();

            $this->audit->log($entity, 'document.purchase_draft_created', $document, [
                'structured' => (bool) data_get($payload, 'e_invoice_meta.structured', false),
            ]);

            return $document->fresh(['lines', 'attachments']) ?? $document;
        });
    }

    /** @param array<string, mixed> $payload */
    public function updateDraft(Document $document, array $payload): Document
    {
        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $this->authorizer->authorize('register_purchase_invoices', $document);

        return DB::transaction(function () use ($document, $entity, $payload): Document {
            $document = Document::query()->lockForUpdate()->whereKey($document->getKey())->firstOrFail();
            if ($document->document_status !== DocumentStatus::Draft || $document->type !== DocumentType::PurchaseInvoice) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_editable'));
            }

            $party = $this->party($entity, $payload['party_id'] ?? $document->party_id);
            $currency = strtoupper((string) ($payload['currency'] ?? $document->currency));
            $issueDate = (string) ($payload['issue_date'] ?? $document->issue_date?->toDateString() ?? now()->toDateString());
            $document->fill([
                'party_id' => $party->getKey(),
                'supplier_invoice_number' => $payload['supplier_invoice_number'] ?? null,
                'issue_date' => $issueDate,
                'receipt_date' => $payload['receipt_date'] ?? $document->receipt_date?->toDateString(),
                'supply_date' => $payload['supply_date'] ?? $issueDate,
                'due_date' => $payload['due_date'] ?? null,
                'payment_terms_days' => $payload['payment_terms_days'] ?? $party->payment_terms_days,
                'currency' => $currency,
                'exchange_rate' => $payload['exchange_rate'] ?? $document->exchange_rate,
            ]);
            $document->save();
            $document->lines()->delete();
            $taxDate = (string) ($document->supply_date?->toDateString() ?? $issueDate);
            $document->fill($this->writeLines($entity, $document, $payload['lines'] ?? [], $taxDate, $currency));
            $document->save();

            return $document->fresh(['lines', 'attachments']) ?? $document;
        });
    }

    public function receive(Document $document, bool $post = true): Document
    {
        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $this->authorizer->authorize('register_purchase_invoices', $document);

        $document = DB::transaction(function () use ($document, $entity): Document {
            $document = Document::query()->lockForUpdate()->with(['lines', 'party'])->whereKey($document->getKey())->firstOrFail();
            if ($document->document_status === DocumentStatus::Received) {
                return $document;
            }
            if ($document->document_status !== DocumentStatus::Draft || $document->type !== DocumentType::PurchaseInvoice) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_issuable'));
            }
            if ($document->lines->isEmpty()) {
                throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
            }

            $party = $this->party($entity, $document->party_id);
            $this->assertClassified($document);
            if (filled($document->supplier_invoice_number)) {
                $duplicate = Document::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('party_id', $party->getKey())
                    ->where('supplier_invoice_number', $document->supplier_invoice_number)
                    ->whereKeyNot($document->getKey())
                    ->exists();
                if ($duplicate) {
                    throw new DocumentException(__('filament-accounting::errors.duplicate_supplier_invoice'));
                }
            }

            $actor = $this->actors->resolve();
            $issueDate = $document->issue_date?->toDateString() ?? now()->toDateString();
            $document->party_snapshot = $party->snapshot();
            $document->legal_entity_snapshot = $entity->invoiceSnapshot();
            $document->number = $this->numbers->next($entity, DocumentType::PurchaseInvoice, $issueDate);
            $document->document_status = DocumentStatus::Received;
            $document->issued_by_type = $actor?->getMorphClass();
            $document->issued_by_id = $actor ? (string) $actor->getKey() : null;
            $document->issued_at = now();
            $document->save();

            $this->audit->log($entity, 'document.received', $document, [
                'number' => $document->number,
                'supplier_invoice_number' => $document->supplier_invoice_number,
            ]);

            return $document->fresh(['lines', 'openItem', 'attachments']) ?? $document;
        });

        return $post ? $this->poster->handle($document) : $document;
    }

    private function party(LegalEntity $entity, mixed $partyId, bool $required = true): ?Party
    {
        if (! filled($partyId) && ! $required) {
            return null;
        }

        $party = Party::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->whereKey($partyId ?? 0)
            ->first();
        if (! $party instanceof Party) {
            throw new DocumentException(__('filament-accounting::errors.party_not_found'));
        }

        return $party;
    }

    private function assertClassified(Document $document): void
    {
        foreach ($document->lines as $line) {
            if (! filled($line->classification_code) || (! filled($line->account_role) && ! filled($line->ledger_account_id))) {
                throw new DocumentException(__('filament-accounting::errors.purchase_classification_required'));
            }
            if (! $line->classification_confirmed || ! $line->tax_confirmed) {
                throw new DocumentException(__('filament-accounting::errors.purchase_classification_confirmation_required'));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{net_minor: int, tax_minor: int, gross_minor: int}
     */
    private function writeLines(LegalEntity $entity, Document $document, array $lines, string $date, string $currency): array
    {
        if ($lines === []) {
            throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
        }

        $net = 0;
        $tax = 0;
        foreach ($lines as $offset => $input) {
            $quantity = (string) ($input['quantity'] ?? '1');
            $unitPrice = array_key_exists('unit_price_minor', $input)
                ? (int) $input['unit_price_minor']
                : ExactMoney::ofString((string) ($input['unit_price'] ?? '0'), $currency)->minorAmount;
            $lineNet = LineMoneyCalculator::netMinor($quantity, $unitPrice);
            $taxCodeValue = $input['tax_code'] ?? null;
            $version = filled($taxCodeValue) ? $this->taxRules->handle($entity, $taxCodeValue, $date) : null;
            if (! $version && ! array_key_exists('imported_tax_rate_bp', $input)) {
                $this->taxRules->handle($entity, $taxCodeValue, $date);
            }
            $rateBp = $version ? (int) $version->rate_bp : (int) $input['imported_tax_rate_bp'];
            $lineTax = LineMoneyCalculator::taxMinor($lineNet, $rateBp);

            DocumentLine::query()->create([
                'document_id' => $document->getKey(),
                'position' => $offset + 1,
                'description' => (string) ($input['description'] ?? ''),
                'quantity' => $quantity,
                'unit' => $input['unit'] ?? null,
                'unit_price_minor' => $unitPrice,
                'discount' => $input['discount'] ?? null,
                'net_minor' => $lineNet,
                'tax_code' => $taxCodeValue,
                'tax_rule_version_id' => $version?->getKey(),
                'tax_rate_bp' => $rateBp,
                'tax_category' => $version?->category,
                'tax_reason' => $version?->reason,
                'tax_recoverable' => $version?->recoverable,
                'tax_export_mapping' => $version?->export_mapping,
                'tax_minor' => $lineTax,
                'gross_minor' => $lineNet + $lineTax,
                'account_role' => $input['account_role'] ?? null,
                'ledger_account_id' => $input['ledger_account_id'] ?? null,
                'catalog_item_id' => $input['catalog_item_id'] ?? null,
                'classification_code' => $input['classification_code'] ?? null,
                'classification_confirmed' => (bool) ($input['classification_confirmed'] ?? false),
                'tax_confirmed' => (bool) ($input['tax_confirmed'] ?? false),
                'imported_tax_code' => $input['imported_tax_code'] ?? null,
                'service_from' => $input['service_from'] ?? null,
                'service_to' => $input['service_to'] ?? null,
            ]);
            $net += $lineNet;
            $tax += $lineTax;
        }

        return ['net_minor' => $net, 'tax_minor' => $tax, 'gross_minor' => $net + $tax];
    }
}

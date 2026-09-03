<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentDirection;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\DocumentType;
use FilamentAccounting\Enums\PostingStatus;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Support\ExactMoney;
use FilamentAccounting\Support\LineMoneyCalculator;
use FilamentAccounting\Tax\SalesTaxSuggestionService;
use Illuminate\Support\Facades\DB;

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

            $party = $this->party($entity, $payload['party_id'] ?? null);
            $currency = strtoupper((string) ($payload['currency'] ?? $entity->base_currency));
            $issueDate = (string) ($payload['issue_date'] ?? now()->toDateString());
            $taxDate = (string) ($payload['supply_date'] ?? $issueDate);
            $actor = $this->actors->resolve();

            $document = new Document;
            $document->fill([
                'legal_entity_id' => $entity->getKey(),
                'type' => DocumentType::SalesInvoice,
                'direction' => DocumentDirection::Outgoing,
                'document_status' => DocumentStatus::Draft,
                'posting_status' => PostingStatus::Unposted,
                'party_id' => $party->getKey(),
                'party_snapshot' => null,
                'issue_date' => $issueDate,
                'supply_date' => $taxDate,
                'due_date' => $payload['due_date'] ?? null,
                'payment_terms_days' => $payload['payment_terms_days'] ?? $party->payment_terms_days,
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
        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $this->authorizer->authorize('create_draft_invoices', $document);

        return DB::transaction(function () use ($document, $entity, $payload): Document {
            $document = Document::query()->lockForUpdate()->whereKey($document->getKey())->firstOrFail();

            if ($document->document_status !== DocumentStatus::Draft) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_editable'));
            }

            $party = $this->party($entity, $payload['party_id'] ?? $document->party_id);
            $currency = strtoupper((string) ($payload['currency'] ?? $document->currency));
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

    public function issue(Document $document, bool $post = true): Document
    {
        $entity = LegalEntity::query()->findOrFail($document->legal_entity_id);
        $this->authorizer->authorize('issue_invoices', $entity);

        $document = DB::transaction(function () use ($document, $entity): Document {
            $document = Document::query()->lockForUpdate()->with(['lines', 'party'])->whereKey($document->getKey())->firstOrFail();

            if ($document->document_status === DocumentStatus::Issued) {
                return $document;
            }

            if ($document->document_status !== DocumentStatus::Draft || $document->type !== DocumentType::SalesInvoice) {
                throw new DocumentException(__('filament-accounting::errors.only_draft_invoice_issuable'));
            }

            if ($document->lines->isEmpty()) {
                throw new DocumentException(__('filament-accounting::errors.document_needs_lines'));
            }

            $party = $this->party($entity, $document->party_id);
            $actor = $this->actors->resolve();
            $issueDate = $document->issue_date?->toDateString() ?? now()->toDateString();

            $document->party_snapshot = $party->snapshot();
            $document->legal_entity_snapshot = $entity->invoiceSnapshot();
            $document->number = $this->numbers->next($entity, DocumentType::SalesInvoice, $issueDate);
            $document->document_status = DocumentStatus::Issued;
            $document->issued_by_type = $actor?->getMorphClass();
            $document->issued_by_id = $actor ? (string) $actor->getKey() : null;
            $document->issued_at = now();
            $document->save();

            $this->audit->log($entity, 'document.issued', $document, [
                'number' => $document->number,
                'type' => $document->type->value,
            ]);

            return $document->fresh(['lines', 'openItem']) ?? $document;
        });

        if ((bool) config('filament-accounting.e_invoice.generate_on_issue', true)) {
            $this->artifacts->handle($document);
        }

        return $post ? $this->poster->handle($document) : $document;
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
    private function writeLines(LegalEntity $entity, Party $party, Document $document, array $lines, string $date, string $currency): array
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

            $quantity = (string) ($input['quantity'] ?? ($catalog instanceof CatalogItem ? $catalog->default_quantity : '1'));
            $unitPrice = array_key_exists('unit_price_minor', $input)
                ? (int) $input['unit_price_minor']
                : (array_key_exists('unit_price', $input)
                    ? ExactMoney::ofString((string) $input['unit_price'], $currency)->minorAmount
                    : ($catalog instanceof CatalogItem ? $catalog->default_unit_price_minor : 0));
            $lineNet = LineMoneyCalculator::netMinor($quantity, $unitPrice);
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
            $line->save();

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

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
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Models\TaxRuleVersion;
use FilamentAccounting\Support\LineMoneyCalculator;
use Illuminate\Support\Facades\DB;

final class IssueSalesInvoice
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly AllocateDocumentNumber $numbers,
        private readonly PostDocument $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(LegalEntity $entity, array $payload, bool $post = true): Document
    {
        $this->authorizer->authorize('issue_invoices', $entity);

        return DB::transaction(function () use ($entity, $payload, $post): Document {
            $party = Party::query()
                ->where('legal_entity_id', $entity->getKey())
                ->whereKey($payload['party_id'] ?? 0)
                ->first();

            if (! $party instanceof Party) {
                throw new DocumentException(__('filament-accounting::errors.party_not_found'));
            }

            $currency = strtoupper((string) ($payload['currency'] ?? $entity->base_currency));
            $issueDate = (string) ($payload['issue_date'] ?? now()->toDateString());
            $actor = $this->actors->resolve();

            if (filled($payload['idempotency_key'] ?? null)) {
                $existing = Document::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->first();

                if ($existing instanceof Document) {
                    return $existing;
                }
            }

            $document = new Document;
            $document->fill([
                'legal_entity_id' => $entity->getKey(),
                'type' => DocumentType::SalesInvoice,
                'direction' => DocumentDirection::Outgoing,
                'document_status' => DocumentStatus::Draft,
                'posting_status' => PostingStatus::Unposted,
                'party_id' => $party->getKey(),
                'party_snapshot' => $party->snapshot(),
                'issue_date' => $issueDate,
                'supply_date' => $payload['supply_date'] ?? $issueDate,
                'due_date' => $payload['due_date'] ?? null,
                'payment_terms_days' => $payload['payment_terms_days'] ?? $party->payment_terms_days,
                'currency' => $currency,
                'exchange_rate' => $payload['exchange_rate'] ?? '1',
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'created_by_type' => $actor?->getMorphClass(),
                'created_by_id' => $actor ? (string) $actor->getKey() : null,
            ]);
            $document->save();

            $totals = $this->writeLines($entity, $document, $payload['lines'] ?? [], $issueDate);

            $document->fill($totals);
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

            if ($post) {
                return $this->poster->handle($document);
            }

            return $document->fresh(['lines', 'openItem']) ?? $document;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{net_minor: int, tax_minor: int, gross_minor: int}
     */
    private function writeLines(LegalEntity $entity, Document $document, array $lines, string $date): array
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
            $unitPrice = (int) ($input['unit_price_minor'] ?? ($catalog instanceof CatalogItem ? $catalog->default_unit_price_minor : 0));
            $lineNet = LineMoneyCalculator::netMinor($quantity, $unitPrice);
            $taxCodeValue = $input['tax_code'] ?? ($catalog instanceof CatalogItem ? $catalog->default_tax_code : null);
            $rateBp = 0;
            $taxVersionId = null;

            if (filled($taxCodeValue)) {
                $taxCode = TaxCode::query()
                    ->where('legal_entity_id', $entity->getKey())
                    ->where('code', $taxCodeValue)
                    ->first();
                $version = $taxCode instanceof TaxCode ? $taxCode->versionOn($date) : null;
                $rateBp = $version instanceof TaxRuleVersion ? (int) $version->rate_bp : 0;
                $taxVersionId = $version?->getKey();
            }

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
                'tax_rule_version_id' => $taxVersionId,
                'tax_rate_bp' => $rateBp,
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

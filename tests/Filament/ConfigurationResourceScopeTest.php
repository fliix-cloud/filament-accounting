<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Contracts\LedgerEngine;
use FilamentAccounting\Filament\Resources\AuditEventResource;
use FilamentAccounting\Filament\Resources\BankStatementLineResource;
use FilamentAccounting\Filament\Resources\CatalogItemResource;
use FilamentAccounting\Filament\Resources\JournalEntryResource;
use FilamentAccounting\Filament\Resources\LedgerAccountResource;
use FilamentAccounting\Filament\Resources\PostingRuleResource;
use FilamentAccounting\Filament\Resources\TaxCodeResource;
use FilamentAccounting\Ledger\JournalLineDraft;
use FilamentAccounting\Ledger\PostJournalCommand;
use FilamentAccounting\Models\CatalogItem;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigurationResourceScopeTest extends TestCase
{
    #[Test]
    public function chart_tax_posting_rule_and_catalog_resources_only_query_the_current_entity(): void
    {
        $current = $this->makeEntity(['legal_name' => 'Current GmbH']);
        $other = $this->makeEntity(['legal_name' => 'Other GmbH']);
        foreach ([$current, $other] as $index => $entity) {
            CatalogItem::query()->create([
                'legal_entity_id' => $entity->getKey(),
                'sku' => 'SCOPE-'.$index,
                'type' => 'service',
                'name' => 'Scoped item '.$index,
                'unit' => 'unit',
                'default_quantity' => '1',
                'default_unit_price_minor' => 100,
                'currency' => 'EUR',
                'default_tax_code' => 'DE-19',
                'is_active' => true,
            ]);
        }

        app(SingleLegalEntityResolver::class)->bind($current);

        foreach ([
            CatalogItemResource::class,
            LedgerAccountResource::class,
            PostingRuleResource::class,
            TaxCodeResource::class,
        ] as $resource) {
            $entityIds = $resource::getEloquentQuery()
                ->pluck('legal_entity_id')
                ->unique()
                ->values()
                ->all();

            $this->assertSame([$current->getKey()], $entityIds, $resource);
        }
    }

    #[Test]
    public function journal_audit_and_bank_transaction_resources_only_query_the_current_entity(): void
    {
        $current = $this->makeEntity(['legal_name' => 'Current GmbH']);
        $other = $this->makeEntity(['legal_name' => 'Other GmbH']);
        $this->actingAs($this->makeUser());

        foreach ([$current, $other] as $entity) {
            $bank = (int) $entity->ledgerAccounts()->where('code', '1200')->value('id');
            $revenue = (int) $entity->ledgerAccounts()->where('code', '8400')->value('id');
            app(LedgerEngine::class)->post(new PostJournalCommand(
                legalEntityId: (int) $entity->getKey(),
                postedOn: '2026-03-01',
                sourceType: 'manual',
                sourceId: 'scope-'.$entity->getKey(),
                currency: 'EUR',
                baseCurrency: 'EUR',
                lines: [
                    JournalLineDraft::debit($bank, 1, 'EUR'),
                    JournalLineDraft::credit($revenue, 1, 'EUR'),
                ],
            ));
        }

        app(SingleLegalEntityResolver::class)->bind($current);

        foreach ([
            JournalEntryResource::class,
            AuditEventResource::class,
        ] as $resource) {
            $entityIds = $resource::getEloquentQuery()
                ->pluck('legal_entity_id')
                ->unique()
                ->values()
                ->all();

            $this->assertSame([$current->getKey()], $entityIds, $resource);
        }

        $this->assertSame(
            [],
            BankStatementLineResource::getEloquentQuery()->pluck('legal_entity_id')->all(),
        );
    }
}

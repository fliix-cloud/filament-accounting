<?php

namespace FilamentAccounting\Documents;

use FilamentAccounting\Enums\AccountType;
use FilamentAccounting\Enums\NormalBalance;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\LedgerAccount;
use FilamentAccounting\Models\LegalEntity;

final class ExpenseCategoryResolver
{
    /** @var array<string, array{code: string, name: string, type: AccountType}> */
    private const CATEGORIES = [
        'goods' => ['code' => '3400', 'name' => 'Wareneinkauf', 'type' => AccountType::Expense],
        'external_services' => ['code' => '3100', 'name' => 'Fremdleistungen', 'type' => AccountType::Expense],
        'other_operating_expense' => ['code' => '4900', 'name' => 'Sonstige Betriebsausgaben', 'type' => AccountType::Expense],
        'office_supplies' => ['code' => '4930', 'name' => 'Bürobedarf', 'type' => AccountType::Expense],
        'software_it' => ['code' => '4964', 'name' => 'Software und IT', 'type' => AccountType::Expense],
        'rent_utilities' => ['code' => '4210', 'name' => 'Miete und Nebenkosten', 'type' => AccountType::Expense],
        'telecom' => ['code' => '4920', 'name' => 'Telefon und Internet', 'type' => AccountType::Expense],
        'travel' => ['code' => '4660', 'name' => 'Reisekosten', 'type' => AccountType::Expense],
        'insurance' => ['code' => '4360', 'name' => 'Versicherungen', 'type' => AccountType::Expense],
        'bank_fees' => ['code' => '4970', 'name' => 'Bankgebühren', 'type' => AccountType::Expense],
        'personnel' => ['code' => '4100', 'name' => 'Personalkosten', 'type' => AccountType::Expense],
        'suspense' => ['code' => '1360', 'name' => 'Ungeklärter Posten', 'type' => AccountType::Asset],
    ];

    /** @return list<string> */
    public function categoryCodes(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public function seed(LegalEntity $entity): void
    {
        foreach (self::CATEGORIES as $definition) {
            LedgerAccount::query()->firstOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'normal_balance' => NormalBalance::Debit,
                    'currency' => $entity->base_currency,
                    'is_active' => true,
                ],
            );
        }
    }

    public function resolve(LegalEntity $entity, string $category): LedgerAccount
    {
        $definition = self::CATEGORIES[$category] ?? null;
        if ($definition === null) {
            throw new DocumentException(__('filament-accounting::errors.purchase_classification_required'));
        }

        $account = LedgerAccount::query()
            ->where('legal_entity_id', $entity->getKey())
            ->where('code', $definition['code'])
            ->where('is_active', true)
            ->first();

        if (! $account instanceof LedgerAccount) {
            throw new DocumentException(__('filament-accounting::errors.purchase_classification_required'));
        }

        return $account;
    }
}

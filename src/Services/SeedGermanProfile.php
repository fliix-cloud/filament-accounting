<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Enums\AccountRole;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\PostingRuleVersion;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Models\TaxRuleVersion;

final class SeedGermanProfile
{
    public function __construct(
        private readonly SeedChartAndRoles $chart,
    ) {}

    public function handle(LegalEntity $entity): void
    {
        $this->chart->handle($entity);
        $this->taxCodes($entity);
        $this->postingRules($entity);
    }

    private function taxCodes(LegalEntity $entity): void
    {
        $codes = [
            [
                'code' => 'DE-19',
                'name' => 'USt 19%',
                'versions' => [
                    ['from' => '2007-01-01', 'to' => '2020-06-30', 'rate' => 1900, 'category' => 'standard'],
                    ['from' => '2020-07-01', 'to' => '2020-12-31', 'rate' => 1600, 'category' => 'standard'],
                    ['from' => '2021-01-01', 'to' => null, 'rate' => 1900, 'category' => 'standard'],
                ],
            ],
            [
                'code' => 'DE-7',
                'name' => 'USt 7%',
                'versions' => [
                    ['from' => '2007-01-01', 'to' => '2020-06-30', 'rate' => 700, 'category' => 'reduced'],
                    ['from' => '2020-07-01', 'to' => '2020-12-31', 'rate' => 500, 'category' => 'reduced'],
                    ['from' => '2021-01-01', 'to' => null, 'rate' => 700, 'category' => 'reduced'],
                ],
            ],
            [
                'code' => 'DE-0',
                'name' => 'steuerfrei',
                'versions' => [
                    ['from' => '2007-01-01', 'to' => null, 'rate' => 0, 'category' => 'exempt'],
                ],
            ],
            [
                'code' => 'DE-RC',
                'name' => 'Reverse Charge',
                'versions' => [
                    ['from' => '2007-01-01', 'to' => null, 'rate' => 0, 'category' => 'reverse_charge'],
                ],
            ],
        ];

        foreach ($codes as $definition) {
            $code = TaxCode::query()->firstOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'direction' => 'both',
                    'is_active' => true,
                ]
            );

            foreach ($definition['versions'] as $version) {
                TaxRuleVersion::query()->firstOrCreate(
                    [
                        'tax_code_id' => $code->getKey(),
                        'valid_from' => $version['from'],
                    ],
                    [
                        'valid_to' => $version['to'],
                        'rate_bp' => $version['rate'],
                        'recoverable' => true,
                        'category' => $version['category'],
                    ]
                );
            }
        }
    }

    private function postingRules(LegalEntity $entity): void
    {
        $rules = [
            ['code' => 'operating_expense', 'label' => 'Sonstige Betriebsausgaben', 'role' => AccountRole::Expense, 'tax' => 'DE-19'],
            ['code' => 'personnel', 'label' => 'Personalkosten', 'role' => AccountRole::PersonnelExpense, 'tax' => 'DE-0'],
            ['code' => 'insurance', 'label' => 'Versicherungen', 'role' => AccountRole::Expense, 'tax' => 'DE-0'],
            ['code' => 'bank_fees', 'label' => 'Bankgebühren', 'role' => AccountRole::Expense, 'tax' => 'DE-0'],
            ['code' => 'private_deposit', 'label' => 'Privateinlage', 'role' => AccountRole::PrivateDeposits, 'tax' => null],
            ['code' => 'private_drawing', 'label' => 'Privatentnahme', 'role' => AccountRole::PrivateDrawings, 'tax' => null],
            ['code' => 'transfer', 'label' => 'Umbuchung', 'role' => AccountRole::Suspense, 'tax' => null],
            ['code' => 'suspense', 'label' => 'ungeklärter Posten', 'role' => AccountRole::Suspense, 'tax' => null],
        ];

        foreach ($rules as $definition) {
            $rule = PostingRule::query()->firstOrCreate(
                [
                    'legal_entity_id' => $entity->getKey(),
                    'code' => $definition['code'],
                ],
                [
                    'label' => $definition['label'],
                    'explanation' => $definition['label'],
                    'compliance_profile_key' => 'DE',
                    'is_active' => true,
                ]
            );

            PostingRuleVersion::query()->firstOrCreate(
                [
                    'posting_rule_id' => $rule->getKey(),
                    'version' => 1,
                ],
                [
                    'valid_from' => '2021-01-01',
                    'tax_code' => $definition['tax'],
                    'account_mappings' => [
                        'counterpart' => $definition['role']->value,
                    ],
                    'line_templates' => [
                        ['side' => 'counterpart', 'role' => $definition['role']->value],
                    ],
                ]
            );
        }
    }
}

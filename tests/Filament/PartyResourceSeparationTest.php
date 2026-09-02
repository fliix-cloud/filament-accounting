<?php

namespace FilamentAccounting\Tests\Filament;

use FilamentAccounting\Filament\Resources\CustomerResource;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use FilamentAccounting\Filament\Resources\CustomerResource\Pages\EditCustomer;
use FilamentAccounting\Filament\Resources\SupplierResource;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\CreateSupplier;
use FilamentAccounting\Filament\Resources\SupplierResource\Pages\EditSupplier;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class PartyResourceSeparationTest extends TestCase
{
    #[Test]
    public function role_switches_are_not_exposed_by_customer_or_supplier_forms(): void
    {
        foreach ([CustomerResource::class, SupplierResource::class] as $resource) {
            $filename = (new ReflectionClass($resource))->getFileName();
            $this->assertNotFalse($filename);
            $source = file_get_contents($filename);
            $this->assertNotFalse($source);
            $this->assertStringNotContainsString("Toggle::make('is_customer')", $source);
            $this->assertStringNotContainsString("Toggle::make('is_supplier')", $source);
        }
    }

    #[Test]
    public function create_pages_force_their_exclusive_party_role(): void
    {
        $entity = $this->makeEntity();

        $customer = (new class extends CreateCustomer
        {
            /** @param array<string, mixed> $data */
            public function normalize(array $data): array
            {
                return $this->mutateFormDataBeforeCreate($data);
            }
        })->normalize([
            'legal_name' => 'Customer GmbH',
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $this->assertSame($entity->getKey(), $customer['legal_entity_id']);
        $this->assertTrue($customer['is_customer']);
        $this->assertFalse($customer['is_supplier']);

        $supplier = (new class extends CreateSupplier
        {
            /** @param array<string, mixed> $data */
            public function normalize(array $data): array
            {
                return $this->mutateFormDataBeforeCreate($data);
            }
        })->normalize([
            'legal_name' => 'Supplier GmbH',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->assertSame($entity->getKey(), $supplier['legal_entity_id']);
        $this->assertFalse($supplier['is_customer']);
        $this->assertTrue($supplier['is_supplier']);
    }

    #[Test]
    public function the_same_business_can_be_an_independent_customer_and_supplier(): void
    {
        $entity = $this->makeEntity();
        $sharedData = [
            'legal_name' => 'Shared Business GmbH',
            'email' => 'accounting@shared-business.example',
        ];

        $customer = $this->makeParty($entity, $sharedData);
        $supplier = $this->makeParty($entity, array_merge($sharedData, [
            'is_customer' => false,
            'is_supplier' => true,
        ]));

        $this->assertNotSame($customer->getKey(), $supplier->getKey());
        $this->assertTrue(CustomerResource::getEloquentQuery()->whereKey($customer->getKey())->exists());
        $this->assertFalse(CustomerResource::getEloquentQuery()->whereKey($supplier->getKey())->exists());
        $this->assertTrue(SupplierResource::getEloquentQuery()->whereKey($supplier->getKey())->exists());
        $this->assertFalse(SupplierResource::getEloquentQuery()->whereKey($customer->getKey())->exists());
    }

    #[Test]
    public function customer_and_supplier_queries_never_cross_the_current_legal_entity(): void
    {
        $firstEntity = $this->makeEntity(['legal_name' => 'First Entity GmbH']);
        $firstCustomer = $this->makeParty($firstEntity);
        $firstSupplier = $this->makeParty($firstEntity, [
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $secondEntity = $this->makeEntity(['legal_name' => 'Second Entity GmbH']);
        $secondCustomer = $this->makeParty($secondEntity);
        $secondSupplier = $this->makeParty($secondEntity, [
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $this->assertFalse(CustomerResource::getEloquentQuery()->whereKey($firstCustomer)->exists());
        $this->assertTrue(CustomerResource::getEloquentQuery()->whereKey($secondCustomer)->exists());
        $this->assertFalse(SupplierResource::getEloquentQuery()->whereKey($firstSupplier)->exists());
        $this->assertTrue(SupplierResource::getEloquentQuery()->whereKey($secondSupplier)->exists());
    }

    #[Test]
    public function edit_pages_discard_injected_role_changes(): void
    {
        $customer = (new class extends EditCustomer
        {
            /** @param array<string, mixed> $data */
            public function normalize(array $data): array
            {
                return $this->mutateFormDataBeforeSave($data);
            }
        })->normalize([
            'legal_name' => 'Customer GmbH',
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $this->assertSame(['legal_name' => 'Customer GmbH'], $customer);

        $supplier = (new class extends EditSupplier
        {
            /** @param array<string, mixed> $data */
            public function normalize(array $data): array
            {
                return $this->mutateFormDataBeforeSave($data);
            }
        })->normalize([
            'legal_name' => 'Supplier GmbH',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->assertSame(['legal_name' => 'Supplier GmbH'], $supplier);
    }
}

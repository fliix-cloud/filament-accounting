<?php

namespace FilamentAccounting\Database\Seeders;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\InvoicePaymentMethod;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Ownership\LegalEntityScope;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\SeedGermanProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/** Loads operator-provided data from a local JSON profile; no private customer data is shipped. */
class InvoiceProfileDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || app(AccountingActorResolver::class)->resolve() === null) {
            throw new \LogicException('The invoice profile seeder requires a local demo and an authenticated actor.');
        }
        $path = (string) config('filament-accounting.demo.profile_path');
        $profile = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($profile) || blank($profile['company']['legal_name'] ?? null) || blank($profile['customer']['legal_name'] ?? null)) {
            throw new \InvalidArgumentException('The demo profile requires company and customer names.');
        }

        (new LegalEntity)->getConnection()->transaction(function () use ($profile): void {
            $entity = app(LegalEntityScope::class)->current() ?? new LegalEntity;
            $isNewEntity = ! $entity->exists;
            app(AccountingAuthorizer::class)->authorize('manage_settings', $entity);
            app(AccountingAuthorizer::class)->authorize('manage_parties', $entity);
            $entity->fill(array_intersect_key($profile['company'], array_flip($entity->getFillable())) + [
                'country_code' => 'DE', 'base_currency' => 'EUR', 'locale' => 'de_DE', 'timezone' => 'Europe/Berlin',
                'fiscal_year_start_month' => 1, 'accounting_basis' => 'accrual', 'vat_method' => 'accrual',
                'compliance_profile_key' => 'DE', 'state' => 'active',
            ]);
            if (filled($profile['logo_file'] ?? null)) {
                $bytes = (string) file_get_contents($profile['logo_file']);
                if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png' || strlen($bytes) > 2 * 1024 * 1024) {
                    throw new \InvalidArgumentException('The demo logo must be a PNG smaller than 2 MB.');
                }
                $logoPath = 'accounting/demo/logo-'.hash('sha256', $bytes).'.png';
                Storage::disk(config('filament-accounting.storage.disk', 'local'))->put($logoPath, $bytes, ['visibility' => 'private']);
                $entity->invoice_logo_path = $logoPath;
            }
            $entity->save();
            if ($isNewEntity) {
                app(SeedGermanProfile::class)->handle($entity);
            }
            $customer = Party::query()->updateOrCreate([
                'legal_entity_id' => $entity->getKey(),
                'external_reference' => $profile['customer']['external_reference'] ?? 'reference-demo-customer',
            ], array_intersect_key($profile['customer'], array_flip([
                'legal_name', 'display_name', 'email', 'invoice_email', 'phone', 'country_code', 'payment_terms_days',
            ])) + ['is_customer' => true, 'is_supplier' => false, 'kind' => 'organization', 'is_active' => true, 'default_currency' => 'EUR']);
            if (isset($profile['customer_address'])) {
                $customer->addresses()->updateOrCreate(['is_primary' => true],
                    array_intersect_key($profile['customer_address'], array_flip(['line1', 'line2', 'postal_code', 'city', 'region', 'country_code']))
                    + ['address_role' => 'billing']);
            }
            $payload = [
                'party_id' => $customer->getKey(),
                'issue_date' => $profile['invoice']['issue_date'] ?? now()->toDateString(),
                'supply_date' => $profile['invoice']['supply_date'] ?? now()->toDateString(),
                'due_date' => $profile['invoice']['due_date'] ?? null,
                'currency' => 'EUR', 'payment_method' => InvoicePaymentMethod::DirectDebit,
                'idempotency_key' => 'demo-reference-sales-1',
                'lines' => [['description' => '<p><strong>Beispielleistung</strong></p><p>Demo-Position zur Layoutprüfung.</p>',
                    'quantity' => '1', 'unit' => 'H87', 'unit_price' => '100.00', 'tax_code' => 'DE-19', 'account_role' => 'revenue']],
            ];
            $draft = Document::query()->where('legal_entity_id', $entity->getKey())->where('idempotency_key', $payload['idempotency_key'])->first();
            if ($draft instanceof Document && $draft->document_status === DocumentStatus::Draft) {
                app(IssueSalesInvoice::class)->updateDraft($draft, $payload);
            } elseif (! $draft instanceof Document) {
                app(IssueSalesInvoice::class)->createDraft($entity, $payload);
            }
        });
    }
}

<?php

namespace FilamentAccounting\Tests\Documents;

use FilamentAccounting\Banking\FinTs\Models\DirectDebitCreditorProfile;
use FilamentAccounting\Banking\FinTs\Models\DirectDebitMandate;
use FilamentAccounting\Database\Seeders\InvoiceProfileDemoSeeder;
use FilamentAccounting\Documents\BladeInvoiceRenderer;
use FilamentAccounting\Documents\ZugferdEInvoiceAdapter;
use FilamentAccounting\Enums\DocumentStatus;
use FilamentAccounting\Enums\InvoicePaymentMethod;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\Party;
use FilamentAccounting\Models\PartyBankAccount;
use FilamentAccounting\Services\GenerateInvoiceArtifacts;
use FilamentAccounting\Services\IssueSalesInvoice;
use FilamentAccounting\Services\ResolveInvoicePayment;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class InvoicePaymentLayoutTest extends TestCase
{
    protected function refreshTestDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        $this->migrateDatabases();
    }

    #[Test]
    public function direct_debit_preview_needs_no_mandate_but_issuance_does(): void
    {
        $document = $this->draft();
        $issuer = app(IssueSalesInvoice::class);
        $pdf = $issuer->preview($document->toArray() + ['lines' => [['description' => 'Demo', 'quantity' => '10', 'unit_price' => '32,99', 'tax_code' => 'DE-19']]]);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, Document::query()->count());
        try {
            $issuer->issue($document);
            $this->fail('Missing mandate must block issuance.');
        } catch (DocumentException $exception) {
            $this->assertSame(__('filament-accounting::invoice.mandate_required'), $exception->getMessage());
        }
        $this->assertSame(DocumentStatus::Draft, $document->fresh()->document_status);
        $this->assertNull($document->fresh()->number);
    }

    #[Test]
    public function direct_debit_xml_uses_frozen_mandate_and_customer_bank_details(): void
    {
        $document = $this->draft();
        $mandate = $this->mandate($document);
        $document = app(IssueSalesInvoice::class)->updateDraft($document, ['direct_debit_mandate_id' => $mandate->id,
            'lines' => [['description' => 'Service', 'quantity' => '10', 'unit_price' => '32,99', 'tax_code' => 'DE-19']]]);
        $document = app(IssueSalesInvoice::class)->issue($document);
        $snapshot = app(GenerateInvoiceArtifacts::class)->snapshot($document);
        $xml = app(ZugferdEInvoiceAdapter::class)->generate($snapshot);
        $this->assertStringContainsString('<ram:TypeCode>59</ram:TypeCode>', $xml);
        $this->assertStringContainsString('<ram:DirectDebitMandateID>DEMO-MANDATE</ram:DirectDebitMandateID>', $xml);
        $this->assertStringContainsString('DE98ZZZ09999999999', $xml);
        $this->assertStringContainsString('DE89370400440532013000', $xml);
        app(GenerateInvoiceArtifacts::class)->handle($document);
        $this->assertSame(2, $document->attachments()->count());
        $mandate->update(['reference' => 'CHANGED']);
        $this->assertSame($snapshot, app(GenerateInvoiceArtifacts::class)->snapshot($document->fresh()));
        $this->assertSame(InvoicePaymentMethod::DirectDebit, $document->payment_method);
        $this->assertNull($mandate->fresh()->first_used_at);
    }

    #[Test]
    public function mandates_of_another_customer_and_future_mandates_are_rejected(): void
    {
        $document = $this->draft();
        $mandate = $this->mandate($document);
        foreach (['future', 'other_customer', 'revoked'] as $case) {
            $document->direct_debit_mandate_id = $mandate->id;
            if ($case === 'future') {
                $mandate->update(['signed_on' => '2027-01-01']);
            } elseif ($case === 'other_customer') {
                $mandate->update(['signed_on' => '2026-01-01']);
                $document->party_id = $this->makeParty($document->legalEntity)->id;
            } else {
                $document->party_id = $mandate->party_id;
                $mandate->update(['status' => 'revoked']);
            }
            try {
                app(ResolveInvoicePayment::class)->snapshot($document);
                $this->fail('Invalid mandate accepted: '.$case);
            } catch (DocumentException $exception) {
                $this->assertSame(__('filament-accounting::invoice.mandate_required'), $exception->getMessage());
            }
        }
    }

    #[Test]
    public function blade_formats_quantities_and_money_and_escapes_untrusted_content(): void
    {
        app()->setLocale('de');
        $document = $this->draft();
        $document->legal_entity_snapshot = ['legal_name' => '<script>seller</script>', 'invoice_subtitle' => 'Service'];
        $document->payment_snapshot = ['method' => 'direct_debit'];
        $snapshot = app(GenerateInvoiceArtifacts::class)->snapshot($document);
        $snapshot['lines'][0]['description'] = '<p><strong>Service</strong><img src="file:///secret"><script>alert(1)</script></p>';
        $html = app(BladeInvoiceRenderer::class)->html($snapshot);
        $this->assertStringContainsString('&lt;script&gt;seller&lt;/script&gt;', $html);
        $this->assertStringContainsString('<strong>Service</strong>', $html);
        $this->assertStringNotContainsString('file:///secret', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('>10</td>', $html);
        $this->assertStringContainsString('329,90 €', $html);
        $this->assertStringContainsString('Lastschrift', $html);
        app()->setLocale('en');
        $this->assertStringContainsString('Direct debit', app(BladeInvoiceRenderer::class)->html($snapshot));
        $snapshot['seller']['locale'] = 'de_DE';
        $snapshot['lines'][0]['unit'] = 'H87';
        $localized = app(BladeInvoiceRenderer::class)->html($snapshot);
        $this->assertStringContainsString('Lastschrift', $localized);
        $this->assertStringContainsString('Stk.', $localized);
        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function local_profile_seeding_reuses_company_and_customer_without_fabricating_a_mandate(): void
    {
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $path = tempnam(sys_get_temp_dir(), 'invoice-profile-');
        file_put_contents($path, json_encode([
            'company' => ['legal_name' => 'Example Services', 'invoice_subtitle' => 'IT Services'],
            'customer' => ['legal_name' => 'Example Customer', 'external_reference' => 'C-101'],
            'customer_address' => ['line1' => 'Example Street 1', 'postal_code' => '10115', 'city' => 'Berlin', 'country_code' => 'DE'],
        ], JSON_THROW_ON_ERROR));
        config()->set('filament-accounting.demo.profile_path', $path);
        try {
            app(InvoiceProfileDemoSeeder::class)->run();
            app(InvoiceProfileDemoSeeder::class)->run();
        } finally {
            unlink($path);
        }
        $this->assertSame('Example Services', $entity->fresh()->legal_name);
        $this->assertSame(1, $entity->newQuery()->count());
        $this->assertSame(1, Party::query()->count());
        $this->assertSame('Berlin', Party::query()->sole()->addresses()->sole()->city);
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(InvoicePaymentMethod::DirectDebit, Document::query()->sole()->payment_method);
        $this->assertSame(0, DirectDebitMandate::query()->count());
    }

    #[Test]
    public function invoice_logo_is_frozen_when_issued_and_survives_source_removal(): void
    {
        $document = $this->draft();
        $entity = $document->legalEntity;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/YQAAAAASUVORK5CYII=');
        Storage::disk('local')->put('logo.png', $png);
        $entity->update(['invoice_logo_path' => 'logo.png']);
        $document->payment_method = InvoicePaymentMethod::CreditTransfer;
        $document->save();
        $document = app(IssueSalesInvoice::class)->issue($document);
        $snapshot = app(GenerateInvoiceArtifacts::class)->snapshot($document);
        Storage::disk('local')->delete('logo.png');
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $snapshot['seller']['invoice_logo_data']);
        $this->assertSame($snapshot, app(GenerateInvoiceArtifacts::class)->snapshot($document->fresh()));
        $this->assertStringStartsWith('%PDF-', app(BladeInvoiceRenderer::class)->render($snapshot));
    }

    private function draft(): Document
    {
        Storage::fake('local');
        $entity = $this->makeEntity(['address_line1' => 'Example Street 1', 'postal_code' => '10115', 'city' => 'Berlin', 'vat_id' => 'DE123456789']);
        $this->actingAs($this->makeUser());

        return app(IssueSalesInvoice::class)->createDraft($entity, [
            'party_id' => $this->makeParty($entity)->id, 'issue_date' => '2026-09-01', 'currency' => 'EUR',
            'payment_method' => 'direct_debit',
            'lines' => [['description' => 'Service', 'quantity' => '10', 'unit_price' => '32,99', 'tax_code' => 'DE-19']],
        ]);
    }

    private function mandate(Document $document): DirectDebitMandate
    {
        $bank = PartyBankAccount::query()->create(['party_id' => $document->party_id, 'iban' => 'DE89370400440532013000']);
        $creditor = DirectDebitCreditorProfile::query()->create(['legal_entity_id' => $document->legal_entity_id, 'creditor_identifier' => 'DE98ZZZ09999999999']);

        return DirectDebitMandate::query()->create([
            'party_bank_account_id' => $bank->id, 'creditor_profile_id' => $creditor->id,
            'reference' => 'DEMO-MANDATE', 'scheme' => 'CORE', 'mandate_type' => 'recurring', 'signed_on' => '2026-01-01',
        ]);
    }
}

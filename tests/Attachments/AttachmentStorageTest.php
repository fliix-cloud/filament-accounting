<?php

namespace FilamentAccounting\Tests\Attachments;

use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Services\StoreAttachment;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class AttachmentStorageTest extends TestCase
{
    #[Test]
    public function private_storage_is_verified_and_retries_are_idempotent(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $entity = $this->makeEntity();
        $this->actingAs($this->makeUser());
        $party = $this->makeParty($entity);
        $contents = '%PDF-1.7'.PHP_EOL.'test invoice';

        $first = app(StoreAttachment::class)->handle($entity, $party, 'Invoice 42.PDF', $contents);
        $retry = app(StoreAttachment::class)->handle($entity, $party, 'Invoice 42.PDF', $contents);

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame('application/pdf', $first->mime_type);
        $this->assertSame(hash('sha256', $contents), $first->sha256);
        Storage::disk('accounting-test')->assertExists($first->path);
        $this->assertSame($contents, app(ReadAttachment::class)->handle($first));
    }

    #[Test]
    public function invalid_signatures_unsafe_xml_and_cross_entity_links_are_rejected(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $firstEntity = $this->makeEntity(['legal_name' => 'First GmbH']);
        $party = $this->makeParty($firstEntity);
        $secondEntity = $this->makeEntity(['legal_name' => 'Second GmbH']);
        $secondParty = $this->makeParty($secondEntity);
        $service = app(StoreAttachment::class);

        foreach ([
            fn () => $service->handle($secondEntity, $party, 'invoice.pdf', '%PDF-1.7'),
            fn () => $service->handle($secondEntity, $secondParty, 'invoice.pdf', 'not a pdf'),
            fn () => $service->handle($secondEntity, $secondParty, 'invoice.xml', '<!DOCTYPE foo><foo/>'),
            fn () => $service->handle($secondEntity, $secondParty, 'invoice.exe', 'binary'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Unsafe attachment input must be rejected.');
            } catch (AccountingException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('accounting_attachments', 0);
    }

    #[Test]
    public function downloads_enforce_tenant_scope_and_content_integrity(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $firstEntity = $this->makeEntity(['legal_name' => 'First GmbH']);
        $this->actingAs($this->makeUser());
        $attachment = app(StoreAttachment::class)->handle(
            $firstEntity,
            $this->makeParty($firstEntity),
            'invoice.xml',
            '<?xml version="1.0"?><Invoice/>',
        );

        $secondEntity = $this->makeEntity(['legal_name' => 'Second GmbH']);
        app(SingleLegalEntityResolver::class)->bind($secondEntity);

        $this->expectException(AccountingException::class);
        app(ReadAttachment::class)->handle($attachment);
    }
}

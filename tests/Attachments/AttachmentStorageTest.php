<?php

namespace FilamentAccounting\Tests\Attachments;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Ownership\SingleLegalEntityResolver;
use FilamentAccounting\Services\ReadAttachment;
use FilamentAccounting\Services\StoreAttachment;
use FilamentAccounting\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class AttachmentStorageTest extends TestCase
{
    #[Test]
    public function identical_files_for_different_owners_and_sources_have_separate_objects(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $entity = $this->makeEntity();
        $party = $this->makeParty($entity);
        $contents = '%PDF-1.7'.PHP_EOL.'original';
        $service = app(StoreAttachment::class);

        $first = $service->handle($entity, $party, 'invoice.pdf', $contents);
        $second = $service->handle($entity, $this->makeParty($entity), 'invoice.pdf', $contents);
        $third = $service->handle($entity, $party, 'invoice.pdf', $contents, 'original_invoice');

        $this->assertCount(3, array_unique([$first->path, $second->path, $third->path]));
        foreach ([$first, $second, $third] as $attachment) {
            $this->assertSame($contents, Storage::disk($attachment->disk)->get($attachment->path));
        }
    }

    #[Test]
    public function retry_rejects_corrupt_or_missing_originals_without_replacing_them(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $entity = $this->makeEntity();
        $party = $this->makeParty($entity);
        $contents = '%PDF-1.7'.PHP_EOL.'original';
        $service = app(StoreAttachment::class);
        $attachment = $service->handle($entity, $party, 'invoice.pdf', $contents);
        $disk = Storage::disk($attachment->disk);

        foreach (['changed bytes', null] as $stored) {
            if ($stored === null) {
                $disk->delete($attachment->path);
            } else {
                $disk->put($attachment->path, $stored);
            }

            try {
                $service->handle($entity, $party, 'invoice.pdf', $contents);
                $this->fail('Retry must surface damaged or missing evidence.');
            } catch (AccountingException $exception) {
                $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
            }

            $this->assertSame($stored, $disk->get($attachment->path));
            $this->assertDatabaseCount('accounting_attachments', 1);
        }
    }

    #[Test]
    public function failed_metadata_save_retains_written_original_and_other_owners_file(): void
    {
        Storage::fake('accounting-test');
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $entity = $this->makeEntity();
        $contents = '%PDF-1.7'.PHP_EOL.'original';
        $first = app(StoreAttachment::class)->handle($entity, $this->makeParty($entity), 'invoice.pdf', $contents);
        $actors = \Mockery::mock(AccountingActorResolver::class);
        $actors->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('Metadata failure'));

        try {
            (new StoreAttachment($actors))->handle($entity, $this->makeParty($entity), 'invoice.pdf', $contents);
            $this->fail('The metadata failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Metadata failure', $exception->getMessage());
        }

        $disk = Storage::disk('accounting-test');
        $this->assertSame($contents, $disk->get($first->path));
        $this->assertCount(2, $disk->allFiles());
        foreach ($disk->allFiles() as $path) {
            $this->assertSame($contents, $disk->get($path));
        }
        $this->assertDatabaseCount('accounting_attachments', 1);
    }

    #[Test]
    public function failed_storage_verification_does_not_delete_evidence(): void
    {
        config()->set('filament-accounting.storage.disk', 'accounting-test');
        $entity = $this->makeEntity();
        $party = $this->makeParty($entity);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->andReturnFalse();
        $disk->shouldReceive('put')->with(\Mockery::type('string'), '%PDF-1.7', ['visibility' => 'private'])->once()->andReturnTrue();
        $disk->shouldReceive('get')->once()->andReturn('damaged write');
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('accounting-test')->andReturn($disk);

        try {
            app(StoreAttachment::class)->handle($entity, $party, 'invoice.pdf', '%PDF-1.7');
            $this->fail('Damaged writes must not create attachment metadata.');
        } catch (AccountingException $exception) {
            $this->assertSame(__('filament-accounting::errors.attachment_integrity_failed'), $exception->getMessage());
        }
        $this->assertDatabaseCount('accounting_attachments', 0);
    }

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

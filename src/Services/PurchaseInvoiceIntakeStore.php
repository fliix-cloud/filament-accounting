<?php

namespace FilamentAccounting\Services;

use FilamentAccounting\Contracts\AccountingActorResolver;
use FilamentAccounting\Contracts\AccountingAuthorizer;
use FilamentAccounting\Exceptions\AccountingException;
use FilamentAccounting\Exceptions\DocumentException;
use FilamentAccounting\Models\LegalEntity;
use FilamentAccounting\Models\PurchaseInvoiceIntake;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PurchaseInvoiceIntakeStore
{
    public function __construct(
        private readonly AccountingAuthorizer $authorizer,
        private readonly AccountingActorResolver $actors,
        private readonly LegalEntityScope $entities,
        private readonly AuditLogger $audit,
    ) {}

    public function authorize(LegalEntity $entity): void
    {
        $this->entities->assertSame($entity->getKey());
        $this->authorizer->authorize('register_purchase_invoices', $entity);
        if ($entity->getConnection()->transactionLevel() !== 0) {
            throw new DocumentException(__('filament-accounting::errors.intake_requires_independent_commit'));
        }
    }

    /** @param array<string, array{filename: string, contents: string}> $inputs */
    public function prepare(LegalEntity $entity, array $inputs): PurchaseInvoiceIntake
    {
        $this->authorize($entity);
        $disk = (string) config('filament-accounting.storage.disk', 'local');
        if ($disk === 'public') {
            throw new AccountingException(__('filament-accounting::errors.public_disk_forbidden'));
        }
        $uuid = (string) Str::uuid();
        $directory = trim((string) config('filament-accounting.storage.attachments_directory', 'accounting/attachments'), '/');
        $files = [];
        $identity = [];
        foreach ($inputs as $role => $input) {
            if (! in_array($role, ['primary', 'companion'], true)) {
                throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
            }
            $filename = basename(str_replace('\\', '/', trim($input['filename'])));
            $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
            $size = strlen($input['contents']);
            if (! in_array($extension, $role === 'primary' ? ['pdf', 'xml'] : ['xml'], true)
                || str_contains($filename, "\0") || $size === 0
                || $size > (int) config('filament-accounting.storage.maximum_attachment_bytes', 15 * 1024 * 1024)) {
                throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
            }
            $hash = hash('sha256', $input['contents']);
            $identity[$role] = [$extension, $hash];
            $files[$role] = ['filename' => $filename, 'path' => $directory.'/intake/'.$uuid.'/'.$role.'.bin', 'sha256' => $hash, 'size' => $size];
        }
        if (! isset($files['primary']) || (isset($files['companion']) && $identity['primary'][0] !== 'pdf')) {
            throw new DocumentException(__('filament-accounting::errors.invalid_attachment'));
        }
        ksort($identity);
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return $entity->getConnection()->transaction(function () use ($entity, $key, $uuid, $disk, $files): PurchaseInvoiceIntake {
            LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
            $existing = PurchaseInvoiceIntake::query()->where('legal_entity_id', $entity->getKey())->where('identity', $key)->first();
            if ($existing instanceof PurchaseInvoiceIntake) {
                return $existing;
            }
            $actor = $this->actors->resolve();
            $intake = PurchaseInvoiceIntake::query()->create([
                'uuid' => $uuid, 'legal_entity_id' => $entity->getKey(), 'identity' => $key,
                'disk' => $disk, 'files' => $files, 'preserved_files' => [], 'status' => 'pending',
                'created_by_type' => $actor?->getMorphClass(), 'created_by_id' => $actor ? (string) $actor->getKey() : null,
            ]);
            $this->audit->log($entity, 'purchase_intake.created', $intake, ['disk' => $disk, 'files' => $files, 'identity' => $key]);

            return $intake;
        });
    }

    /** @param array<string, array{filename: string, contents: string}> $inputs */
    public function preserve(LegalEntity $entity, PurchaseInvoiceIntake $intake, array $inputs): void
    {
        $this->authorize($entity);
        $this->entities->assertSame($intake->legal_entity_id, $entity);
        foreach (array_keys($intake->files) as $role) {
            $entity->getConnection()->transaction(function () use ($entity, $intake, $inputs, $role): void {
                LegalEntity::query()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $locked = PurchaseInvoiceIntake::query()->where('legal_entity_id', $entity->getKey())->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
                $file = $locked->files[$role];
                $disk = Storage::disk($locked->disk);
                if (! ($locked->preserved_files[$role] ?? false) && ! $disk->exists($file['path'])) {
                    $contents = $inputs[$role]['contents'] ?? null;
                    if (! is_string($contents) || hash('sha256', $contents) !== $file['sha256']) {
                        throw new AccountingException(__('filament-accounting::errors.attachment_integrity_failed'));
                    }
                    if (! $disk->put($file['path'], $contents, ['visibility' => 'private'])) {
                        throw new AccountingException(__('filament-accounting::errors.attachment_write_failed'));
                    }
                }
                $this->verifiedContents($locked, $role);
                if (! ($locked->preserved_files[$role] ?? false)) {
                    $locked->preserved_files = $locked->preserved_files + [$role => true];
                    if (count($locked->preserved_files) === count($locked->files)) {
                        $locked->preserved_at = now();
                        $locked->status = 'ready';
                    }
                    $locked->save();
                    $this->audit->log($entity, 'purchase_intake.file_preserved', $locked, ['role' => $role, 'sha256' => $file['sha256']]);
                }
            });
        }
    }

    /** @return array<string, string> */
    public function read(LegalEntity $entity, PurchaseInvoiceIntake $intake): array
    {
        $this->entities->assertSame($entity->getKey());
        $this->entities->assertSame($intake->legal_entity_id, $entity);
        $this->authorizer->authorize('register_purchase_invoices', $entity);
        $intake = PurchaseInvoiceIntake::query()->where('legal_entity_id', $entity->getKey())->findOrFail($intake->getKey());
        $contents = [];
        foreach (array_keys($intake->files) as $role) {
            if (! ($intake->preserved_files[$role] ?? false)) {
                throw new DocumentException(__('filament-accounting::errors.invoice_originals_incomplete'));
            }
            $contents[$role] = $this->verifiedContents($intake, $role);
        }

        return $contents;
    }

    private function verifiedContents(PurchaseInvoiceIntake $intake, string $role): string
    {
        $file = $intake->files[$role];
        $contents = Storage::disk($intake->disk)->get($file['path']);
        if (! is_string($contents) || strlen($contents) !== $file['size'] || hash('sha256', $contents) !== $file['sha256']) {
            throw new AccountingException(__('filament-accounting::errors.attachment_integrity_failed'));
        }

        return $contents;
    }
}

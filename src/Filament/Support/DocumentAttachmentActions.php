<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Services\ReadAttachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentAttachmentActions
{
    /** @return list<Action> */
    public static function make(Document $document): array
    {
        return Attachment::query()
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (Attachment $attachment): Action => Action::make('attachment'.$attachment->getKey())
                ->label(self::label($attachment))
                ->tooltip($attachment->original_filename)
                ->icon(str_contains($attachment->mime_type, 'pdf') ? 'heroicon-o-document' : 'heroicon-o-code-bracket')
                ->action(fn (): StreamedResponse => self::download($document, $attachment)))
            ->all();
    }

    /** @return list<Action> */
    public static function table(): array
    {
        return [
            self::tableDownloadAction('downloadPdf', 'PDF', 'pdf', 'heroicon-o-document'),
            self::tableDownloadAction('downloadXml', 'XML', 'xml', 'heroicon-o-code-bracket'),
        ];
    }

    private static function tableDownloadAction(string $name, string $label, string $extension, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (Document $record): bool => self::attachment($record, $extension) instanceof Attachment)
            ->tooltip(fn (Document $record): ?string => self::attachment($record, $extension)?->original_filename)
            ->action(function (Document $record) use ($extension): StreamedResponse {
                $attachment = self::attachment($record, $extension);

                abort_unless($attachment instanceof Attachment, 404);

                return self::download($record, $attachment);
            });
    }

    private static function attachment(Document $document, string $extension): ?Attachment
    {
        if ($extension === 'xml' && ! data_get($document->e_invoice_meta, 'structured', false)) {
            return null;
        }

        $document->loadMissing('attachments');

        return $document->attachments
            ->first(
                fn (Attachment $attachment): bool => self::extension($attachment) === $extension,
            );
    }

    private static function download(Document $document, Attachment $attachment): StreamedResponse
    {
        $contents = app(ReadAttachment::class)->handle($attachment);

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            self::downloadFilename($document, $attachment),
            ['Content-Type' => $attachment->mime_type],
        );
    }

    public static function downloadFilename(Document $document, Attachment $attachment): string
    {
        $extension = self::extension($attachment);
        $identifier = $document->number
            ?: $document->supplier_invoice_number
            ?: $document->uuid;
        $identifier = preg_replace('/\.(pdf|xml)$/i', '', (string) $identifier) ?: $document->uuid;
        $identifier = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $identifier), '-_.');

        return ($identifier !== '' ? $identifier : $document->uuid).'.'.$extension;
    }

    private static function label(Attachment $attachment): string
    {
        $extension = strtolower(pathinfo($attachment->original_filename, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf' || str_contains($attachment->mime_type, 'pdf') => 'PDF',
            $extension === 'xml' || str_contains($attachment->mime_type, 'xml') => 'XML',
            default => __('filament-accounting::actions.download_attachment', ['name' => $attachment->original_filename]),
        };
    }

    private static function extension(Attachment $attachment): string
    {
        return match (true) {
            str_contains($attachment->mime_type, 'pdf') => 'pdf',
            str_contains($attachment->mime_type, 'xml') => 'xml',
            default => strtolower((string) pathinfo($attachment->original_filename, PATHINFO_EXTENSION)),
        };
    }
}

<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Actions\Action;
use FilamentAccounting\Models\Attachment;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Services\ReadAttachment;

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
                ->label(__('filament-accounting::actions.download_attachment', ['name' => $attachment->original_filename]))
                ->icon(str_contains($attachment->mime_type, 'pdf') ? 'heroicon-o-document' : 'heroicon-o-code-bracket')
                ->action(function () use ($attachment) {
                    $contents = app(ReadAttachment::class)->handle($attachment);

                    return response()->streamDownload(
                        static function () use ($contents): void {
                            echo $contents;
                        },
                        $attachment->original_filename,
                        ['Content-Type' => $attachment->mime_type],
                    );
                }))
            ->all();
    }
}

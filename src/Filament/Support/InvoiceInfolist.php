<?php

namespace FilamentAccounting\Filament\Support;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use FilamentAccounting\Models\Document;
use FilamentAccounting\Models\DocumentLine;
use FilamentAccounting\Support\MoneyFormatter;

final class InvoiceInfolist
{
    public static function originalFiles(): TextEntry
    {
        return TextEntry::make('original_files')
            ->label(__('filament-accounting::fields.original_files'))
            ->state(fn (Document $record): string => $record->attachments
                ->pluck('original_filename')
                ->implode(', '))
            ->visible(fn (Document $record): bool => $record->attachments->isNotEmpty())
            ->columnSpanFull();
    }

    public static function totals(): Section
    {
        return Section::make(__('filament-accounting::fields.totals'))
            ->schema([
                TextEntry::make('net_minor')
                    ->label(__('filament-accounting::fields.net'))
                    ->formatStateUsing(fn ($state, Document $record): string => MoneyFormatter::format((int) $state, $record->currency)),
                TextEntry::make('gross_minor')
                    ->label(__('filament-accounting::fields.gross'))
                    ->formatStateUsing(fn ($state, Document $record): string => MoneyFormatter::format((int) $state, $record->currency)),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    public static function lines(): Section
    {
        return Section::make(__('filament-accounting::fields.lines'))
            ->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->schema([
                        TextEntry::make('description')
                            ->label(__('filament-accounting::fields.description'))
                            ->html()
                            ->columnSpanFull(),
                        TextEntry::make('quantity')
                            ->label(__('filament-accounting::fields.quantity'))
                            ->columnSpan(2),
                        TextEntry::make('unit')
                            ->label(__('filament-accounting::fields.unit'))
                            ->placeholder('—')
                            ->columnSpan(1),
                        TextEntry::make('unit_price_minor')
                            ->label(__('filament-accounting::fields.unit_price'))
                            ->formatStateUsing(fn ($state, DocumentLine $record): string => self::formatLineMoney($record, (int) $state))
                            ->columnSpan(2),
                        TextEntry::make('tax_rate_bp')
                            ->label(__('filament-accounting::fields.tax'))
                            ->formatStateUsing(fn ($state): string => number_format((int) $state / 100, 2, ',', '.').' %')
                            ->columnSpan(2),
                        TextEntry::make('net_minor')
                            ->label(__('filament-accounting::fields.net'))
                            ->formatStateUsing(fn ($state, DocumentLine $record): string => self::formatLineMoney($record, (int) $state))
                            ->columnSpan(2),
                        TextEntry::make('gross_minor')
                            ->label(__('filament-accounting::fields.gross'))
                            ->formatStateUsing(fn ($state, DocumentLine $record): string => self::formatLineMoney($record, (int) $state))
                            ->columnSpan(3),
                    ])
                    ->columns(12)
                    ->grid(1)
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    private static function formatLineMoney(DocumentLine $line, int $amount): string
    {
        return MoneyFormatter::format($amount, (string) $line->document->currency);
    }
}

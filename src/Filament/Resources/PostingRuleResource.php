<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\CreatePostingRule;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\EditPostingRule;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\ListPostingRules;
use FilamentAccounting\Models\PostingRule;

class PostingRuleResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = PostingRule::class;

    protected static ?string $slug = 'accounting/posting-rules';

    protected static ?int $navigationSort = 51;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function getNavigationLabel(): string
    {
        return __('filament-accounting::navigation.posting_rules');
    }

    public static function getModelLabel(): string
    {
        return __('filament-accounting::resources.posting_rule.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-accounting::resources.posting_rule.plural');
    }

    protected static function ability(): string
    {
        return 'manage_chart';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('filament-accounting::fields.code'))->required(),
            TextInput::make('label')->label(__('filament-accounting::fields.label'))->required(),
            Textarea::make('explanation')->label(__('filament-accounting::fields.explanation')),
            TextInput::make('compliance_profile_key')->label(__('filament-accounting::fields.compliance_profile')),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('filament-accounting::fields.code')),
            TextColumn::make('label')->label(__('filament-accounting::fields.label'))->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostingRules::route('/'),
            'create' => CreatePostingRule::route('/create'),
            'edit' => EditPostingRule::route('/{record}/edit'),
        ];
    }
}

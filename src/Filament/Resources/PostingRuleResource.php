<?php

namespace FilamentAccounting\Filament\Resources;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use FilamentAccounting\Filament\Concerns\HasAccountingNavigation;
use FilamentAccounting\Filament\Navigation\AccountingNavigation;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\CreatePostingRule;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\EditPostingRule;
use FilamentAccounting\Filament\Resources\PostingRuleResource\Pages\ListPostingRules;
use FilamentAccounting\Models\PostingRule;
use FilamentAccounting\Models\TaxCode;
use FilamentAccounting\Ownership\LegalEntityScope;
use Illuminate\Database\Eloquent\Builder;

class PostingRuleResource extends Resource
{
    use HasAccountingNavigation;

    protected static ?string $model = PostingRule::class;

    protected static ?string $slug = 'accounting/posting-rules';

    protected static ?int $navigationSort = 51;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function getNavigationParentItem(): ?string
    {
        return AccountingNavigation::LEDGER;
    }

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

    public static function getEloquentQuery(): Builder
    {
        return app(LegalEntityScope::class)->constrain(parent::getEloquentQuery());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('filament-accounting::fields.code'))->required(),
            TextInput::make('label')->label(__('filament-accounting::fields.label'))->required(),
            Textarea::make('explanation')->label(__('filament-accounting::fields.explanation')),
            TextInput::make('compliance_profile_key')->label(__('filament-accounting::fields.compliance_profile')),
            Toggle::make('is_active')->label(__('filament-accounting::fields.is_active'))->default(true),
            Repeater::make('versions')
                ->relationship()
                ->label(__('filament-accounting::fields.posting_rule_versions'))
                ->schema([
                    TextInput::make('version')->label(__('filament-accounting::fields.version'))->integer()->minValue(1)->required(),
                    DatePicker::make('valid_from')->label(__('filament-accounting::fields.valid_from'))->required(),
                    DatePicker::make('valid_to')->label(__('filament-accounting::fields.valid_to')),
                    Select::make('direction')->label(__('filament-accounting::fields.direction'))->options([
                        'incoming' => __('filament-accounting::fields.incoming'),
                        'outgoing' => __('filament-accounting::fields.outgoing'),
                    ]),
                    Toggle::make('requires_receipt')->label(__('filament-accounting::fields.requires_receipt')),
                    Select::make('tax_code')
                        ->label(__('filament-accounting::fields.tax_code'))
                        ->options(fn (): array => TaxCode::query()
                            ->where('legal_entity_id', app(LegalEntityScope::class)->require()->getKey())
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->pluck('name', 'code')
                            ->all())
                        ->searchable(),
                    KeyValue::make('account_mappings')
                        ->label(__('filament-accounting::fields.account_mappings'))
                        ->keyLabel(__('filament-accounting::fields.mapping_key'))
                        ->valueLabel(__('filament-accounting::fields.mapping_value')),
                    Repeater::make('line_templates')
                        ->label(__('filament-accounting::fields.line_templates'))
                        ->schema([
                            Select::make('side')->options([
                                'counterpart' => __('filament-accounting::fields.counterpart'),
                                'debit' => __('filament-accounting::fields.debit'),
                                'credit' => __('filament-accounting::fields.credit'),
                            ])->required(),
                            TextInput::make('role')->label(__('filament-accounting::fields.account_role'))->required(),
                        ])
                        ->defaultItems(1),
                ])
                ->defaultItems(1)
                ->collapsible()
                ->columnSpanFull(),
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

<?php

namespace FilamentAccounting\Models;

use FilamentAccounting\Models\Concerns\BelongsToLegalEntity;
use FilamentAccounting\Support\HasUuid;
use FilamentAccounting\Support\Sepa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $legal_entity_id
 * @property string $direction
 * @property string $match_type
 * @property string $match_value
 * @property string $target_type
 * @property int $target_id
 * @property string $target_label
 * @property int $confirmed_count
 * @property bool $is_active
 */
class ReconciliationLearningRule extends AccountingModel
{
    use BelongsToLegalEntity;
    use HasUuid;

    protected $table = 'accounting_reconciliation_learning_rules';

    protected $fillable = [
        'legal_entity_id',
        'direction',
        'match_type',
        'match_value',
        'target_type',
        'target_id',
        'target_label',
        'confirmed_count',
        'last_confirmed_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'confirmed_count' => 'integer',
            'last_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return Collection<int, self> */
    public static function matching(BankStatementLine $line, string $targetType): Collection
    {
        $values = self::matchValues($line);

        return self::query()
            ->where('legal_entity_id', $line->legal_entity_id)
            ->where('direction', $line->isIncoming() ? 'incoming' : 'outgoing')
            ->where('target_type', $targetType)
            ->where('is_active', true)
            ->get()
            ->filter(fn (self $rule): bool => ($values[$rule->match_type] ?? null) === $rule->match_value)
            ->values();
    }

    /** @return array<string, string> */
    public static function matchValues(BankStatementLine $line): array
    {
        $values = [];
        if (filled($line->counterparty_iban)) {
            $values['iban'] = Sepa::normalizeIban((string) $line->counterparty_iban);
        }

        $name = self::normalize((string) $line->counterparty_name, false);
        if ($name !== '') {
            $values['counterparty_name'] = $name;
        }

        $purpose = self::normalize(implode(' ', array_filter([
            $line->purpose,
            $line->payment_reference,
        ])), true);
        if (mb_strlen($purpose) >= 4) {
            $values['purpose_pattern'] = $purpose;
        }

        return $values;
    }

    private static function normalize(string $value, bool $generalizeNumbers): string
    {
        $value = Str::lower(Str::ascii($value));
        if ($generalizeNumbers) {
            $value = preg_replace('/\d+/', '#', $value) ?? $value;
        }

        return trim(preg_replace('/[^a-z0-9#]+/', ' ', $value) ?? $value);
    }
}

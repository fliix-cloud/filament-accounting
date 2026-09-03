<?php

namespace FilamentAccounting\Banking\FinTs\Models;

use FilamentAccounting\Banking\FinTs\Models\Concerns\UsesPackageConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $bank_code
 * @property string $name
 * @property string|null $city
 * @property string|null $bic
 * @property string|null $checksum_method
 * @property string|null $hbci_host
 * @property string|null $pin_tan_url
 * @property string|null $hbci_version
 * @property string|null $pin_tan_version
 * @property bool $has_pin_tan
 * @property string|null $source
 * @property Carbon|null $synced_at
 */
class BankInstitute extends Model
{
    use UsesPackageConnection;

    protected $table = 'fints_institutes';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'has_pin_tan' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function label(): string
    {
        $parts = array_filter([
            $this->name,
            $this->city,
            $this->bank_code,
        ]);

        $label = implode(' · ', $parts);

        if (filled($this->bic)) {
            $label .= ' ('.$this->bic.')';
        }

        return $label;
    }

    public function scopeWithPinTan(Builder $query): Builder
    {
        return $query->where('has_pin_tan', true)->whereNotNull('pin_tan_url');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query->whereRaw('0 = 1');
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $inner) use ($like, $term): void {
            $inner->where('bank_code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('bic', 'like', $like);

            if (ctype_digit($term)) {
                $inner->orWhere('bank_code', $term);
            }
        });
    }
}

<?php

namespace FilamentAccounting\Models\Concerns;

use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToLegalEntity
{
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}

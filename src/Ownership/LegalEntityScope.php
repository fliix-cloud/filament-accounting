<?php

namespace FilamentAccounting\Ownership;

use FilamentAccounting\Contracts\AccountingEntityResolver;
use FilamentAccounting\Exceptions\EntityIsolationException;
use FilamentAccounting\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LegalEntityScope
{
    public function __construct(
        private readonly AccountingEntityResolver $resolver,
    ) {}

    public function current(): ?LegalEntity
    {
        return $this->resolver->resolve();
    }

    public function require(): LegalEntity
    {
        $entity = $this->current();

        if ($entity instanceof LegalEntity) {
            return $entity;
        }

        if ($this->required()) {
            throw new EntityIsolationException(__('filament-accounting::errors.entity_required'));
        }

        throw new EntityIsolationException(__('filament-accounting::errors.entity_required'));
    }

    public function required(): bool
    {
        return (bool) config('filament-accounting.ownership.required', true);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrain(Builder $query, string $column = 'legal_entity_id'): Builder
    {
        $entity = $this->require();

        return $query->where($column, $entity->getKey());
    }

    public function assertSame(int|string|null $legalEntityId, ?LegalEntity $entity = null): void
    {
        $entity ??= $this->require();

        if ((string) $legalEntityId !== (string) $entity->getKey()) {
            throw new EntityIsolationException(__('filament-accounting::errors.entity_mismatch'));
        }
    }

    public function assertModel(Model $model, string $column = 'legal_entity_id'): void
    {
        $this->assertSame($model->getAttribute($column));
    }
}

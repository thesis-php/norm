<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\EntityState;

/**
 * An entity that is known to exist in the storage.
 *
 * @internal
 *
 * @template TEntity of object
 * @template TRow of array<mixed>
 */
final class PersistedEntity
{
    public bool $isRemoved = false;

    /**
     * @param TEntity $entity
     * @param TRow $snapshot entityToRow() result taken right after loading
     */
    public function __construct(
        public readonly object $entity,
        private readonly array $snapshot,
    ) {}

    /**
     * @param TEntity $entity
     * @param \Closure(TEntity): TRow $entityToRow
     */
    public function state(object $entity, \Closure $entityToRow): ?EntityState
    {
        if ($entity !== $this->entity) {
            return null;
        }

        if ($this->isRemoved) {
            return EntityState::Removed;
        }

        return $entityToRow($entity) === $this->snapshot ? EntityState::Unchanged : EntityState::Changed;
    }

    /**
     * @param ChangeSet<TEntity, TRow> $changeSet
     */
    public function collectChanges(ChangeSet $changeSet): void
    {
        if ($this->isRemoved) {
            $changeSet->delete($this->snapshot);

            return;
        }

        $changeSet->update($this->entity, $this->snapshot);
    }
}

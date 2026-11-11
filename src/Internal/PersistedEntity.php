<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

use Thesis\Norm\EntityState;
use Thesis\Norm\Exception\DuplicateEntity;
use Thesis\Norm\Exception\EntityNotManaged;

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
    private bool $removed = false;

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
     * @throws DuplicateEntity
     */
    public function add(object $entity): void
    {
        if ($entity !== $this->entity) {
            throw new DuplicateEntity();
        }

        $this->removed = false;
    }

    /**
     * @param TEntity $entity
     * @throws EntityNotManaged
     */
    public function remove(object $entity): void
    {
        if ($entity !== $this->entity) {
            throw new EntityNotManaged();
        }

        $this->removed = true;
    }

    /**
     * @param TEntity $entity
     * @param \Closure(TEntity): TRow $entityToRow
     */
    public function state(object $entity, \Closure $entityToRow): ?EntityState
    {
        if ($entity !== $this->entity) {
            return null;
        }

        if ($this->removed) {
            return EntityState::Removed;
        }

        return $entityToRow($entity) === $this->snapshot ? EntityState::Unchanged : EntityState::Changed;
    }

    /**
     * @param ChangeSet<TEntity, TRow> $changeSet
     */
    public function collectChanges(ChangeSet $changeSet): void
    {
        if ($this->removed) {
            $changeSet->delete($this->snapshot);

            return;
        }

        $changeSet->update($this->entity, $this->snapshot);
    }
}

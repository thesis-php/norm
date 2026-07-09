<?php

declare(strict_types=1);

namespace Thesis\Norm\Internal;

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
     * @param TRow $row
     */
    public function __construct(
        public readonly object $entity,
        private readonly array $row,
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
     * @param ChangeSet<TEntity, TRow> $changeSet
     */
    public function collectChanges(ChangeSet $changeSet): void
    {
        if ($this->removed) {
            $changeSet->delete($this->row);

            return;
        }

        $changeSet->update($this->entity, $this->row);
    }
}
